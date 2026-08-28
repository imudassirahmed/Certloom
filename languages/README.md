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
