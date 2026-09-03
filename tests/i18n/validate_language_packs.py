#!/usr/bin/env python3
"""Validate Certloom language-pack metadata/PO catalogs and compile deterministic MO files.

Reviewed packs are intentionally held to a strict standard: exact source POT
identity, complete coverage, no fuzzy entries, placeholder/tag parity, bounded
Unicode direction controls, declared plural forms and deterministic MO output.
Candidate packs may be incomplete, but every translated entry must still be safe.
"""
from __future__ import annotations

import argparse
from collections import Counter
from dataclasses import dataclass
from hashlib import sha256
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sys
from typing import Dict, Iterable, List, Optional, Tuple

from po_tools import PoEntry, PoSyntaxError, parse_headers, parse_po, write_mo, write_wp_json_catalogs

DOMAIN = "certloom"
SCHEMA = 1
ALLOWED_STATUSES = {"planned", "candidate", "reviewed"}
ALLOWED_DIRECTIONS = {"ltr", "rtl"}
BIDI_FORBIDDEN = set(chr(cp) for cp in list(range(0x202A, 0x202F)) + list(range(0x2066, 0x206A)))
PRINTF_RE = re.compile(r"%(?:\d+\$)?[-+0 #']*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX%]")
PLURAL_RE = re.compile(r"(?:^|;)\s*nplurals\s*=\s*(\d+)\s*;")
PLURAL_FORMS_RE = re.compile(r"^\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*([^;]{1,512})\s*;\s*$")
PLURAL_TOKEN_RE = re.compile(r"\s+|&&|\|\||==|!=|<=|>=|[()?:%*/+\-<>!]|n|\d+")
LOCALE_RE = re.compile(r"^[a-z]{2,3}(?:_[A-Z][a-z]{3}|_[A-Z]{2}|_[0-9]{3}){0,2}$")


class TagCollector(HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=False)
        self.tags: Counter[str] = Counter()

    def handle_starttag(self, tag: str, attrs) -> None:
        self.tags["+" + tag.lower()] += 1

    def handle_startendtag(self, tag: str, attrs) -> None:
        self.tags["/" + tag.lower()] += 1

    def handle_endtag(self, tag: str) -> None:
        self.tags["-" + tag.lower()] += 1


def printf_tokens(value: str) -> Counter[str]:
    tokens = Counter()
    for match in PRINTF_RE.finditer(value):
        token = match.group(0)
        if token == "%%":
            continue
        # Width/precision may be localized/reordered only through numbered args;
        # compare exact tokens so a translator cannot silently change types.
        tokens[token] += 1
    return tokens


def html_tags(value: str) -> Counter[str]:
    parser = TagCollector()
    try:
        parser.feed(value)
        parser.close()
    except Exception as exc:
        raise ValueError(f"invalid HTML fragment: {exc}") from exc
    return parser.tags


def validate_translation(source: str, translated: str, label: str, errors: List[str]) -> None:
    if any(ch in translated for ch in BIDI_FORBIDDEN):
        errors.append(f"{label}: hidden bidi embedding/override/isolate controls are not permitted")
    controls = [ord(ch) for ch in translated if ord(ch) < 32 and ch not in "\n\t"]
    if controls:
        errors.append(f"{label}: control characters are not permitted: {controls}")
    if printf_tokens(source) != printf_tokens(translated):
        errors.append(f"{label}: printf placeholder mismatch {dict(printf_tokens(source))} != {dict(printf_tokens(translated))}")
    if html_tags(source) != html_tags(translated):
        errors.append(f"{label}: HTML tag structure mismatch {dict(html_tags(source))} != {dict(html_tags(translated))}")


def validate_plural_forms_header(value: str, label: str, errors: List[str]) -> int:
    """Validate a bounded gettext/Jed plural expression without evaluating it.

    WordPress JavaScript catalogs carry this header into Jed-compatible runtime
    translation logic. Accept only the conventional arithmetic/comparison/
    boolean/ternary grammar over ``n`` so a translator-controlled header cannot
    smuggle identifiers, calls, property access, strings or statement syntax.
    """
    match = PLURAL_FORMS_RE.match(value or "")
    if not match:
        errors.append(f"{label}: missing or invalid Plural-Forms header")
        return 2
    nplurals = int(match.group(1))
    if nplurals < 1 or nplurals > 6:
        errors.append(f"{label}: nplurals must be between 1 and 6")
        nplurals = 2
    expression = match.group(2)
    position = 0
    paren_depth = 0
    questions = 0
    colons = 0
    saw_n = False
    for token in PLURAL_TOKEN_RE.finditer(expression):
        if token.start() != position:
            errors.append(f"{label}: Plural-Forms expression contains unsupported syntax")
            return nplurals
        value_token = token.group(0)
        position = token.end()
        if value_token.isspace():
            continue
        if value_token == "n":
            saw_n = True
        elif value_token == "(":
            paren_depth += 1
        elif value_token == ")":
            paren_depth -= 1
            if paren_depth < 0:
                errors.append(f"{label}: Plural-Forms parentheses are unbalanced")
                return nplurals
        elif value_token == "?":
            questions += 1
        elif value_token == ":":
            colons += 1
    if position != len(expression):
        errors.append(f"{label}: Plural-Forms expression contains unsupported syntax")
    if paren_depth != 0:
        errors.append(f"{label}: Plural-Forms parentheses are unbalanced")
    if questions != colons:
        errors.append(f"{label}: Plural-Forms ternary operators are unbalanced")
    if not saw_n:
        errors.append(f"{label}: Plural-Forms expression must depend on n")
    return nplurals


def nplurals_from(headers: Dict[str, str], label: str, errors: List[str]) -> int:
    return validate_plural_forms_header(headers.get("Plural-Forms", ""), label, errors)


def translated_complete(entry: PoEntry, nplurals: int) -> bool:
    if entry.msgid_plural is None:
        return bool(entry.msgstr.strip())
    return all(entry.msgstr_plural.get(i, "").strip() for i in range(nplurals))


def validate_manifest_shape(manifest: dict, pot_sha: str, errors: List[str]) -> None:
    if manifest.get("schema_version") != SCHEMA:
        errors.append(f"manifest: schema_version must be {SCHEMA}")
    if manifest.get("textdomain") != DOMAIN:
        errors.append(f"manifest: textdomain must be {DOMAIN}")
    if manifest.get("source_pot_sha256") != pot_sha:
        errors.append("manifest: source_pot_sha256 does not match languages/certloom.pot")
    targets = manifest.get("targets")
    if not isinstance(targets, list) or not targets:
        errors.append("manifest: targets must be a non-empty list")
        return
    seen = set()
    for idx, target in enumerate(targets):
        label = f"manifest target {idx + 1}"
        if not isinstance(target, dict):
            errors.append(f"{label}: must be an object")
            continue
        locale = target.get("locale")
        if not isinstance(locale, str) or not LOCALE_RE.match(locale):
            errors.append(f"{label}: invalid normalized locale {locale!r}")
        if locale in seen:
            errors.append(f"{label}: duplicate locale {locale}")
        seen.add(locale)
        if target.get("direction") not in ALLOWED_DIRECTIONS:
            errors.append(f"{label}: direction must be ltr or rtl")
        if target.get("status") not in ALLOWED_STATUSES:
            errors.append(f"{label}: invalid status")
        if not isinstance(target.get("language_name"), str) or not target.get("language_name", "").strip():
            errors.append(f"{label}: language_name is required")
        if target.get("status") == "planned" and (target.get("po") or target.get("mo")):
            errors.append(f"{label}: planned packs must not claim PO/MO artifacts")


def safe_path(root: Path, rel: str, label: str, errors: List[str]) -> Optional[Path]:
    if not isinstance(rel, str) or not rel or Path(rel).is_absolute():
        errors.append(f"{label}: artifact path must be a non-empty relative path")
        return None
    path = (root / rel).resolve()
    try:
        path.relative_to(root)
    except ValueError:
        errors.append(f"{label}: artifact path escapes the plugin root")
        return None
    return path


def validate_pack(root: Path, target: dict, pot_entries: List[PoEntry], pot_sha: str, compile_mo: bool, errors: List[str]) -> Optional[dict]:
    locale = target["locale"]
    status = target["status"]
    if status == "planned":
        return None
    po_rel = target.get("po")
    mo_rel = target.get("mo")
    if not po_rel or not mo_rel:
        errors.append(f"{locale}: candidate/reviewed packs require po and mo paths")
        return None
    po_path = safe_path(root, po_rel, f"{locale} PO", errors)
    mo_path = safe_path(root, mo_rel, f"{locale} MO", errors)
    if po_path is None or mo_path is None:
        return None
    if not po_path.is_file():
        errors.append(f"{locale}: missing PO file {po_rel}")
        return None
    try:
        entries = parse_po(po_path)
    except (PoSyntaxError, UnicodeError) as exc:
        errors.append(f"{locale}: {exc}")
        return None
    headers = parse_headers(entries[0])
    required_headers = {
        "Language": locale,
        "X-Domain": DOMAIN,
        "X-Certloom-Pack-Schema": str(SCHEMA),
        "X-Certloom-POT-SHA256": pot_sha,
        "X-Certloom-Review-Status": status,
        "X-Certloom-Direction": target["direction"],
    }
    for key, expected in required_headers.items():
        if headers.get(key) != expected:
            errors.append(f"{locale}: header {key} must be {expected!r}, got {headers.get(key)!r}")
    nplurals = nplurals_from(headers, locale, errors)

    pot_map = {entry.original_key(): entry for entry in pot_entries[1:]}
    po_map = {entry.original_key(): entry for entry in entries[1:]}
    stale = sorted(set(po_map) - set(pot_map))
    if stale:
        errors.append(f"{locale}: {len(stale)} stale/non-source translation key(s)")

    translated = 0
    fuzzy = 0
    for key, entry in po_map.items():
        source_entry = pot_map.get(key)
        if source_entry is None:
            continue
        if entry.fuzzy:
            fuzzy += 1
        if entry.msgid != source_entry.msgid or entry.msgctxt != source_entry.msgctxt or entry.msgid_plural != source_entry.msgid_plural:
            errors.append(f"{locale}:{entry.lineno}: source identity differs from POT")
            continue
        if entry.msgid_plural is None:
            if entry.msgstr:
                validate_translation(entry.msgid, entry.msgstr, f"{locale}:{entry.lineno}", errors)
        else:
            for i in range(nplurals):
                value = entry.msgstr_plural.get(i, "")
                if value:
                    source = entry.msgid if i == 0 else entry.msgid_plural
                    validate_translation(source or entry.msgid, value, f"{locale}:{entry.lineno}:plural[{i}]", errors)
            extra = sorted(i for i in entry.msgstr_plural if i >= nplurals)
            if extra:
                errors.append(f"{locale}:{entry.lineno}: plural translations exceed declared nplurals: {extra}")
        if translated_complete(entry, nplurals):
            translated += 1

    total = len(pot_map)
    coverage = 100.0 * translated / total if total else 100.0
    if status == "reviewed":
        if set(po_map) != set(pot_map):
            missing = len(set(pot_map) - set(po_map))
            errors.append(f"{locale}: reviewed pack must contain every POT key ({missing} missing)")
        if translated != total:
            errors.append(f"{locale}: reviewed pack must be 100% translated ({translated}/{total})")
        if fuzzy:
            errors.append(f"{locale}: reviewed pack must contain zero fuzzy entries ({fuzzy} found)")
        if not target.get("reviewed_by") or not target.get("reviewed_at"):
            errors.append(f"{locale}: reviewed pack requires reviewed_by and reviewed_at metadata")
    expected_coverage = target.get("coverage_percent")
    if expected_coverage is not None and abs(float(expected_coverage) - coverage) > 0.01:
        errors.append(f"{locale}: manifest coverage_percent {expected_coverage} does not match computed {coverage:.2f}")

    # Compile only complete translated messages plus the gettext header. Empty
    # candidate entries are omitted so WordPress/gettext falls back to source
    # English rather than rendering blank strings.
    scratch_dir = root / "tests" / "i18n" / ".pack-validation"
    temp_mo = scratch_dir / f"{locale}.mo"
    mo_bytes = write_mo(entries, temp_mo, nplurals)
    mo_sha = sha256(mo_bytes).hexdigest()
    if status == "reviewed" and not compile_mo:
        if not mo_path.is_file():
            errors.append(f"{locale}: reviewed pack is missing committed MO artifact")
        elif mo_path.read_bytes() != mo_bytes:
            errors.append(f"{locale}: committed MO is stale/non-deterministic")
    if compile_mo:
        mo_path.parent.mkdir(parents=True, exist_ok=True)
        mo_path.write_bytes(mo_bytes)
    if target.get("mo_sha256") and target["mo_sha256"] != mo_sha:
        errors.append(f"{locale}: manifest mo_sha256 does not match deterministic MO")

    # Native block-editor strings require the WordPress Jed JSON sidecars in
    # addition to the PHP gettext MO. Generate filenames using the standard md5
    # of the canonical source path used by wp_set_script_translations().
    plural_forms = headers.get("Plural-Forms", "")
    revision = target.get("reviewed_at") or "1970-01-01"
    revision_date = f"{revision} 00:00+0000"
    temp_json_dir = scratch_dir / f"{locale}-json"
    json_catalogs = write_wp_json_catalogs(entries, pot_entries, temp_json_dir, locale, plural_forms, revision_date, DOMAIN)
    json_hashes = {}
    json_dir_rel = target.get("json_dir", "languages")
    json_dir = safe_path(root, json_dir_rel, f"{locale} JSON directory", errors)
    if json_dir is not None:
        for source, (temp_path, data) in json_catalogs.items():
            filename = temp_path.name
            json_hashes[source] = sha256(data).hexdigest()
            committed = json_dir / filename
            if status == "reviewed" and not compile_mo:
                if not committed.is_file():
                    errors.append(f"{locale}: reviewed pack is missing WordPress JS catalog {filename}")
                elif committed.read_bytes() != data:
                    errors.append(f"{locale}: WordPress JS catalog {filename} is stale/non-deterministic")
            if compile_mo:
                json_dir.mkdir(parents=True, exist_ok=True)
                committed.write_bytes(data)
    expected_json = target.get("json_sha256")
    if expected_json is not None:
        if not isinstance(expected_json, dict) or expected_json != json_hashes:
            errors.append(f"{locale}: manifest json_sha256 does not match deterministic WordPress JS catalogs")
    return {"locale": locale, "status": status, "translated": translated, "total": total, "coverage": coverage, "fuzzy": fuzzy, "mo_sha256": mo_sha, "json_sha256": json_hashes}


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("--manifest", default="languages/certloom-language-packs.json")
    ap.add_argument("--compile", action="store_true", help="write deterministic MO artifacts for non-planned packs")
    ap.add_argument("--json", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    pot_path = root / "languages" / "certloom.pot"
    manifest_path = root / args.manifest
    errors: List[str] = []
    try:
        pot_entries = parse_po(pot_path)
    except Exception as exc:
        print(f"POT validation failed: {exc}", file=sys.stderr)
        return 2
    pot_sha = sha256(pot_path.read_bytes()).hexdigest()
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"Manifest validation failed: {exc}", file=sys.stderr)
        return 2
    validate_manifest_shape(manifest, pot_sha, errors)

    reports = []
    for target in manifest.get("targets", []):
        if not isinstance(target, dict) or target.get("status") not in ALLOWED_STATUSES:
            continue
        report = validate_pack(root, target, pot_entries, pot_sha, args.compile, errors)
        if report:
            reports.append(report)

    # Never leave validation scratch material in source/release artifacts.
    scratch = root / "tests" / "i18n" / ".pack-validation"
    if scratch.exists():
        import shutil
        shutil.rmtree(scratch)

    result = {
        "schema_version": SCHEMA,
        "source_pot_sha256": pot_sha,
        "source_strings": max(0, len(pot_entries) - 1),
        "targets": len(manifest.get("targets", [])),
        "packs": reports,
        "errors": errors,
    }
    if args.json:
        print(json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2))
    else:
        print(f"Certloom language-pack contract: {result['source_strings']} source strings, {result['targets']} target locales")
        for report in reports:
            print(f"  {report['locale']}: {report['status']} {report['translated']}/{report['total']} ({report['coverage']:.2f}%) fuzzy={report['fuzzy']} mo={report['mo_sha256'][:12]}…")
        if errors:
            print(f"\n{len(errors)} error(s):", file=sys.stderr)
            for error in errors:
                print("  " + error, file=sys.stderr)
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
