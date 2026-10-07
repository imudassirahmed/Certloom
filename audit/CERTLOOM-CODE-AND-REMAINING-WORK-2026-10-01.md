# Certloom code audit and remaining work — 1 October 2026

This pass fixed four demonstrated reliability defects: concurrent Designer
checkpoint ownership, stale issuance reviews, stale picker requests, and
cross-PHP renderer geometry. The standalone regression matrix has no unresolved
failures after the affected renderer suites were rechecked. Real WordPress/MySQL
checks preserved existing data. All 42 built-in certificate designs produced
deterministic real PDF/PNG/JPEG output and passed supplementary QR checks.

The remaining work is **not complete**. Portable whole-state backup/recovery and
the broader font artifact contract remain engineering work. Playwright now
connects through the authorized Lance session. The Home/manual-issuance journey
has fresh desktop/mobile evidence and three UI repairs, documented in the
[Lance issuance audit](CERTLOOM-LANCE-ISSUANCE-UX-2026-10-01.md). Full-product UX
and accessibility acceptance remain open. Provider and immutable release acceptance also remain
open. The earlier September checkpoint packages are stale after production
changes; this report does not approve or publish a release.

## Workspace and scope

- Authoritative source: `C:\Projects\Certloom WP\Certloom`, existing HEAD
  `d4b0f21`, version `1.7.0-alpha.4.87`. The existing staged and unstaged work was
  preserved. No branch change, alternate source checkout, commit, push, version
  bump, copied plugin or release installation was performed.
- Real-host checks used only the disposable Local site
  `C:\Users\Admin\Local Sites\ecapla-test-wp-site\app\public`,
  `http://ecapla.local`, database `local`, MySQL port `10006`:
  WordPress 7.1.2, PHP 8.5.1 and MySQL 8.4.0. The plugin junction resolves to the
  authoritative workspace. Standalone suites also ran on PHP 8.2.29.
- A fresh SQL snapshot was stored outside the plugin/webroot at
  `C:\Users\Admin\AppData\Local\Temp\certloom-audit-cab104c290b7928af256\before-designer-tests.sql`.
  It contains 2,093,151 bytes; SHA-256
  `e05d9d5cdd0c87daea2d2f965b60c0a6c26b5b865804ef9cd28531c690769499`.
  Temporary database credential configuration was removed without printing it.
- Existing immutable-media, visual-privacy, draft-CAS and strict-backup fixes
  were reviewed/rechecked, rather than attributed to this pass as new work.
  No third-party plugin source was edited and no external delivery was sent.

## Demonstrated defects and repairs

| ID / severity | Trigger and original behavior | Repair | Verification |
| --- | --- | --- | --- |
| CODE-01 / P1 | Two Designer sessions could both observe an empty user-meta slot, both claim success and overwrite the recovery owner. Cleanup had the same check/write window. | Serialize save, editor preparation, discard and retirement with a database/site/template/user-scoped MySQL session lock. Clear metadata caches within the ownership boundary, prevent reconnect replay, verify lock release, and preserve a ready checkpoint when retirement intent is stale. Busy autosaves use the existing timed retry. | The overlapping-session fixture failed before repair. It now passes 48 checks. A separate real WordPress/MySQL worker proves contention exclusion and durable session ownership; 12 checks pass with transaction rollback and unchanged original data. |
| CODE-02 / P1 | Editing fields while Review was in flight let an earlier review response appear current against the edited form. | Bind success and failure handling to the request's captured form signature. Changed forms invalidate the old review and show the existing review-again message. Ignore duplicate review starts while busy. | Production request-handler functions execute under controlled deferred responses; stale success, duplicate request and current-response scenarios pass. Server-side issuance review enforcement remains in place. |
| CODE-03 / P2 | Picker requests remained active until the next debounce elapsed. An old response could temporarily replace newer search input; removing Program could leave a Batch request active. | Cancel the pending timer and active request immediately on input, including the no-Program branch. Apply success/error results only for the current, non-aborted controller. | Eleven combined review/picker behavioral checks pass, including stale review errors, cancelled lookup errors, delayed cancelled responses and Program removal. These are JavaScript behavior tests, not browser acceptance. |
| CODE-04 / P1 | PHP 8.2 rounded three transformed primitive coordinates differently from PHP 8.5. The resulting render-plan hash failed unchanged frozen-reference checks. | Format transformed corner coordinates at twelve decimal places with locale-independent formatting. Keep the existing normalization for unrelated values, including QR geometry. | The seven reference fixtures and eleven acceptance plans retain their frozen values. Twenty affected renderer suites pass on each runtime: 40 executions, no failures. Golden files, expected hashes and verifier thresholds were not changed. |

Primary code: [Designer recovery](../src/Infrastructure/Admin/TemplateDesignerAutosave.php),
[Designer retry](../admin/assets/designer.js),
[issuance handlers](../admin/assets/issue-credential.js),
[render-plan geometry](../src/Application/Rendering/RenderPlanBuilder.php).

## Regression reproducibility

Several source-contract fixtures depended on deleted, ignored documentation or
old markup/copy. They now use the tracked
[acceptance contract](../tests/acceptance-contract.md) and current source
structure. The UX milestone is explicitly an acceptance target, not an approved
certification. Missing docs no longer generate false product failures. Behavioral
oracles, frozen renderer inputs and acceptance thresholds remain unchanged.

The new [standalone runner](../tests/run-suite.py) uses argument-vector processes,
supports multiple PHP executables/CLI configurations, records skips and
diagnostics, limits each suite and removes real-site integration variables.
[Usage and coverage boundaries](../tests/README.md) are tracked. It deliberately
excludes the two authorized-release suites that create complete plugin copies;
their exclusion is visible in the results.

The POT was regenerated from current production source and its SHA-256 refreshed
in the language-pack manifest and candidate fixtures. This does not claim human
translation review or locale acceptance.

## Evidence and practical limits

| Check | Result / evidence |
| --- | --- |
| Broad standalone matrix | 269 applicable PHP suites on each runtime plus one JavaScript suite. Initially 536 passed, three PHP 8.2 renderer suites failed and four source-copy results were excluded. `output/audit-20261001/verified-full-suite/` preserves that history. |
| Renderer repair recheck | All 40 affected suite/runtime executions pass in `verified-rendering-after-precision-fix/`. Replacing those matrix entries and including the final checks gives 539 passing applicable results, no unresolved failures and four excluded results. The entire broad matrix was not rerun after this narrow fix. `resolved-suite-checkpoint.json` retains the original failures and points each result to its transcript. |
| Platform boundaries | The broad run contains 17 skip-bearing executions; the resolved checkpoint contains 18. Both have no diagnostic-bearing executions. Unavailable symlink/POSIX-mode probes are skips, not successful platform tests. Read individual transcripts before claiming cross-platform acceptance. |
| Final changed-function and localization checks | Nine executions pass, no skips or diagnostics, in `final-change-checks/`: Designer ownership, issuance request races, POT determinism, language-pack quality and locale contracts. The expanded 11-check request-race fixture also passes in `final-request-failure-checks/`. |
| Final Lance issuance/UI/localization checks | Thirty executions pass on final UI bytes: fourteen PHP suites on each runtime and two JS suites. The two language-review runs each skip an unavailable symlink fixture; there are no failures or diagnostics. `lance-issuance-final-checks/`. The initial UI run retained two failures from the old email-copy assertion; its corrected contract and full targeted rerun pass. |
| Syntax | 1,353 PHP files parse on each runtime, 26 JavaScript files parse and 109 Python files parse, with no failures. Final tracked/new-file results are recorded in `syntax-current.json`. This checks syntax, not behavior. |
| Real WordPress services | Designer concurrency 12, admin draft concurrency 10, issued-media integrity/privacy 26 and account-linked restore guard 55: 103 passing checks across four suites. The probes preserve existing rows/assets and retain the account-link refusal. Logs are under `output/audit-20261001/`. |
| Real built-in template rendering | 591 runtime checks pass across 42 designs, 252 A/B renders and 42 very-long-name PNGs. Each design has deterministic PDF/PNG/JPEG bytes and hash-bound receipts. `output/playwright/certloom-current-review-20261001/results.json`. |
| Independent supplementary artifact checks | 42/42 PDFs have one page; 168/168 QR payload checks pass across rasterized PDF, PNG, JPEG and long-name PNG. The verifier validates retained file hashes before checking. `current-template-independent-review/verification.json`. This is not phone-camera, print, arbitrary-content or release acceptance. |

The [42-design review gallery](../output/audit-20261001/current-template-independent-review/index.html)
links actual synthetic PDF/PNG/JPEG samples. All seven standard PNG and all seven
long-name contact sheets were visually inspected, along with two PDF sheets.
No obvious clipping appeared at sheet scale. Long portrait names remain on one
line by shrinking substantially; preserving prominent readable names through a
reviewed wrap/minimum-size policy is a design follow-up, not a claim that the
current shrink policy loses content. Contact sheets do not replace full-size
human artifact review.

Final Local inspection records 93 tables, seven account-linked recipients, no
legacy rows, no custom-font test families, no maintenance file or pending restore
journal/build/owner, and one watchdog plus one analytics-pruning event. Certloom,
Elementor, Tutor and WooCommerce remain active. Production renderer authority
remains absent. Evidence: `final-local-state-current.json`.

WordPress boot emits one Elementor PHP 8.5 implicit-nullability deprecation from
its own source. Standalone Certloom transcripts have no diagnostics; the
third-party warning remains visible in live logs. PHP 7.4, the lowest supported
WordPress version, multisite and the full platform/database matrix were not
accepted in this pass. No comprehensive security or accessibility certification
is inferred from fixture counts or source inspection.

## Remaining work, in order

Public UI continuation, 2 October: the [public certificate and verification audit](CERTLOOM-PUBLIC-CERTIFICATE-UX-2026-10-02.md) now covers global Left/Right/Center layouts, widget/block result replacement, share stacking, mobile actions, privacy/status behavior, print emulation and verification timestamps. Its final checkpoint passed 56 relevant PHP suite executions and 39 grouped Lance browser assertions. The engineering, provider and full-product acceptance priorities below remain open.

Admin and Pro continuation, 2 October: the [back-panel and Verification Studio audit](CERTLOOM-ADMIN-AND-PRO-STUDIO-2026-10-02.md) records five profile-based presets plus Custom for Elementor and the native block, responsive styling, safe downgrade behavior, Settings save feedback and email-preview focus repairs. The final checkpoint passes 46 PHP suite executions, 30 syntax checks, 90 focused browser assertions and 23 initial desktop/mobile screen checks. These counts are separate from the previous public phase. The commercial licensing provider and complete task-state acceptance remain open.

All-widget and setup continuation, 2 October: the [latest flow audit](CERTLOOM-ALL-WIDGETS-AND-GUIDED-SETUP-2026-10-02.md) restores Profile, Organization, Fields, Certificate, Program, Email and Review before optional issuance. All four block types and eight Elementor widgets have editor/frontend coverage, including six standard styles and Pro presets/Custom. Final initial-screen checks and screenshots cover all 23 admin screens on desktop/mobile. Optional Grade, scoped SVG/ARIA references, inert block previews, mobile targets and header notice placement are repaired. Focused validation passes on PHP 8.2/8.5 and guarded cleanup preserves original business rows/settings. Actual issuance/receipt, complete Designer/import/recovery states and the release/platform priorities below remain open.

| Priority / status | Work and why it remains | Concrete next implementation / acceptance |
| --- | --- | --- |
| P0 / engineering open | Portable whole-state recovery. Legacy manual/scheduled backups and the native fourteen-entity/three-configuration archive remain separate partial formats. Assets/fonts, durable delivery/replay state, integration mappings and trustworthy local references are not coherently represented. | Implement the versioned [whole-state contract](../tests/acceptance-contract.md#whole-state-recovery-implementation-target): common manual/scheduled snapshot, exact referenced bytes, reviewed account/object mapping and a recoverable DB/file journal. Keep unverified account links refused. Test mixed stores, same/fresh-site recovery, forged/colliding links, rollback/resume and non-replayed deliveries. The earlier SQL/files rehearsal is useful recovery evidence, not the missing product implementation. |
| P1 / integration open | Commercial Pro license provider. Verification Studio is implemented, tested with licensed/disabled adapters, and defaults closed. The repository has no real commercial license SDK/provider. | Connect the trusted Pro companion to the [strict entitlement contract](CERTLOOM-PRO-VERIFICATION-STUDIO-CONTRACT.md); verify activation/expiry/revocation/offline behavior and cache invalidation with the actual provider. Keep new Pro insertion unavailable until entitlement is validated; retain standard lookup on saved pages after downgrade. |
| P1 / engineering open | Custom/system fonts, italics and additional weights remain outside accepted PDF/PNG/JPEG output. Authoring font upload/picker repairs do not expand the frozen artifact contract. | Add exact per-snapshot face resolution, immutable font bytes, glyph/style checks and an independent multilingual/weight/style corpus. Freeze and review the resulting renderer candidates before enabling delivery. |
| P1 / acceptance open | Production PDF/PNG/JPEG and normalized-domain release authority. Current tests use explicit fixture seams; there is no installed approved immutable authority. Earlier package fingerprints no longer describe current bytes. | After remaining production/UI changes, rerun the applicable exact-payload database gates and migration/restore/cutover sequence, prepare fresh renderer candidates/package, and obtain the required independent artifact review. Keep production gates closed meanwhile. |
| P1 / external access pending | Real Credly, Coursera and LearnDash acceptance. Local contract tests cannot prove live provider credentials, courses, callbacks or publication behavior. | Use the requested provider environments when access arrives; exercise success, duplicate/replay, failure, revocation and retry with exact mapping/identity checks. Do not resubmit existing support requests or send external data without authorization. |
| P2 / partial browser coverage | The user explicitly requested Playwright with Lance. The extension connection now works and the authenticated Home/manual-issuance path reaches server Review on desktop/mobile. Duplicate-recipient labels, manual-email summaries and field-error recovery were repaired and rechecked. | Continue the [fresh screen audit](CERTLOOM-LANCE-ISSUANCE-UX-2026-10-01.md): actual issuance/receipt, creation, Program/Batch inheritance, Designer, import/recovery, public verification and remaining product screens. No final credential or email was issued in this browser pass. Native interaction/accessibility and full-product acceptance remain open. |
| P2 / coverage open | Native zoom, screen reader, RTL/mixed direction, forced colors, reduced motion, narrow-height and role variants across the whole product; minimum PHP/WordPress, multisite and broader DB/OS behavior. | Test the actual environments and report unavailable probes separately. Do not equate emulated viewport changes, syntax checks or source assertions with those acceptance results. |

## Customer-flow direction and next screen matrix

Program and recipient planning continuation, 2 October: the [approved implementation plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) maps the customer direction to current architecture and defines Program-first setup, profile starter designs, recipient-editable details, trusted results, approvals, durable waiting requests, integration field mapping and shared bulk readiness. It includes phased implementation and acceptance checklists. These are planned extensions; earlier audits remain evidence for the existing code and the release priorities above remain open.

The code repairs already improve continuity: late requests cannot undo a newer
choice, Review prompts for current edits, and competing Designer tabs cannot
silently replace recovery ownership. A larger visual redesign needs current
screen evidence across the remaining product. The Lance issuance audit now
supports the shortest-path, identity, manual-email and field-error observations.
The broader directions below remain targets for the next product pass:

- Make the shortest first-credential journey read as Recipient → Essentials →
  Review & issue. Preserve the existing early Review path and expose optional
  Program/Batch, artwork and email choices progressively.
- State inherited defaults and one-off changes in customer language. Show where
  a value comes from and what editing it affects before the customer changes it.
- Give completed issuance one clear receipt with View credential, Copy link and
  Issue another. Preserve useful context without silently reusing identity or
  delivery decisions.
- Use consistent field errors, focused recovery actions and retry states across
  setup, import, Designer and issuance. Test preservation of edits under failure
  before adding animation or decorative polish.
- Keep long recipient names prominent in certificate templates through a
  reviewed layout policy; test real raster/PDF output and newly frozen receipts
  after any typography changes.

| Order | Screen / task | Required states and assertions |
| --- | --- | --- |
| 1 | Dashboard and first-time setup | Empty/existing site, progress/resume, role access, save failure, stale-tab protection and a clear next task. |
| 2 | Recipient creation and search | New/existing recipient, duplicate/ambiguous identity, keyboard search, delayed/out-of-order results, cancelled requests and recoverable validation. |
| 3 | Program and Batch context | Optional context, inherited values, one-off changes, removal/change during search and unavailable choices. |
| 4 | Credential essentials | Required fields, date/number rules, hidden/private fields, custom fields, backtracking and draft continuity. |
| 5 | Artwork and Designer | Preset preview, media identity, supported/unsupported fonts, long names, recovery ownership, stale revision, multi-tab and manual save. |
| 6 | Email choices and Review | Clear prepare-later/review-next manual choice and separate automation disclosure, inherited templates, current field summary, editing during in-flight Review, request failure and duplicate action prevention. |
| 7 | Issue and receipt | Exact reviewed payload, duplicate retries, partial delivery/error recovery, clear completion actions and preserved numbering/history. |
| 8 | Bulk import and data recovery | Mapping, bounded review, partial failures, actual backup scope, mode/identity confirmation and interruption/resume. Whole-state restore acceptance waits for its implementation. |
| 9 | Public verification and recipient portal | Found/not-found/hidden/revoked/expired states, anonymous privacy ceiling, account ownership and historical artifact integrity. |
| 10 | Settings, integrations and lifecycle | Save/retry, connection failure, secret masking, roles, retain/delete-data disclosure, keyboard focus and recovery choices. |

For each task, capture the relevant initial, busy, error and success states on
desktop and narrow layouts. Exercise keyboard navigation, native zoom and role
variants where available. Record task success and evidence for each state,
separately from remaining or blocked coverage.
