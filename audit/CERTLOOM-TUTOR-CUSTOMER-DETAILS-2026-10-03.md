# Tutor completion and customer details

Date: 3 October 2026. Product implementation checkpoint; no release approval or LearnDash acceptance.

Tutor now has an explicit, separate customer-detail connection. The existing completion-only flow retains its meaning. An actual Tutor completion creates stable pending intent and a server-owned registration for the exact linked learner. The learner supplies enabled personal/enrollment information on the organization's website; staff supply approvals. All issuance still uses the canonical pending/manual services and immutable credential Versions.

## Authority and consistency

- Current stored Tutor completion is read directly, with transaction locks at final issuance. Missing completion never falls back to the current time. Its private completion token is hashed before computing the stored evidence digest.
- Stable event identity binds course and WordPress user independently of connection changes; original mapping authority stays separate, and the saved reference also binds the original completion record and its proof. An unrelated account, enrollment, replacement completion or mapping cannot authorize the request.
- Complete Global → Issuer → Program → Batch requirements and design compatibility apply. Completion alone supplies no arbitrary assessment results. Unsupported required sources fail closed during configuration and execution.
- One course uses one active flow. Switching a previously issued completion to pending cannot create another request, number or certificate. Restored work stays held, and late provider replay reconciles the original receipt.
- Mapping create/pause/activate/remove uses strict whole-option validation, optimistic revisions, shared configuration locks, checked database persistence and an atomic reassessment outbox. The supported completion-only save/remove commands also share those locks and rollback their generation/outbox/cron changes together. Removing an absent legacy mapping is a no-op; orphaned pending connections can be paused/removed safely.
- Canonical field-provider account locks follow account → Recipient order and recheck the exact link, matching the account-link/deletion commands.

## Observed evidence

Local WordPress 7.1.2, MySQL 8.4.0, actual installed Tutor 4.0.9; PHP 8.5.1 and 8.2.29.

- 57 guarded live checks pass per runtime: actual Tutor `CourseModel::mark_course_as_completed` hook, missing qualification, waiting details, cross-account denial, protected results, approval, completion removal between review and commit, paused mapping, durable JobRunner issuance, exact leading-zero values, immutable replay, legacy-flow preservation, switch-over duplicate prevention, restored holds and orphan repair. Eight additional legacy save/remove SQL-error/zero-row cases prove option/outbox rollback. All tracked business/core rows, Certloom options and cron are preserved by checked rollback; test email and external HTTP are disabled.
- Final artifacts: `output/program-recipient-20261003/tutor-pending-mapping-final-php85.json` and `tutor-pending-mapping-final-php82.json`.
- 18 focused pure executions pass across both runtimes without diagnostics: `output/program-recipient-20261003/tutor-mapping-final-pure`.
- Account/bulk/signed-app regressions pass 40/42/64 checks per runtime. Staff requests pass 92 checks per runtime. The staff regression exposed a health-cache refresh on GET; its permission path now verifies stale schema health without persisting the marker. Acceptance explicitly removes the marker and proves all successful/forbidden GETs leave tracked state untouched.
- The installed Elementor emits its own PHP 8.5 nullable-parameter deprecation during WordPress bootstrap; this is outside the changed Certloom code.

## Connection-switch hardening

The final native-event identity is independent of connection keys. Removing/recreating a connection for another Program cannot create another enrollment, intent, number or award. Cancelled requests retain the event identity. The completion-only canonical service checks for existing pending intent both before allocation and again inside the locked transaction using a current `FOR UPDATE` read, including Programs with no managed collection fields. Existing v1 integration event hashes and immutable Versions remain unchanged; older adapter-wide receipts also fence pending intake.

Final actual Tutor acceptance passes 62 checks per PHP runtime: `output/program-recipient-20261003/completion-replay-tutor-php85.json` and `completion-replay-tutor-php82.json`, with all tracked state preserved. Twenty-four focused executions pass across both runtimes without diagnostics in `completion-replay-pure`. WooCommerce is now implemented separately; see its [checkpoint](CERTLOOM-WOOCOMMERCE-CUSTOMER-DETAILS-2026-10-03.md).

## Remaining acceptance

Native Tutor connection controls and the full ordinary-subscriber browser journey are not yet accepted. Lance is connected but the task tab intermittently reports hidden; neither a hidden-tab attempt nor fixture staging counts as browser acceptance. The second owned subscriber fixture was removed with original business/configuration/accounts/pages and current unrelated metadata/cron progress preserved; no certificate or delivery was created.

Other first-party pending adapters, advanced staff filters, reminders/expiry, whole-state recovery, actual worker/approval/cancellation concurrency and remaining widget/screen acceptance remain open. LearnDash installation is deferred as requested. See the [master plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) and [organization/customer guide](../guides/recipient-details-and-certificate-requests.md).
