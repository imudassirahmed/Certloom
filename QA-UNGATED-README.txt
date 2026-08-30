CERTLOOM 1.6.98-qa.1 — UNGATED QA BUILD

STAGING / FRONTEND INSPECTION ONLY. NOT FOR PRODUCTION.

Base: closed-authority Certloom 1.6.98 QA-preparation installable.

QA-only behavior:
- normalized-domain admin preview, data tools and write paths are enabled in memory;
- normalized public credential verification/profile readiness is enabled in memory so the human-facing credential URL, direct QR, REST adapter and Recipient Portal can be inspected;
- Template publication release authority is bypassed;
- PDF/PNG/JPEG release authority is bypassed only after a real registered renderer and its runtime requirements are actually available.

1.6.98 QA areas to inspect:
- Setup Wizard design selection/preview and canonical numbering prefix/start/padding/suffix;
- Backup & Finish local/Google controls and OAuth popup UX;
- Dashboard compact readiness reminder and smaller Setup Wizard action;
- contextual Certloom routes keeping their WordPress parent menu active;
- Backup Center compact settings and 50-row pagination;
- legacy Registry Program/Batch explanation;
- public credential Copy/Profile/Share/Print action hierarchy;
- plugin deactivation keep/delete-data safety dialog.

This package does NOT:
- create resources/domain/accepted.json;
- create resources/renderer/accepted.json;
- mark DBAUTH or PDF-04 accepted;
- write a fake public-cutover readiness option or migration/cutover receipt;
- make missing mPDF/GD/runtime dependencies appear available;
- constitute release or acceptance evidence.

Google note: current 1.6.98 uses site-owned OAuth. A true zero-config Ecapla/Certloom-branded OAuth flow across arbitrary customer domains needs a separately approved hosted OAuth relay/broker; this QA build does not fake that hosted service.

Use only on a disposable/staging WordPress installation with a database backup.
Remove this build before genuine DBAUTH/PDF-04 acceptance or production deployment.
