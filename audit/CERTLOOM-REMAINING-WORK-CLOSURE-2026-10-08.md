# Remaining product work: completion and acceptance

Updated 8 October 2026. The requested pre-release engineering scope is complete: whole-state recovery, reminders and request expiry, canonical issuance concurrency, native bulk/app commits, and the current block/Elementor coverage. Release validation and the installed LearnDash acceptance stage remain separate, as requested.

This report supersedes the earlier “still open” checkpoints for this scope. Historical audits retain the evidence and limitations that applied when they were written.

## User review after this checkpoint

On 8 October, the user reported that some newer screens do not follow the established admin UI and that their processes feel too complicated. The functional engineering evidence below is not UX sign-off. A consistency and flow-simplification pass remains open: capture the affected screens, compare them with the existing shell/components, reduce unnecessary steps and technical language, and repeat their task/role/error checks. The GitHub checkpoint preserves the current implementation before that review or a product split. The user is installing LearnDash manually in Local; this checkpoint does not install it or accept its integration.

## Changes resulting from the final checks

- Recovery validates each integration's native indexed mapping representation, including the index-to-row identity. Reviewed course/product mappings rebuild those indexes. Existing typed object review refuses colliding target identities. List-shaped stores retain their existing contract.
- Email recovery accepts the exact inert responsive CSS prefix left by older WordPress sanitization and equivalent apostrophe entities. Other CSS, script, changed URLs and changed content remain refused. Stored original designs are not rewritten during review.
- Complete snapshot catalog entries use their native archive checksum and component receipts. They no longer require a legacy credential-stream checksum that complete snapshots do not produce. Malformed supplied hashes and missing archive hashes still fail.
- Recipient updates reuse the current row locked with `FOR UPDATE` for email identity history. A confirmed identical refresh does not alter timestamps, review generation or reassessment work. Two actual database connections prove that an older transaction snapshot cannot discard a concurrent email's privacy digest.
- Widget fixture cleanup records non-cache metadata independently. Elementor may regenerate its global Kit CSS during a frontend read; cleanup preserves that generated cache while continuing to refuse unrelated page, metadata or business changes. Exact original option values, autoload flags and row identities are retained.

No schema/version bump, production authority change, licensing override, LearnDash installation, release package or original-site whole-state restore was performed.

## Final backend matrix

Every row passes on **PHP 8.2.29 and PHP 8.5.1**, with successful JSON receipts and empty stderr. These are disposable real WordPress/MySQL/filesystem tests, including independent sessions and actual transaction lock waits where applicable.

| Test | Checks per runtime | Accepted behavior |
| --- | ---: | --- |
| Whole option semantics | 79 | Native configuration validation, paused mappings and source/target separation |
| Whole email files | 46 | Nonempty native email designs, attachments, images/backgrounds and exact file mappings |
| Whole reference/capture/recovery | 189 | Unified 21-store capture and commit, native worker handoff, provenance, files, faults, lease loss, rollback/resume, receipt replay, catalog and safety retention |
| Native indexed mappings | 20 | Actual Tutor/Woo/LearnDash-shaped course/product indexes, remapping, disconnect and collision refusal |
| Pending lifecycle | 115 | Reminders, activation cutoffs, expiry, cancellation, receipts and exact scalar storage |
| Pending lifecycle concurrency | 53 | Real races, coalescing, notice transport claims and uncertain-outcome restraint |
| Canonical issuance concurrency | 96 | 21 independent-worker/manual orderings and canonical receipt reconciliation |
| Canonical issuance faults | 129 | Actual SQL faults, zero-write refusal and atomic rollback |
| Recipient account links | 40 | Exact ownership, linking/unlinking and history/access boundaries |
| Recipient email concurrency | 6 | Current locked history retains all concurrent email digests; identical refresh is a no-op |
| Credential-only restore account guard | 65 | Unverified account links remain refused in both restore modes |

Evidence: `output/program-recipient-20261004/final-run-<test>-php82.json` and `-php85.json`, with corresponding `.stderr` files. The eleven suites total **838 checks per runtime**; this is an evidence count, not a claim of exhaustive certification.

Additional final standalone coverage passes on both runtimes: source semantics (236), email designs (259), configuration graph (18), pending policy (38), and repository/transaction/bootstrap/maintenance/job recovery checks. The final scoped syntax pass covers 17 PHP files on both runtimes, followed by the updated cleanup harness checks and scoped whitespace validation.

## Native Lance browser acceptance

| Surface | Current accepted evidence |
| --- | --- |
| Recovery controls | 21 checks: actual capture, pause/resume, failed capture retry, private multipart upload, populated native connection search, explicit disconnect, confirmation grammar, discard, owned backup removal and phone layout |
| Recipient account | 16 checks: signed-out boundary, private portal, personal/enrollment collection, trusted-score separation, failure preservation, approval, stale refusal, refresh/focus, WordPress profile and Woo account embedding, composition handling, mobile and reduced motion |
| Organization queue | 17 checks: restored work held, history, cancellation confirmation/focus, actual stale POST refusal, current private GET, recheck, background-worker revision conflict, deliberate refresh, cancellation, filters, mobile and reduced motion |
| Bulk issuance | Actual CSV upload → review → explicit commit → native worker → two canonical test Versions; four settled receipt checks |
| App connections | Eight checks across actual create/mapping/pause/re-enable/rotation/removal; one-time display and SQL hash evidence without exporting secrets |
| Widget frontend/editor/global layouts | 163 checks: 28 basic frontend, 17 native editor, 103 public-result/share/print, and 15 global left/center/right layout checks |

All four Gutenberg block types and all eight Elementor widget types are represented. The frontend exercises twelve verifier instances per platform, including standard designs and all six Studio configurations with their actual unlicensed fallback. The native editors show their real canvases, unique accessible IDs, inert preview controls and premium gating. The Studio policy suite independently passes 46 checks per runtime; an actual licensed commercial provider is still a release requirement.

The public matrix covers designed certificates, uploaded images, details only, summaries, private records, revoked credentials and refused unsafe artwork. Designed certificates replace the verification inputs. Image/details results retain those inputs above the result. Private fields remain concealed. All widgets inherit the single global certificate layout setting; the center mode uses the configured 800 px limit and details below.

Every share menu is proven in the browser's top layer, inside the viewport and above surrounding content. Each DOM Escape handler closes it and returns focus. Print CSS isolates one selected certificate, hides surrounding content/actions, fits the artwork and removes its portal after the print lifecycle.

Artifacts are under `output/playwright/`: `recovery-native-*-current-20261007.json`, `recipient-native-final-20261007.json`, `pending-native-final-{initial,actions}-20261008.log`, `native-bulk-*-current-20261007.log`, `native-app-*-current-20261007.log`, `widget-current-20261007.log`, `widget-editors-current-20261007.log`, `widget-public-final-20261008.log`, and `widget-global-{left,center,right}-current-20261007.log`. The 103-check public run replaces the earlier 79-check run; it is not double-counted. Current recipient/staff/widget screenshots and the populated recovery screenshots were inspected.

Browser acceptance used native DOM control activation, actual protected REST, focus assertions, browser geometry and media emulation. Chrome visibility fluctuated; the final physical-input retry stopped before any changes when the page reported hidden. DOM keyboard-handler tests do not establish physical key delivery. Print emulation does not establish an operating-system printer result.

The recovery UI review intentionally stopped before committing a replacement of the original site. Actual commit/rollback/handoff acceptance comes from the isolated real recovery matrix. No original-site replacement or external mail was sent by these test fixtures.

## Cleanup and preservation

Exact owned fixture cleanup is complete. Recipient and staff tests created no credential Versions or deliveries. Bulk cleanup removed its two test Versions and owned recipients/Programs/template/jobs/files. Recovery cleanup removed only owned captures, draft uploads and jobs; ordinary backups were preserved. Widget cleanup removed owned pages, attachments, public cases and private journals, and restored exact original configuration bytes and option identities. Licensing did not change.

The final widget cleanup initially retained its journals after a fingerprint mismatch. Masked diagnostics proved unchanged business/page fingerprints and exact original non-cache metadata. The only metadata difference was regenerated `_elementor_css` for the existing global Kit. That generated cache was preserved, and the exact paired cleanup was reconciled without overwriting it. The revised harness then received a fresh stage/render/cleanup regression.

Evidence: `output/program-recipient-20261004/recipient-native-final-cleanup-20261008.json`, `pending-native-final-cleanup-20261008.json`, `native-bulk-app-cleanup-current-20261007.json`, `recovery-native-cleanup-current-20261007.json`, and `widget-final-reconciled-cleanup-20261008.json`. Ordinary cron, schema-health, rate-limit, account/session and cache progress is retained rather than rewound.

## Separate next stages

1. Release validation: exact production renderer/domain payloads and fonts, actual commercial Pro activation/expiry/revocation if shipping Pro, minimum-platform/multisite/database variants, large-workspace/HTTP performance, actual physical keyboard/pointer/motion/zoom/assistive-technology and printer environments, final package fingerprints and release notes.
2. LearnDash: install the supplied archive only in the separately authorized acceptance stage, then check the installed version's native qualification/results/events, identity, missing details, replay and failure paths. The native mapping recovery test uses process-local post-type registration; it is not installed LearnDash integration acceptance.

User guides now explain the implemented recipient collection, triggering, reminders/request deadlines, and backup/recovery flows: [recipient details and requests](../guides/recipient-details-and-certificate-requests.md), [backup and recovery](../guides/backup-and-recovery.md).
