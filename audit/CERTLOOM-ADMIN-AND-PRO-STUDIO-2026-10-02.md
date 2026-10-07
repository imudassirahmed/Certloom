# Certloom admin UX and Pro Verification Studio — 2 October 2026

The later [all-widget and restored guided-setup pass](CERTLOOM-ALL-WIDGETS-AND-GUIDED-SETUP-2026-10-02.md)
supersedes the setup journey described below. Profile, Program, certificate and
email choices are restored before reviewed setup save and optional issuance.
It also checks every block/Elementor widget, repeats all 23 desktop/mobile admin
screens, repairs mobile targets and header notices, and verifies fixture cleanup.
Its current catalog contains 6,614 strings. The counts below remain the earlier
checkpoint's evidence.

The back panel now has consistent panels, selected navigation, restrained hover
motion, visible keyboard focus and mobile navigation targets. Settings has a
sticky save bar, an unsaved-change indicator that clears when edits are undone,
and a direct Certificate layout shortcut. The existing brand hero is preserved.

One Pro Verification Studio widget and one native WordPress block provide five
profile-based designs plus Custom. They reuse the existing REST verification,
privacy, status, sharing and immutable certificate presentation paths. The global
Left/Right/Center certificate setting still applies once across all credentials.

## Designs and customer controls

| Dropdown choice | Recommended usage profiles | Presentation |
| --- | --- | --- |
| Campus | Training/LMS; School results | Welcoming split layout with a pale blue surface |
| Authority | ISO management; Government licensing; Product inspection | Precise registry layout with a blue rule |
| Executive | Corporate compliance; Membership/association | Calm, centered presentation |
| Celebration | Events/awards | Generous editorial layout with a warm accent |
| Essential | General/Other | Compact inline presentation |
| Custom | Any profile | Independent desktop, tablet and mobile controls |

Custom supports split, stacked and inline layouts; intro alignment; row/stack
forms; ten bounded size/spacing controls; seven colors; font family, heading
weight and shadow choices. Elementor also exposes heading/body typography and
panel shadow controls. The native block supports wide/full alignment and anchors.
Tablet/mobile values inherit when unset; device overrides use viewport widths
1024/767px. Presets also adapt to narrow containing columns. These are flexible
supported arrangements, rather than an unrestricted page-design engine.

The recommended preset comes from the existing usage profile. Content remains
editable without changing a preset's styling. Custom styling is scoped to its
own instance and cannot change another widget or a designed certificate.

## Pro activation boundary

The code is complete and both licensed and unlicensed behavior were exercised.
The repository contains no commercial Pro license provider to connect. It now
exposes a strict, server-side entitlement contract; without a validated provider,
the panel correctly reports **Requires Pro** and both editors hide new Pro
insertion. Saved Pro widgets/blocks continue as standard verification.

The temporary local QA provider was removed. Production Pro activation remains
open until the actual companion/license service implements the
[entitlement contract](CERTLOOM-PRO-VERIFICATION-STUDIO-CONTRACT.md). Editor
permissions, submitted attributes, UI settings and release acceptance do not
grant Pro.

## Findings repaired during implementation

| Finding / severity | Trigger and effect | Repair and evidence |
| --- | --- | --- |
| STU-01 / P1 | Independent REST block previews reused request-local IDs. A Custom style could color every preview and duplicate accessible references. | UUID-backed instance scopes; input/label/ARIA references move together. Six actual editor previews have unique IDs and independent colors. |
| STU-02 / P1 | The Pro grid display rule kept the search panel visible after a designed certificate loaded. | Match the existing design-result hide state with sufficient specificity. Both editors' frontend paths replace input for designs and retain it for details/images. |
| STU-03 / P2 | A narrow desktop column activated tablet Custom values, overriding an explicitly chosen desktop layout. | Device overrides follow viewport widths; automatic preset collapse remains. Actual Elementor Split/Stack and padding edits update the canvas. |
| STU-04 / P2 | Saved Elementor typography/shadow selectors could still target a preset after leaving Custom. | Advanced selectors require the Custom class. The control contract verifies all three selectors; this final selector change was not separately exercised in the live licensed editor. |
| ADM-01 / P2 | Settings lacked immediate saved/dirty feedback and a discoverable certificate-layout shortcut. | Sticky save bar, live status and navigation/search entry. Actual edits and undo pass without saving. |
| ADM-02 / P2 | Email thumbnails exposed sample headings and nested links inside their preview buttons. Preview/import dialogs lacked explicit names. | Decorative thumbnails are inert and hidden from assistive technology; dialogs reference their titles. Browser focus refusal, modal activation and focus return pass. Email artwork and sending paths are unchanged. |

## Verification and evidence

| Check | Final result |
| --- | --- |
| PHP regression matrix | **46 passing suite executions:** 23 suites on PHP 8.2.29 and 8.5.1. Zero failures, exclusions, skip-bearing runs or diagnostics. [Totals](../output/audit-20261002/pro-studio-final-2152/totals.json), [results](../output/audit-20261002/pro-studio-final-2152/results.json). |
| Changed-file syntax | **30 passing checks:** 14 PHP files on both runtimes plus two JavaScript files. [Results](../output/audit-20261002/pro-studio-complete/lint-results.json). |
| Focused Lance browser checks | **90 passing grouped assertions:** public Studio flows 52, Elementor editor 4, native editor 7, Settings/admin 11, print/share 2, Pro removal 7, email preview/focus 5, loaded issuance form 2. Per-flow JSON and scripts live in `output/playwright/certloom-pro-studio-20261002/`. |
| Back-panel screen matrix | **23 screens pass** initial desktop/mobile heading, load and page-overflow checks at 1440/390px. [Results](../output/playwright/certloom-pro-studio-20261002/admin-screen-matrix-results.json), [46 original screenshots](../output/playwright/certloom-pro-studio-20261002/screen-gallery.html). All eight contact sheets were visually reviewed, with selected full-size screens and the email dialog. The issuance desktop capture was replaced after its real REST form became ready. |
| Existing integrations | All six standard Elementor verification widgets remain available. Pro removal leaves saved Elementor and block lookup working. Existing public/private/summary/revoked/not-found behavior and REST request deduplication pass. |
| Design-system/localization | Ratchet thresholds were preserved; new preset colors use existing token aliases. POT extraction/determinism and language-quality checks pass with 6,578 source strings. This does not confer human translation acceptance. |

These are local checks on the workspace-linked WordPress site in the authorized
Lance profile, with Elementor 4.3.2. Some background-tab interactions required
scripted DOM activation or editor selection APIs. The report distinguishes these
from native input/select operations. Native Enter/Escape did not reach the
background tab even after bringing it forward, so that keyboard activation is
not accepted. Print used production handlers plus media emulation; no physical
printer was exercised. Screen-reader, native zoom, RTL, minimum platform versions,
multisite and every busy/error/completion state remain outside this acceptance.

The six-preset full-page image from earlier in this pass has a fixed WordPress
admin-bar capture artifact and predates the final token/typography refinements.
The earlier native-editor image with style bleed and the stale scrolled capture
are not final evidence. Current DOM/CSS assertions establish the corrected
preview scoping; final admin captures show current source.

## Fixture cleanup and preservation

A fresh private SQL snapshot was taken before fixture creation. Its imported
temporary-table digest matches the stored pre-fixture business digest. Seven
owned credentials, number reservations, recipient, attachment/image, two pages
and their revisions, and the local Pro provider were removed. Native cache
generation was invalidated. Original settings and existing credential/template/
recipient profile fields match the snapshot. Anonymous verification counters
were retained.

One existing account-linked recipient's `updated_at` advanced during editor QA;
all other fields in that row and all other non-analytics native rows match. This
timestamp-only difference was retained and explicitly recorded, rather than
restoring the entire database or claiming byte-identical business rows.
[Cleanup comparison](../output/playwright/certloom-pro-studio-20261002/studio-cleanup-results.json).

No commit, push, release installation, external provider publication or email
delivery was performed. The pre-existing staged and unstaged work remains.

## Remaining work

Commercial Pro entitlement integration is still required. The larger engineering
priorities remain portable whole-state backup/recovery, accepted immutable custom
font output, fresh exact-payload release/renderer acceptance and live provider
acceptance. The [ordered backlog](CERTLOOM-CODE-AND-REMAINING-WORK-2026-10-01.md#remaining-work-in-order)
also retains full issuance/receipt, Designer, import/recovery, role and
accessibility state-by-state testing. These initial screen checks do not close
those workflows.
