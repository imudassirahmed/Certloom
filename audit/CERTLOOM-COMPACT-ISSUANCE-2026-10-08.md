# Compact Program issuance

Status: implemented. This replaces the earlier shared issuance megaform popup described in the administration simplification checkpoint. Free/Pro packaging, release validation and LearnDash integration validation remain separate work.

## Experience

- Program and Batch Issue actions open a short popup immediately. Home and Recipient actions ask for a Program when it is not already selected.
- Choose an existing recipient by name, email or external ID, or enter a new recipient's name and optional email. Keyboard selection resolves an exact record; matching names retain their record numbers.
- Choose a Batch only when the Program has batches or its repeat policy requires one. A Batch launch already has that context. Programs without batches omit the chooser.
- Review the recipient and inherited credential facts, then issue. The receipt offers the public credential, email follow-up and another issuance.
- Fields, certificate design, signing, title, privacy and delivery rules are read from existing configuration. Their editing forms are absent from issuance; the Program settings link remains available.
- Only configuration that explicitly requires an issuance-time answer exposes another input: manual certificate numbering, or an expiry date when the Program says “Decide at issuance.” Renewal-required Programs require that date. Ambiguous repeated enrollments expose a scoped exact-enrollment choice after canonical review reports ambiguity.

The popup uses Certloom's existing typography, colors and controls, with a small entry transition and reduced-motion support. Desktop positioning respects the WordPress expanded or folded sidebar. Mobile uses the available content width; short landscape spacing is condensed. The tested forms and reviews require no internal scrolling.

## Implementation and data ownership

`ProgramIssuancePopup`, `program-issuance.js` and `program-issuance.css` replace the retired popup branches of the advanced issuance application. The hidden legacy issuance URL retains its compatible application and endpoints. Ordinary popup pages do not load that application or its preview/catalog workflow.

One private, authenticated `program-bootstrap` REST call reads the small current configuration response. Recipient, Program and Batch searches are debounced and abortable; the Batch lookup is Program-scoped. Enrollment selection is an indexed, bounded lookup for the exact recipient/Program/Batch, with human-readable record/date/status labels.

The compact request opts into `ProgramIssuanceSettings`. The server derives the Program title, inherited design and lifecycle rules and strips configuration overrides and one-off field values from that request. Batch end date is authoritative when set; otherwise the reviewed effective date defaults to the current site date and is retained across retries. Existing canonical review/commit, numbering, field-source ownership, qualification and duplicate prevention remain authoritative.

Required personal data still comes from the recipient profile; enrollment data comes from the owned registration; Program defaults and protected result/source data use their existing services. Missing values block review and link to the existing recipient/Program workflow. Issuance does not add an alternative field editor, infer enrollment by email or let customer-entered values replace protected source results.

## Recovery

Confirmation freezes the canonical review fingerprint and exact payload. An interrupted, unreadable, partial or server-error response retains that request. The same tab can close/reopen or reload and reconcile it using a temporary actor-specific session record. An uncertain request cannot be replaced by another launch or review; expired authentication retains it for a later retry.

Receipt reconciliation checks the original actor and immutable issuance fingerprint before reading mutable Program configuration. A committed receipt therefore remains recoverable if the Program is subsequently disabled. A definitive validation rejection clears the commit identity and restores current editable context, retaining the recipient draft. A complete receipt clears the temporary recovery record. Browsers that disable session storage retain in-memory recovery until navigation.

## Validation record

- Native Local WordPress/MySQL: **26 passing checks** using actual canonical review, commit and replay inside owned rollback fixtures. Includes inherited Batch design/signing/date, Program title/expiry, override rejection, current data-source ownership, missing/protected data, exact enrollment identity, actor-bound recovery, disabled Program after commit, stale design after review, incompatible Batch and anonymous denial. Every tracked database row fingerprint matched after rollback; mail and external HTTP were disabled, including during WordPress bootstrap. Evidence: `output/compact-issuance-20261008/native-issuance-final.log`.
- Focused standalone regression: **13 passing suite runs** across PHP 8.2.29/8.5.1 and Node, without skips or diagnostics. Evidence: `output/compact-issuance-20261008/final-standalone/`. After final JavaScript fixes, the new race/recovery suite passed **19 checks**, including partial receipt handling, restored rejection, required Batch and renewal expiry. Evidence: `program-issuance-races-final.log`.
- Syntax: **19 passing checks**, covering eight PHP files on both runtimes and three JavaScript files with Node. Evidence: `output/compact-issuance-20261008/syntax-final.json`. `git diff --check` passed.
- Authorized Lance browser: **46 passing checks** (35 compact flow/recovery, 11 Batch/error/conditional/reduced-motion checks). Includes actual read-only review, keyboard search, matching identity, stale-result removal, missing/invalid input, one-request startup, inherited context, back navigation, responsive geometry, interrupted/reloaded recovery, receipt continuation and recipient-draft restoration after definitive rejection. Browser confirmation requests, issuance-only exception flags and one startup failure were intercepted fixtures; they did not create live credentials or change Program settings. Batch selection read genuine current data. Native fixtures separately verified real commits. Evidence: `output/playwright/compact-issuance-report-final.log` and `compact-issuance-states.log`.

Final responsive evidence covers 1536×960, 1024×768, 390×844, 390×667 and 844×390. Screenshot evidence is ignored local output: `issuance-05-final-desktop-review.png`, `issuance-06-final-phone-review.png`, `issuance-07-final-phone-recipient.png` under `output/playwright/`. These captures were opened for visual inspection.

LearnDash's installed Courses screen was inspected as a read-only reference for a focused primary action. Its settings, data and integration behavior were not changed. This task does not establish release authority, physical-device or screen-reader acceptance, or complete integration coverage.
