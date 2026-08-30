#!/usr/bin/env python3
"""Generate languages/certloom.pot from the Certloom production tree.

Why this exists rather than `wp i18n make-pot`
----------------------------------------------
`wp i18n make-pot` is the right tool and should replace this the moment WP-CLI
is available in the release pipeline. This script is a standalone equivalent so
the POT can be produced and reviewed without a WordPress checkout, and so the
extraction rules are visible and auditable rather than implicit.

It parses PHP string literals properly (single- and double-quoted, with escape
handling) instead of pattern-matching, because a naive regex silently drops
every string containing an apostrophe -- and Certloom has hundreds of those.

Strings whose arguments are not all literals are skipped and reported. A
translator cannot translate a runtime-composed string, and a POT that pretends
otherwise produces broken .mo entries.

Usage:
    python3 make-pot.py /path/to/certloom [-o languages/certloom.pot]
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

DOMAIN = "certloom"

# name -> (index of singular, index of plural or None, index of context or None,
#          index of the domain argument)
FUNCTIONS = {
    "__":            (0, None, None, 1),
    "_e":            (0, None, None, 1),
    "esc_html__":    (0, None, None, 1),
    "esc_html_e":    (0, None, None, 1),
    "esc_attr__":    (0, None, None, 1),
    "esc_attr_e":    (0, None, None, 1),
    "esc_textarea__": (0, None, None, 1),
    "_x":            (0, None, 1, 2),
    "_ex":           (0, None, 1, 2),
    "esc_html_x":    (0, None, 1, 2),
    "esc_attr_x":    (0, None, 1, 2),
    "_n":            (0, 1, None, 3),
    "_n_noop":       (0, 1, None, 2),
    "_nx":           (0, 1, 2, 3),
    "_nx_noop":      (0, 1, 2, 3),
}

# Directories excluded from the shipped payload; strings there are not user
# facing and must not reach translators.
EXCLUDED_DIRS = {"tests", "docs", "node_modules", ".git", "languages"}
BLOCK_METADATA_KEYS = ("title", "description")

CALL_RE = re.compile(r"(?<![\w$>:])(" + "|".join(sorted(FUNCTIONS, key=len, reverse=True)) + r")\s*\(")
TRANSLATOR_COMMENT_RE = re.compile(r"/\*\s*(translators?:.*?)\*/", re.IGNORECASE | re.DOTALL)


class Skipped(Exception):
    """Raised when a call argument is not a plain literal."""


def read_php_string(src: str, i: int):
    """Parse one PHP string literal starting at src[i]. Returns (value, next_index)."""
    quote = src[i]
    if quote not in "'\"":
        raise Skipped("not a string literal")
    out = []
    i += 1
    while i < len(src):
        ch = src[i]
        if ch == "\\":
            nxt = src[i + 1] if i + 1 < len(src) else ""
            if quote == "'":
                # In single quotes only \' and \\ are escapes.
                out.append(nxt if nxt in "'\\" else "\\" + nxt)
            else:
                out.append(
                    {"n": "\n", "t": "\t", "r": "\r", "\\": "\\", '"': '"',
                     "$": "$", "0": "\0", "e": "\x1b", "v": "\v", "f": "\f"}.get(nxt, "\\" + nxt)
                )
            i += 2
            continue
        if ch == quote:
            return "".join(out), i + 1
        out.append(ch)
        i += 1
    raise Skipped("unterminated string")


def read_argument(src: str, i: int):
    """Parse one argument. Handles 'a' . 'b' concatenation of literals."""
    parts = []
    while True:
        while i < len(src) and src[i].isspace():
            i += 1
        if i >= len(src):
            raise Skipped("truncated")
        value, i = read_php_string(src, i)
        parts.append(value)
        j = i
        while j < len(src) and src[j].isspace():
            j += 1
        if j < len(src) and src[j] == "." and (j + 1 >= len(src) or src[j + 1] != "."):
            i = j + 1
            continue
        return "".join(parts), i


def split_call(src: str, open_paren: int):
    """Return the list of raw argument spans inside a call, and the closing index."""
    args, depth, start, i = [], 1, open_paren + 1, open_paren + 1
    while i < len(src):
        ch = src[i]
        if ch in "'\"":
            try:
                _, i = read_php_string(src, i)
            except Skipped:
                return None, i
            continue
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
            if depth == 0:
                args.append(src[start:i])
                return args, i
        elif ch == "," and depth == 1:
            args.append(src[start:i])
            start = i + 1
        i += 1
    return None, i


def line_of(src: str, index: int) -> int:
    return src.count("\n", 0, index) + 1


def preceding_comment(src: str, index: int):
    """Find a /* translators: ... */ comment immediately above the call."""
    window = src[max(0, index - 600):index]
    matches = list(TRANSLATOR_COMMENT_RE.finditer(window))
    if not matches:
        return None
    last = matches[-1]
    between = window[last.end():]
    # Only attach when nothing but whitespace, a tab-indented continuation, or
    # a sprintf/printf opener sits between the comment and the call.
    if re.fullmatch(r"[\s]*(?:s?printf\s*\()?[\s]*", between):
        return " ".join(last.group(1).split())
    return None


def escape_po(value: str) -> str:
    return (
        value.replace("\\", "\\\\")
        .replace('"', '\\"')
        .replace("\t", "\\t")
        .replace("\n", "\\n")
    )


def po_string(prefix: str, value: str) -> str:
    if "\n" not in value:
        return f'{prefix} "{escape_po(value)}"'
    lines = value.split("\n")
    out = [f'{prefix} ""']
    for k, line in enumerate(lines):
        piece = line + ("\n" if k < len(lines) - 1 else "")
        if piece:
            out.append(f'"{escape_po(piece)}"')
    return "\n".join(out)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("-o", "--output", default=None)
    ap.add_argument("--report-skipped", action="store_true")
    args = ap.parse_args()

    root = Path(args.root).resolve()
    output = Path(args.output) if args.output else root / "languages" / f"{DOMAIN}.pot"

    entries: dict[tuple, dict] = {}
    skipped: list[str] = []
    wrong_domain: list[str] = []
    files = 0

    for dirpath, dirnames, filenames in os.walk(root):
        # os.walk() follows the filesystem's directory iteration order unless
        # callers normalize dirnames in-place. Release artifacts must not
        # depend on extraction order, inode allocation, filesystem hash order,
        # or the machine that happened to unpack the source archive.
        dirnames[:] = sorted(d for d in dirnames if d not in EXCLUDED_DIRS)
        for name in sorted(filenames):
            if not name.endswith(".php"):
                continue
            path = Path(dirpath) / name
            rel = path.relative_to(root).as_posix()
            src = path.read_text(encoding="utf-8", errors="replace")
            files += 1

            for match in CALL_RE.finditer(src):
                func = match.group(1)
                sing_i, plur_i, ctx_i, dom_i = FUNCTIONS[func]
                raw_args, _ = split_call(src, match.end() - 1)
                if raw_args is None or len(raw_args) <= dom_i:
                    continue
                line = line_of(src, match.start())
                try:
                    domain, _ = read_argument(raw_args[dom_i], 0)
                except Skipped:
                    continue
                if domain != DOMAIN:
                    wrong_domain.append(f"{rel}:{line} {func}() uses domain {domain!r}")
                    continue
                try:
                    singular, _ = read_argument(raw_args[sing_i], 0)
                    plural = read_argument(raw_args[plur_i], 0)[0] if plur_i is not None else None
                    context = read_argument(raw_args[ctx_i], 0)[0] if ctx_i is not None else None
                except Skipped as error:
                    skipped.append(f"{rel}:{line} {func}() {error}")
                    continue

                key = (context, singular, plural)
                entry = entries.setdefault(
                    key,
                    {"context": context, "singular": singular, "plural": plural,
                     "refs": [], "comments": set()},
                )
                entry["refs"].append(f"{rel}:{line}")
                note = preceding_comment(src, match.start())
                if note:
                    entry["comments"].add(note)

    # Native block editor strings live in JavaScript rather than PHP. Preserve
    # the same singular/plural/context semantics for the WordPress i18n helpers
    # that Certloom permits in shipped block code. This stays aligned with the
    # PHP extractor so `_x()` / `_nx()` context cannot silently disappear from
    # the deterministic POT.
    js_functions = {
        "__":  (0, None, None, 1),
        "_x":  (0, None, 1, 2),
        "_n":  (0, 1, None, 3),
        "_nx": (0, 1, 2, 3),
    }
    js_call_re = re.compile(r"(?<![\w$.:])(" + "|".join(sorted(js_functions, key=len, reverse=True)) + r")\s*\(")
    for path in sorted((root / "blocks").glob("*/index.js")):
        rel = path.relative_to(root).as_posix()
        src = path.read_text(encoding="utf-8")
        for match in js_call_re.finditer(src):
            func = match.group(1)
            sing_i, plur_i, ctx_i, dom_i = js_functions[func]
            raw_args, _ = split_call(src, match.end() - 1)
            if raw_args is None or len(raw_args) <= dom_i:
                skipped.append(f"{rel}:{line_of(src, match.start())} JS {func}() malformed call")
                continue
            try:
                domain, _ = read_argument(raw_args[dom_i], 0)
            except Skipped:
                skipped.append(f"{rel}:{line_of(src, match.start())} JS {func}() non-literal domain")
                continue
            if domain != DOMAIN:
                wrong_domain.append(f"{rel}:{line_of(src, match.start())} JS {func}() uses domain {domain!r}")
                continue
            try:
                singular, _ = read_argument(raw_args[sing_i], 0)
                plural = read_argument(raw_args[plur_i], 0)[0] if plur_i is not None else None
                context = read_argument(raw_args[ctx_i], 0)[0] if ctx_i is not None else None
            except Skipped as error:
                skipped.append(f"{rel}:{line_of(src, match.start())} JS {func}() {error}")
                continue
            key = (context, singular, plural)
            entry = entries.setdefault(
                key,
                {"context": context, "singular": singular, "plural": plural, "refs": [], "comments": set()},
            )
            entry["refs"].append(f"{rel}:{line_of(src, match.start())}")

    for path in sorted((root / "blocks").glob("*/block.json")):
        rel = path.relative_to(root).as_posix()
        data = json.loads(path.read_text(encoding="utf-8"))
        if data.get("textdomain") != DOMAIN:
            wrong_domain.append(f"{rel}: block metadata uses domain {data.get('textdomain')!r}")
            continue
        values = []
        for key_name in BLOCK_METADATA_KEYS:
            if isinstance(data.get(key_name), str):
                values.append(data[key_name])
        values.extend(v for v in data.get("keywords", []) if isinstance(v, str))
        for value in values:
            key = (None, value, None)
            entry = entries.setdefault(key, {"context": None, "singular": value, "plural": None, "refs": [], "comments": set()})
            entry["refs"].append(f"{rel}:1")

    epoch = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
    if epoch.isdigit():
        now = datetime.fromtimestamp(int(epoch), timezone.utc).strftime("%Y-%m-%d %H:%M%z")
    else:
        now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M%z")
    out = [
        "# Copyright (C) Ecapla",
        "# This file is distributed under the same license as the Certloom plugin.",
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Certloom\\n"',
        '"Report-Msgid-Bugs-To: https://ecapla.com\\n"',
        f'"POT-Creation-Date: {now}\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"PO-Revision-Date: YEAR-MO-DA HO:MI+ZONE\\n"',
        '"Last-Translator: FULL NAME <EMAIL@ADDRESS>\\n"',
        '"Language-Team: LANGUAGE <LL@li.org>\\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\\n"',
        '"X-Generator: certloom make-pot.py\\n"',
        '"X-Domain: certloom\\n"',
        "",
    ]

    # Reference encounter order is not authoritative. Canonicalize every
    # reference list before it can influence entry order or output bytes. This
    # is deliberately redundant with sorted directory traversal: it keeps POT
    # determinism fail-safe if another extractor source is added later.
    for entry in entries.values():
        entry["refs"] = sorted(set(entry["refs"]))

    def entry_sort_key(key):
        entry = entries[key]
        refs = entry["refs"]
        return (
            refs[0] if refs else "",
            entry["context"] or "",
            entry["singular"],
            entry["plural"] or "",
        )

    for key in sorted(entries, key=entry_sort_key):
        entry = entries[key]
        for note in sorted(entry["comments"]):
            out.append(f"#. {note}")
        for ref in entry["refs"][:40]:
            out.append(f"#: {ref}")
        if entry["context"] is not None:
            out.append(po_string("msgctxt", entry["context"]))
        out.append(po_string("msgid", entry["singular"]))
        if entry["plural"] is not None:
            out.append(po_string("msgid_plural", entry["plural"]))
            out.append('msgstr[0] ""')
            out.append('msgstr[1] ""')
        else:
            out.append('msgstr ""')
        out.append("")

    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(out), encoding="utf-8")

    print(f"Scanned {files} PHP files plus native block JavaScript/metadata under {root}")
    print(f"Wrote {len(entries)} unique strings to {output}")
    if wrong_domain:
        print(f"\n{len(wrong_domain)} call(s) use a text domain other than '{DOMAIN}':")
        for item in wrong_domain[:20]:
            print("  " + item)
    if skipped:
        print(f"\n{len(skipped)} call(s) skipped (non-literal arguments):")
        if args.report_skipped:
            for item in skipped:
                print("  " + item)
        else:
            for item in skipped[:20]:
                print("  " + item)
            if len(skipped) > 20:
                print(f"  ... and {len(skipped) - 20} more (pass --report-skipped)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
