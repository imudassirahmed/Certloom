# Certloom: Free/Pro boundary and Program-centered batches

Date: 8 October 2026. Working product decision and implementation handoff before splitting the plugin.

## Scope and sequence

The user requested these two changes before the Free/Pro split: settle the product boundary, and manage batches within Program tabs. LearnDash has been installed by the user; do not configure it, issue through it, or run its acceptance tests in this phase. Comprehensive screen-by-screen and function-by-function validation follows the split, with LearnDash acceptance afterward.

The user confirmed **CSV bulk issuance in Pro** on 8 October. The matrix records the proposed boundary presented with that choice. Package ownership and entitlement checks are implemented during the split; the existing monolithic bulk workflow remains usable in this phase. No licensing or release-authority gates are changed here.

## Product principle

Free should complete the ordinary journey: set up an organization and Program, collect legitimate recipient details, design and manually issue a certificate, deliver it, verify it, and recover the site's own data. Pro saves staff time and supports sophisticated presentation and connected systems. Avoid certificate, recipient, Program or batch quotas and forced public branding.

Both editions share one issuance engine, field hierarchy, recipient ownership model, immutable credential history and recovery format. A paid license never substitutes for account authorization, source eligibility, database health or renderer release authority.

## Recommended matrix

| Area | Free | Pro adds |
| --- | --- | --- |
| Organization and setup | Guided organization/profile → Program → fields → design → delivery; profile-specific starter templates and missing-design-field guidance | Reusable advanced setup presets where an actual premium tool is involved |
| Programs and batches | Unlimited Programs; optional batches created and managed in each Program; recipients and membership; inherited fields/design; stable IDs and history | No artificial restriction on these core entities |
| Recipient details | Account-linked self-service, organization-selected editable fields, reusable person details and separate Program details, staff approval/correction, privacy controls | Automatic collection/resumption driven by connected systems; scheduled reminders |
| Field safety | Required fields, permitted sources, confidential/public visibility, ownership, provenance, validation and approval protections | Advanced integration mapping and supported trusted result intake; recipients still cannot assert earned scores/results |
| Manual issuance | Review and issue a credential, basic downloadable certificate/PDF when its renderer is release-ready, revoke/expire, correct/reissue through canonical history | CSV bulk issuance with mapping/review/resume (confirmed), scheduled or event-driven issuance |
| Design | Basic editor, starter designs, ordinary field placement, one default design per Program and basic inheritance; certificate placement left/right/center | Advanced conditional design rules, supported advanced elements/fonts, richer branded variants and premium authoring |
| Public verification | Verification page; QR/link validation; ordinary shortcode, block and Elementor widget; core certificate/details presentation | Verification Studio: five presets plus Custom, expanded layout/style and responsive controls, premium widget/block |
| Recipient account | Details needed, own profile/Program details, own credentials, safe account links and status explanations | No charge for recipients to access records or complete a request |
| Delivery and lifecycle | Basic issue email, transparent delivery state, permitted manual retries, certificate validity/status, cancellation and manual follow-up | Scheduled collection reminders, expiry communications and supported automatic delivery rules |
| Integrations | Core WordPress identity/account integration; documented extension points and public verification contracts | Supported LMS/WooCommerce issuance adapters, authenticated third-party issuance apps/API and signed webhooks, automation source mapping |
| History and reporting | Search/filter/export ordinary administrative records; necessary operational/audit history and diagnostics | Advanced analytics, broader reporting/scheduled exports where implemented |
| Recovery | Manual export/backup and validated restore, privacy/ownership checks, durable recovery of existing records; lossless preservation of dormant Pro configuration | Scheduled backups, supported cloud destinations and backup automation |
| Support | Clear in-product guides and documentation | Commercial support and updates according to the eventual product offering |

The matrix assigns ownership of existing and future capabilities; it does **not** claim every named advanced capability is already release-ready. Check the source feature inventory and acceptance receipts when splitting. Do not advertise universal layouts, arbitrary third-party data, cloud providers or renderer output that has not passed its own release criteria.

## License expiry and Pro deactivation

- Existing certificates remain verifiable and recipients retain access to already-issued records and available artifacts.
- Free preserves Program/batch relationships, field definitions, stored Pro settings, immutable snapshots and audit history. It does not erase data or rewrite templates on downgrade.
- New premium authoring and Pro issuance triggers pause with a clear reason. Staff retain core manual actions, review, cancellation, privacy operations and recovery.
- Preserve pending requests and execution receipts. Resuming Pro must recheck eligibility, ownership, configuration and idempotency through the existing coordinator; never replay issuance or resend email simply because entitlement returns.
- Actual companion removal, paused jobs and existing premium-designed artifact behavior require explicit split acceptance tests. Published previews must not silently fabricate a replacement certificate when Pro rendering code is absent.

## Architecture and packaging

Use a Free plugin and a separate Pro companion. WordPress.org's rules recommend external premium add-ons and exclude paid-locked local code from the directory plugin; restrained contextual upgrade links are allowed. Keep the distributable Free bundle consistent with those rules. [WordPress plugin directory guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)

Free owns the canonical schema, migrations, account/recipient contracts, field resolver, manual issuance engine, public verification, immutable history, core jobs, privacy and recovery validators. Pro registers premium adapters, automation entry points, premium authoring and scheduler policies through explicit interfaces. Do not create a second issuance engine, duplicate tables or let either package independently upgrade the same schema.

Create one explicit feature catalog at split time, with matching server-side checks, discoverable UI availability and package ownership. Reuse the existing `certloom_pro_feature_enabled` contract for premium features after auditing its callers. Do not convert `DomainFeatureGate`, `DomainAuthorityGate`, review preview constants or renderer acceptance manifests into commercial licensing gates.

Recovering data that includes Pro settings must be lossless in Free. Configuration can remain dormant; free-package recovery and safety code cannot depend on active premium adapters. Determine licensing/update provider and distribution details before implementing remote entitlement validation; do not add speculative network calls.

## Program-centered batches: implementation contract

Architecture fits already: each batch has a mandatory `program_id`. Keep batches as normalized entities, not arrays inside Program metadata. Their IDs, codes, memberships, signer assignments, field overrides, design assignments and issued credential references stay intact. No schema migration is needed.

- Programs remain the primary navigation destination. Opening **MBA**, for example, opens a workspace headed **MBA**, with **Details**, **Fields**, **Certificate**, and **Batches** belonging to that specific Program. This follows the user's clarification: relocating a global Batches submenu is insufficient. Users without batch permissions do not see the batch section.
- Fields are edited inside that Program's workspace; details and field saves are isolated, preserving the other section's current settings under a checked transaction and locking read. Certificate selection uses the existing Program-scoped design interface. Batch settings, field differences, members and design overrides remain inside the owning Program.
- Only the selected Program's batches are queried, including scoped search, status and pagination. Invalid explicit scopes fail rather than returning every Program's data.
- Add/edit a batch without selecting its Program again. The normal starting point is its name; dates, stable import code, signer and field changes are optional settings.
- Show Program-required fields when creating a batch; preserve all existing field/source validation, dependency guards and design inheritance.
- Keep member search, add/remove actions, notices and save/retry destinations inside the Program. Embedded writes carry both their existing action nonce and a verified Program context.
- Reject a batch ID belonging to another Program before rendering its editor or accepting a mismatched scoped write.
- Existing batch links resolve to the correct Program when the user has both permissions. Preserve the legacy route for batch-only roles; do not silently grant Program management or remove their existing authorized access.
- The previous Program quick-add handler remains compatible, but its normal UI now points to the Batches tab.

## Further simplifications recommended for the later screen review

1. One Program checklist, in this order: **Program → recipient details → certificate design → issuance**. Keep organization defaults reusable and optional batch differences out of the first setup path.
2. Explain blockers by responsible person: **Recipient needs to add details**, **Staff approval needed**, **Waiting for results**, **Ready to issue**, **Issued**. Show one useful next action; put technical diagnostics in details.
3. Keep results and recipient-entered details visibly separate. Explain why a field is requested and whether it can be reused in another Program.
4. Make automation creation a guided sentence: **When [event] happens in [source], issue for [Program], using [mapped fields]**. Preview missing information and eligibility before enabling the rule.
5. Show **Using Program defaults** unless someone chooses an override. Avoid asking staff to configure fields/design/email independently for every batch.
6. Use the existing Certloom design tokens and page components for every new screen. Functional passing tests do not close the user's outstanding UI consistency review.

## Targeted checks and later acceptance

This phase verifies scoped repository reads, Program context rejection, canonical batch navigation, changed PHP/JavaScript syntax and read-only Lance browser rendering. No LearnDash hooks or settings are exercised.

Implementation status: the selected Program has its own named workspace and navigation, rather than the global Programs subnavigation. Details, Fields and Certificate controls belong to that Program; Batches includes scoped add/edit, membership and design access. Stable batch records and legacy links are preserved. Scoped field/detail writes use a locking Program read inside the existing checked transaction; draft recovery separates the two forms and preserves fresh nonces.

Targeted evidence on 8 October:

- `tests/run-program-batch-workspace.php`: 26 behavior checks on each PHP runtime, covering scoped count/list/search/paging, bad scopes, mismatched context, capabilities, and legacy routing.
- `tests/run-program-workspace-sections.php`: 14 behavior checks on each runtime, covering isolated field/detail patches, preserved defaults/profile/structured metadata, unconfigured legacy field contracts, incomplete forms and dependency rejection.
- `tests/run-program-batch-draft.js`: 21 checks of the actual draft-recovery JavaScript, including fresh nonce preservation and separate Details/Fields draft keys.
- 23 focused standalone suite runs passed across PHP 8.2/8.5 and Node, without skipped checks or diagnostics. Receipt: ignored `output/program-batches-20261008/workspace-verified/`.
- Lance read-only checks covered the four Program-owned sections, responsive Fields/Certificate views, batch creation UI, an existing batch, member search/form context, legacy routing and cross-Program rejection. Native commits were not performed in this phase. Receipts and screenshots are under ignored `output/playwright/`.

These targeted checks do not substitute for post-split native save/issuance acceptance or the user's broader UI consistency review. No Free/Pro split, production authority override or LearnDash acceptance has happened in this phase.

After the split, validate Free alone, Free+Pro, entitlement loss/return and Pro removal. Test screen by screen and function by function, including native Program/batch creation/editing, membership changes, field/design inheritance, manual/bulk/app issuance, recovery, recipient ownership, reminders/expiry, concurrency and every block/widget. Re-run the canonical safety checks for the actual split artifacts rather than relying on the earlier monolithic checkpoint. Run LearnDash acceptance only after that overall validation, as requested.
