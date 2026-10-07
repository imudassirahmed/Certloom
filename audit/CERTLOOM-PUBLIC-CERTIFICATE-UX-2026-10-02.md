# Certloom public certificate UI and verification audit — 2 October 2026

The requested public certificate presentation is implemented and checked in the authorized **Lance Chrome profile using Playwright**. This completes this public UI pass. The broader engineering and product acceptance backlog remains open; see the [code audit](CERTLOOM-CODE-AND-REMAINING-WORK-2026-10-01.md#remaining-work-in-order).

Dates here use Asia/Karachi. Evidence directories retain their 1 October UTC date. Existing workspace changes were preserved; this pass did not create a release, commit or deployment.

## Resulting behavior

- **One global setting:** Certloom → Settings → Public certificate layout. Left places certificate left/information right; Right places information left/certificate right; Center places a bounded certificate above its information. Center accepts 320–1400 pixels and shrinks to available space. The restored default is **Right, 960 pixels**.
- **Coursera-inspired composition:** clear recipient/course hierarchy, a pale information panel and a separate certificate column. Extra certificate framing, padding, shadow and surrounding background are removed. The issued artwork's own canvas and contents remain intact.
- **Designed certificates:** a successful shortcode/widget/block lookup replaces the search view with the certificate profile. Verify another certificate restores the input and focus.
- **Details-only/uploaded-image results:** the search stays visible and the result appears below it. The same global presentation applies to public profile routes and shared frontend renderers.
- **Share ribbon:** native browser popovers escape stacking contexts; an owned body portal supplies the fallback. Menus fit the viewport, close on Escape/outside click and restore focus. Only one share menu stays open.
- **Print and motion:** printing isolates the selected credential and fits its artwork to available print width. Short pointer feedback and result fades respect reduced motion; keyboard navigation receives immediate focus feedback.

## Findings repaired during the audit

| Finding | Impact | Repair and evidence |
| --- | --- | --- |
| Share menu inside a card stacking context | Menu could appear behind other content or outside the viewport. | Top-layer popover plus portal fallback; desktop/mobile hit testing and bounded geometry passed. |
| Narrow profile and extra certificate frame | The artwork and information lacked the requested hierarchy. | Shared responsive layout; Left, Right and Center checked in the real site, including 390px mobile. |
| Center certificate margin overridden by a later rule | Center's artwork column sat toward the left. | Corrected selector priority; certificate and information now align at the selected width. |
| Mobile Share wrapper consumed the whole action row | Copy, Share and Print stacked unnecessarily. | Actions fit one row at 390px with 44px targets. |
| Status styling depended on an outer route wrapper | Embedded widgets showed a different status icon. | Explicit shared card styling; all seven shortcode/block contexts show the same status treatment. |
| Cached results reused heading IDs | Multiple widgets could reference another certificate heading. | Separate IDs and matching references for both REST insertion and server rendering. Live direct-link rendering confirmed seven distinct, locally referenced headings. |
| Native verification time used a local timestamp as UTC | Site timezone could be applied twice. | ISO UTC verification instants; unit coverage checks one Asia/Karachi conversion and live browser checks confirm the current UTC instant. |
| Uploaded images previously lacked proven byte identity | An attachment replacement could silently alter the visible issued certificate. | Image review freezes media provenance, detects changes before number allocation and refuses changed/unproven bytes at display. |

The public layout continues to use current canonical status and visibility rules. Revoked credentials remain revoked. Private/recipient-only/organization credentials remain unavailable to guests; summary credentials omit identity and artwork. Unsafe private merge fields prevent artwork display while permitted details remain available.

## Validation

- **56 regression suite executions passed:** 28 relevant suites on PHP 8.2.29 and PHP 8.5.1, with **0 failures, 0 skips and 0 diagnostics** after the final changes. Coverage includes issuance/media integrity, settings, privacy, sharing, verification, routing, frontend architecture, recipient portal and translation tooling. [Results](../output/audit-20261001/public-redesign-complete/totals.json).
- **29 syntax checks passed:** 14 changed PHP files on both runtimes and the public JavaScript file. [Lint results](../output/audit-20261001/public-redesign-acceptance/lint-results.json).
- **39 grouped browser assertions passed**, recorded in [widget flows](../output/playwright/certloom-public-redesign-20261001/widget-flow-results.json), [profile interactions](../output/playwright/certloom-public-redesign-20261001/profile-interaction-results.json), [native actions and REST](../output/playwright/certloom-public-redesign-20261001/native-rest-results.json), [share fallback](../output/playwright/certloom-public-redesign-20261001/share-fallback-results.json) and [final server checks](../output/playwright/certloom-public-redesign-20261001/final-server-results.json).
- All six shortcode styles and the native WordPress verification block passed design/details/image/missing-result state checks. Seven simultaneous identical lookups used **one REST request**. Native pointer and keyboard checks subsequently passed in Lance after foreground input recovered.
- REST retained no-store headers and the established fields contract. Responses omitted raw snapshots and the private canary value. Revoked status and safe fallback behavior were checked against real local WordPress fixtures.
- Print isolation was checked through the production click handler under print-media emulation. Reduced motion and native Share Enter/Escape behavior passed. The older-browser portal branch was exercised by making the popover API unavailable on an owned QA URL.
- The POT catalog and candidate manifest hashes were regenerated consistently. The suite runner now distinguishes explicit skip results from passing accessibility checks about a skip link.

## Visual evidence

Screenshots were captured and opened for inspection. The fixtures use synthetic learners, preset sample artwork and a gallery image; their sample issuer/date text is not a real issuance record.

- [Final Right layout, including corrected verification time](../output/playwright/certloom-public-redesign-20261001/after-certificate-right-final.png)
- [Left layout](../output/playwright/certloom-public-redesign-20261001/after-certificate-left.png)
- [Centered layout](../output/playwright/certloom-public-redesign-20261001/after-certificate-center-final.png)
- [390px mobile layout](../output/playwright/certloom-public-redesign-20261001/after-mobile-390-final.png)
- [Share ribbon](../output/playwright/certloom-public-redesign-20261001/after-share-ribbon-final.png) and [mobile ribbon](../output/playwright/certloom-public-redesign-20261001/after-mobile-share.png)
- [Image below the input](../output/playwright/certloom-public-redesign-20261001/after-image-widget-final.png), [details below the input](../output/playwright/certloom-public-redesign-20261001/after-details-widget-final.png) and [block certificate view](../output/playwright/certloom-public-redesign-20261001/after-design-block-status-final.png)
- [Revoked profile](../output/playwright/certloom-public-redesign-20261001/after-revoked-profile.png) and [global settings](../output/playwright/certloom-public-redesign-20261001/after-global-settings-final.png)

The earlier geometry screenshots precede the final UTC and server-ID corrections; the final Right screenshot and final server assertions cover those corrections.

## Data preservation and cleanup

Both rounds of owned QA credentials, recipients, attachments, image files and pages were removed. Original preferences were restored and native cache generation was advanced. Existing domain/business rows match the private pre-test SQL snapshot exactly. The [comparison](../output/audit-20261001/public-redesign-acceptance/cleanup-comparison.json) found only two added anonymous daily verification-counter buckets from test lookups; original analytics rows were unchanged. Those aggregate counters were retained. [Cleanup summary](../output/audit-20261001/public-redesign-acceptance/cleanup-results.json).

The final live-check round also passed the complete native-row digest comparison when its fixtures were removed. No historical credential snapshots were rewritten.

## Limits and remaining work

Historical uploaded images without recorded byte provenance show an unavailable notice with their permitted details. Current attachment contents cannot safely establish what was originally issued. Those records need an explicit review/reissuance workflow rather than an inferred historical hash.

Physical printer output, a JavaScript-disabled browser, full screen-reader/zoom/RTL testing, Elementor editor-canvas integration and the rest of the product's screen-by-screen acceptance remain open. This pass verifies server no-script markup and shared renderers; those checks do not constitute complete accessibility or cross-browser acceptance. Existing WordPress/Elementor development warnings were observed separately from the zero-diagnostic standalone regressions.

Broader remaining priorities are portable whole-state backup/recovery, custom/system font artifact support, live provider acceptance and fresh exact-payload renderer/domain release authority. This UI work does not close those gates. The next product journey checks should cover actual issuance/receipt, recipient creation, Program/Batch inheritance, Designer, import/recovery and role-specific flows, building on the [Lance issuance audit](CERTLOOM-LANCE-ISSUANCE-UX-2026-10-01.md).
