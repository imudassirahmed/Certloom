# Certloom issuance audit in Lance — 1 October 2026

Playwright attached through the existing Chrome extension after the user
explicitly authorized Playwright with Lance. The authenticated target is the
local Ecapla Test WP Site at `ecapla.local`. The CLI does not expose independent
Chrome profile metadata; Lance identity is grounded in the user's selection.

The Home → recipient → independent context → essentials → server Review path
works. Optional certificate and manual-email steps preserve the entered values
when revisited. This pass repaired ambiguous recipient choices, manual-email
summaries and field-error recovery. It stops before final issuance; no credential
or email was created through this browser run.

## Findings and implemented changes

| Finding | Evidence and impact | Result |
| --- | --- | --- |
| UX-01 / P1: indistinguishable recipient choices | Two `Lance receipt focus QA` records had identical visible labels. Picking the intended person could not be verified from the choices. | Identical labels in a recipient result set now include `Recipient #…`. The selected reference remains in the picker, live summary and matching server Review. Unique names retain their existing labels. Underlying recipient IDs and review enforcement are unchanged. |
| UX-02 / P2: unclear manual-email summary | The sidebar said `Email / Skip manual review`; choosing a design displayed its machine identifier in the summary. The choice controls a later manual review, while automation rules run separately. | The sidebar now says `Manual email / Prepare later`, the choice says `Prepare a manual email later`, and Review next uses the selected design's display name. Review and the live summary share the same formatter. Automation disclosure remains visible. |
| UX-03 / P2: disconnected validation feedback | The required-recipient error appeared below the entire grid while focus moved back to the search. It was below the viewport in the tested desktop state and had no field error association. | Validation errors appear beside affected inputs, use `aria-invalid` and `aria-describedby`, and focus the input. Editing clears the owned error state. Existing help descriptions and explicit labels are preserved. A failed cross-step review reveals the field before restoring focus. |

New summary/reference strings are available through the PHP localization
configuration. The POT and manifest/fixture source hashes were regenerated.
Human translation review remains separate.

## Captured journey

Every image below was saved and inspected in this audit run. The desktop viewport
was 1520 × 920 CSS pixels; mobile checks used 390 × 844. Scrollbars and the
existing display/browser scale affect raster dimensions. These viewport checks
do not establish native zoom or physical-device acceptance.

1. **Home — clear entry to issuance.** The main Issue credential action is
   discoverable on the populated QA site. Existing counters and the recent
   activity list provide context. Empty-site onboarding and other roles were
   not exercised here.

   ![Home with the Issue credential entry](../output/playwright/certloom-lance-audit-20261001/01-home-before.png)

2. **Recipient — ambiguity repaired; keyboard selection exercised.** Duplicate
   records now have distinct references. Tab moves from search to the first
   option; Enter selects it. The same reference survives selection and Review.
   The empty-recipient error is visible beside the focused input on mobile,
   with an accessible description, and editing removes it. Arrow-key combobox
   navigation and screen-reader announcements were not accepted in this pass.

   ![Duplicate recipients now show distinct record references](../output/playwright/certloom-lance-audit-20261001/09-recipient-duplicates-after.png)

   ![Required-recipient feedback beside the focused mobile search](../output/playwright/certloom-lance-audit-20261001/11-recipient-error-mobile-after.png)

3. **Context — independent recovery works.** Required Program validation points
   to the Program search and offers Independent credential as recovery. Switching
   to Independent clears that error and continues. Backtracking preserves the
   independent basis and the selected recipient. Actual Program/Batch inheritance,
   changing context during a live request and role variants remain browser work.

   ![Independent credential context after backtracking](../output/playwright/certloom-lance-audit-20261001/16-context-desktop-after.png)

4. **Essentials — errors repaired; short path works.** Empty title validation
   focuses the title, names the field separately from its error description and
   keeps the error nearby. Correcting it restores the original attributes. Review
   now reaches server validation without visiting optional artwork/email steps.
   Title, date, recipient and the later selected email choice survive backtracking.
   One-off, repeating, required scoped data and all date/number policies were not
   exercised in this browser run.

   ![Title-required error and optional early-review path on mobile](../output/playwright/certloom-lance-audit-20261001/12-title-error-mobile-after.png)

   ![Preserved essentials and Review now on desktop](../output/playwright/certloom-lance-audit-20261001/17-details-desktop-after.png)

5. **Certificate — optional default is clear.** Details only is selected and the
   screen explains that a design preview is unnecessary for this appearance.
   Returning here retains the entered essentials. Uploaded-image identity,
   design selection/rendering, signer selection and Designer recovery were not
   accepted through this browser path.

   ![Optional certificate and signing screen with preserved summary](../output/playwright/certloom-lance-audit-20261001/15-certificate-desktop-after.png)

6. **Manual email — terminology repaired.** The mobile layout stacks the choices
   and design selector while keeping the next action available. Prepare later
   and Review next both update the summary. Automatic hierarchy and the explicit
   Credential ready design display by name. The screen states that automations
   may still send separately. Actual Email Delivery preview/confirmation and
   provider delivery remain untested in this browser run.

   ![Manual-email choices and human-readable hierarchy summary on mobile](../output/playwright/certloom-lance-audit-20261001/10-email-mobile-after.png)

7. **Review — correct observed values on both layouts.** Server Review shows
   the selected recipient reference, synthetic title, independent context,
   effective date, no expiry, details-only presentation and `Review next ·
   Credential ready`. Final Issue is available. Mobile scroll width equals
   document client width in this state; measured controls remain inside the page
   bounds. This run deliberately stops at review and does not establish the
   issued receipt, duplicate retry, email or public-verification journey.

   ![Final server Review on desktop](../output/playwright/certloom-lance-audit-20261001/14-review-desktop-after.png)

   ![The same final Review on mobile](../output/playwright/certloom-lance-audit-20261001/13-review-mobile-after.png)

## Validation and limits

- The final targeted matrix contains 30 passing executions: fourteen PHP suites
  on PHP 8.5.1 and PHP 8.2.29, plus two JavaScript suites. There are no failures
  or diagnostic-bearing executions. Evidence:
  `output/audit-20261001/lance-issuance-final-checks/`.
- The field-error behavior suite passes eleven checks, including prior help/label
  preservation, replacement errors, unavailable inputs and cross-step focus.
  The issuance request-race suite passes eleven checks. These controlled-function
  checks supplement the observed browser behavior.
- Language-review acceptance skips one unavailable symlink fixture per PHP
  runtime. Both runs pass their other 48 checks; the skip is not platform acceptance.
- Changed PHP files parse on both runtimes. The production issuance JS and new
  field-error fixture parse; the scoped diff whitespace check passes.
- The exercised screens have zero recorded JavaScript errors. Four jQuery Migrate
  deprecation warnings remain visible per load; attribution to owned versus
  third-party handlers was not established.
- A hidden Chrome audit tab caused intermittent click timeouts and one screenshot
  timeout. Selecting the existing audit tab restored operation earlier in the
  run. At handoff Chrome again reported the page hidden, even after bringing it
  to the front; the last navigation could not finish. The tab remains on Details
  with the test values preserved, and the earlier completed Review screenshots
  above remain valid. These are browser-state interruptions, not demonstrated
  product failures. The failed screenshot was not used as evidence.
- The earlier full-page captures at nonzero scroll placed WordPress's fixed
  toolbar partway down the image. Those images are retained as raw evidence;
  the normalized screenshots above avoid that capture artifact.
- No full accessibility or security certification is inferred. Screen reader,
  native zoom, forced colors, reduced motion, RTL/mixed direction, role variants,
  busy/network-failure browser states and full-product journeys remain open.

## Next product work

Keep the short essentials-to-Review path prominent. For the next screen pass,
prioritize completed issuance/receipt, recipient creation, Program/Batch
inheritance, Designer, import/recovery and public verification. Use the same
field-error behavior and preserve edits through recoverable failures.

Portable whole-state recovery, expanded custom-font artifacts, provider access
and immutable production acceptance remain in the
[engineering audit](CERTLOOM-CODE-AND-REMAINING-WORK-2026-10-01.md). This UI pass
does not close those work items or authorize a release.
