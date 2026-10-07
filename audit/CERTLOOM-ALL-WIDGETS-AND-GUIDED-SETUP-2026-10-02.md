# Certloom widgets and restored guided setup — 2 October 2026

The main setup journey again includes Profile, Organization, Fields, Certificate,
Program, Email and Review before optional first issuance. Quick issue remains a
secondary Welcome action. Existing profile recommendations, all ten starter
certificate designs, uploaded-image/details options and the canonical
Issuer/Program/Batch/Signer relationships remain available.

The guided Review creates only named context records and applies the explicitly
chosen site defaults. Context IDs are retained and materialized names cleared
for safe revisits. The save uses the existing database transaction, with option
and draft cache invalidation on failure. In the real Local database, injected
finalization failure rolled back the context, options and cached user draft.
Saving setup and deferring issuance also completed through actual browser forms;
it created the named Program and issued no credential or email.

Email setup offers the current design or a published credential-issued site
default, with a sanitized, inert sample. Program/Batch email rules retain their
priority. The certificate site-default checkbox is explicit; it does not rewrite
previously issued certificates. Skip email resets its choice to inheritance.
Deferred completion has truthful copy, Prepare first credential and Run setup
again.

## Repairs from current screen evidence

| Finding | Change |
| --- | --- |
| Recommended Grade was incorrectly mandatory for details-only setup and blocked Continue. | Separate selected/visible Grade from a Grade actually required by a certificate token. A behavioral planner test preserves both cases. |
| Five standard verification styles gave the text input approximately 20px of mobile height inside a larger shell. | Stretch the actual input and enforce a 44px minimum; all six standard styles pass in blocks and Elementor. |
| Independently rendered standard block/Elementor previews could reuse generated IDs. | Scope verification, Credential Profile and portal block renders, plus standard Elementor/Profile renders. Labels, ARIA and local certificate references move with their IDs. |
| Scoping could break SVG clip/use references. | Remap local `href`, `xlink:href` and `url(#id)` references on server and client while preserving external fragments. |
| Standard editor previews could expose working forms. | Make the server preview area inert for Verification, Credential Profile and My Credentials; preserve live inspector updates. Pro previews were already inert. |
| Mobile wizard disclosures, fields, Back/Exit and auxiliary Issue links had small targets. | Scoped 44px minimums for mobile controls and links; maintain compact desktop controls and center the current progress step. |
| WordPress placed other plugins' notices inside the nested Certloom hero heading, making mobile headers very tall. | Add WordPress's header boundary and move non-inline hero notices below it. Preserve original nodes, nested content and handlers; keep inline validation in place. A browser notice-event probe verifies those behaviors. |

## Widget coverage

| Type | Current real-browser coverage |
| --- | --- |
| Native Verification block | Professional, Meridian, Halo, Axis, Nova and Forge frontend and actual Gutenberg design controls. |
| Six standard Elementor verification widgets | Frontend result states and each widget's actual content controls updating its live canvas. |
| Credential Profile block and Elementor widget | Known credential artwork without a search form; missing-number preview; unique local references. |
| My Credentials block | Actual Cards/Grid/Table, status filtering, pagination, sorting, account ownership, anonymous privacy, mobile overflow and touch targets; live editor layout controls. |
| Pro Verification Studio block and Elementor widget | Campus, Authority, Executive, Celebration, Essential and Custom; all eight public result states, live preset/Custom controls and responsive overrides. |

Frontend matrices use real REST responses for designed, details-only, uploaded
image, revoked, summary, private, unsafe-art and missing credentials. Designed
results replace the search panel. Details and uploaded images retain input above
the result. Summary/private cases protect identity/artwork. Six simultaneous
forms share one request; independent IDs and reduced-motion behavior pass.

Current evidence is in
[`output/playwright/certloom-all-widgets-flow-20261002/`](../output/playwright/certloom-all-widgets-flow-20261002/).
The eight complete widget/editor/frontend runs contain 197 passing grouped
checks. [Accepted runs](../output/playwright/certloom-all-widgets-flow-20261002/accepted-runs.json)
keep incomplete diagnostic runs separate.

## Screen pass and cleanup

All 23 main admin screens passed final checks at actual viewport widths 1,440px
and 390px: one page title, no document overflow or fatal error, and no non-inline
notices inside the hero. The issuance screen was allowed to finish its REST
bootstrap before capturing. All 46 final screenshots have matching dimensions
and were reviewed in desktop/mobile contact sheets; no page script errors were
reported. These are initial screen checks, not every action and failure state.
[Final matrix](../output/playwright/certloom-all-widgets-flow-20261002/admin-screen-accepted.json),
[browser results](../output/playwright/certloom-all-widgets-flow-20261002/admin-final-polish-results.log).

| Area | Screens |
| --- | --- |
| Home and credential operations | Dashboard, Credentials, Recipients, Issue credential, Bulk Issuance, Import / issue from file, Send credential email |
| Context | Programs, Issuers & brands, Batches & cohorts, Signers |
| Design | Certificate designs, Design Rules, Email designs, Verification page |
| Connections and insights | Automations, Integrations, Verification Analytics |
| Configuration | Settings, Field manager, Credential data tools, Backup & Restore, Setup |

The ten wizard screens, including Welcome and Finish, have desktop/mobile
captures. The actual browser flow saved the reviewed Program/email choices and
deferred issuance without creating a placeholder credential. Final Issue and
Finish mobile probes explicitly verified the 390px viewport and 44px targets.
A separate unpublished Designer draft passed Insert Text, Undo, Redo and
Sample/Tokens preview-state checks, plus desktop/mobile shell checks and visual
review. Neither Publish nor a delivery action was invoked.
[Wizard flow](../output/playwright/certloom-all-widgets-flow-20261002/wizard-resume-results.json),
[final wizard mobile](../output/playwright/certloom-all-widgets-flow-20261002/wizard-final-mobile-results.json),
[Designer](../output/playwright/certloom-all-widgets-flow-20261002/designer-final-results.json).

Guarded cleanup removed the six owned pages/revisions, ten disposable
verification/portal credentials, QA recipient/image, unpublished Designer
draft/revisions/recovery metadata, QA Program/Issuer and temporary Pro adapter.
The original settings, setup options and administrator draft were restored.
A fresh comparison against the private pre-test SQL snapshot preserved all
original native and legacy business rows; the sole retained business difference
was the existing linked recipient's `updated_at` timestamp. Anonymous lookup
analytics were retained. The owned browser tab was closed and the user's Lance
Home tab remained open.
[Cleanup verification](../output/playwright/certloom-all-widgets-flow-20261002/cleanup-verification-final.log).

## Validation and remaining acceptance

The final focused matrix has 32 passing executions across PHP 8.2.29 and 8.5.1,
with no failures, exclusions, skips or diagnostics.
[Results](../output/audit-20261002/all-widget-guided-verified/results.json),
[totals](../output/audit-20261002/all-widget-guided-verified/totals.json).
The later mobile setup polish has ten additional passing suite executions;
the final notice polish has eight passing executions across both PHP runtimes.
All 18 changed-file syntax checks and the scoped whitespace check pass.
[Polish totals](../output/audit-20261002/mobile-setup-polish-final/totals.json),
[final admin totals](../output/audit-20261002/final-admin-notice-verified/totals.json),
[syntax](../output/audit-20261002/all-widget-syntax.json).
POT extraction contains 6,614 strings. This is technical catalog validation,
not human translation acceptance.

The tests use the existing workspace-linked Local WordPress 7.1.2 site and
Elementor 4.3.2 in the authorized Lance profile. Some background-tab controls
need DOM activation or editor selection APIs; native fills/selects and real
server responses are distinguished in the evidence. Viewport emulation and
programmatic focus do not accept native keyboard, screen-reader, zoom, RTL,
multisite, role variants or minimum-platform behavior. Print uses production
handlers and print CSS with a stubbed dialog.

Earlier capture timeouts and stale cached viewport sizes were retained as
diagnostics, then resolved with a fresh owned Lance tab, explicit actual-width
checks and final capture retries. A cleanup guard initially rejected differing
Windows path separators; comparing canonical resolved image/attachment paths
within the owned directory corrected that helper. No guard failure was bypassed.

The commercial Pro provider, full issuance/receipt and Designer/import/recovery
state coverage, portable whole-state backup, immutable font output, exact-payload
release/renderer acceptance and live provider checks remain in the
[ordered backlog](CERTLOOM-CODE-AND-REMAINING-WORK-2026-10-01.md#remaining-work-in-order).
Initial screen captures do not close those workflows.
