# Native customer and staff flow checkpoint

Date: 3 October 2026. Authorized Lance Chrome session, visible and unminimized; Local WordPress 7.1.2, WooCommerce 11.1.2 and MySQL 8.4.0. This is product-flow acceptance, separate from release validation and LearnDash testing.

## Customer journey

Sixteen explicit browser checks pass in `output/playwright/recipient-native-20261003.json`. The actual subscriber signs in through WordPress, opens an exact owned pending request, supplies personal and enrollment details, and sees personal identity awaiting approval. The trusted assessment score remains organization/app supplied and has no customer input. The fixture's qualification stays unknown, so completing these forms cannot issue a certificate.

The real REST handler returns private/no-store/noindex account responses, preserves leading-zero identifiers, and refuses a stale second-tab save with HTTP 409. An aborted save retains both entries and allows retry; the conflict retains the stale entries and requires refresh. Refresh loads the current saved answers. Customer access to the staff queue returns 403. Save feedback receives focus, keyboard refresh returns focus to the form heading, reduced-motion transitions are disabled, and controls fit the 390 px viewport.

Both the native WordPress profile and WooCommerce Edit account contain one Certloom details surface inside their existing account form. Enter submits a certificate-detail REST save with HTTP 200 and zero surrounding-form submit events. Enter while composing a character has no prevented default and sends zero save requests. The production handler now respects `isComposing` and the legacy composition key code; inactive buttons use a normal cursor, while busy workspace buttons show progress.

WooCommerce deliberately blocks ordinary subscribers from wp-admin. The initial ordinary-subscriber portal checks passed, and source inspection explains that redirect. For the separate WordPress profile check, only the disposable subscriber received `view_admin_dashboard`; it retained no Certloom staff capability or staff navigation. A disposable Woo My Account page provided the native Woo form. The original account-page setting was restored. This does not change the organization's normal customer access policy.

Screenshots inspected: `recipient-native-desktop-20261003.png`, `recipient-native-mobile-20261003.png`, and `recipient-native-woo-20261003.png` under `output/playwright`. Composition coverage dispatches a composition-marked keyboard event; actual OS input-method and screen-reader acceptance remains separate.

## Staff filtering

Ten explicit native browser checks pass in `output/playwright/staff-filters-native-20261003.log`: exact Program, trusted-source responsibility, qualification task, age/empty result, failed filter rollback with retained card/selection, keyboard refresh, labels, mobile fit and reduced motion. All accepted queue reads return HTTP 200 with private cache headers. An aborted queue read restores the last accepted responsibility filter, retains the request, and enables retry.

Desktop and 390 px mobile screenshots were captured and inspected. These checks add visible interaction evidence to the existing 121-check guarded staff filter acceptance; they do not replace its bounded-query and permissions checks.

## Preservation and remaining scope

`output/program-recipient-20261003/recipient-and-staff-native-accepted-cleanup.json` confirms original business/configuration/account/page rows preserved, original Woo account-page selection restored, unrelated user metadata and cron progress retained, exact owned user/pages/requests/submission/jobs removed, and private journal deleted. Zero credential Versions or deliveries were created. No full database restore was used. The original administrator's existing browser session is restored.

The harness initially captured only root-path login cookies; it now captures and clears all matching site authentication paths. It also uses DOM-ready navigation, the current staff heading identifier and the actual Program navigation after answers enter review. Early failed attempts are not acceptance evidence.

Six focused standalone executions pass on PHP 8.5.1 and 8.2.29 with no diagnostics in `output/program-recipient-20261003/recipient-native-focused` (collection fields, field edits and recipient portal). The installed Elementor bootstrap's existing PHP 8.5 nullable-parameter deprecation is outside Certloom.

Whole-state restore/orchestration, optional reminders and expiry, independent canonical worker/approval/cancellation races, native bulk CSV and signed connection commits, and the complete block/Elementor screen matrix remain open. No release, commercial renderer or entitlement authority is granted by these local fixtures.
