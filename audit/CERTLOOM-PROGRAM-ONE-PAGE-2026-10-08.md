# Certloom one-page Program correction

Date: 8 October 2026. Targeted implementation checkpoint before the Free/Pro split.

## Final product behavior

The user's latest instruction supersedes the earlier left-side layout: **batches are on the right**, inside the same enclosing Program editor. Add and edit share that layout. There are no Details/Fields/Certificate/Batches tabs and no separate batch card.

Program title, code, issuer, description, active status, profile defaults, lifecycle/language, fields and collection controls, shared values, certificate choice and the existing optional/technical settings remain in their original inline sections. Saved Programs retain email settings and credential activity. The batch dialog includes name, code, dates, signer, active status, certificate override, field differences and access to existing recipient controls. Previous/next navigation keeps the dialog open, including across list pages. Mobile stacks batches after the Program form within the same layout.

**Apply batch** prepares changes locally. **Save Program** commits the Program form, certificate choice and up to 25 prepared batch changes together. A new Program can include batches in its first save. Bulk configuration changes only checked settings: start/end date, design, active status and signer. Other values remain intact. The 25-change limit bounds one command; it is not a quota on stored batches. Email settings and recipient membership keep their existing scoped save commands; pending Program/batch changes must be saved before membership management.

CSV bulk issuance remains assigned to Pro in the [Free/Pro plan](CERTLOOM-FREE-PRO-AND-PROGRAM-BATCHES-2026-10-08.md). Editing several batch settings is part of core Program management. No split or commercial entitlement gates were added.

## Source and safety

- `ProgramWorkspaceView.php`, `program-workspace.css` and `program-workspace.js` own the integrated renderer, responsive layout and dialog/draft interactions. Native dialog top layers avoid page stacking conflicts. Pointer motion uses transform/opacity; keyboard and reduced-motion opening do not animate.
- `ProgramWorkspaceRest.php` exposes authenticated, capability-checked, Program-scoped **GET** list/detail/membership reads, bounded to 25 rows per page, with private/no-store headers. Foreign batch IDs are rejected. Technical metadata is not sent through the new editor transport.
- `DomainAdmin.php` retains canonical request validation and owns the checked Program transaction. `ProgramBatchChanges.php` bounds/validates payloads, locks batches in deterministic order and rejects stale or foreign edits. `DomainStructureAdmin.php` reuses the existing batch parser and field/integration/design dependency guards. No parallel issuance engine or schema migration is introduced.
- `TemplateRepository.php` supports a checked current locking read for assignments. Configuration writers acquire the existing generation marker before Program/batch locks. Revisions cover entity and assignment state. Draft retry retains original revisions, so retry cannot silently turn a stale edit into an overwrite.
- Metadata, legacy field contracts, memberships, stable IDs and issued credential history are preserved. Existing batch-only authorized routes and old partial Program handlers remain compatible. Program email UI uses the existing rule dialog with the certificate choice kept in the inline Program form.

## Verification receipts

All receipts are ignored local artifacts; no private database snapshot is committed.

| Evidence | Final result | Receipt |
| --- | --- | --- |
| Twelve focused suites | 22 executions passed across PHP 8.2/8.5 and Node; no skips or diagnostics | `output/program-single-page-20261008/final-focused/` |
| Actual native Program commands | 18 checks on each PHP runtime; fixtures rolled back and exact stored-state fingerprints preserved | `output/program-single-page-20261008/native-php82-final.json`, `native-php85-final.json` |
| Real Lance integrated UI | 28 checks passed; ten desktop/mobile screenshots; visibly rendered and fitted certificate preview; no product JavaScript errors in the final run | `output/playwright/program-integrated-rendered-preview-final.log` |
| Bulk draft status | Three checks passed; list/popup inactive status agrees; draft undone | `output/playwright/program-inactive-check.log` |
| Lance page-boundary/error recovery | Nine checks passed with controlled REST responses for 29 batches; GET only; no staged changes or database writes | `output/playwright/program-pagination-check-second.log` |
| Syntax and whitespace | Changed PHP files pass both runtimes; both admin JS files pass Node syntax; diff whitespace passes | Terminal check on 8 October |

The native probe calls the real nonce/capability-checked create/update handlers inside an owning rollback-only transaction. It covers Program plus staged batch creation; date/design/inactive bulk updates; preserved structured metadata, unconfigured contracts and membership; later invalid/stale batch rollback; stale Program rejection; foreign Program rejection; invalid new batch rollback and truncated forms. The outer rollback and every domain/core-table fingerprint are checked. LearnDash settings and hooks are not exercised.

The integrated Lance check covers one-page/right-side geometry, retained inline settings, field addition, published certificate selection and visibly rendered REST preview, two new draft batches, both navigation directions, bulk changes preserving earlier edits, inactive reopening, mobile overflow, reduced-motion emulation, shared add/edit controls, existing email dialog and recipient forms. Browser drafts are undone; no form commits occur. The paging check supplies controlled REST responses, checks both page boundaries and a failed-read retry, removes its route handler and returns to the saved Program.

Earlier attempt receipts are retained, including stale source assertions and browser timeouts/an error-only run. Visual review caught a blank preview that the initial iframe-existence assertion missed: the canonical preview fragment requires the existing admin composition styles. The corrected preview uses that escaped server-rendered fragment and the established text-fitting utility, scales it on resize, and now checks visible sample text, composition positioning and desktop/mobile bounds. The final receipts above supersede the earlier ones; no failing check was disabled. A separate real-page check confirms that bulk status `'0'` displays as Inactive in both list and popup. Both draft JavaScript suites and JS syntax pass after these fixes. Desktop/mobile screenshots were visually reviewed. Cleanup removes unused intermediate popup helpers/styles and simplifies batch help text.

## Remaining sequence

This completes the specific Program layout correction. The user's broader screen consistency review, Free/Pro package split and post-split function-by-function validation remain separate work. No release, commercial entitlement-provider acceptance, physical accessibility/printing acceptance or installed LearnDash acceptance is claimed. LearnDash remains untouched until the requested later stage.
