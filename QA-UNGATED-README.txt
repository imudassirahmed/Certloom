CERTLOOM 1.6.98-qa.2 — UNGATED QA BUILD

STAGING / FRONTEND INSPECTION ONLY. NOT FOR PRODUCTION.

Base: deterministic closed-authority Certloom 1.6.98 build after UX-OPS-02 staging corrections.

QA-only behavior:
- normalized-domain admin preview, data tools and write paths are enabled in memory;
- normalized public credential verification/profile readiness is enabled in memory so the human-facing credential URL, direct QR, REST adapter and Recipient Portal can be inspected;
- Template publication release authority is bypassed;
- PDF/PNG/JPEG release authority is bypassed only after a real registered renderer and its runtime requirements are actually available.

1.6.98 QA.2 areas to inspect:
- Design publication is obvious: draft designs expose a primary “Publish design” action; older internal formats are upgraded deterministically during that action when needed;
- Program, issuance and language-specific design searches explain that only published designs are eligible and link back to Design management;
- Programs, Recipients, Issuers, Batches and Signers are list-first with a top-right Add action instead of a permanent blank form;
- contextual Certloom routes retain the Certloom WordPress parent-menu active state;
- Program language design rows, legacy design-format cards and Field Manager policy/action rows use corrected alignment/responsive rules;
- all UX-OPS-01 Setup/numbering/Backup/Google/readiness/public-action/lifecycle corrections remain present.

This package does NOT:
- create resources/domain/accepted.json;
- create resources/renderer/accepted.json;
- mark DBAUTH or PDF-04 accepted;
- write a fake public-cutover readiness option or migration/cutover receipt;
- make missing mPDF/GD/runtime dependencies appear available;
- constitute release or acceptance evidence.

OAuth note:
- auth.ecapla.com is approved/live infrastructure for a future narrow Ecapla OAuth relay;
- the hosted relay is NOT implemented or acceptance-tested in this QA package;
- site-owned Google OAuth remains the current working fallback.

Use only on a disposable/staging WordPress installation with a database backup.
Remove this build before genuine DBAUTH/PDF-04 acceptance or production deployment.
