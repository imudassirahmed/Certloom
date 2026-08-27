# Certloom 1.6.71 — Experience Preview build

This package is an intentionally non-production preview derived from the frozen Certloom 1.6.71 source.

## What is unlocked

- Normalized Recipients administration
- Programs / Issuers administration
- Credential Management workspace
- Template library and Designer administration
- Manual Issuance administration
- Bulk Issuance administration
- Email Delivery administration
- Existing Analytics, Recipient Portal and Public Sharing product surfaces

## What remains locked

- Normalized-domain production write-authority cutover (`CERTLOOM_DOMAIN_WRITE_PATHS_READY=false`)
- Normalized Data Tools / import / restore / cutover toolchain (`CERTLOOM_DOMAIN_DATA_TOOLS_READY=false`)
- Public normalized Series verification authority
- Renderer acceptance (`resources/renderer/accepted.json` remains absent)
- Production PDF / PNG / JPEG authority and authoritative attachment/download behavior

The unlocked admin UI can create and modify data in the additive normalized-domain preview tables. Use this build on a staging/demo WordPress installation, not on a live production site.

A persistent admin warning identifies this package as Experience Preview and explains that the legacy registry remains public authority.

## Optional controls

The preview build defines `CERTLOOM_EXPERIENCE_PREVIEW=true` by default. It may be disabled before plugin load from `wp-config.php` by defining it as `false`.

New-domain Data Tools remain disabled by default. They are intentionally separate from visual/product preview. Do not enable them merely to inspect the UI.
