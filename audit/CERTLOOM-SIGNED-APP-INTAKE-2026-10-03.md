# Signed app results checkpoint

Date: 3 October 2026. Product implementation continues; release validation and LearnDash are separate later stages.

## Implemented behavior

Version 2 has its own connection repository and REST namespace. Version 1 completion clients retain their existing payload and signing contract. Organization staff select a Program/optional Batch, map only applicable app-owned achievement fields, pause connections, edit mappings, rotate secrets and remove connections. Optional mappings can be removed and newly configured results can be added. Pausing/removing/rotating an orphaned connection remains possible; activation still requires a valid current target.

Authentication binds the version, POST method, exact namespace route, timestamp and exact UTF-8 body. REST uses matched URL parameters so query parameters cannot replace the signed connection or request identity. Intake is bounded to 32 KiB and 30 scalar result fields. Customer identity uses an exact existing Recipient External ID; enrollment details require the exact owned registration. No lookup by name/email and no guessed enrollment is permitted. Results preserve typed values, including zero, and immutable source revisions. Required Program values need the supported staff-owned shared-value provider.

Qualification and missing customer details enter the existing durable request service and canonical issuer. Customers receive only their permitted personal/enrollment form. They cannot supply results, approve themselves or qualify by completing a profile. Current source configuration and writing authority are checked again by canonical review and worker execution. Mapping changes hold the original request rather than remapping accepted evidence.

Signed `/test` receipts use canonical readiness without creating requests, registrations, jobs, numbers, Versions or delivery. Intake and evidence updates have separate replay identities. Exact accepted bytes reconcile the original receipt, including after issuance; changed raw bytes conflict even if normalized values match. New evidence cannot overwrite a closed request.

Connection secrets and temporary one-time setup secrets are encrypted. Customer access cannot expose or consume organization credentials. Organization list data omits encrypted credentials, and authorized setup display consumes the secret once. Configuration mutation, generation and reassessment outbox writes are atomic; SQL-error and zero-row failures roll them back.

## Accepted evidence

Authorized Local `ecapla.local`, WordPress 7.1.2, MySQL 8.4.0, PHP 8.5.1/8.2.29. Rollback probes disable external transport, email and their own WP cron. A verified private SQL snapshot is required by the harness; no full database restore was performed.

- 64 live checks per runtime: `output/program-recipient-20261003/webhook-v2-php85-route-accepted.json` and `webhook-v2-php82-route-accepted.json`. Includes real REST test/intake/evidence dispatch, missing customer data → submit → approve → canonical issuance, exact enrollment ownership, live writing-authority closure, immutable provenance, paused/deleted/changed mappings, rotation, replay conflicts, encrypted one-time display and eight outbox fault cases. All tracked rows/options/cron are preserved.
- 14 focused pure executions across both runtimes: `output/program-recipient-20261003/webhook-v2-final-pure`. The wire suite passes 36 checks per runtime, including the portable PHP request recipe on all three routes. No failures, skips or diagnostics.
- Subsequent account/bulk/staff regressions pass 40/42/73 live checks per runtime, respectively: `v2-regression-inert-run-recipient-account-links-php{85,82}.json`, `v2-regression-inert-run-bulk-recipient-sources-php{85,82}.json`, `v2-regression-inert-run-pending-request-admin-php{85,82}.json` in the same directory. Tracked state is preserved; owned bulk files are removed.
- Visible Lance native Help tab, Program selector and read-only form navigation pass. At 390 px, controls have no measured overflow; the lower create form was visually inspected. `output/playwright/webhook-v2-final-controls-20261003.txt`, `issuance-workflow-help-20261003.png`, `webhook-v2-mobile-fields-20261003.png`. No browser errors; existing JQMigrate warnings remain. This does not claim a native browser create/rotate/remove journey.
- User guidance: [signed app recipe](../guides/app-results-and-signed-webhooks-v2.md), [portable PHP builder](../examples/webhook-v2-request.php), and WordPress contextual Help.

An initial authority/configuration harness asserted repository behavior that belongs to the organization configuration guard, then attempted to edit a request still held by the injected authority closure. These test assumptions were corrected; both failed runs rolled back tracked state. An earlier account regression passed all 40 behavior checks but failed preservation while Lance was on Setup; the changed state was not attributed. After making the browser tab inert, all regressions preserve state exactly. The test harness now reports changed table/option names for future mismatches. Do not treat the earlier run as accepted or infer its cause.

The installed Elementor nullable-parameter deprecation on PHP 8.5 is external to Certloom and remains visible in live CLI diagnostics.

## Work still required

The new v2 source option is not yet included in the coherent recovery configuration inventory. Backup/restore must preserve exact source identity and replay history, pause restored execution, remap Program/Batch references during Merge, and require secret/account/source reconciliation before deliberate resumption. No recovery completeness is granted here. First-party pending adapters, scheduler diagnostics/reminders, actual subscriber browser transport, canonical concurrent-session races, and remaining widgets/screens remain open. Renderer/domain/commercial/platform release gates and LearnDash are deferred.
