#!/usr/bin/env python3
"""Minimal, dependency-free gettext PO/MO utilities for Certloom language packs.

The release pipeline intentionally does not depend on GNU gettext or third-party
Python packages.  This module implements only the PO features Certloom accepts:
comments/flags, msgctxt, msgid, msgid_plural, msgstr and msgstr[N] with quoted
continuations.  It also emits deterministic GNU MO catalogs.
"""
from __future__ import annotations

from dataclasses import dataclass, field
import ast
import struct
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Tuple
import hashlib
import json


@dataclass
class PoEntry:
    msgid: str = ""
    msgstr: str = ""
    msgctxt: Optional[str] = None
    msgid_plural: Optional[str] = None
    msgstr_plural: Dict[int, str] = field(default_factory=dict)
    flags: set[str] = field(default_factory=set)
    comments: List[str] = field(default_factory=list)
    references: List[str] = field(default_factory=list)
    lineno: int = 0

    @property
    def fuzzy(self) -> bool:
        return "fuzzy" in self.flags

    def original_key(self) -> str:
        base = self.msgid
        if self.msgctxt is not None:
            base = self.msgctxt + "\x04" + base
        if self.msgid_plural is not None:
            base += "\x00" + self.msgid_plural
        return base

    def translation_value(self, nplurals: int) -> str:
        if self.msgid_plural is None:
            return self.msgstr
        return "\x00".join(self.msgstr_plural.get(i, "") for i in range(nplurals))


class PoSyntaxError(ValueError):
    pass


def _decode_quoted(text: str, path: Path, lineno: int) -> str:
    text = text.strip()
    if not (len(text) >= 2 and text[0] == '"' and text[-1] == '"'):
        raise PoSyntaxError(f"{path}:{lineno}: expected a quoted PO string")
    try:
        value = ast.literal_eval(text)
    except (ValueError, SyntaxError) as exc:
        raise PoSyntaxError(f"{path}:{lineno}: invalid PO string literal: {exc}") from exc
    if not isinstance(value, str):
        raise PoSyntaxError(f"{path}:{lineno}: invalid PO string value")
    return value


def parse_po(path: Path | str) -> List[PoEntry]:
    path = Path(path)
    text = path.read_text(encoding="utf-8")
    if text.startswith("\ufeff"):
        raise PoSyntaxError(f"{path}: UTF-8 BOM is not permitted")
    if "\r" in text:
        raise PoSyntaxError(f"{path}: CR/CRLF line endings are not permitted; use LF")

    entries: List[PoEntry] = []
    entry = PoEntry()
    field_name: Optional[Tuple[str, Optional[int]]] = None
    active = False

    def flush() -> None:
        nonlocal entry, field_name, active
        if active:
            entries.append(entry)
        entry = PoEntry()
        field_name = None
        active = False

    lines = text.split("\n")
    for lineno, raw in enumerate(lines, start=1):
        line = raw.strip()
        if not line:
            flush()
            continue
        active = True
        if not entry.lineno:
            entry.lineno = lineno
        if line.startswith("#,"):
            entry.flags.update(flag.strip() for flag in line[2:].split(",") if flag.strip())
            field_name = None
            continue
        if line.startswith("#:"):
            entry.references.extend(part for part in line[2:].strip().split() if part)
            field_name = None
            continue
        if line.startswith("#"):
            entry.comments.append(raw)
            field_name = None
            continue

        if line.startswith("msgctxt "):
            entry.msgctxt = _decode_quoted(line[len("msgctxt "):], path, lineno)
            field_name = ("msgctxt", None)
        elif line.startswith("msgid_plural "):
            entry.msgid_plural = _decode_quoted(line[len("msgid_plural "):], path, lineno)
            field_name = ("msgid_plural", None)
        elif line.startswith("msgid "):
            entry.msgid = _decode_quoted(line[len("msgid "):], path, lineno)
            field_name = ("msgid", None)
        elif line.startswith("msgstr["):
            close = line.find("]")
            if close < 7:
                raise PoSyntaxError(f"{path}:{lineno}: malformed plural msgstr index")
            try:
                idx = int(line[7:close])
            except ValueError as exc:
                raise PoSyntaxError(f"{path}:{lineno}: invalid plural msgstr index") from exc
            rest = line[close + 1:].strip()
            entry.msgstr_plural[idx] = _decode_quoted(rest, path, lineno)
            field_name = ("msgstr_plural", idx)
        elif line.startswith("msgstr "):
            entry.msgstr = _decode_quoted(line[len("msgstr "):], path, lineno)
            field_name = ("msgstr", None)
        elif line.startswith('"'):
            if field_name is None:
                raise PoSyntaxError(f"{path}:{lineno}: continuation without a field")
            value = _decode_quoted(line, path, lineno)
            name, idx = field_name
            if name == "msgstr_plural":
                entry.msgstr_plural[idx or 0] = entry.msgstr_plural.get(idx or 0, "") + value
            else:
                current = getattr(entry, name)
                setattr(entry, name, (current or "") + value)
        else:
            raise PoSyntaxError(f"{path}:{lineno}: unsupported PO syntax: {line[:80]}")
    flush()

    seen: set[str] = set()
    for item in entries:
        key = item.original_key()
        if key in seen:
            raise PoSyntaxError(f"{path}:{item.lineno}: duplicate gettext key")
        seen.add(key)
    if not entries or entries[0].msgid != "":
        raise PoSyntaxError(f"{path}: first entry must be the gettext header")
    return entries


def parse_headers(entry: PoEntry) -> Dict[str, str]:
    if entry.msgid != "":
        raise ValueError("not a gettext header entry")
    headers: Dict[str, str] = {}
    for line in entry.msgstr.split("\n"):
        if not line or ":" not in line:
            continue
        key, value = line.split(":", 1)
        headers[key.strip()] = value.strip()
    return headers


def write_mo(entries: Iterable[PoEntry], output: Path | str, nplurals: int) -> bytes:
    """Compile entries into a deterministic little-endian GNU MO catalog."""
    pairs: List[Tuple[bytes, bytes]] = []
    for entry in entries:
        # Gettext catalogs must omit untranslated messages; writing an empty
        # translation can blank UI text instead of allowing the source-language
        # fallback. The header is the sole intentional empty-msgid entry.
        if entry.msgid != "":
            if entry.msgid_plural is None and not entry.msgstr:
                continue
            if entry.msgid_plural is not None and not all(entry.msgstr_plural.get(i, "") for i in range(nplurals)):
                continue
        original = entry.original_key().encode("utf-8")
        translated = entry.translation_value(nplurals).encode("utf-8")
        pairs.append((original, translated))
    pairs.sort(key=lambda pair: pair[0])

    count = len(pairs)
    header_size = 7 * 4
    orig_table_offset = header_size
    trans_table_offset = orig_table_offset + count * 8
    data_offset = trans_table_offset + count * 8

    originals = bytearray()
    translations = bytearray()
    orig_table: List[Tuple[int, int]] = []
    trans_table: List[Tuple[int, int]] = []

    for original, _ in pairs:
        orig_table.append((len(original), data_offset + len(originals)))
        originals.extend(original + b"\0")
    trans_data_offset = data_offset + len(originals)
    for _, translated in pairs:
        trans_table.append((len(translated), trans_data_offset + len(translations)))
        translations.extend(translated + b"\0")

    out = bytearray()
    out.extend(struct.pack("<7I", 0x950412DE, 0, count, orig_table_offset, trans_table_offset, 0, 0))
    for length, offset in orig_table:
        out.extend(struct.pack("<2I", length, offset))
    for length, offset in trans_table:
        out.extend(struct.pack("<2I", length, offset))
    out.extend(originals)
    out.extend(translations)

    output = Path(output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_bytes(bytes(out))
    return bytes(out)

def write_wp_json_catalogs(
    po_entries: Iterable[PoEntry],
    pot_entries: Iterable[PoEntry],
    output_dir: Path | str,
    locale: str,
    plural_forms: str,
    revision_date: str,
    textdomain: str = "certloom",
) -> Dict[str, Tuple[Path, bytes]]:
    """Compile deterministic WordPress/Jed JSON catalogs for shipped block JS.

    Source ownership comes from the canonical POT references, not translator-
    maintained PO comments, so stripping/reformatting translator references does
    not make JavaScript strings disappear from a reviewed pack.
    """
    po_map = {entry.original_key(): entry for entry in po_entries}
    sources: Dict[str, Dict[str, object]] = {}
    for source_entry in pot_entries:
        if source_entry.msgid == "":
            continue
        js_refs = sorted({ref.split(":", 1)[0] for ref in source_entry.references if ref.startswith("blocks/") and ref.split(":", 1)[0].endswith("/index.js")})
        if not js_refs:
            continue
        translated = po_map.get(source_entry.original_key())
        if translated is None or translated.fuzzy:
            continue
        if translated.msgid_plural is None:
            if not translated.msgstr:
                continue
            values = [translated.msgstr]
        else:
            plural_values = [translated.msgstr_plural[i] for i in sorted(translated.msgstr_plural)]
            if not plural_values or any(not value for value in plural_values):
                continue
            values = plural_values
        key = source_entry.msgid if source_entry.msgctxt is None else source_entry.msgctxt + "\x04" + source_entry.msgid
        for source in js_refs:
            sources.setdefault(source, {})[key] = values

    output_dir = Path(output_dir)
    result: Dict[str, Tuple[Path, bytes]] = {}
    for source in sorted(sources):
        messages: Dict[str, object] = {
            "": {"domain": "messages", "lang": locale, "plural-forms": plural_forms}
        }
        for key in sorted(sources[source]):
            messages[key] = sources[source][key]
        payload = {
            "translation-revision-date": revision_date,
            "generator": "Certloom deterministic language-pack compiler",
            "source": source,
            "domain": "messages",
            "locale_data": {"messages": messages},
        }
        data = (json.dumps(payload, ensure_ascii=False, sort_keys=False, separators=(",", ":")) + "\n").encode("utf-8")
        digest = hashlib.md5(source.encode("utf-8")).hexdigest()  # WordPress language-pack filename contract; not a security digest.
        path = output_dir / f"{textdomain}-{locale}-{digest}.json"
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        result[source] = (path, data)
    return result

