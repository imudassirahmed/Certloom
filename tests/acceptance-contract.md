# Certloom acceptance boundaries

This document records requirements and the current source acceptance boundary.
The [8 October completion report](../audit/CERTLOOM-REMAINING-WORK-CLOSURE-2026-10-08.md)
closes the requested pre-release engineering work with executable and real
WordPress evidence. It does not certify a release, unavailable provider,
production renderer payload or product-wide accessibility compliance.

## Privacy and relationship history (PRV-02)

Public visibility and personal-data erasure are separate decisions. A public
field must satisfy both its frozen issuance policy and the current field policy.
The visual certificate must enforce the same ceiling as the public details list.
Internal notes (`internal_notes`) must never enter a public response.

Never silently rewrite/delete through a generic erasure callback. Immutable
credential Versions, relationship history, and issued evidence must be retained.
The WordPress eraser must report `items_retained` truthfully. Mutable recipient
anonymization requires the configured opt-in policy, a checked transaction,
explicit identity history, and an append-only audit event. Hiding a credential
does not erase it, and anonymizing a profile does not rewrite issued snapshots.

## Public surface inventory (SURF-01)

- `/certloom/v1/verify`, shortcode, block, and Elementor verification use the
  same exact-number lookup and public-field policy.
- The legacy signed URL token is routing transport only; it does not create
  a new credential identity or confer ownership.
- `/credential/{public_id}` resolves a normalized credential's current Version
  through the public policy. Anonymous users cannot use private download routes.
- Recipient account ownership is explicit. A matching email or numeric ID from
  another installation cannot establish ownership during a restore.

## Hosted integration boundary

The source restricts the managed Google OAuth relay to `https://auth.ecapla.com`.
Its purpose is the Google authorization handshake and access-token refresh.
Credential data and backup bytes remain outside that relay's request contract.
The handshake binds a single-use local state to the administrator and exact
return URL. The WordPress site stores tokens encrypted and supports a separate
site-owned OAuth mode. Local contract tests do not prove the hosted service is
available or that a real Google account can connect.

## Required customer journey coverage

`UX-QA-01` | Product-wide usability/accessibility certification is an acceptance
target, not a claim of completed certification.

The matrix must cover desktop/mobile/keyboard/RTL/mixed-direction/forced-colors/reduced-motion,
the end-to-end first-credential task and advanced-admin task, errors, retry,
backtracking, interrupted requests, stale tabs, and role variants. Native zoom
and screen-reader tests require their actual environments. Synthetic fixture
screenshots and source-string assertions do not establish task acceptance.

## Recovery and release limits

Legacy and normalized credential-only backups retain their separate, limited
scopes. The whole-state implementation now has accepted internal configuration,
assets, immutable history, typed account/object mappings and paused delivery/replay
behavior. Acceptance is limited to the recorded real tests and environment;
large-workspace/HTTP performance, full platform variants, actual assistive
technology and production delivery still require their separate release checks.
PDF/PNG/JPEG delivery requires the exact independently reviewed renderer payload.
Test authority seams cannot grant production authority.

## Whole-state recovery implementation target

The six-component capture, typed ownership review, unified twenty-one-store
replacement engine and native worker implement the recorded internal recovery
cases. The final matrix passes 189 whole capture/commit/handoff/rollback checks,
79 option-semantic checks, 46 native email/file checks, 20 actual indexed-mapping
checks and 65 credential-only account-refusal checks per PHP 8.2/8.5 runtime.
Both runtimes have successful receipts and empty stderr. Nonempty source
configuration and email media, exact target mappings, immutable source bytes,
paused execution, native catalog/safety receipts and fault recovery are covered.

Twenty-one native Lance recovery controls, sixteen recipient checks, seventeen
staff checks and 163 widget/frontend/editor/global-layout checks pass. Actual
native CSV commits and app changes have independent receipts and owned cleanup.
Browser results use native DOM activation, actual REST, focus and geometry;
DOM keyboard handlers and print emulation do not prove physical key delivery or
an operating-system print result. Original-site replacement was not attempted.
Actual commit and rollback tests use isolated real database/filesystem state.

Reference/assets component v1 remains readable alongside v2; outer, legacy and
domain formats remain unchanged. The credential-only path keeps its unverified
WordPress-account-link refusal. Large-load/HTTP performance, minimum-platform,
multisite/database variants, actual commercial entitlement, production renderer
payloads and OS input/accessibility remain separate release acceptance. See the
[completion report](../audit/CERTLOOM-REMAINING-WORK-CLOSURE-2026-10-08.md) for exact
artifact paths, cleanup results and the deferred installed LearnDash stage.

| State | Required archive and restore behavior |
| --- | --- |
| Mixed credential stores | Include the legacy registry and all eighteen native credential/history/workflow entities in one reviewed, internally consistent snapshot. Preserve public identities, numbering reservations, immutable revisions, privacy markers and relationship history. |
| Authoring and configuration | Inventory durable configuration explicitly, including fields, retired fields, settings, email designs and assignments, template design state and the custom-font index. Do not assume three existing configuration keys cover the product. |
| Referenced files | Include exact referenced media/font/static-email-attachment bytes with bounded, content-addressed descriptors. Preserve issued asset identities independently of mutable attachment metadata. Reject missing or changed bytes before restore. Editable email image/background URLs follow reviewed exact-file target mappings. |
| Accounts and third-party objects | Treat source WordPress user IDs, attachment IDs, course/product IDs and URLs as installation-local references. Same-site trust needs locally verified provenance; a source URL or matching email is insufficient. Cross-site restore needs reviewed target mappings. |
| Deliveries and integrations | Preserve durable delivery/idempotency evidence and mapping configuration. Restore executable work in a suspended state until destination identities and credentials are reconciled. Never replay sent email, publication or issuance merely because a backup was imported. |
| Ephemeral state and authority | Do not reinstate worker leases, maintenance owners, temporary import/restore workspaces, cached capabilities or release authority from an archive. A restore must not enable a renderer or domain payload that has not passed its current acceptance requirements. |

Use a new archive version with strict member names, duplicate-member rejection,
bounded compressed/uncompressed sizes, exact per-member hashes and an explicit
scope manifest. Integrity alone is not authenticity. Read-only analysis must
complete before selecting a restore mode or mapping any identities.

Stage data and immutable files under one owned restore journal. Database
commit, file publication and cleanup require checked receipts and recoverable
transitions; a process can stop between any two of them. Never overwrite changed
ordinary files or delete content without proven restore ownership. Manual and
scheduled backups must share the same snapshot implementation and scope.

Acceptance requires mixed-store/manual/scheduled parity, unchanged full graph
and referenced-file hashes after same-site recovery, fresh-site mapping,
forged/colliding account references, missing/corrupt/traversing/duplicate/oversized
members, interrupted snapshot and restore, rollback/resume, cleanup ownership,
and delivery/replay invariants. Test those behaviors against disposable real
WordPress/MySQL and filesystem state, with a fresh private recovery snapshot.
