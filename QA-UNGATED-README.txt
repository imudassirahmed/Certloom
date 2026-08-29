CERTLOOM 1.6.96-QA.1 — UNGATED STAGING BUILD

Purpose
-------
This package exists only so Certloom features that are correctly fail-closed in the canonical 1.6.96 release can be inspected on a disposable/staging WordPress installation.

What is bypassed
----------------
- Normalized-domain release-authority feature scopes: admin preview, Data Tools and write-path gate.
- Renderer release-authority gate for Template publication and, if a real renderer is registered, artifact authorization.

What is NOT faked or removed
----------------------------
- Database schema/migration/maintenance/receipt integrity checks.
- Per-site cutover coordinator safety requirements other than the release-authority prerequisite.
- Renderer registration, renderer dependencies, GD/mPDF availability, local font integrity, render-plan validation, artifact inspection or receipts.
- Genuine DBAUTH acceptance evidence.
- Genuine PDF-04 acceptance evidence.

Important
---------
NOT FOR PRODUCTION. NOT AN ACCEPTED RELEASE. DO NOT USE AS RELEASE EVIDENCE.
Do not install over a live Certloom site. Use a disposable/staging site and take a database backup first.

Canonical production baseline remains Certloom 1.6.96 with both external authorities closed.
