# Profile starters and setup readiness — 3 October 2026

Status: this checkpoint is implemented and accepted on the authorized Local site. The [Program and recipient plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md) remains in progress. This is not complete integration, first-issuance browser, widget, recovery or release acceptance.

## Resulting behavior

All nine usage profiles now have a versioned recommendation bundle and a matching Essentials certificate. Eight profiles also have a With details design containing their corresponding custom fields. General / Other uses native facts without invented extra fields. These 17 additions preserve all 42 existing factory keys and their immutable revisions.

| Profile | Extra example fields | Suggested ownership |
| --- | --- | --- |
| Training / LMS | Completion date, training hours, assessment score | Organization/results; hours shared by Program |
| ISO management | Standard, certification scope, certified site | Organization; standard shared by Program |
| Government licensing | License type, authority registration, jurisdiction | Issuing authority; authority numbers are not customer answers |
| School results | Student roll number, session, result | Customer enrollment number; organization owns session and results |
| Corporate compliance | Department, competency, completion date | Organization; competency shared by Program |
| Membership | Association member number, class, admission date | Association; member number is association-issued |
| Events / awards | Event, category, event date | Organizer; event/date shared by Program |
| Product inspection | Product/item, lot number, inspection date | Organization/verified results; no invented customer-account authority |
| General / Other | Native recipient, Program, issuer and certificate facts | Existing native sources |

Recommendations and examples never create answers, requirements, public exposure, qualification evidence or collection permissions. The wizard preserves existing field policies and the source control's existing default. The organization deliberately chooses actual collection settings in the field step. Existing field types remain authoritative; new recommendations use date, number, text and textarea appropriately. Student roll numbers are text, preserving `000142`.

Essentials designs enter the existing starter publication process using native facts. Detailed variants remain drafts for deliberate field/source/privacy review and publication in Design Center. A matching available Essentials design appears first in setup and is labeled recommended without silently changing the selected design. Direct stable-key lookup avoids losing that recommendation to the bounded general selector query.

Version 1 layouts remain reproducible, with fixed hash fixtures and the original layout schema. Version 2 wraps detailed values within their existing boxes to keep longer scope text readable. Native Essentials artwork is unchanged. Factory synchronization can advance an untouched detailed draft, preserves historical and published revision pointers, and protects archived/customer-edited revisions. An explicit reset creates a new revision rather than deleting history. Profile discovery uses lightweight metadata; starter publication builds only the nine Essentials layouts.

The Fields step offers a collapsed, responsive example explaining Program-wide, enrollment and individual-result values. The design and review previews use synthetic profile names, Programs and field values. Example issue dates follow the example completion/event/inspection date. Existing long-name, RTL and other stress sample profiles retain their behavior. Mobile preview height follows the selected artwork's aspect ratio.

## Private setup review

The Review step's **Check setup & preview** button calls `GET /certloom/v1/admin/setup/readiness-check`. The previous `/admin/setup/field-check` contract remains available unchanged.

The new route requires an authenticated administrator with `manage_options`, the existing administration feature gate and that administrator's exact saved setup run/revision. Unauthorized requests fail with 401/403; stale reviews fail with 409. Responses are private, no-store and noindex. They contain configuration labels/guidance and synthetic artwork; they do not return field catalog/default envelopes or evaluate an actual recipient.

The review gathers independent organization-name, selected-image availability/type, field/source/hierarchy/privacy, presentation/renderability, Program/relationship/native-design-fact and selected-email-design checks. Each section states Checked, Needs attention or Next step and links to the relevant setup step. The shared relationship validator preserves the same final-apply/first-issue error codes and behavior. Individual values are still required at issuance, rather than being demanded to save reusable setup.

A successful configuration check does not prove recipient eligibility, actual source mappings, approvals, mail-provider delivery or production artifact authority. Saving revalidates current configuration through the canonical write path. Issuing revalidates actual recipient data, approvals, results, qualification and production gates. Existing stored relationship assets and all configuration-consumer/concurrency combinations are not comprehensively assessed by this preview. The response and UI explicitly retain these next actions.

Client rendering uses text nodes for messages and the existing escaped RenderPlanHtmlPreview output for artwork. It rejects results if the local draft run/revision changed while checking, and disconnects the previous preview ResizeObserver when running another check.

## Acceptance evidence

- `output/program-recipient-20261003/profile-starters-final`: 18 focused suite executions on PHP 8.5.1 and 8.2.29; all passed, zero diagnostics or skips. This covers profile bundles, original/curated presets, metadata, preview foundation, field setup, setup/resume/apply and design-required context.
- `profile-example-dates-final`: four final profile/sample regression executions across both runtimes, all passed after aligning synthetic issue dates.
- `profile-setups-final-php85.json` and `profile-setups-final-php82.json`: 314 rollback-protected live checks per runtime. Includes existing immutable-row preservation, all 17 real factory previews, nine Essentials publications/eight detailed drafts, safe v1→v2 advancement, customer-edit/reset history, multiple readiness blockers, missing image rejection, private REST headers/roles/stale revisions, zero tracked preview writes, and the existing atomic field/context apply, issue/replay and injected-failure rollback checks. The count includes individual row-preservation checks.
- All 23 business stores, users/usermeta, Certloom-owned options and cron match their before-test fingerprints after each live rollback. Sequence counters are not claimed to be restored. Original administrator draft restored exactly using its owned private journal; the journal was removed, and Lance returned to its original URL and 1536×674 viewport. The new factory designs are the intended feature additions retained by normal wizard seeding.
- Thirty PHP syntax checks across both runtimes, JavaScript syntax validation and targeted whitespace checks passed.
- Lance captured all 17 synthetic RenderPlanHtmlPreview artworks; all text fitted and every element remained inside its page. Each profile's artwork was visually inspected. Gallery captures are preview evidence, not production PDF/PNG/JPEG acceptance.
- Real Lance wizard guidance, design selection, AJAX profile preview/autosave, responsive layouts and readiness Needs attention/Checked states were captured and inspected. Final mobile review measures a 307.6px preview component and 197px canvas within a 390px viewport, without horizontal page overflow. Setup reads created no recipient answers, certificates or email deliveries. Console: zero errors; four existing warnings.

Browser evidence is in `output/playwright/20261003-profile-*.png` and `20261003-setup-readiness-*.png`; reproducible browser scripts use the `profile-wizard-*` and `profile-gallery-*` names in that directory. The synthetic gallery is `output/program-recipient-20261003/profile-design-gallery.html`.

Harness limitations/corrections: the first customer-edit fixture used an invalid shape property and was corrected to `fill`; the failing attempt rolled back exactly. A browser waiter used the wrong autosave action name, an inline CLI argument lost quoting, local-file navigation was unsupported, and element screenshot stability timed out through the attached extension. Correct action predicates, script files, the authorized Local plugin junction and viewport screenshots resolved these. Native DOM activation used actual application handlers where attached-extension pointer actions were unreliable; complete pointer/keyboard/assistive-technology acceptance is still required.

## Remaining work

The fresh guided browser apply/first-issue journey is now accepted for details-only issuance. The final Finish guidance reflects an already saved Program. Global, Issuer and Batch configuration writers and narrow independent-session outbox concurrency also passed. Exact fixture cleanup preserves existing data, normal cron progress and verification rate-limit counters. See [the next checkpoint](CERTLOOM-PENDING-CONFIGURATION-WRITERS-AND-GUIDED-ACCEPTANCE-2026-10-03.md).

Complete late-response/browser/canonical-issuance concurrency acceptance, supported trusted-source mappings and v2 intake, real mixed CSV commits/replays/failures, remaining configuration consumers, staff account/request/name-policy operations, reminders and expiry, actual subscriber browser transport, all widgets/screens, whole-state recovery and production/provider/platform gates remain open. LearnDash stays deferred until the current Program/recipient implementation is ready, as requested.
