#!/usr/bin/env python3
"""Create a deterministic external reviewed-pack acceptance bundle.

This tool is the final *engineering* boundary around I18N-03 human review. It
cannot determine whether a translation is linguistically good and it never
modifies the Certloom source tree. Instead it binds an exact 1.6.x candidate
intake ZIP to explicit human review evidence, requires technically complete and
non-fuzzy translations, reruns the frozen placeholder/HTML/bidi/plural checks,
and emits deterministic runtime artifacts plus a reviewed manifest proposal.

A bundle produced here is still external review evidence until its exact PO,
MO, WordPress/Jed JSON files and manifest entry are deliberately integrated into
Certloom and the normal release validator/browser/release ceremony passes.
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
import tempfile
import zipfile
from typing import Dict, Iterable, List, NoReturn, Tuple

from po_tools import PoEntry, PoSyntaxError, parse_headers, parse_po, write_mo, write_wp_json_catalogs
from validate_language_packs import (
    DOMAIN,
    SCHEMA,
    nplurals_from,
    translated_complete,
    validate_manifest_shape,
    validate_translation,
)

CANDIDATE_FILES = {
    "INTAKE-CHECKLIST.md",
    "SHA256SUMS.txt",
    "candidate-manifest-entry.json",
    "candidate-report.json",
    "candidate.po",
    "submitted.po",
}
CHECKSUM_RE = re.compile(r"^([0-9a-f]{64})  ([^/\\]+)$")
ACTOR_RE = re.compile(r"^[^\x00-\x1f\x7f]{1,200}$")
MAX_CANDIDATE_TOTAL = 16 * 1024 * 1024
MAX_CANDIDATE_FILE = 8 * 1024 * 1024
MAX_EVIDENCE = 128 * 1024
REQUIRED_BOOLEAN_EVIDENCE = {
    "linguistic_review_complete",
    "meaning_and_claims_review_complete",
    "browser_review_complete",
    "no_bulk_machine_translation_claimed",
}


def die(message: str) -> NoReturn:
    raise ValueError(message)


def sha(data: bytes) -> str:
    return sha256(data).hexdigest()


def json_no_duplicates(data: bytes, label: str) -> dict:
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ValueError(f"{label} must be UTF-8") from exc
    duplicate: List[str] = []

    def hook(pairs):
        out = {}
        for key, value in pairs:
            if key in out:
                duplicate.append(str(key))
            out[key] = value
        return out

    try:
        value = json.loads(text, object_pairs_hook=hook)
    except Exception as exc:
        raise ValueError(f"{label} is invalid JSON: {exc}") from exc
    if duplicate:
        die(f"{label} contains duplicate key(s): {', '.join(sorted(set(duplicate)))}")
    if not isinstance(value, dict):
        die(f"{label} must be a JSON object")
    return value


def safe_actor(value: str, label: str = "reviewed_by") -> str:
    value = value.strip()
    if not ACTOR_RE.match(value):
        die(f"{label} must be 1-200 printable characters without control characters")
    return value


def safe_date(value: str, label: str = "reviewed_at") -> str:
    try:
        parsed = date.fromisoformat(value)
    except ValueError as exc:
        raise ValueError(f"{label} must be an ISO YYYY-MM-DD date") from exc
    if parsed.isoformat() != value:
        die(f"{label} must be a normalized ISO YYYY-MM-DD date")
    return value


def fixed_zip_datetime() -> Tuple[int, int, int, int, int, int]:
    raw = os.environ.get("SOURCE_DATE_EPOCH")
    if raw is None or not raw.isdigit():
        die("SOURCE_DATE_EPOCH is required for deterministic review-acceptance ZIPs")
    dt = datetime.fromtimestamp(int(raw), tz=timezone.utc)
    year = max(1980, min(2107, dt.year))
    return (year, dt.month, dt.day, dt.hour, dt.minute, dt.second - (dt.second % 2))


def ensure_external_output(root: Path, output: Path, zip_path: Path) -> None:
    root = root.resolve()
    if output.is_symlink() or zip_path.is_symlink():
        die("review acceptance output/ZIP may not be a symbolic link")
    resolved = output.resolve()
    try:
        resolved.relative_to(root)
    except ValueError:
        pass
    else:
        die("review acceptance output must be outside the Certloom plugin tree")
    if output.exists():
        die("review acceptance output directory must be absent")
    if zip_path.exists():
        die("review acceptance ZIP already exists; refusing to overwrite review evidence")


def parse_checksum_file(data: bytes) -> Dict[str, str]:
    try:
        text = data.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise ValueError("candidate SHA256SUMS.txt must be UTF-8") from exc
    result: Dict[str, str] = {}
    for raw in text.splitlines():
        if not raw:
            continue
        match = CHECKSUM_RE.match(raw)
        if not match:
            die("candidate SHA256SUMS.txt contains malformed or nested paths")
        digest, name = match.groups()
        if name in result:
            die("candidate SHA256SUMS.txt contains duplicate file names")
        result[name] = digest
    expected = CANDIDATE_FILES - {"SHA256SUMS.txt"}
    if set(result) != expected:
        die("candidate SHA256SUMS.txt does not cover the exact intake payload")
    return result


def read_candidate_zip(path: Path) -> Dict[str, bytes]:
    if path.is_symlink():
        die("candidate intake ZIP may not be a symbolic link")
    if not path.is_file():
        die("candidate intake ZIP does not exist")
    payload: Dict[str, bytes] = {}
    with zipfile.ZipFile(path, "r") as archive:
        infos = archive.infolist()
        if len(infos) != len(CANDIDATE_FILES):
            die("candidate intake ZIP must contain exactly the six canonical files")
        total = 0
        names: List[str] = []
        for info in infos:
            name = info.filename
            names.append(name)
            if name not in CANDIDATE_FILES or Path(name).name != name:
                die("candidate intake ZIP contains an unexpected or nested path")
            mode = (info.external_attr >> 16) & 0o170000
            if mode and mode != stat.S_IFREG:
                die("candidate intake ZIP contains a non-regular file")
            if info.flag_bits & 0x1:
                die("encrypted candidate intake ZIP entries are not accepted")
            if info.file_size > MAX_CANDIDATE_FILE:
                die("candidate intake ZIP entry exceeds the bounded acceptance size")
            total += info.file_size
            if total > MAX_CANDIDATE_TOTAL:
                die("candidate intake ZIP exceeds the bounded acceptance size")
            payload[name] = archive.read(info)
        if len(names) != len(set(names)) or set(names) != CANDIDATE_FILES:
            die("candidate intake ZIP contains duplicate or missing canonical files")
    sums = parse_checksum_file(payload["SHA256SUMS.txt"])
    for name, expected in sums.items():
        if sha(payload[name]) != expected:
            die(f"candidate intake checksum mismatch for {name}")
    return payload


def source_key_set_sha(entries: Iterable[PoEntry]) -> str:
    keys = sorted(entry.original_key().encode("utf-8") for entry in entries if entry.msgid != "")
    return sha(b"\0".join(keys))


def po_quote(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def serialize_reviewed_po(
    pot_entries: List[PoEntry],
    candidate_map: Dict[str, PoEntry],
    locale: str,
    direction: str,
    pot_sha: str,
    plural_forms: str,
    reviewed_at: str,
    nplurals: int,
) -> bytes:
    headers = [
        ("Project-Id-Version", "Certloom"),
        ("Language", locale),
        ("MIME-Version", "1.0"),
        ("Content-Type", "text/plain; charset=UTF-8"),
        ("Content-Transfer-Encoding", "8bit"),
        ("PO-Revision-Date", f"{reviewed_at} 00:00+0000"),
        ("Plural-Forms", plural_forms),
        ("X-Domain", DOMAIN),
        ("X-Certloom-Pack-Schema", str(SCHEMA)),
        ("X-Certloom-POT-SHA256", pot_sha),
        ("X-Certloom-Review-Status", "reviewed"),
        ("X-Certloom-Direction", direction),
        ("X-Generator", "Certloom deterministic reviewed-pack acceptance"),
    ]
    out = ['msgid ""', 'msgstr ""']
    out.extend(po_quote(f"{k}: {v}\n") for k, v in headers)
    out.append("")
    for source in pot_entries[1:]:
        returned = candidate_map[source.original_key()]
        for comment in source.comments:
            out.append(comment)
        if source.references:
            out.append("#: " + " ".join(sorted(set(source.references))))
        # Reviewed catalogs cannot carry fuzzy state. Preserve other canonical
        # source flags only (for example php-format).
        flags = sorted(flag for flag in source.flags if flag != "fuzzy")
        if flags:
            out.append("#, " + ", ".join(flags))
        if source.msgctxt is not None:
            out.append("msgctxt " + po_quote(source.msgctxt))
        out.append("msgid " + po_quote(source.msgid))
        if source.msgid_plural is None:
            out.append("msgstr " + po_quote(returned.msgstr))
        else:
            out.append("msgid_plural " + po_quote(source.msgid_plural))
            for idx in range(nplurals):
                out.append(f"msgstr[{idx}] " + po_quote(returned.msgstr_plural.get(idx, "")))
        out.append("")
    return ("\n".join(out).rstrip() + "\n").encode("utf-8")


def validate_evidence(
    data: bytes,
    locale: str,
    direction: str,
    candidate_intake_zip_sha: str,
    candidate_po_sha: str,
    pot_sha: str,
    source_key_sha: str,
) -> Tuple[dict, str, str]:
    if len(data) > MAX_EVIDENCE:
        die("review evidence exceeds the bounded size")
    evidence = json_no_duplicates(data, "review evidence")
    if evidence.get("schema_version") != 1:
        die("review evidence schema_version must be 1")
    if evidence.get("locale") != locale:
        die("review evidence locale does not match candidate locale")
    exact_bindings = {
        "candidate_intake_zip_sha256": candidate_intake_zip_sha,
        "candidate_po_sha256": candidate_po_sha,
        "source_pot_sha256": pot_sha,
        "source_key_set_sha256": source_key_sha,
    }
    for key, expected in exact_bindings.items():
        if evidence.get(key) != expected:
            die(f"review evidence {key} does not bind the exact candidate/source")
    reviewed_by = safe_actor(str(evidence.get("reviewed_by", "")))
    reviewed_at = safe_date(str(evidence.get("reviewed_at", "")))
    for key in REQUIRED_BOOLEAN_EVIDENCE:
        if evidence.get(key) is not True:
            die(f"review evidence requires {key}=true")
    if direction == "rtl" and evidence.get("rtl_mixed_direction_review_complete") is not True:
        die("RTL review evidence requires rtl_mixed_direction_review_complete=true")
    if direction == "ltr" and "rtl_mixed_direction_review_complete" in evidence and not isinstance(evidence["rtl_mixed_direction_review_complete"], bool):
        die("rtl_mixed_direction_review_complete must be boolean when supplied")
    notes = evidence.get("notes", "")
    if notes is not None:
        safe_actor(str(notes), "notes") if str(notes).strip() else ""
    return evidence, reviewed_by, reviewed_at


def write_zip(root: Path, zip_path: Path, when: Tuple[int, int, int, int, int, int]) -> bytes:
    """Write a complete deterministic ZIP and publish it without overwrite.

    The canonical evidence path is never used as a partial-write target. A
    temporary sibling is completed first, then hard-linked into place so an
    independently-created/pre-existing evidence archive cannot be replaced.
    """
    zip_path.parent.mkdir(parents=True, exist_ok=True)
    fd, raw_tmp = tempfile.mkstemp(prefix=f".{zip_path.name}.", suffix=".tmp", dir=zip_path.parent)
    os.close(fd)
    tmp_path = Path(raw_tmp)
    try:
        with zipfile.ZipFile(tmp_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for path in sorted((p for p in root.rglob("*") if p.is_file()), key=lambda p: p.relative_to(root).as_posix()):
                rel = path.relative_to(root).as_posix()
                info = zipfile.ZipInfo(rel, date_time=when)
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = (0o100644 & 0xFFFF) << 16
                archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        data = tmp_path.read_bytes()
        try:
            os.link(tmp_path, zip_path)
        except FileExistsError as exc:
            raise ValueError("review acceptance ZIP appeared during publication; refusing to overwrite review evidence") from exc
        return data
    finally:
        try:
            tmp_path.unlink()
        except FileNotFoundError:
            pass


def checksum_tree(root: Path) -> str:
    rows = []
    for path in sorted((p for p in root.rglob("*") if p.is_file() and p.name != "SHA256SUMS.txt"), key=lambda p: p.relative_to(root).as_posix()):
        rows.append(f"{sha(path.read_bytes())}  {path.relative_to(root).as_posix()}")
    return "\n".join(rows) + "\n"


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("locale")
    ap.add_argument("--candidate-intake", required=True)
    ap.add_argument("--review-evidence", required=True)
    ap.add_argument("--output", required=True)
    args = ap.parse_args()

    root = Path(args.root).resolve()
    candidate_input = Path(args.candidate_intake)
    evidence_input = Path(args.review_evidence)
    output_input = Path(args.output)
    if candidate_input.is_symlink():
        die("candidate intake ZIP may not be a symbolic link")
    if evidence_input.is_symlink():
        die("review evidence may not be a symbolic link")
    if output_input.is_symlink():
        die("review acceptance output may not be a symbolic link")
    candidate_zip = candidate_input.resolve()
    evidence_path = evidence_input.resolve()
    output = output_input.resolve()
    zip_path = output.parent / f"certloom-{args.locale}-reviewed-acceptance.zip"
    created_output = False

    try:
        when = fixed_zip_datetime()
        ensure_external_output(root, output, zip_path)
        if evidence_path.is_symlink() or not evidence_path.is_file():
            die("review evidence must be a regular file")
        evidence_bytes = evidence_path.read_bytes()

        pot_path = root / "languages" / "certloom.pot"
        manifest_path = root / "languages" / "certloom-language-packs.json"
        pot_bytes = pot_path.read_bytes()
        pot_sha = sha(pot_bytes)
        pot_entries = parse_po(pot_path)
        source_count = len(pot_entries) - 1
        source_key_sha = source_key_set_sha(pot_entries[1:])
        manifest = json_no_duplicates(manifest_path.read_bytes(), "language manifest")
        manifest_errors: List[str] = []
        validate_manifest_shape(manifest, pot_sha, manifest_errors)
        if manifest_errors:
            die("language manifest failed validation: " + "; ".join(manifest_errors))
        target = next((item for item in manifest["targets"] if item.get("locale") == args.locale), None)
        if target is None:
            die("locale is not an approved Certloom language target")
        direction = target["direction"]
        language_name = target["language_name"]
        quality_tier = target.get("quality_tier")

        candidate_zip_bytes = candidate_zip.read_bytes()
        candidate_zip_sha = sha(candidate_zip_bytes)
        payload = read_candidate_zip(candidate_zip)
        candidate_report = json_no_duplicates(payload["candidate-report.json"], "candidate report")
        candidate_manifest = json_no_duplicates(payload["candidate-manifest-entry.json"], "candidate manifest proposal")
        if candidate_report.get("locale") != args.locale or candidate_manifest.get("locale") != args.locale:
            die("candidate locale does not match requested locale")
        if candidate_report.get("status") != "candidate" or candidate_manifest.get("status") != "candidate":
            die("candidate intake must remain candidate status")
        if candidate_report.get("human_review_complete") is not False or candidate_report.get("reviewed_status_granted") is not False:
            die("candidate report has invalid pre-review state")
        if candidate_report.get("source_pot_sha256") != pot_sha:
            die("candidate POT identity does not match current Certloom POT")
        if candidate_report.get("source_key_set_sha256") != source_key_sha:
            die("candidate source-key-set identity does not match current Certloom source")
        if int(candidate_report.get("source_strings", -1)) != source_count:
            die("candidate source-string count does not match current Certloom source")
        if candidate_report.get("candidate_po_sha256") != sha(payload["candidate.po"]):
            die("candidate report does not bind the exact candidate.po bytes")
        if candidate_report.get("submitted_po_sha256") != sha(payload["submitted.po"]):
            die("candidate report does not bind the exact submitted.po bytes")
        submitted_by = safe_actor(str(candidate_report.get("submitted_by", "")), "candidate submitted_by")
        if candidate_manifest.get("direction") != direction or candidate_manifest.get("language_name") != language_name:
            die("candidate manifest locale metadata differs from the approved roadmap target")
        expected_candidate_manifest = {
            "quality_tier": quality_tier,
            "po": f"languages/certloom-{args.locale}.po",
            "mo": f"languages/certloom-{args.locale}.mo",
            "json_dir": "languages",
        }
        for key, expected in expected_candidate_manifest.items():
            if candidate_manifest.get(key) != expected:
                die(f"candidate manifest {key} differs from the canonical integration proposal")

        candidate_po_sha = sha(payload["candidate.po"])
        evidence, reviewed_by, reviewed_at = validate_evidence(
            evidence_bytes,
            args.locale,
            direction,
            candidate_zip_sha,
            candidate_po_sha,
            pot_sha,
            source_key_sha,
        )
        submitted_at = safe_date(str(candidate_report.get("submitted_at", "")), "candidate submitted_at")
        if reviewed_at < submitted_at:
            die("reviewed_at may not precede the candidate submission date")

        with tempfile.TemporaryDirectory(prefix="certloom-reviewed-accept-") as tmp:
            tmp_path = Path(tmp)
            candidate_po_path = tmp_path / "candidate.po"
            candidate_po_path.write_bytes(payload["candidate.po"])
            candidate_entries = parse_po(candidate_po_path)
            headers = parse_headers(candidate_entries[0])
            expected_headers = {
                "Language": args.locale,
                "X-Domain": DOMAIN,
                "X-Certloom-Pack-Schema": str(SCHEMA),
                "X-Certloom-POT-SHA256": pot_sha,
                "X-Certloom-Review-Status": "candidate",
                "X-Certloom-Direction": direction,
            }
            for key, expected in expected_headers.items():
                if headers.get(key) != expected:
                    die(f"candidate header {key} must be {expected!r}")
            validation_errors: List[str] = []
            nplurals = nplurals_from(headers, args.locale, validation_errors)
            pot_map = {entry.original_key(): entry for entry in pot_entries[1:]}
            candidate_map = {entry.original_key(): entry for entry in candidate_entries[1:]}
            if set(candidate_map) != set(pot_map):
                die("reviewed acceptance requires the candidate PO to contain every current POT key")
            translated = 0
            fuzzy = 0
            for key, entry in candidate_map.items():
                source = pot_map[key]
                if entry.fuzzy:
                    fuzzy += 1
                if entry.msgid != source.msgid or entry.msgctxt != source.msgctxt or entry.msgid_plural != source.msgid_plural:
                    validation_errors.append(f"{args.locale}:{entry.lineno}: source identity differs from current POT")
                    continue
                if entry.msgid_plural is None:
                    if entry.msgstr:
                        validate_translation(source.msgid, entry.msgstr, f"{args.locale}:{entry.lineno}", validation_errors)
                else:
                    for idx in range(nplurals):
                        value = entry.msgstr_plural.get(idx, "")
                        if value:
                            source_value = source.msgid if idx == 0 else (source.msgid_plural or source.msgid)
                            validate_translation(source_value, value, f"{args.locale}:{entry.lineno}:plural[{idx}]", validation_errors)
                    extra = sorted(i for i in entry.msgstr_plural if i >= nplurals)
                    if extra:
                        validation_errors.append(f"{args.locale}:{entry.lineno}: plural translations exceed declared nplurals: {extra}")
                if translated_complete(entry, nplurals):
                    translated += 1
            if validation_errors:
                die("candidate translation failed reviewed technical validation: " + "; ".join(validation_errors[:20]))
            if fuzzy:
                die(f"reviewed acceptance requires zero fuzzy entries ({fuzzy} found)")
            if translated != source_count:
                die(f"reviewed acceptance requires 100% translated coverage ({translated}/{source_count})")
            if float(candidate_report.get("coverage_percent", -1)) != 100.0 or int(candidate_report.get("fuzzy_entries", -1)) != 0:
                die("candidate report completeness metadata differs from independently validated candidate bytes")
            if float(candidate_manifest.get("coverage_percent", -1)) != 100.0:
                die("candidate manifest coverage differs from independently validated candidate bytes")

            plural_forms = headers.get("Plural-Forms", "")
            reviewed_po = serialize_reviewed_po(
                pot_entries, candidate_map, args.locale, direction, pot_sha,
                plural_forms, reviewed_at, nplurals,
            )

            output.mkdir(parents=True, exist_ok=False)
            created_output = True
            (output / "integration" / "languages").mkdir(parents=True)
            (output / "provenance").mkdir(parents=True)
            (output / "provenance" / "candidate-intake.zip").write_bytes(candidate_zip_bytes)
            (output / "submitted-review-evidence.json").write_bytes(evidence_bytes)
            reviewed_po_path = output / "integration" / "languages" / f"certloom-{args.locale}.po"
            reviewed_po_path.write_bytes(reviewed_po)
            reviewed_entries = parse_po(reviewed_po_path)
            mo_path = output / "integration" / "languages" / f"certloom-{args.locale}.mo"
            mo_bytes = write_mo(reviewed_entries, mo_path, nplurals)
            json_catalogs = write_wp_json_catalogs(
                reviewed_entries,
                pot_entries,
                output / "integration" / "languages",
                args.locale,
                plural_forms,
                f"{reviewed_at} 00:00+0000",
                DOMAIN,
            )
            json_hashes = {source: sha(data) for source, (_path, data) in json_catalogs.items()}
            manifest_entry = {
                "locale": args.locale,
                "language_name": language_name,
                "direction": direction,
                "status": "reviewed",
                "quality_tier": quality_tier,
                "po": f"languages/certloom-{args.locale}.po",
                "mo": f"languages/certloom-{args.locale}.mo",
                "json_dir": "languages",
                "coverage_percent": 100.0,
                "reviewed_by": reviewed_by,
                "reviewed_at": reviewed_at,
                "po_sha256": sha(reviewed_po),
                "mo_sha256": sha(mo_bytes),
                "json_sha256": json_hashes,
            }
            (output / "reviewed-manifest-entry.json").write_text(json.dumps(manifest_entry, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            report = {
                "schema_version": 1,
                "status": "reviewed-acceptance",
                "locale": args.locale,
                "language_name": language_name,
                "direction": direction,
                "quality_tier": quality_tier,
                "source_pot_sha256": pot_sha,
                "source_key_set_sha256": source_key_sha,
                "source_strings": source_count,
                "coverage_percent": 100.0,
                "fuzzy_entries": 0,
                "candidate_intake_zip_sha256": candidate_zip_sha,
                "candidate_po_sha256": sha(payload["candidate.po"]),
                "submitted_po_sha256": sha(payload["submitted.po"]),
                "submitted_by": submitted_by,
                "submitted_at": submitted_at,
                "review_evidence_sha256": sha(evidence_bytes),
                "reviewed_po_sha256": sha(reviewed_po),
                "mo_sha256": sha(mo_bytes),
                "json_sha256": json_hashes,
                "reviewed_by": reviewed_by,
                "reviewed_at": reviewed_at,
                "review_evidence_attested": True,
                "source_tree_mutated": False,
                "integration_required": True,
                "notes": "External acceptance bundle only. Linguistic quality is attested by the supplied human review evidence; Certloom still requires deliberate source integration plus normal browser/release certification before support is shipped.",
            }
            (output / "acceptance-report.json").write_text(json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n", encoding="utf-8")
            (output / "ACCEPTANCE-CHECKLIST.md").write_text(
                "# Certloom reviewed language acceptance\n\n"
                "This bundle binds technically complete translation bytes to explicit human review evidence.\n\n"
                "- Verify reviewer identity and organizational provenance independently.\n"
                "- Retain the exact nested `provenance/candidate-intake.zip`; the review attestation is bound to its SHA-256.\n"
                "- Integrate only the exact files under `integration/languages/` and the reviewed manifest entry.\n"
                "- Run the frozen language-pack validator without `--compile`; committed MO/Jed artifacts must match exactly.\n"
                "- Run locale-specific admin/frontend/Designer/verification browser QA, including mixed-direction review for RTL locales.\n"
                "- Run the complete Certloom release ceremony before claiming the locale as shipped/reviewed.\n"
                "- This external bundle does not itself mutate Certloom or publish language support.\n",
                encoding="utf-8",
            )
            (output / "SHA256SUMS.txt").write_text(checksum_tree(output), encoding="utf-8")
            zip_bytes = write_zip(output, zip_path, when)

            result = dict(report)
            result["zip"] = str(zip_path)
            result["zip_sha256"] = sha(zip_bytes)
            print(json.dumps(result, ensure_ascii=False, sort_keys=True))
            return 0
    except (ValueError, PoSyntaxError, UnicodeError, OSError, zipfile.BadZipFile) as exc:
        if created_output and output.exists():
            shutil.rmtree(output, ignore_errors=True)
        print(f"Review acceptance failed: {exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
