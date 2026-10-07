# Staff certificate-request filters

Date: 3 October 2026. Product implementation checkpoint, no release approval.

The organization can filter Certificate requests by Program, responsible actor, missing information, approvals, connection/setup review, trusted qualification/results and received age. Customer-owned details remain separate from staff approvals and app results. Filtering cannot approve, qualify, recheck or issue a request.

## Read and interaction contracts

- Program/status pages use the existing `program_status` index. All-status Program views merge separately limited status branches before a small final sort. Existing global recent/status cursor order remains compatible.
- Actor/task/age predicates inspect at most four bounded pages (200 requests) per call. Sparse results preserve the exact last-inspected cursor, even when there are no matches; the screen offers **Check more activity**. It never labels a partly checked queue as fully empty or runs an unbounded JSON scan/count to fill a page.
- Age uses one UTC boundary across continuation pages; stale/future/malformed boundaries and mixed cursor orders are rejected. Fresh filter selections reset the cursor. Failed transport restores the visible selection associated with the retained results.
- Program choices use private 50-row primary-key pages, including inactive Programs. **Load more Programs** reaches the rest without loading credential counts or customer identities.
- Ownership comes from the current field policy and blocker context. Restore/configuration holds need the organization, and unqualified requests need the trusted source even before field blockers exist. Issued/cancelled requests do not become active tasks.
- All GETs enforce organization capabilities, return private/no-store/noindex headers and leave business state, jobs and options unchanged, including when schema health is stale. Management-only staff gain no issuance permission.
- The responsive controls retain native labels/selects, keyboard focus after continuation, escaped labels and existing reduced-motion styling. Malformed response models fail closed without discarding retained results.

## Observed evidence

Local WordPress 7.1.2/MySQL 8.4.0, PHP 8.5.1/8.2.29.

- 121 guarded live checks pass per runtime: `output/program-recipient-20261003/staff-filters-accepted-php85.json` and `staff-filters-accepted-php82.json`. This includes existing request/health/repair behavior, private and denied GETs, sparse searches across more than 200 records, exact continuation, Program/lifecycle ordering, all Program/status query plans, ownership/task/age checks, uncomputed restore/qualification states, rejected filter expansions and choices beyond fifty.
- MySQL EXPLAIN confirms each bounded Program/status branch uses `program_status`; the existing global due view still uses `work_due` without filesort.
- Checked rollback preserves tracked business/core rows, Certloom configuration and current cron. There are no Certloom diagnostics in the final live runs; installed Elementor emits its existing PHP 8.5 bootstrap deprecation.
- Twenty focused standalone executions pass without diagnostics across both runtimes in `output/program-recipient-20261003/staff-filters-pure`. The production JavaScript parses successfully with Node.
- Lance loads all four additional labeled selects and eleven Program choices, finishes loading without feedback errors, and has no overflow at 1536px. Its page reports hidden, so this is DOM evidence only. Independent Program-choice, health and queue GETs now start together: the observed starts differ by two milliseconds, rather than requiring three sequential responses. Evidence: `output/playwright/staff-filters-parallel-read-20261003.log`.

## Remaining acceptance

Visible Lance desktop/mobile, native keyboard refresh, failed-filter rollback and empty age views now pass ten explicit checks in `output/playwright/staff-filters-native-20261003.log`. Original business/configuration/account/page state is preserved after exact owned fixture cleanup. See the [native customer/staff checkpoint](CERTLOOM-NATIVE-CUSTOMER-AND-STAFF-FLOW-2026-10-03.md). Sparse native continuation, the full widget matrix, reminders, completion/cancellation notices, request expiry, actual canonical worker/approval/cancellation concurrency and coherent whole-state recovery remain open in the [master plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md). LearnDash installation remains deferred.
