# Staff pending-request operations — 3 October 2026

## Accepted scope

Implemented **Credentials → Certificate requests** for the organization, using private REST reads and exact-revision commands. This is additive to schema 20 and the existing internal manual pending executor. It does not grant an adapter new qualification or mapping authority and does not change issued snapshots. Version remains `1.7.0-alpha.4.87`; no release or commit was made.

The screen provides bounded status queues, recent activity, responsibility-labelled blockers, lazy paged history, explicit refresh, safe rechecking and inline confirmed cancellation. Closed cards suppress old actionable blockers while their underlying records/history remain intact. Restored requests display **Held after restore** and cannot be rechecked into execution. Anonymized recipient names are suppressed and actions are unavailable.

An inline **How a request becomes a certificate** guide distinguishes organization configuration, customer account/enrollment forms, staff approval and trusted qualification/results. The companion [user guide](../guides/recipient-details-and-certificate-requests.md) records the current account adapters, safe actions and remaining adapter/notification limits.

## Persistence and permission boundaries

- `PendingRequestAdminService` authorizes credential management independently of the REST controller. Recheck additionally requires issuance permission. Subscribers and logged-out callers cannot use the staff API.
- Commands acquire Recipient, then Request locks, a subset of the canonical worker’s order. They do not assess or issue and need no configuration locks; an inactive Program can still have unissued intent cancelled.
- Exact request revision is checked under lock. Original intake, evidence, evidence revision, source identity, mapping, restore authority and answers are not editable through these commands.
- A matching canonical receipt is checked before cancellation/recheck. Interrupted receipt state is reconciled to issued; it is not cancelled or issued again. Issued/cancelled intent stays terminal.
- Recheck moves permitted local internal-manual intent through waiting, discards the old review and persists a due hint. Current rules are re-evaluated by the existing durable worker. Unknown qualification stays held; not-eligible, restored, mapped and unsupported external sources cannot gain execution authority through this action.
- Request changes and append-only staff events share the checked transaction. Failed event/request writes and zero-row CAS updates roll back the row, event and generation without emitting a successful scheduling signal.
- Queue reads use explicit `work_due(status,next_attempt_at,id)` status pages or descending primary-key recent pages with 51-row lookahead. History uses `request_history(request_id,id)`. No OFFSET pagination or total-count scan was introduced. Real EXPLAIN of the actual joined status query confirms `work_due` and no filesort.
- JSON responses expose labels, notices and allowed actions. They exclude immutable intake, source references, mapping fingerprints, raw evidence/results and arbitrary event metadata. REST responses, including errors, are private/no-store/noindex. Healthy GETs make no business writes or execution jobs.

## Validation

`tests/wp-integration/run-pending-request-admin.php` is CLI-only, requires the exact Local root/site/database/port and a SHA-256-verified private SQL snapshot, suppresses external transport and disables cron in its own CLI request. Its fixtures run inside an intentionally rolled-back checked transaction.

Final authoritative results:

| Evidence | Result |
| --- | --- |
| `output/program-recipient-20261003/pending-request-admin-indexed-php85.json` | 73 checks pass, PHP 8.5.1 |
| `output/program-recipient-20261003/pending-request-admin-indexed-php82.json` | 73 checks pass, PHP 8.2.29 |
| `output/program-recipient-20261003/pending-request-admin-focused/` | 14 focused suite executions pass, zero diagnostics/skips |
| PHP lint | 16 checks pass across both runtimes, including the final Setup view |
| JavaScript syntax / targeted diff whitespace | Pass |

The live probe covers role separation at service and REST boundaries, healthy read nonmutation, private errors, strict JSON body shape, source/evidence preservation, old-review invalidation across five states, cancellation of inactive/held contexts, canonical interrupted-receipt reconciliation for both actions, immutable Version bytes, every failed/zero-row command write, request/history pagination past 50 rows, invalid cursors, anonymized recipients and absence of email delivery. All 23 business stores, users/usermeta/posts, Certloom-owned options and cron match their before-state after each rollback run.

An installed Elementor dependency emits its existing implicit-nullable deprecation on PHP 8.5 to stderr. Certloom’s JSON receipt remains valid and all checks pass; the PHP 8.2 run and focused suites have no such dependency diagnostic. This is not claimed as a whole-installed-plugin PHP 8.5 clean-diagnostic gate.

## Lance browser acceptance

Used only authorized session `certloom-lance` at `ecapla.local`. Owned browser fixtures had blank email, no WordPress-account claim, unknown qualification and a second restored hold. Real native page handlers sent nonce-authenticated REST requests.

Accepted checks:

- Empty/recent/status-filtered states and readable owner guidance.
- Actual competing CLI edit followed by HTTP 409 in the stale browser, actions disabled until a fresh GET, and safe action recovery.
- Actual HTTP 200 recheck and cancellation, focus moved to current status, no actions on the closed card and the real append-only staff history.
- Native keyboard Enter opens history. Keep-request focus is the initial safe confirmation focus and returns to Cancel request after dismissal.
- Desktop and 390px mobile layouts fit with no horizontal overflow. Reduced-motion mode removes shared button transitions.
- A real history GET was fully received, then deliberately delivered late after refresh; it cannot populate a refreshed card. The temporary fetch wrapper was restored in `finally`. This is a narrow read-race check, not the entire multi-tab/source/issuance race matrix.
- Final page reports zero console errors and four existing jQuery warnings. The deliberate 409 earlier produced an expected HTTP console error; final reload clears it.

Screenshots are under `output/playwright/20261003-pending-*.png`. Final presentation is `09-final-desktop` and `10-final-mobile`. Earlier cancelled screenshots retain the superseded old-blocker display. Full-page screenshots taken while scrolled can place WordPress’s fixed toolbar mid-artifact; final captures explicitly scroll to the top.

Attached-extension pointer `.click()` timed out on the refresh button’s stability wait. Native DOM click handlers were used for the remaining button actions; keyboard history Enter succeeded. This does not establish full pointer/assistive-technology acceptance.

After cleanup, the expanded inline guide was inspected on desktop/mobile (`11-help-desktop`, `12-help-mobile`), with zero overflow and zero remaining fixture cards. The attached guide Enter attempt did not open the disclosure; native summary click did. Full keyboard/assistive-technology coverage remains an open gate rather than being inferred from the earlier isolated history-keyboard result.

## Browser fixture cleanup and harness corrections

`output/program-recipient-20261003/pending-browser-cleanup.json` records removal of exactly one owned Recipient, one Program, three jobs, two requests and eight request events. No credential Version or delivery was created. All original Certloom business rows are preserved. The private journal was removed.

The strict cleanup check detected changed `wp_usermeta` during the browser run. The fixture never created/edited a WordPress user, account link or page. Narrow `restore-preserve-core` retained current core data and checked its hashes before/after cleanup; it did not restore old metadata. Current cron/retention progress was also preserved, with only proved-owned job events eligible for removal (zero remained).

The helper initially inherited the guided-fixture manifest filename. Its exact manifest was moved only after proving the private journal belonged to this new pending fixture; both manifest paths are absent after cleanup. The first late-response probe left the response body unread, so refresh correctly aborted its body read. The corrected probe consumes the actual response before delaying delivery and passes. Neither correction changed source authority or restored the site SQL dump.

## Still open

This completes the basic staff queue/history/recheck/cancel slice. Program/actor/age filters, scheduler diagnostics, reminders/expiry, recipient cancellation notices, certificate-name policy, reviewed linking/unlinking, the remaining configuration consumers, accepted current external/v2 source mappings, real mixed-CSV commits, subscriber browser transport, full block/Elementor/UI acceptance, true simultaneous canonical issue-once races and whole-state restore/multisite/minimum-platform/production artifact gates remain open.

LearnDash `Integrations/learndash-5.2.1.zip` remains inventoried and deferred. See the [master implementation plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) and [organization/recipient guide](../guides/recipient-details-and-certificate-requests.md). The whole plan is not complete or release-ready.
