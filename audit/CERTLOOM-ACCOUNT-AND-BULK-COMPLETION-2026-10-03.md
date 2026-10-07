# Account, naming and bulk completion checkpoint

Date: 3 October 2026. Product implementation remains in progress. Release validation and LearnDash acceptance follow separately.

## Implemented behavior

- Staff explicitly review exact WordPress account links and unlinking. Account deletion holds future execution; issued Versions remain immutable. A bounded private link history and full Recipient revision prevent stale commands and link/unlink/link races.
- Certificate names follow either the account identity or one eligible approved personal field, selected once in Settings. The latter becomes a Global requirement for future issuance. Field retirement and policy downgrades cannot silently invalidate it.
- Customer accounts distinguish in-progress requests from closed history. An exact owned waiting request opens its Program-specific completion form, including personal details collected just before issuance. General profile revisions, another customer, and sibling enrollments cannot authorize that edit. Staff and app results never appear as customer inputs.
- Program CSV downloads list staff-owned input columns and exact enrollment identifiers when needed. Managed personal/enrollment values, Program defaults and source results cannot be replaced in a CSV. The default stops incomplete rows; an explicit organization qualification confirmation can instead create waiting requests for missing customer answers or approval.
- Mixed durable commits issue ready rows and accept qualified waiting rows independently. Interrupted cursors reconcile the original request or immutable issuance receipt. Leading-zero identifiers and a valid zero result survive the final snapshot.
- Signer and published-design changes retain checked transactional configuration signals. Failed or zero-row outbox writes roll back the configuration edit.
- WordPress contextual Help and the recipient workflow guide explain actor ownership, triggering, source fetches and bulk waiting. Help is implemented; full visual acceptance is still being completed.

## Accepted local evidence

Environment: authorized Local `ecapla.local`, WordPress 7.1.2, MySQL 8.4.0; PHP 8.5.1 and 8.2.29. External HTTP and mail are disabled in the rollback probes. The installed Elementor nullable-parameter deprecation is external to Certloom.

- Account/name probe: 40 checks per runtime, `output/program-recipient-20261003/recipient-account-name-php85-guards.json` and `recipient-account-name-php82-guards.json`.
- Configuration writers: 103 checks per runtime, `design-signer-reassessment-php85.json` and `design-signer-reassessment-php82.json` in the same evidence directory.
- Actual CSV/private-file/durable JobRunner/customer completion journey: 42 checks per runtime, `bulk-mixed-enrollment-php85-accepted.json` and `bulk-mixed-enrollment-php82-accepted.json`. Both accepted runs preserve all tracked rows, Certloom options and cron, and remove only owned private files. The final reports list no changed tables or option names.
- Focused pure regressions: 20 executions in `bulk-account-final-pure` and 18 in `collected-values-final-pure`; no failures, skips or diagnostics in these suites.
- Lance: the authenticated header-only Program CSV response is HTTP 200, one header row, with the expected CSV download headers. Bulk policy controls fit at 390 px. Evidence: `output/playwright/bulk-source-controls-20261003-final.txt` and `bulk-source-mobile-20261003.png`. Chrome blocks the native download on this HTTP Local origin; that browser policy is separate from the accepted CSV endpoint response.

One earlier failed mixed probe reported a preservation mismatch while browser navigation was also occurring. Its cause is not proven. Subsequent probes run without concurrent browser activity include per-table/per-option diagnostics and preserve tracked state exactly. No full database restore was performed.

## Remaining implementation and acceptance

The later [native customer and staff checkpoint](CERTLOOM-NATIVE-CUSTOMER-AND-STAFF-FLOW-2026-10-03.md) accepts sixteen actual customer/profile/Woo account checks and ten staff filter interaction checks in visible Lance. Failed/stale saves, source-owned results, Enter/composition behavior, customer permissions, keyboard/mobile/reduced-motion and exact fixture cleanup are covered. Native CSV commit and the full widget matrix remain open.

Later checkpoints on 3 October accept mapped signed version 2 app intake and visible contextual Help, followed by bounded scheduling diagnostics and repair. See [signed app evidence](CERTLOOM-SIGNED-APP-INTAKE-2026-10-03.md) and [health evidence](CERTLOOM-PENDING-WORKFLOW-HEALTH-2026-10-03.md) for the current state; the remaining list below records the earlier checkpoint.

Signed version 2 app intake and current mapping authority, supported first-party pending adapters, bounded reminders/diagnostics, coherent whole-state recovery and reconciliation, and the remaining screen/account/concurrency journeys remain open. The wider release renderer/domain/commercial/platform gates are not granted by these local checks. LearnDash remains deferred.
