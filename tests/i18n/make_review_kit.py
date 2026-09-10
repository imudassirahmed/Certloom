#!/usr/bin/env python3
"""Build a deterministic, translation-free review kit for one Certloom locale.

The kit is intentionally a human-review input, not a translated language pack.
It binds the exact Certloom POT, exposes source/context/reference/risk metadata,
and keeps all msgstr content empty by shipping the canonical POT unchanged.
Review kits must be generated outside the plugin tree so they can never leak
into production or be mistaken for bundled locale support.
"""
from __future__ import annotations

import argparse
import csv
from datetime import datetime, timezone
from hashlib import sha256
import io
import json
import os
from pathlib import Path
import shutil
import sys
import zipfile

from po_tools import parse_po
from validate_language_packs import html_tags, printf_tokens, validate_manifest_shape

SCHEMA = 1
GENERATOR = "Certloom deterministic language review kit"
DEFAULT_EPOCH = 1787356800


def fail(message: str) -> int:
    print(f"Review kit error: {message}", file=sys.stderr)
    return 2


def inside(path: Path, root: Path) -> bool:
    try:
        path.relative_to(root)
        return True
    except ValueError:
        return False


def visible(value: str) -> str:
    return value.replace("\\", "\\\\").replace("\t", "\\t").replace("\r", "\\r").replace("\n", "\\n")


def spreadsheet_safe(value: str) -> str:
    """Keep translator-facing TSV inert in spreadsheet applications.

    The canonical source remains certloom.pot. The TSV is only a review index,
    so prefix formula-like cells with an apostrophe instead of allowing a
    gettext source/comment/reference beginning with =,+,-,@ to execute when a
    reviewer opens the file in a spreadsheet program.
    """
    if value and value[0] in "=+-@":
        return "'" + value
    return value


def risk_labels(entry) -> list[str]:
    risks: list[str] = []
    sources = [entry.msgid]
    if entry.msgid_plural is not None:
        sources.append(entry.msgid_plural)
    if any(printf_tokens(source) for source in sources):
        risks.append("printf")
    if any(html_tags(source) for source in sources):
        risks.append("html")
    if entry.msgid_plural is not None:
        risks.append("plural")
    if entry.msgctxt is not None:
        risks.append("context")
    if any(ref.split(":", 1)[0].endswith("/index.js") for ref in entry.references):
        risks.append("block-js")
    if any(ord(ch) > 127 for ch in entry.msgid):
        risks.append("unicode-source")
    return risks


def fixed_zip_time(epoch: int) -> tuple[int, int, int, int, int, int]:
    dt = datetime.fromtimestamp(epoch, timezone.utc)
    if dt.year < 1980:
        dt = datetime(1980, 1, 1, tzinfo=timezone.utc)
    return (dt.year, dt.month, dt.day, dt.hour, dt.minute, dt.second)


def write_zip(directory: Path, target: Path, epoch: int) -> bytes:
    stamp = fixed_zip_time(epoch)
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
        for path in sorted(p for p in directory.iterdir() if p.is_file()):
            info = zipfile.ZipInfo(path.name, stamp)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            info.create_system = 3
            zf.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
    data = buffer.getvalue()
    target.write_bytes(data)
    return data


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("root")
    parser.add_argument("locale")
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    root = Path(args.root).resolve()
    output_raw = Path(args.output)
    if output_raw.exists() and output_raw.is_symlink():
        return fail("output directory may not be a symbolic link")
    output = output_raw.resolve()
    if inside(output, root):
        return fail("review kits must be generated outside the Certloom plugin tree")

    epoch_text = os.environ.get("SOURCE_DATE_EPOCH", str(DEFAULT_EPOCH)).strip()
    try:
        epoch = int(epoch_text)
    except ValueError:
        return fail("SOURCE_DATE_EPOCH must be an integer")

    manifest_path = root / "languages" / "certloom-language-packs.json"
    pot_path = root / "languages" / "certloom.pot"
    try:
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        pot_bytes = pot_path.read_bytes()
        pot_entries = parse_po(pot_path)
    except Exception as exc:
        return fail(str(exc))

    pot_sha = sha256(pot_bytes).hexdigest()
    manifest_errors: list[str] = []
    validate_manifest_shape(manifest, pot_sha, manifest_errors)
    if manifest_errors:
        return fail("language-pack manifest is invalid: " + "; ".join(manifest_errors))

    target = next((item for item in manifest.get("targets", []) if item.get("locale") == args.locale), None)
    if target is None:
        return fail(f"locale {args.locale!r} is not an approved Certloom language-pack target")
    if target.get("status") == "reviewed":
        return fail("review kits are not generated for already-reviewed packs")

    zip_target = output.parent / f"certloom-{target['locale']}-review-kit.zip"
    if zip_target.exists() or zip_target.is_symlink():
        return fail("review-kit ZIP target already exists; choose a clean output parent")

    if output.exists():
        if not output.is_dir():
            return fail("output exists and is not a directory")
        if any(output.iterdir()):
            return fail("output directory must be empty")
    else:
        output.mkdir(parents=True, mode=0o700)

    entries = pot_entries[1:]
    source_keys = sorted(entry.original_key().encode("utf-8") for entry in entries)
    source_key_set_sha256 = sha256(b"\0".join(source_keys)).hexdigest()
    plural_count = sum(e.msgid_plural is not None for e in entries)
    context_count = sum(e.msgctxt is not None for e in entries)
    js_count = sum(any(ref.split(":", 1)[0].endswith("/index.js") for ref in e.references) for e in entries)
    printf_count = sum(bool(printf_tokens(e.msgid)) or bool(printf_tokens(e.msgid_plural or "")) for e in entries)
    html_count = sum(bool(html_tags(e.msgid)) or bool(html_tags(e.msgid_plural or "")) for e in entries)

    kit_manifest = {
        "schema_version": SCHEMA,
        "generator": GENERATOR,
        "textdomain": "certloom",
        "locale": target["locale"],
        "language_name": target["language_name"],
        "direction": target["direction"],
        "quality_tier": target.get("quality_tier"),
        "source_status": target["status"],
        "source_pot_sha256": pot_sha,
        "source_strings": len(entries),
        "source_key_set_sha256": source_key_set_sha256,
        "plural_strings": plural_count,
        "context_strings": context_count,
        "block_javascript_strings": js_count,
        "printf_strings": printf_count,
        "html_strings": html_count,
        "translation_content_included": False,
        "review_required": True,
        "strings_tsv_is_spreadsheet_safe": True,
    }
    (output / "review-manifest.json").write_text(
        json.dumps(kit_manifest, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
        newline="\n",
    )
    (output / "certloom.pot").write_bytes(pot_bytes)

    with (output / "strings.tsv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle, delimiter="\t", lineterminator="\n", quoting=csv.QUOTE_MINIMAL)
        writer.writerow([
            "index", "source_key_sha256", "context", "source", "source_plural", "references", "translator_comments",
            "printf_placeholders", "plural_printf_placeholders", "html_tags", "plural_html_tags", "risk_labels",
        ])
        for index, entry in enumerate(entries, start=1):
            placeholders = ",".join(f"{token}:{count}" for token, count in sorted(printf_tokens(entry.msgid).items()))
            plural_placeholders = ",".join(f"{token}:{count}" for token, count in sorted(printf_tokens(entry.msgid_plural or "").items()))
            tags = ",".join(f"{token}:{count}" for token, count in sorted(html_tags(entry.msgid).items()))
            plural_tags = ",".join(f"{token}:{count}" for token, count in sorted(html_tags(entry.msgid_plural or "").items()))
            comment_parts = []
            for comment in entry.comments:
                if comment.startswith("#."):
                    comment = comment[2:]
                comment_parts.append(comment.strip())
            comments = " | ".join(part for part in comment_parts if part)
            cells = [
                str(index),
                sha256(entry.original_key().encode("utf-8")).hexdigest(),
                visible(entry.msgctxt or ""),
                visible(entry.msgid),
                visible(entry.msgid_plural or ""),
                " ".join(entry.references),
                visible(comments),
                placeholders,
                plural_placeholders,
                tags,
                plural_tags,
                ",".join(risk_labels(entry)),
            ]
            writer.writerow([spreadsheet_safe(cell) for cell in cells])

    checklist = f"""# Certloom language review checklist — {target['language_name']} ({target['locale']})

This kit contains **no translated strings** and does not make a language-support claim.
It is bound to Certloom POT SHA-256 `{pot_sha}` and contains {len(entries):,} source strings.

## Review requirements

- Translate from `certloom.pot` using the official WordPress locale glossary/style guide where one exists.
- Preserve gettext context; never merge entries merely because their English `msgid` looks similar.
- Preserve every printf placeholder exactly, including argument numbering and type.
- Preserve HTML tag structure; translate human text, not markup semantics.
- Review plural forms with a qualified speaker/editor for this WordPress locale.
- For RTL languages, use natural Unicode text. Do not insert hidden bidi embedding/override/isolate controls to force visual order.
- Review mixed-direction strings containing identifiers, certificate numbers, URLs, hashes, dates or Latin product names in a real WordPress browser.
- Preserve Certloom product/authority meaning. Translation must not imply accreditation, compliance, renderer authority or disclosure that the English source does not claim.
- Review high-risk rows in `strings.tsv` (`printf`, `html`, `plural`, `context`, `block-js`) before ordinary copy.
- Test the final candidate in wp-admin, public verification/profile, issuance review, Program language settings, Template Library and Quick/Pro Designer.
- Do not mark the pack `reviewed` until the Certloom validator reports 100% coverage, zero fuzzy entries and deterministic MO + WordPress block JSON parity.
- Human reviewer identity/date belong in the accepted pack manifest/PO headers only after review; this review kit deliberately does not invent sign-off metadata.

## Evidence files

- `certloom.pot` — exact source catalog.
- `strings.tsv` — translator-oriented source/context/risk index. It is spreadsheet-safe display metadata; `certloom.pot` remains the canonical source text.
- `review-manifest.json` — exact locale/POT/count identity.
- `SHA256SUMS.txt` — deterministic kit-file hashes.

The translation team should return the reviewed PO plus reviewer/date evidence. Certloom's existing `validate_language_packs.py` remains the acceptance authority for candidate/reviewed artifacts.
"""
    (output / "REVIEW-CHECKLIST.md").write_text(checklist, encoding="utf-8", newline="\n")

    checksum_files = ["REVIEW-CHECKLIST.md", "certloom.pot", "review-manifest.json", "strings.tsv"]
    checksum_lines = [f"{sha256((output / name).read_bytes()).hexdigest()}  {name}" for name in checksum_files]
    (output / "SHA256SUMS.txt").write_text("\n".join(checksum_lines) + "\n", encoding="ascii", newline="\n")

    zip_bytes = write_zip(output, zip_target, epoch)

    result = {
        "locale": target["locale"],
        "language_name": target["language_name"],
        "direction": target["direction"],
        "source_strings": len(entries),
        "pot_sha256": pot_sha,
        "zip": str(zip_target),
        "zip_sha256": sha256(zip_bytes).hexdigest(),
    }
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
