# WooCommerce completion and customer details

Date: 3 October 2026. Product implementation checkpoint; no release or LearnDash approval.

## Larger product libraries

Both completion-only and customer-detail product selectors now use the shared labeled, responsive relationship-search controls, with REST and the existing AJAX fallback. Organization staff can search by product name, SKU or exact ID beyond the initial twenty choices. Current selection is retained while searching or when a request fails. Product IDs and SKU/status labels distinguish similarly named products. Search results stay limited to twenty product objects; provider parent/zero placeholders are handled explicitly, malformed IDs fail closed, and a literal product ID remains reachable when more than twenty names contain that number.

The provider-backed search requires integration-management permission, including through shared context lookup. Customers and credential-viewing-only staff cannot retrieve unpublished choices. Context REST success/denial responses are private/no-store/noindex, and client GETs bypass cache. Search never changes mappings, qualification or issuance. The normal save/intake paths still validate the selected product and current authority.

Final installed-provider acceptance passes **70 checks in each PHP 8.5.1/8.2.29 and HPOS/CPT combination**: `output/program-recipient-20261003/woo-product-search-final-{hpos,cpt}-{php85,php82}.json`. The actual library includes twenty-five earlier products and ID-text collisions. Tests cover name/SKU/ID reachability, trash exclusion, malformed/failed provider responses, REST/capability boundaries, non-mutating reads and the existing completion/customer/approval/canonical issuance/replay flows. Checked rollback preserves tracked business, WooCommerce, options and cron state. CPT remains process-local datastore acceptance, not a persistent storage migration.

Twelve final focused executions pass without diagnostics in `woo-product-search-pure-final`; the JavaScript parses. Lance initializes both search controls with labels and no desktop overflow: `output/playwright/woo-product-search-read-20261003.log`. Chrome reports the task tab hidden, so visible pointer/mobile/keyboard/error acceptance remains open. This does not complete the wider screen-by-screen audit or release validation.

An explicit customer-detail product connection creates durable intent from a registered customer's actual Completed order. Customers enter enabled personal/enrollment fields in their own website account; the organization supplies approvals. Canonical pending/manual issuance remains the only certificate writer.

## Authority and behavior

- Strict versioned mapping configuration, optimistic pause/activate/remove controls, shared configuration/account/Recipient locks, checked persistence and atomic reassessment signals. Malformed configuration fails closed. Supported legacy save/remove commands share these locks and roll back option/outbox failures together.
- Qualification reads authoritative current SQL purchase facts through the actual WooCommerce HPOS or CPT storage mode. It binds the original order, exact customer, product, completion date and normalized purchased line quantities. Processing/payment, changed facts and any refund cannot qualify an unissued request. No missing-time fallback or email/name identity guessing.
- One request per order/product/account, independently of mapping keys and quantity. Repeat orders receive separate enrollments and cannot borrow registration answers. Original mapping/Program authority cannot be replaced by a new connection.
- Native event identity fences issued, waiting, held and cancelled requests across connection recreation and pending/completion-only switches. The completion-only canonical transaction repeats the indexed pending lookup with a current locking read. Historical v1 hashes/Versions remain unchanged; old adapter-wide receipts are also respected.
- Complete Global → Issuer → Program → Batch requirements, compatible published designs and automatic numbering apply. Arbitrary exam/result fields remain trusted-source owned; incompatible required results prevent configuration.
- Late replay reconciles the same immutable certificate. Restored requests remain paused. No guest-claim flow, course/order backfill, automatic revocation or reminder email is implied.

## Observed evidence

Local WordPress 7.1.2, MySQL 8.4.0, installed WooCommerce 11.1.2, PHP 8.5.1/8.2.29.

- 58 guarded live checks pass in each of four runs: `output/program-recipient-20261003/completion-replay-woo-hpos-php85.json`, `completion-replay-woo-cpt-php85.json`, `completion-replay-woo-hpos-php82.json`, `completion-replay-woo-cpt-php82.json`.
- Real WooCommerce product/order CRUD, actual Completed hooks and partial refunds are used. Coverage includes source changes between review/commit, protected results, leading-zero customer details, approval, actual durable JobRunner issuance, immutable receipts, exact repeat enrollments, guest refusal, malformed mapping/dependency guards, restore holds, mapping overlap and connection/flow switches to different Programs. Injected SQL failures and zero-row writes prove configuration/outbox rollback.
- All tracked WordPress/business/WooCommerce rows, configuration and cron are preserved through checked rollback. Mail, external HTTP and fixture file logging are disabled. PHP 8.5 bootstrap emits an existing Elementor nullable-parameter deprecation.
- CPT runs select the real CPT data store through a process-local option filter while preserving the site's actual HPOS setting. This accepts adapter behavior for real CPT rows; it does not accept a permanent storage switch or HPOS migration.
- 24 focused pure executions pass across both PHP runtimes without diagnostics: `output/program-recipient-20261003/completion-replay-pure`.

Account/bulk/signed-app regressions also pass 40/42/64 checks per runtime, with tracked state preserved: `completion-replay-regression-run-*-php85.json` and `completion-replay-regression-run-*-php82.json`.

## Remaining acceptance

Native product-connection mutation, the full visible subscriber browser journey, actual concurrent worker/source/approval sessions and broader account/widget screens remain open. Lance connects but its task tab reports hidden; this does not count as visible acceptance. Checkout field builders, Blocks checkout custom-field intake and purchases by guests are outside this completion adapter's contract.

Advanced staff filters, reminders/expiry and coherent whole-state recovery remain implementation work in the [master plan](CERTLOOM-PROGRAM-AND-RECIPIENT-FLOW-IMPLEMENTATION-PLAN-2026-10-02.md). LearnDash installation/testing remains deferred as requested. See the [organization/customer guide](../guides/recipient-details-and-certificate-requests.md).
