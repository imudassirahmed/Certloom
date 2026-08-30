CERTLOOM 1.6.97-qa.1 — UNGATED QA BUILD

STAGING / FRONTEND INSPECTION ONLY. NOT FOR PRODUCTION.

Base: final frozen Certloom 1.6.97 installable.

QA-only behavior:
- normalized-domain admin preview, data tools and write paths are enabled in memory;
- normalized public credential verification/profile readiness is enabled in memory so the human-facing credential URL, direct QR, REST adapter and Recipient Portal can be inspected;
- Template publication release authority is bypassed;
- PDF/PNG/JPEG release authority is bypassed only after a real registered renderer and its runtime requirements are actually available.

This package does NOT:
- create resources/domain/accepted.json;
- create resources/renderer/accepted.json;
- mark DBAUTH or PDF-04 accepted;
- write a fake public-cutover readiness option or migration/cutover receipt;
- make missing mPDF/GD/runtime dependencies appear available;
- constitute release or acceptance evidence.

Use only on a disposable/staging WordPress installation with a database backup.
Remove this build before genuine DBAUTH/PDF-04 acceptance or production deployment.
