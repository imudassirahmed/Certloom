# Certloom simpler administration and customer details

Status: implemented and checked for this administration/customer-flow phase. This extends the completed single-page Program work. Free/Pro packaging and LearnDash validation remain separate; do not change LearnDash settings or integration behavior in this phase.

## Intended experience

An organization creates a Program, chooses the details its customers must provide, sets its certificate and email, and manages batches on the right of that same page. Issuing from a Program opens a compact popup. Home shows work needing attention. A customer supplies personal details through signup/profile and enrollment details for their own Program; staff or a trusted app supplies results. Existing permissions, exact account ownership, approval, immutable issuance and duplicate prevention remain authoritative.

## Screenshot audit and implementation checklist

Screenshots are local ignored evidence in `output/playwright/`, captured in the authorized Lance session before editing. Audit findings describe the observed screen, not inferred authorization or accessibility guarantees.

1. **Programs list and editor — needs improvement.** No batch count; the right batch pane is narrow; form and preview require too much scrolling. Evidence: `simplification-01-program-before.png`, `simplification-02-program-list-before.png`.
   - [x] Add bounded batch counts to both list modes.
   - [x] Widen right pane; compact existing inline controls without removing fields/design.
   - [x] Automatically refresh the canonical certificate preview and identify inherited template/source.
   - [x] Explain which Program details Certloom asks customers to provide, versus staff/app data.
2. **Issuance — needs improvement.** A separate six-step workspace interrupts the Program journey. Evidence: `simplification-03-issuance-before.png`; reproduce recipient search separately.
   - [x] Reuse the canonical REST review/commit engine in a compact popup with Program preselected.
   - [x] Remove the ordinary issuance navigation tab; keep compatible legacy routes.
   - [x] Fix recipient search based on reproduced behavior, including changing a selected recipient and stale responses.
3. **Recipient reviews and certificate requests — needs improvement.** Public account styles and large filters do not match administration; two extra tabs obscure the main tasks. Evidence: `simplification-04-recipient-reviews-before.png`, `simplification-05-certificate-requests-before.png`.
   - [x] Consolidate a compact Needs attention entry on Home with on-demand review/request details.
   - [x] Retain review decisions, waiting reasons, retry/cancel/history and reminder/deadline policy.
   - [x] Use the shared admin shell and remove extra navigation tabs.
4. **Fields and customer account — needs improvement.** Existing collection policies and Program requirements support the ownership model, but timing and scope are technical. Evidence: `simplification-08-fields-before.png`.
   - [x] Clarify reusable global customer details in Fields.
   - [x] Add supported website signup collection, keeping profile correction and approval available.
   - [x] Keep Program-specific requirements on Programs and expose them only to exactly owned enrollments.
   - [x] Never let recipients write scores/results or gain qualification from filling a form.
5. **Email Studio — needs improvement.** Three-pane block assembly and a large empty top area make routine email editing difficult. Evidence: `simplification-10-email-studio-before.png`.
   - [x] Replace routine block editing with a standard rich email composer: subject, preheader, message, branding, action and footer.
   - [x] Responsive live preview, merge variables and attachments use existing normalization/compiler/delivery contracts.
   - [x] Preserve existing nested/custom content and styles without flattening or automatic migration.
6. **Certificate Studio layers — inspect actual editor.** `simplification-11-design-studio-before.png` is a draft creation form, not evidence of layer behavior.
   - [x] Eye at the start, English layer names left aligned, bounded names, lock/delete always visible.
7. **Design assignment controls — needs improvement.** Inherited entries in dropdowns obscure whether the organization is overriding a parent setting.
   - [x] Use an explicit assign checkbox and show effective inherited design plus its source when unchecked.
   - [x] Preserve canonical posted assignment values, bulk no-change behavior and unavailable-design warnings.

## Architecture and validation

- Reuse repositories, field collection/context services, exact WordPress account links, Program registrations, pending request coordinator, review/issue fingerprint and commit idempotency. Do not add competing queues, ownership by email matching, or eligibility shortcuts.
- REST previews are authenticated, private, read-only and use synthetic sample values; email previews never send mail.
- Existing stored email layouts remain readable/editable; issuance snapshots and historical templates remain unchanged.
- Validate changed PHP on supported 8.2/8.5 runtimes; run focused contract/race/security tests and rollback-only native integration checks. Use Lance for populated, empty, error, responsive and keyboard/reduced-motion flows.
- Capture after screenshots and record results/limits here before the authorized develop checkpoint. A broader release audit and LearnDash checks follow the split.

Accessibility limits: browser DOM/keyboard checks are useful evidence, but do not constitute a screen-reader or physical-device audit. Neither has been completed in this phase.

## Completed behavior and after evidence

1. Programs show indexed, paged batch counts in both list layouts. Add/edit retains one unified page, a wider right batch pane, inline requirements and certificate settings. The canonical sample certificate loads automatically and refreshes on design/issuer changes. After evidence: `simplification-15-program-list-first.png`, `simplification-21-program-live-assigned.png`, `simplification-26-program-compact-final.png`. The current Local Program 3 has no inherited published design: the UI explains how to assign/configure one rather than displaying a misleading sample. A temporarily assigned design was previewed without saving.
2. Program Issue opens the shared REST issuance engine in a popup with the Program preselected. Details and server review replace the ordinary six-step journey. Its header/footer stay outside the scrolling form; WordPress Media Library stays in the popup top layer. Searching again after selecting a recipient preserves the new query. Lost/invalid commit responses retain the reviewed fingerprint and allow reconciliation through the same request. After evidence: `simplification-30-issuance-final.png`, `simplification-34-issue-375.png`, `simplification-35-review-375.png`, `simplification-36-media-375.png`. Browser review was read-only; native/unit fixtures verify commit contracts.
3. Home contains a compact Needs attention entry. Customer approvals and waiting certificates open on demand using the shared admin shell. The queue precedes collapsed filtering/policy/help; reason ownership, recheck, cancellation, history, reminders and deadlines retain the existing services. Ordinary review/request tabs are removed, with compatible legacy URLs preserved. After evidence: `simplification-25-waiting-final.png`, `simplification-37-queue-populated-fixture.png`, `simplification-38-review-populated-fixture.png`. Populated browser screenshots use explicitly labelled intercepted UI fixtures; native tests exercise real service actions. Conflict/failure checks preserve visible work and require refresh where needed.
4. Fields Manager describes reusable/global customer details and supports standard WordPress/WooCommerce signup plus later profile correction. Program quick-create explicitly asks who supplies a value and whether it belongs to a person, enrollment or result. Program rows state when Certloom asks the customer. Protected results remain staff/app owned. New signup answers use the existing exact WordPress account link and approval service, never qualification by form completion. WooCommerce checkout/API account creation is not blocked by controls absent from those forms; missing personal details remain available in the customer's profile.
5. Email Studio uses a normal Visual/Code rich composer with subject, inbox preview text, message, merge variables, action/footer, attachments and brand controls. Desktop/mobile sample previews use the canonical compiler. Nested/custom content, existing styles and intentional plain text are retained; generated plain text follows message edits. The current route does not load the old block editor/font picker scripts or their unused localization payload. Archived builder files remain for compatibility fixtures until the later cleanup. After evidence: `simplification-29-email-live-final.png`, `simplification-32-email-375.png`, `simplification-32-email-768.png`. A failed preview keeps the draft and last usable preview; the next edit retries successfully.
6. The actual certificate editor keeps the eye first, English names left aligned, Unicode names bounded to 24 characters plus ellipsis (full name in title), and lock/delete within the row. All 15 rendered rows passed geometry checks. After evidence: `simplification-12-real-design-studio-before.png`, `simplification-28-layers-final.png`.
7. Assignment checkboxes retain the original canonical select values. Unassigned designs hide lookup/search and explain the effective design/source or unavailable parent configuration. Assign/unassign and bulk checked/no-change states were checked without saving. Existing bulk draft and native Program atomic-save tests also pass. After evidence: `simplification-27-batch-final.png`, `simplification-31-batch-375.png`, `simplification-31-batch-768.png`.

## Final validation record

- Focused standalone suite: 25 passing runs across PHP 8.2.29/8.5.1 and Node, no failures/skips/diagnostics. After email asset cleanup, both email contract runs passed again. Evidence: `output/simplification-20261008/focused-final-02/totals.json` and `email-followup-final/totals.json`.
- Native rollback-only checks on each PHP runtime: signup/customer enrollment 21; actual email save/preview 14; Program single-page save/counts 19; request administration/lifecycle/actions 121; exact recipient account/approval links 40. All 430 checks passed. Fixtures rolled back and pre/post database fingerprints matched; mail/external HTTP disabled. Evidence: `signup-collection-native-complete-02-*`, `email-composer-native-complete-02-*`, `program-one-page-native-final-*`, `pending-request-admin-native-final-*`, `recipient-account-links-native-final-*` under `output/simplification-20261008/`.
- Syntax: all 30 changed/new PHP files passed on both runtimes and 13 JavaScript files passed Node syntax checks (73 checks). `git diff --check` passed. Evidence: `output/simplification-20261008/syntax-final.json`.
- Authorized Lance browser: 17 final desktop checks; 16 final responsive/reduced-motion checks at 375/768 px. Includes Escape/focus return, recipient search, real read-only server review, native Media Library, live composer, no horizontal overflow and assignment visibility. An additional 15 populated/conflict/error/assignment checks passed, for 48 final browser checks. Browser state/error fixtures are recorded separately in `output/playwright/simplification-states-final.log`; they do not issue, approve or cancel live data.
- Exact after screenshots were opened and visually inspected. Ignored browser/database evidence is not part of the source checkpoint; the private verified database snapshot remains outside tracked source.

## Boundaries and next phase

- A Program field becomes available automatically once an exact customer-owned Program registration exists. Creating a field or adding someone to a batch does not itself enroll the customer. Existing supported intake/registration paths remain authoritative. A source that only reports completion cannot expose an earlier enrollment form unless it also supplies that registration. No new LearnDash enrollment hooks are introduced here.
- Signup collection covers the standard WordPress and WooCommerce account registration forms. Custom signup builders/checkout UI need their own adapter to collect at that earlier moment; the owned profile/enrollment flow remains available afterward.
- This is the requested simplification checkpoint, not complete release validation. Physical devices, screen readers, all integration permutations and the broader post-split audit remain separate. LearnDash settings/data and integration behavior were not changed.
- Free/Pro split is still pending. The agreed boundary keeps CSV bulk issuance in Pro. Continue packaging/screen-by-screen release validation after this checkpoint, then validate LearnDash as requested.
