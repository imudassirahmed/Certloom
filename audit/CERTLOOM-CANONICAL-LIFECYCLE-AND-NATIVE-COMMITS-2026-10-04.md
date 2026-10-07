# Canonical issuance, request lifecycle and native commits

Updated 8 October 2026: Canonical/lifecycle concurrency, reminders/expiry, native bulk/app commits and current widget/recipient/staff engineering coverage are complete. See the [current completion report and evidence](CERTLOOM-REMAINING-WORK-CLOSURE-2026-10-08.md). The dated sections below are historical checkpoints; their earlier open-work statements are superseded for this scope. Release validation and installed LearnDash remain separate.

Implementation acceptance, 4 October 2026. Release validation and LearnDash remain separate.

## Current command reads and canonical races

Recipient commands lock configuration before recipient, enrollment and request state. Final review uses current locked reads after a competing transaction commits; ordinary GETs retain their behavior. This prevents an older InnoDB snapshot from authorizing issuance with superseded answers, approval, account links or Program requirements. Later edits preserve immutable issued history.

The real-MySQL matrix covers 21 command orderings: pending issue/issue, cancellation, answer/approval, eligibility, Program activity, account unlink, field policy and requirements, plus ordinary reviewed manual issuance versus answers/policy/requirements. Every ordering proves distinct connections and an actual InnoDB lock wait. **96 checks pass on PHP 8.2.29 and 8.5.1**, recorded in `output/program-recipient-20261004/canonical-concurrency-manual-php82.json` and `canonical-concurrency-manual-php85.json`. All 36 exact disposable tables are removed; original business data and ordinary site option progress are preserved. Blank-destination fixtures queue no customer mail.

## Reminders and expiry

Opt-in policy, activation cutoffs, daily recipient limits, bounded ten-request sweeps, append-only notice evidence and final expiry checks are implemented. Transport claims commit before sending. Failed/uncertain attempts do not automatically resend. Expiry first reconciles a canonical receipt and preserves answers/history; restored requests remain paused. Notice commands reread current configuration, account/request state and exact recipient email under their transaction.

**112 guarded live checks pass per runtime**, including intercepted transport, terminal activation cutoffs, interrupted canonical receipt reconciliation, all 31 fixed durable option writers, exact scalar/leading-zero storage, maintenance refusal and namespace/size bounds. Evidence: `lifecycle-expanded-corrected-php82.json` and `lifecycle-expanded-php85.json` in the same output directory. Original tracked rows/options are unchanged after rollback. Four test messages per run are intercepted; none is sent externally.

## Native Lance commits

Actual native CSV upload, review, explicit commit and scheduled worker execution produced two canonical test Versions. The completion receipt exposed and fixed an obsolete credential-screen slug in `BulkIssuanceAdmin`; four settled receipt checks pass. Actual request-policy POST/reload/narrow-layout checks pass five assertions. App create, one-time display consumption, source-field mapping changes, pause/re-enable and settled reload pass eight assertions. SQL evidence proves rotation changes the signing secret while retaining the exact Program/mapping; no secret values are exported.

A confirm-dialog browser call returned an already-handled dialog error and is not counted as an accepted browser assertion. Subsequent browser/SQL inspection independently confirms zero owned QA connections and no remaining one-time display. Cleanup verifies original business rows and ordinary accounts/pages, restores original fields/settings/mappings, removes both test Versions and owned rows/jobs, and removes the private journal. Unrelated cron, user metadata, schema-health and disposable watchdog progress are preserved. Evidence: `native-bulk-app-final-inspect.json`, `native-bulk-app-restored.json`, native bulk/app/policy logs under `output/playwright/`, and `native-app-rotation-after.json`.

Earlier commits used actual DOM form activation while Chrome reported hidden. They do not establish pointer/keyboard/screenshot acceptance. Lance reattachment now reports **visible**; current visual, widget/editor/role and native customer source journeys remain to be checked.

## Still required

Finish application of reviewed account/object mappings, complete semantic configuration/graph review, recoverable full database/file restore, native recovery controls and actual manual/scheduled parity. Refresh every widget/editor surface, multiple-instance/private-field/error paths and current visible customer journeys. These checks do not grant renderer, commercial-provider, platform or release acceptance.

### Current command regressions, 7 October

`pending-lifecycle-current-php82.json` and `pending-lifecycle-current-php85.json` each pass **112 checks** with unchanged tracked original state. `canonical-concurrency-current-php82.json` and `canonical-concurrency-current-php85.json` each pass **96 independent-worker checks** after native job creation joined the maintenance write fence. Both preserve the original business/core graph and remove their disposable tables. The PHP 8.5 run preserves ordinary cron, Action Scheduler, WordPress cache and Elementor diagnostic progress rather than rewinding those options. Earlier native bulk/app receipts remain recorded above; current visible screen/customer/widget refresh is still required.

## Canonical failure and lifecycle worker expansion

The new real SQL fault matrix exercises fourteen manual/pending mutation points under both SQL error and successful zero-row write: Series, number reservation, Version, first-Version pointer, current-Version pointer, audit event and final request receipt/event. **129 checks pass per runtime** in `canonical-issuance-faults-php82.json` and `canonical-issuance-faults-php85.json`. Every failed attempt preserves the canonical graph/jobs/deliveries; retry creates one valid issuance and pending replay returns its same receipt. Exact first/current pointers are checked in SQL. Legitimate re-selection of an already persisted current Version remains idempotent. Those first runs contain a duplicate test bootstrap constant warning; the guard is corrected and clean reruns remain due.

Persistence now requires one actual inserted row and a positive ID. A zero pointer update must independently prove the exact stored pointer; it cannot simply report success. This closes the zero-write gap without changing canonical identity or immutable snapshots.

`lifecycle-concurrency-expanded-php85.json` passes **53 independent-worker checks across eleven orderings**: expiry/process/expiry, current policy disable, queue duplication, two requests competing for one recipient's daily limit, overlapping committed transport attempts and overdue reconciliation of an already committed canonical Version. Actual InnoDB lock waits establish concurrency. The transport barrier occurs after the real sending claim COMMIT; only one intercepted mail attempt occurs and both competing workers preserve uncertain evidence. All 36 owned tables/private signals/journals are removed and original business rows are preserved. Ordinary original option progress changed during that run and was intentionally not reset. Its duplicate bootstrap constant warning is corrected; clean final reruns on both runtimes remain due.
