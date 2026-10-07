# Certloom guided field setup — 3 October 2026

Status: this checkpoint is implemented and accepted locally. The larger [Program and recipient workflow plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) remains in progress. This is not a release or complete screen/widget acceptance.

## Resulting behavior

The organization chooses reusable field definitions, who supplies each detail, when the customer is asked, approval requirements and the Program's optional/required/excluded rules in guided setup. New fields remain private. Selecting a field alone grants no customer permission: the explicit collection choice does that only after reviewed setup is applied. Identity numbers remain text so leading zeros survive. Customers cannot supply protected identity facts or overwrite app/staff assessment results. Unchecked rows use disabled fieldsets so their hidden settings do not consume the request's input budget. A complete form can explicitly select no extra fields; malformed transports and missing completeness markers still fail closed.

Organization-owned values shared by everyone in a Program use the existing typed Program defaults envelope. Person, enrollment and result facts stay separate. Setup saves no actual customer answer and cannot issue just because a customer filled a form. The first-certificate guide directs customer/app/shared collection through its canonical workflow instead of offering misleading manual answer boxes.

Fields use a bounded, versioned draft intent with catalog, retired-key and Program fingerprints. Continue validates the choices without changing the site. Review/apply repeats validation under current option/Program row locks and retains the original metadata envelopes. Fields, new organization/context records, Program rules/defaults and draft pointers are applied in the existing checked setup transaction. A failed downstream write rolls everything back. Retry reuses the saved context instead of duplicating it. Global collection settings are explicitly distinguished from Program-specific requirements and defaults.

The design step advises in both directions: selected public fields absent from artwork, and fields referenced by artwork but not reviewed in the chosen field list. Private references and undefined custom tokens are distinct. Inline tokens and the historical catalog-backed namespaces recognized by the privacy guard are covered. A design-only notice does not claim the field is missing from an inherited contract; the complete hierarchy is checked at save. No token or recommendation grants public exposure.

## Read-only field/design check

`GET /certloom/v1/admin/setup/field-check` is available behind the administration feature gate to a logged-in administrator with `manage_options`. It checks that administrator's exact saved setup run and revision. Stale requests return 409; recipients and anonymous visitors cannot inspect the setup. Responses are private, no-store and noindex and contain labels/guidance, not customer values, defaults or catalog metadata.

The check validates staged fields, typed shared defaults, Program/context relationships, the resolved Global → Issuer → Program → Batch requirements, and a selected published design's field/privacy compatibility. It explains customer collection, required approval and verified app results still needed. It creates no credential, request, job or email. It is a **fields/design configuration check**, not a full setup, delivery or recipient-eligibility simulation. Review/apply and final issuance recheck current authority.

## Verification and preservation

- Final focused evidence: `output/program-recipient-20261003/wizard-payload-final`. Ten relevant suites executed on PHP 8.5 and 8.2: **20 passed, zero failures, skips or diagnostics**. The field-intent/coverage suite contains **52 checks**. Earlier `wizard-final-focused` and `wizard-coverage-final` checkpoints also passed 20 executions each.
- Real Local WordPress/MySQL apply and REST acceptance: **27 checks on each runtime**, final `wizard-payload-live-php85.json` and `wizard-payload-live-php82.json` in the same evidence directory's parent. Earlier acceptance is retained in `wizard-fields-live-php85.json` and `wizard-fields-live.json`. These cover staged permissions, actual private field/default writes, exact REST revision/roles/no-write behavior, retry, concurrent-edit rejection, unknown metadata preservation, shared zero issuance/replay and injected downstream failure rollback.
- The existing actual customer submission → approval → pending worker → canonical issue/replay journey was rerun after the wizard changes: **74 checks passed**, `account-workflow-after-wizard.json`.
- All live acceptance helpers disabled email/HTTP and used checked rollback. Fingerprints confirm preservation of the 23 tracked business tables, users/usermeta, tracked Certloom options and cron. This does not assert preservation of auto-increment counters or acceptance of external delivery.
- Forty PHP syntax checks passed across the two runtimes for the checkpoint's initial changed PHP files. Eight final PHP syntax checks cover the updated field planner, coverage, view and contract suite. JavaScript syntax and changed-file whitespace checks passed.

Lance's authorized Chrome profile was exercised through Playwright. Real field controls retained source/lifetime, customer timing/approval and a shared zero after autosave/reload. A custom customer ID could be added with its real label/key, required rule and approval. Published design AJAX/coverage and the read-only REST check completed. Desktop and 390px captures were inspected with no horizontal overflow. The original administrator draft was restored exactly after the isolated browser draft; its private journal was removed. No QA setup was applied through the browser. The original 1536 × 674 viewport and Certloom tab URL were restored. Console: zero errors, four existing WordPress/theme warnings.

Inspected screenshots in `output/playwright`:

- `20261003-wizard-recipient-desktop.png` and `20261003-wizard-recipient-mobile-final.png`.
- `20261003-wizard-design-coverage-mobile.png`.
- `20261003-wizard-review-desktop.png` and `20261003-wizard-review-mobile.png`.
- `20261003-wizard-rest-check-desktop.png` and `20261003-wizard-rest-check-mobile.png`.
- `20261003-wizard-selected-fields-desktop.png` and `20261003-wizard-selected-fields-mobile.png` after the transport optimization.
- `20261003-wizard-coverage-final-desktop.png` and `20261003-wizard-coverage-final-mobile.png` on the restored original draft. Its design has no extra custom-field references, so these accept the empty guidance/actions state rather than the mismatch cases covered by the contract suite.

The desktop field capture predates the final custom-label/private-checkbox tweak; the final mobile capture includes it. Native pointer actions through the attached extension sometimes timed out even when the control was visible; observed DOM-native clicks exercised actual handlers. Adding a custom field was also checked with keyboard Space. This is not proof of complete pointer, keyboard or assistive-technology acceptance. Appearance/Review were inspected via actual step routes, not an uninterrupted browser Continue/apply/issue journey.

The selected-only browser check accepted 12 available fields with two selected and 32 total form entries, including the completeness marker. Native check/uncheck enabled/excluded the actual settings, and the acknowledged latest queued autosave reloaded the same two selections. The first helper accidentally reevaluated an unchecked-only locator and clicked a different checkbox; the second awaited the earlier save instead of the latest queued one. An attached-browser waitForFunction also timed out despite the saved DOM state. Stable observed names and the actual latest response/saved element resolved the helper issues; no corresponding product save failure was observed. Every isolated draft was restored exactly and its owned private journal removed.

## Remaining work and order

1. Finish versioned profile starter bundles and artwork containing the corresponding display fields, with sample values that distinguish customer identity, enrollment, shared Program and changing results. Preserve existing factory versions, customer edits and issued snapshots. Current recommendation labels are not proof of profile-specific artwork coverage.
2. Complete the broader nonmutating setup/readiness preview and the fresh guided browser apply/first-issue journey, including late responses, skip/resume and both design mismatch directions. The new endpoint only covers fields/design.
3. Finish supported trusted source adapters/mappings, other configuration writers and staff request management; then typed integration v2, observed CSV commit/replay/failure and the tested trigger/field-source guide.
4. Continue all-screen, Gutenberg/Elementor, subscriber browser, recovery/concurrency and release acceptance from the approved plan. Existing renderer, domain and Pro-provider gates remain applicable.

## LearnDash ZIP received; testing deferred

`Integrations/learndash-5.2.1.zip` is present: **24,234,691 bytes**, SHA-256 **577e60f27ea3db1da14e8a362e695feafec80f9c7281b00427d1d3f3cec5aafb**. It has only been inventoried and hashed. It has not been extracted, installed or tested. Per the user's 3 October instruction, implement the current Program/recipient plan before LearnDash integration acceptance. Receipt removes the earlier missing-package prerequisite; actual licensed/provider workflow acceptance remains open.
