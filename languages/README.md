# Certloom language packs

`certloom.pot` is the deterministic English source catalog. `certloom-language-packs.json`
records the reviewed-pack roadmap and exact POT identity.

A locale is **not** a supported/reviewed Certloom language merely because it appears in
the manifest. Statuses mean:

- `planned` — target locale only; no translation artifact is claimed.
- `candidate` — translation work may be incomplete; never marketed as reviewed.
- `reviewed` — complete source coverage, zero fuzzy entries, human review metadata,
  exact POT binding, safe placeholders/HTML, deterministic MO, and deterministic
  WordPress block-editor JSON catalogs all pass the release validator.

WordPress falls back to Certloom's English source strings whenever a translation is not
available. Certloom deliberately does not bulk-fill missing strings with machine output
and call that a reviewed language pack.

The source-only validator/compiler is `tests/i18n/validate_language_packs.py`.

## Human-review intake kits

Source releases include `tests/i18n/make_review_kit.py` for creating a deterministic,
translation-free review packet for any planned/candidate roadmap locale. Review kits
must be generated **outside** the plugin tree and contain the exact POT, a spreadsheet-safe source/context
/risk TSV with singular/plural placeholder and HTML evidence, a review checklist, a locale/POT identity manifest and SHA-256 checksums.
They do not contain translated strings and do not change a locale's manifest status.

Example from a complete source checkout:

```sh
SOURCE_DATE_EPOCH=1787356800 python3 tests/i18n/make_review_kit.py . es_ES --output /secure/review/es_ES
```

The resulting ZIP is deterministic for the same source/locale/epoch. Translation teams
return a PO plus genuine reviewer/date evidence; `validate_language_packs.py` remains the
acceptance authority for candidate/reviewed artifacts. Never commit a review kit or use
its existence as evidence that the locale is translated.

