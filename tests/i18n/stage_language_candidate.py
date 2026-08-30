#!/usr/bin/env python3
"""Stage a returned human PO as a Certloom I18N-03 candidate intake artifact.

This tool deliberately does not modify the plugin tree or promote a locale to
``reviewed``. It binds the return to an exact Certloom review kit, validates all
translated content against the current canonical POT, normalizes a deterministic
candidate PO, and emits an auditable external intake bundle.
"""
from __future__ import annotations

import argparse
from datetime import date, datetime, timezone
from hashlib import sha256
import json
import os
from pathlib import Path
import re
import shutil
import stat
import sys
import zipfile
from typing import Dict, Iterable, List, Tuple

from po_tools import PoEntry, PoSyntaxError, parse_headers, parse_po
from validate_language_packs import (
    ALLOWED_STATUSES,
    DOMAIN,
    SCHEMA,
    nplurals_from,
    translated_complete,
    validate_manifest_shape,
    validate_translation,
)

KIT_FILES = {
    "REVIEW-CHECKLIST.md",
    "SHA256SUMS.txt",
    "certloom.pot",
    "review-manifest.json",
    "strings.tsv",
}
MAX_KIT_TOTAL = 8 * 1024 * 1024
MAX_KIT_FILE = 4 * 1024 * 1024
MAX_RETURNED_PO = 8 * 1024 * 1024
ACTOR_RE = re.compile(r"^[^\x00-\x1f\x7f]{1,200}$")
CHECKSUM_RE = re.compile(r"^([0-9a-f]{64})  ([^/\\]+)$")


def die(message: str) -> "NoReturn":
    raise ValueError(message)


def sha(data: bytes) -> str:
    return sha256(data).hexdigest()


def safe_actor(value: str) -> str:
    value = value.strip()
    if not ACTOR_RE.match(value):
        die("submitted_by must be 1-200 printable characters without control characters")
    return value


def safe_date(value: str) -> str:
    try:
        parsed = date.fromisoformat(value)
    except ValueError as exc:
        raise ValueError("submitted_at must be an ISO YYYY-MM-DD date") from exc
    if parsed.isoformat() != value:
        die("submitted_at must be a normalized ISO YYYY-MM-DD date")
    return value


def fixed_zip_datetime() -> Tuple[int, int, int, int, int, int]:
    raw = os.environ.get("SOURCE_DATE_EPOCH")
    if raw is None or not raw.isdigit():
        die("SOURCE_DATE_EPOCH is required for deterministic candidate intake ZIPs")
    stamp = int(raw)
    dt = datetime.fromtimestamp(stamp, tz=timezone.utc)
    year = max(1980, min(2107, dt.year))
    return (year, dt.month, dt.day, dt.hour, dt.minute, dt.second - (dt.second % 2))


def ensure_external_output(root: Path, output: Path, zip_path: Path) -> None:
    root = root.resolve()
    if output.is_symlink():
        die("candidate intake output directory may not be a symbolic link")
    if zip_path.is_symlink():
        die("candidate intake ZIP target may not be a symbolic link")
    output_resolved = output.resolve()
    try:
        output_resolved.relative_to(root)
    except ValueError:
        pass
    else:
        die("candidate intake output must be outside the Certloom plugin tree")
    if output.exists() and any(output.iterdir()):
        die("candidate intake output directory must be absent or empty")
    if zip_path.exists():
        die("candidate intake ZIP already exists; refusing to overwrite operator evidence")


def load_json(path: Path, label: str) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise ValueError(f"{label} is invalid JSON: {exc}") from exc
    if not isinstance(value, dict):
        die(f"{label} must be a JSON object")
    return value


def parse_checksum_file(data: bytes) -> Dict[str, str]:
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ValueError("review kit SHA256SUMS.txt must be UTF-8") from exc
    result: Dict[str, str] = {}
    for raw in text.splitlines():
        if not raw:
            continue
        match = CHECKSUM_RE.match(raw)
        if not match:
            die("review kit SHA256SUMS.txt contains malformed or nested paths")
        digest, name = match.groups()
        if name in result:
            die("review kit SHA256SUMS.txt contains duplicate file names")
        result[name] = digest
    expected = KIT_FILES - {"SHA256SUMS.txt"}
    if set(result) != expected:
        die("review kit SHA256SUMS.txt does not cover the exact review-kit payload")
    return result


def read_review_kit(path: Path) -> Dict[str, bytes]:
    if path.is_symlink():
        die("review kit ZIP may not be a symbolic link")
    if not path.is_file():
        die("review kit ZIP does not exist")
    payload: Dict[str, bytes] = {}
    with zipfile.ZipFile(path, "r") as archive:
        infos = archive.infolist()
        if len(infos) != len(KIT_FILES):
            die("review kit ZIP must contain exactly the five canonical review files")
        total = 0
        names: List[str] = []
        for info in infos:
            name = info.filename
            names.append(name)
            if name not in KIT_FILES or Path(name).name != name:
                die("review kit ZIP contains an unexpected or nested path")
            mode = (info.external_attr >> 16) & 0o170000
            if mode and mode != stat.S_IFREG:
                die("review kit ZIP contains a non-regular file")
            if info.flag_bits & 0x1:
                die("encrypted review kit ZIP entries are not accepted")
            if info.file_size > MAX_KIT_FILE:
                die("review kit ZIP entry exceeds the bounded intake size")
            total += info.file_size
            if total > MAX_KIT_TOTAL:
                die("review kit ZIP exceeds the bounded intake size")
            payload[name] = archive.read(info)
        if len(names) != len(set(names)) or set(names) != KIT_FILES:
            die("review kit ZIP contains duplicate or missing canonical files")
    checksums = parse_checksum_file(payload["SHA256SUMS.txt"])
    for name, expected in checksums.items():
        if sha(payload[name]) != expected:
            die(f"review kit checksum mismatch for {name}")
    return payload


def source_key_set_sha(entries: Iterable[PoEntry]) -> str:
    keys = sorted(entry.original_key().encode("utf-8") for entry in entries if entry.msgid != "")
    return sha(b"\0".join(keys))


def po_quote(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def header_lines(headers: List[Tuple[str, str]]) -> List[str]:
    lines = ['msgid ""', 'msgstr ""']
    for key, value in headers:
        lines.append(po_quote(f"{key}: {value}\n"))
    return lines


def serialize_candidate_po(
    pot_entries: List[PoEntry],
    submitted: Dict[str, PoEntry],
    locale: str,
    direction: str,
    pot_sha: str,
    plural_forms: str,
    submitted_at: str,
    nplurals: int,
) -> bytes:
    out: List[str] = []
    out.extend(header_lines([
        ("Project-Id-Version", "Certloom"),
        ("Language", locale),
        ("MIME-Version", "1.0"),
        ("Content-Type", "text/plain; charset=UTF-8"),
        ("Content-Transfer-Encoding", "8bit"),
        ("PO-Revision-Date", f"{submitted_at} 00:00+0000"),
        ("Plural-Forms", plural_forms),
        ("X-Domain", DOMAIN),
        ("X-Certloom-Pack-Schema", str(SCHEMA)),
        ("X-Certloom-POT-SHA256", pot_sha),
        ("X-Certloom-Review-Status", "candidate"),
        ("X-Certloom-Direction", direction),
        ("X-Generator", "Certloom deterministic candidate intake"),
    ]))
    out.append("")
    for source in pot_entries[1:]:
        returned = submitted.get(source.original_key())
        # Canonical source comments/references are retained. Translator-maintained
        # reference metadata is not authority and is intentionally discarded.
        for comment in source.comments:
            out.append(comment)
        if source.references:
            out.append("#: " + " ".join(sorted(set(source.references))))
        flags = set(source.flags)
        if returned is not None and returned.fuzzy:
            flags.add("fuzzy")
        if flags:
            out.append("#, " + ", ".join(sorted(flags)))
        if source.msgctxt is not None:
            out.append("msgctxt " + po_quote(source.msgctxt))
        out.append("msgid " + po_quote(source.msgid))
        if source.msgid_plural is None:
            value = returned.msgstr if returned is not None else ""
            out.append("msgstr " + po_quote(value))
        else:
            out.append("msgid_plural " + po_quote(source.msgid_plural))
            for idx in range(nplurals):
                value = returned.msgstr_plural.get(idx, "") if returned is not None else ""
                out.append(f"msgstr[{idx}] " + po_quote(value))
        out.append("")
    return ("\n".join(out).rstrip() + "\n").encode("utf-8")


def write_deterministic_zip(root: Path, zip_path: Path, when: Tuple[int, int, int, int, int, int]) -> bytes:
    with zipfile.ZipFile(zip_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted((p for p in root.iterdir() if p.is_file()), key=lambda p: p.name):
            info = zipfile.ZipInfo(path.name, date_time=when)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100644 & 0xFFFF) << 16
            archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    return zip_path.read_bytes()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("locale")
    ap.add_argument("returned_po")
    ap.add_argument("--review-kit", required=True)
    ap.add_argument("--output", required=True)
    ap.add_argument("--submitted-by", required=True)
    ap.add_argument("--submitted-at", required=True)
    args = ap.parse_args()

    root = Path(args.root).resolve()
    returned_po = Path(args.returned_po).resolve()
    review_kit = Path(args.review_kit).resolve()
    output = Path(args.output).resolve()
    zip_path = output.parent / f"certloom-{args.locale}-candidate-intake.zip"

    created_output = False
    try:
        actor = safe_actor(args.submitted_by)
        submitted_at = safe_date(args.submitted_at)
        zip_when = fixed_zip_datetime()
        ensure_external_output(root, output, zip_path)
        if returned_po.is_symlink():
            die("returned PO may not be a symbolic link")
        if not returned_po.is_file():
            die("returned PO does not exist")
        if returned_po.stat().st_size > MAX_RETURNED_PO:
            die("returned PO exceeds the bounded intake size")
        returned_bytes = returned_po.read_bytes()
        kit = read_review_kit(review_kit)

        pot_path = root / "languages" / "certloom.pot"
        manifest_path = root / "languages" / "certloom-language-packs.json"
        pot_bytes = pot_path.read_bytes()
        pot_sha = sha(pot_bytes)
        pot_entries = parse_po(pot_path)
        root_manifest = load_json(manifest_path, "language-pack manifest")
        manifest_errors: List[str] = []
        validate_manifest_shape(root_manifest, pot_sha, manifest_errors)
        if manifest_errors:
            die("language-pack manifest is invalid: " + "; ".join(manifest_errors))
        targets = [t for t in root_manifest.get("targets", []) if isinstance(t, dict) and t.get("locale") == args.locale]
        if len(targets) != 1:
            die("locale must be exactly one approved Certloom language target")
        target = targets[0]
        if target.get("status") not in {"planned", "candidate"}:
            die("candidate intake is only valid for planned/candidate locales")

        try:
            kit_manifest = json.loads(kit["review-manifest.json"].decode("utf-8"))
        except Exception as exc:
            raise ValueError(f"review kit manifest is invalid: {exc}") from exc
        if not isinstance(kit_manifest, dict):
            die("review kit manifest must be a JSON object")
        expected_kit = {
            "schema_version": 1,
            "textdomain": DOMAIN,
            "locale": args.locale,
            "direction": target.get("direction"),
            "source_pot_sha256": pot_sha,
            "source_strings": len(pot_entries) - 1,
            "source_key_set_sha256": source_key_set_sha(pot_entries[1:]),
            "review_required": True,
            "translation_content_included": False,
        }
        for key, expected in expected_kit.items():
            if kit_manifest.get(key) != expected:
                die(f"review kit manifest {key} does not match the frozen source/locale")
        if kit["certloom.pot"] != pot_bytes:
            die("review kit POT bytes do not match the current Certloom POT")

        submitted_entries = parse_po(returned_po)
        submitted_headers = parse_headers(submitted_entries[0])
        if submitted_headers.get("Language") != args.locale:
            die("returned PO Language header must exactly match the intake locale")
        content_type = submitted_headers.get("Content-Type", "")
        if "charset=UTF-8" not in content_type and "charset=utf-8" not in content_type.lower():
            die("returned PO must declare UTF-8 Content-Type")
        errors: List[str] = []
        nplurals = nplurals_from(submitted_headers, args.locale, errors)
        if errors:
            die("returned PO plural contract failed: " + "; ".join(errors))
        plural_forms = submitted_headers.get("Plural-Forms", "")

        pot_map = {entry.original_key(): entry for entry in pot_entries[1:]}
        submitted_map = {entry.original_key(): entry for entry in submitted_entries[1:]}
        stale = sorted(set(submitted_map) - set(pot_map))
        if stale:
            die(f"returned PO contains {len(stale)} stale/non-source translation key(s)")

        translated_entries = 0
        translated_values = 0
        fuzzy_entries = 0
        validation_errors: List[str] = []
        for key, entry in submitted_map.items():
            source = pot_map[key]
            if entry.msgid != source.msgid or entry.msgctxt != source.msgctxt or entry.msgid_plural != source.msgid_plural:
                validation_errors.append(f"{args.locale}:{entry.lineno}: source identity differs from current POT")
                continue
            if entry.fuzzy:
                fuzzy_entries += 1
            if entry.msgid_plural is None:
                if entry.msgstr:
                    translated_values += 1
                    validate_translation(source.msgid, entry.msgstr, f"{args.locale}:{entry.lineno}", validation_errors)
            else:
                for idx, value in sorted(entry.msgstr_plural.items()):
                    if idx >= nplurals:
                        validation_errors.append(f"{args.locale}:{entry.lineno}: plural translation index {idx} exceeds nplurals={nplurals}")
                        continue
                    if value:
                        translated_values += 1
                        source_value = source.msgid if idx == 0 else (source.msgid_plural or source.msgid)
                        validate_translation(source_value, value, f"{args.locale}:{entry.lineno}:plural[{idx}]", validation_errors)
            if translated_complete(entry, nplurals):
                translated_entries += 1
        if validation_errors:
            die("returned PO failed translation safety checks: " + "; ".join(validation_errors))
        if translated_values == 0:
            die("returned PO contains no translated values; there is nothing to stage")

        candidate_bytes = serialize_candidate_po(
            pot_entries, submitted_map, args.locale, target["direction"], pot_sha,
            plural_forms, submitted_at, nplurals,
        )
        # Parse the normalized output again so the intake tool certifies its own
        # emitted PO grammar and exact source-key coverage.
        if not output.exists():
            output.mkdir(parents=True, exist_ok=False)
            created_output = True
        submitted_out = output / "submitted.po"
        candidate_out = output / "candidate.po"
        submitted_out.write_bytes(returned_bytes)
        candidate_out.write_bytes(candidate_bytes)
        normalized_entries = parse_po(candidate_out)
        if len(normalized_entries) != len(pot_entries):
            die("normalized candidate PO does not contain the complete current source-key set")

        total = len(pot_map)
        coverage = 100.0 * translated_entries / total if total else 100.0
        report = {
            "schema_version": 1,
            "status": "candidate",
            "locale": args.locale,
            "language_name": target.get("language_name"),
            "direction": target.get("direction"),
            "quality_tier": target.get("quality_tier"),
            "source_pot_sha256": pot_sha,
            "source_key_set_sha256": source_key_set_sha(pot_entries[1:]),
            "source_strings": total,
            "submitted_po_sha256": sha(returned_bytes),
            "candidate_po_sha256": sha(candidate_bytes),
            "translated_entries_complete": translated_entries,
            "translated_values_nonempty": translated_values,
            "coverage_percent": round(coverage, 2),
            "fuzzy_entries": fuzzy_entries,
            "submitted_by": actor,
            "submitted_at": submitted_at,
            "human_review_complete": False,
            "reviewed_status_granted": False,
            "notes": "Candidate intake validates technical safety only; reviewed status still requires complete human linguistic review and the frozen I18N-03 acceptance contract.",
        }
        manifest_entry = {
            "locale": args.locale,
            "language_name": target.get("language_name"),
            "direction": target.get("direction"),
            "status": "candidate",
            "quality_tier": target.get("quality_tier"),
            "po": f"languages/certloom-{args.locale}.po",
            "mo": f"languages/certloom-{args.locale}.mo",
            "json_dir": "languages",
            "coverage_percent": round(coverage, 2),
        }
        (output / "candidate-report.json").write_text(json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
        (output / "candidate-manifest-entry.json").write_text(json.dumps(manifest_entry, ensure_ascii=False, sort_keys=True, indent=2) + "\n", encoding="utf-8")
        (output / "INTAKE-CHECKLIST.md").write_text(
            "# Certloom language candidate intake\n\n"
            "This bundle is **candidate evidence, not reviewed language support**.\n\n"
            "- Confirm translator/submission provenance independently.\n"
            "- Review every remaining untranslated/fuzzy entry.\n"
            "- Run native admin/frontend/Designer/verification language and RTL/LTR browser review.\n"
            "- Do not add `reviewed_by` / `reviewed_at` until genuine linguistic review is complete.\n"
            "- Promotion to reviewed must rerun the frozen I18N-03 placeholder/plural/HTML/bidi/MO/Jed acceptance pipeline.\n",
            encoding="utf-8",
        )
        checksum_paths = [
            output / "INTAKE-CHECKLIST.md",
            output / "candidate-manifest-entry.json",
            output / "candidate-report.json",
            candidate_out,
            submitted_out,
        ]
        sums = "".join(f"{sha(path.read_bytes())}  {path.name}\n" for path in sorted(checksum_paths, key=lambda p: p.name))
        (output / "SHA256SUMS.txt").write_text(sums, encoding="utf-8")
        zip_bytes = write_deterministic_zip(output, zip_path, zip_when)
        result = dict(report)
        result.update({"output": str(output), "zip": str(zip_path), "zip_sha256": sha(zip_bytes)})
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0
    except (ValueError, PoSyntaxError, UnicodeError, zipfile.BadZipFile) as exc:
        # Fail closed and remove only a directory that this invocation created.
        if created_output and output.exists():
            try:
                output.relative_to(root)
            except ValueError:
                shutil.rmtree(output, ignore_errors=True)
        print(f"Candidate intake failed: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
