# Default Batch and visible inheritance

Date: 2026-10-08. Follow-up to the compact issuance implementation.

## Delivered behavior

- The existing Batch popup has **Use as default batch for issuance**. Apply the popup changes, then **Save Program**. One active Batch can be the default for each Program, including a Batch created in that same unsaved Program draft.
- The Program's right Batch pane is wider: 430 px on desktop, up to 350 px at narrower desktop widths, and stacked on phones. It remains part of the shared Program page.
- Batch rows show a **Default** badge, **From Program** and **Set on batch** summaries. These identify certificate design, issued-email design, signing, base fields, validity/delivery, Batch field changes, dates and instructor choices. Field changes show names and requirement rules; dates show their actual values. Pending edits update these summaries immediately.
- The issuance popup preselects the Program default and shows its **Default** badge. **Change Batch** and **Clear** remain available. Review submits the selected Batch explicitly; the canonical issuance command never adds a default silently to an explicit no-Batch request.
- Inactive/foreign Batch selections are rejected. Retiring a default during the Program save clears the preference. Removing an unsaved default draft also clears it. Clearing the preference preserves unrelated prepared Batch edits.

## Implementation and boundaries

The optional preference is part of the existing typed Program Profile. Older Programs keep `default_batch_id = 0`. Program settings, staged Batch changes and default selection share the existing checked transaction and revision conflict protection. New draft identities resolve only after their Batch records are successfully saved; failure rolls back the whole command.

The read-only Program REST list adds a bounded Program-scoped query for direct certificate/signing configuration, plus the existing issued-email assignment option read. It returns configuration choices and field requirements without exposing raw technical metadata or fetching each row separately. Base Program settings continue to follow their existing Issuer/global inheritance.

Merge recovery defers source default IDs until Batch mappings are available, maps only newly inserted Programs, validates target ownership and preserves the preferences of existing target Programs. The recovery graph relationship checks reject missing/foreign preferences before promotion. Whole-state recovery retains its original IDs and uses the same relationship validation.

No new Program tabs or issuance field/design/email editors were added. This checkpoint does not perform the Free/Pro split or release validation.

## Validation

- **31 Lance browser checks:** wider shared layout; badge/default-only edits; inactive restrictions; reopening; live staged design/field/date summaries; clear/undo/remove behavior; default selection for new drafts; 1024/844/783/390 px page widths; issuance default startup; manual override and clear payloads; 1024×768 and 390×667 popup geometry outside the sidebar with no internal scrolling.
- **34 native Program checks:** real add/update handlers and MySQL transaction rollback, newly created default IDs, revision conflicts, invalid/foreign/inactive selections, batch count and inheritance reads, native REST startup selection/override/clear, explicit canonical no-Batch intent, and the production recovery relationship SQL on owned/foreign/missing defaults.
- **26 native compact issuance checks:** inherited data and settings, real canonical issue and idempotent receipt recovery, stale review/configuration rejection, enrollment/recipient ownership, required customer fields and source-owned evidence, privacy/permissions.
- Focused PHP runners: Program Batch changes (35), one-page workspace contracts (26), Program Profiles (57), default-Batch Merge recovery (10), and existing Merge/workspace/preflight/bounds/staging/job/review checks (38 total). The changed Profile/Batch/Merge recovery runners also passed under PHP 8.5.
- JavaScript runners: Batch changes/source summaries (17), Batch draft recovery (25), compact issuance race/recovery behavior (19).
- PHP/JavaScript syntax and Git whitespace checks passed.

All native fixtures rolled back. Before/after fingerprints matched across domain data and WordPress users, posts, metadata and options, including LearnDash data. Browser edits were not saved; issuance browser checks used intercepted read/review responses and created no credentials.

Browser evidence is kept locally under the ignored `output/playwright/default-batch-*` paths. The expected missing inherited design preview on the existing QA Program is unrelated to this change; the configured empty-design message remains visible.
