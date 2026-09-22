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


## Returned candidate intake

Source releases also include `tests/i18n/stage_language_candidate.py`. It accepts a returned human PO only when paired with the exact Certloom review-kit ZIP for the same locale/current POT. It preserves the returned bytes, validates source identity plus placeholder/HTML/bidi/plural safety, and emits a deterministic **external** candidate evidence bundle.

Candidate intake never writes language artifacts into the plugin tree and never grants `reviewed` status. `Plural-Forms` is treated as runtime-sensitive metadata: only bounded conventional syntax over `n` is accepted before MO or WordPress/Jed JSON compilation.

Example:

```sh
SOURCE_DATE_EPOCH=1787356800 python3 tests/i18n/stage_language_candidate.py . es_ES /secure/returns/certloom-es_ES.po \
  --review-kit /secure/review/certloom-es_ES-review-kit.zip \
  --output /secure/intake/es_ES --submitted-by "Translation team" --submitted-at 2026-08-28
```

The resulting candidate bundle is review workflow evidence only. Genuine linguistic completion, browser review, `reviewed_by` / `reviewed_at` and the frozen reviewed-pack acceptance gate are still required before the locale may be called reviewed.


## Reviewed-language acceptance

Source releases include `tests/i18n/accept_language_review.py` as the final engineering boundary after candidate intake. It requires exact human review evidence bound to the candidate-intake ZIP, candidate PO, current POT and source-key set; reviewed acceptance additionally requires 100% translated coverage, zero fuzzy entries, safe placeholders/HTML/bidi/plurals, browser + meaning/claims review and explicit mixed-direction review for RTL locales.

Successful output remains **external**. It preserves the exact candidate intake ZIP and review evidence and emits deterministic proposed PO/MO/WordPress-Jed artifacts plus a reviewed manifest entry. It never edits this directory or makes a locale supported by itself. Integrate only exact accepted artifacts, then run the frozen language validator, locale browser QA and the complete release ceremony before claiming reviewed support.
