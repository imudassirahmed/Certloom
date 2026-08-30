#!/usr/bin/env python3
"""Generate a technically complete test-only PO return for I18N acceptance QA.

This helper exists solely under tests/. It copies source-language strings into
translations so the acceptance workflow can exercise complete coverage without
shipping or claiming a real translation. Never use its output as language
support evidence.
"""
from __future__ import annotations
import argparse, json
from pathlib import Path
from po_tools import parse_po

PLURALS = {
    "es_ES": "nplurals=2; plural=(n != 1);",
    "ar": "nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5);",
}


def q(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def main() -> int:
    ap=argparse.ArgumentParser()
    ap.add_argument("root")
    ap.add_argument("locale")
    ap.add_argument("output")
    args=ap.parse_args()
    if args.locale not in PLURALS:
        raise SystemExit("unsupported QA locale")
    root=Path(args.root).resolve()
    entries=parse_po(root / "languages" / "certloom.pot")
    out=['msgid ""','msgstr ""']
    headers=[
        ("Project-Id-Version","Certloom QA only"),
        ("Language",args.locale),
        ("MIME-Version","1.0"),
        ("Content-Type","text/plain; charset=UTF-8"),
        ("Content-Transfer-Encoding","8bit"),
        ("Plural-Forms",PLURALS[args.locale]),
    ]
    out.extend(q(f"{k}: {v}\n") for k,v in headers)
    out.append("")
    for e in entries[1:]:
        for c in e.comments: out.append(c)
        if e.references: out.append("#: " + " ".join(sorted(set(e.references))))
        flags=sorted(f for f in e.flags if f != "fuzzy")
        if flags: out.append("#, " + ", ".join(flags))
        if e.msgctxt is not None: out.append("msgctxt " + q(e.msgctxt))
        out.append("msgid " + q(e.msgid))
        if e.msgid_plural is None:
            out.append("msgstr " + q(e.msgid))
        else:
            out.append("msgid_plural " + q(e.msgid_plural))
            nplurals=2 if args.locale == "es_ES" else 6
            for idx in range(nplurals):
                out.append(f"msgstr[{idx}] " + q(e.msgid if idx == 0 else (e.msgid_plural or e.msgid)))
        out.append("")
    Path(args.output).write_text("\n".join(out).rstrip()+"\n",encoding="utf-8")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
