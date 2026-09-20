#!/usr/bin/env python3
"""Phase 4 browser UX contract for the six-step Issue Credential app."""
import asyncio
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]

def fixture():
    r = subprocess.run(['php', str(ROOT / 'tests/browser/build_ui_phase4_issue_credential_fixture.php')], cwd=ROOT, capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks = 0
    failed = []
    async def ck(ok, label):
        nonlocal checks
        checks += 1
        if not ok:
            failed.append(label)

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page = await browser.new_page(viewport={'width': 1440, 'height': 1000})
        page.set_default_timeout(7000)
        await page.set_content(fixture(), wait_until='load')
        await page.wait_for_selector('[data-issue-body]:not([hidden])')

        await ck(await page.locator('[data-issue-loading]').is_hidden(), 'bootstrapLoadingStateActuallyHidden')
        await ck(await page.locator('[data-issue-step]').count() == 6, 'sixFocusedSteps')
        await ck(await page.locator('[data-issue-step-button]').count() == 6, 'stepperMatchesPanels')
        await ck(await page.locator('[data-step-key="data"]').count() == 0 and await page.locator('[data-step-key="authority"]').count() == 0, 'dataAndAuthorityNotStandaloneSteps')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 1 of 6', 'startsAtRecipient')
        await ck(await page.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position') == 'sticky', 'desktopSummarySticky')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'desktopNoOverflow')

        # Setup-Wizard visual language: tokenized rounded panel, branded progress accent, no hard legacy min-height.
        detail_panel = page.locator('[data-step-key="details"]')
        await ck((await detail_panel.evaluate('(e)=>getComputedStyle(e).borderRadius')) != '0px', 'stepPanelUsesTokenizedRoundedSurface')
        current_num = page.locator('[data-issue-step-button].is-current .certloom-issue-step-number')
        await ck('rgb' in await current_num.evaluate('(e)=>getComputedStyle(e).backgroundColor'), 'currentStepUsesBrandTokenFill')
        await ck(await detail_panel.evaluate('(e)=>parseFloat(getComputedStyle(e).minHeight)||0') < 100, 'stepPanelHasNoLegacyTallMinHeight')

        # Contextual validation.
        await page.locator('[data-step-next]').click()
        await ck('Choose an existing Recipient' in await page.locator('[data-issue-message]').inner_text(), 'recipientValidationSpecific')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 1 of 6', 'validationKeepsCurrentStep')

        # New-recipient email validation.
        await page.locator('[data-recipient-mode="new"]').click()
        await page.locator('[data-field="new_recipient.display_name"]').fill('Amina New')
        await page.locator('[data-field="new_recipient.email"]').fill('not-an-email')
        await page.locator('[data-step-next]').click()
        await ck('valid recipient email' in await page.locator('[data-issue-message]').inner_text(), 'newRecipientEmailValidatedClientSide')
        await page.locator('[data-recipient-mode="existing"]').click()

        # Existing Recipient via REST.
        rec = page.locator('[data-picker="recipient"] [data-picker-search]')
        await rec.focus(); await page.wait_for_selector('[data-picker="recipient"] [role="option"]')
        await page.locator('[data-picker="recipient"] [role="option"]').first.click()
        await ck('Amina Khan' in await page.locator('[data-summary-recipient]').inner_text(), 'recipientSummaryLive')
        await page.locator('[data-step-next]').click()
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 2 of 6', 'contextIsSecondStep')
        await ck(await page.locator('[data-independent-note]').is_hidden(), 'independentGuidanceDoesNotLeakIntoProgramMode')

        # Quick setup controls remain spatially stable.
        program_add = page.locator('[data-picker="program"] [data-quick-open="program"]')
        program_search = page.locator('[data-picker="program"] [data-picker-search]')
        add_box = await program_add.bounding_box(); search_box = await program_search.bounding_box()
        await ck(bool(add_box and search_box and add_box['y'] < search_box['y']), 'quickAddLivesInPickerHeading')
        before_quick = await page.evaluate("window.__issueCalls.filter(c=>c.url.includes('/quick-program')).length")
        await program_add.click()
        dialog = page.locator('[data-quick-dialog="program"]')
        await ck(await dialog.get_attribute('open') is not None, 'quickProgramDialogOpens')
        cancel_box = await dialog.locator('.certloom-dialog-actions [data-quick-close]').bounding_box()
        primary_box = await dialog.locator('.certloom-dialog-actions .button-primary').bounding_box()
        close_box = await dialog.locator('header [data-quick-close]').bounding_box()
        await ck(bool(cancel_box and primary_box and primary_box['x'] - (cancel_box['x'] + cancel_box['width']) >= 80), 'quickDialogActionsAreSpatiallySeparated')
        await ck(bool(close_box and close_box['width'] >= 35 and close_box['height'] >= 35), 'quickDialogCloseHasDedicatedHitTarget')
        await ck(await dialog.evaluate('(e)=>e.scrollWidth <= e.clientWidth + 1'), 'quickProgramDialogHasNoHorizontalScrollbar')
        await ck(await dialog.locator('input[name="title"]').get_attribute('aria-required') == 'true', 'quickProgramRequiredFieldIsExplicit')
        await dialog.locator('[data-quick-close]').last.click()
        after_quick = await page.evaluate("window.__issueCalls.filter(c=>c.url.includes('/quick-program')).length")
        await ck(after_quick == before_quick and await dialog.get_attribute('open') is None, 'quickProgramCancelDoesNotSubmit')

        batch_quick = page.locator('[data-picker="batch"] [data-quick-open="batch"]')
        # Program is not selected yet, so Batch quick-create must stay guarded.
        await batch_quick.click()
        await ck(await page.locator('[data-quick-dialog="batch"]').get_attribute('open') is None, 'quickBatchRequiresProgramContext')

        # Program context owns inherited issuer; selected Program links back to its editor.
        program = page.locator('[data-picker="program"] [data-picker-search]')
        await program.focus(); await page.wait_for_selector('[data-picker="program"] [role="option"]')
        await page.locator('[data-picker="program"] [role="option"]').first.click()
        await page.wait_for_function("document.querySelector('[data-program-context-note]').textContent.includes('Example Authority')")
        manage_link = page.locator('[data-manage-program]')
        manage_href = await manage_link.get_attribute('href')
        await ck(bool(manage_href and 'program_id=1' in manage_href), 'manageProgramTargetsSelectedProgram')
        await ck(await manage_link.get_attribute('target') == '_blank' and 'noopener' in ((await manage_link.get_attribute('rel')) or ''), 'manageProgramPreservesIssuanceInNewTab')

        batch_quick = page.locator('[data-picker="batch"] [data-quick-open="batch"]')
        await batch_quick.click()
        batch_dialog = page.locator('[data-quick-dialog="batch"]')
        await ck(await batch_dialog.get_attribute('open') is not None, 'quickBatchDialogOpensAfterProgramSelection')
        await ck(await batch_dialog.evaluate('(e)=>e.scrollWidth <= e.clientWidth + 1'), 'quickBatchDialogHasNoHorizontalScrollbar')
        await ck(await batch_dialog.locator('input[name="name"]').get_attribute('aria-required') == 'true', 'quickBatchRequiredFieldIsExplicit')
        await batch_dialog.locator('[data-quick-close]').last.click()

        batch = page.locator('[data-picker="batch"] [data-picker-search]')
        await batch.focus(); await page.wait_for_selector('[data-picker="batch"] [role="option"]')
        await ck(await page.locator('[data-picker="batch"] [data-picker-results]').evaluate('(e)=>getComputedStyle(e).position') == 'static', 'batchResultsStayInFlowInsteadOfCoveringQuickAdd')
        batch_add_box = await page.locator('[data-picker="batch"] [data-quick-open="batch"]').bounding_box()
        batch_results_box = await page.locator('[data-picker="batch"] [data-picker-results]').bounding_box()
        await ck(bool(batch_add_box and batch_results_box and batch_add_box['y'] < batch_results_box['y']), 'batchQuickAddRemainsAboveOpenResults')
        await page.locator('[data-picker="batch"] [role="option"]').first.click()
        await ck(await page.locator('[data-picker="program"] [data-picker-selected]').is_hidden(), 'selectedProgramNotDuplicatedBelowPicker')
        await ck((await page.locator('[data-picker="program"] [data-picker-status]').inner_text()).strip() == '', 'selectedProgramClearsStaleMatchStatus')
        await program.focus(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-picker="program"] [data-picker-results]').is_hidden(), 'selectedProgramFocusDoesNotReopenExactMatch')

        # Independent issuer is chosen in Context, not in a standalone Authority step.
        await page.locator('[data-credential-basis="independent"]').click()
        await ck(await page.locator('[data-program-context]').is_hidden(), 'programControlsHideForIndependentCredential')
        await ck(await page.locator('[data-independent-note]').is_visible(), 'independentGuidanceVisible')
        issuer = page.locator('[data-independent-issuer] [data-picker="issuer"] [data-picker-search]')
        await ck(await issuer.is_visible() and (await issuer.input_value()) == '', 'programIssuerDoesNotLeakIntoIndependentPicker')
        await issuer.focus(); await page.wait_for_selector('[data-independent-issuer] [data-picker="issuer"] [role="option"]')
        await page.locator('[data-independent-issuer] [data-picker="issuer"] [role="option"]').first.click()
        await page.locator('[data-step-next]').click()

        # Details combines credential lifecycle and credential-specific data.
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 3 of 6', 'detailsIsThirdStep')
        title = page.locator('[data-field="credential_title"]')
        await title.fill('Independent Professional Recognition')
        await ck('Independent Professional Recognition' in await page.locator('[data-summary-title]').inner_text(), 'independentTitleUpdatesSummary')
        expiry = page.locator('[data-field="expiry_mode"]')
        await ck(await expiry.input_value() == 'permanent', 'independentDefaultsPermanent')
        effective_box = await page.locator('[data-field="valid_from"]').bounding_box()
        validity_box = await expiry.bounding_box()
        await ck(bool(effective_box and validity_box and abs(effective_box['y'] - validity_box['y']) <= 1.5), 'effectiveDateAndValidityControlsAlign')
        await ck(await page.locator('[data-oneoff-fields]').is_visible(), 'oneOffDataLivesInsideDetails')
        await page.locator('[data-add-oneoff]').click()
        await ck(await page.locator('.certloom-oneoff-row').count() == 1, 'oneOffFieldCanBeAddedInline')
        await page.locator('.certloom-oneoff-row [data-oneoff-key]').fill('membership_level')
        await page.locator('.certloom-oneoff-row [data-oneoff-value]').fill('Gold')
        await page.locator('[data-step-next]').click()

        # Certificate owns appearance and signing; independent issuer already came from Context.
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 4 of 6', 'certificateIsFourthStep')
        await ck('is-active' in ((await page.locator('[data-presentation="details_only"]').get_attribute('class')) or ''), 'detailsOnlyDefault')
        await ck('No design preview is needed' in await page.locator('[data-preview-status]').inner_text(), 'detailsOnlyDoesNotPretendRendererPreview')
        await ck('is-compact' in ((await page.locator('.certloom-issue-preview-card').get_attribute('class')) or ''), 'nonDesignPreviewUsesCompactStatusCard')
        await ck(await page.locator('[data-preview-stage]').evaluate('(e)=>e.getBoundingClientRect().height') < 180, 'nonDesignPreviewAvoidsLargeEmptyCanvas')
        manage_designs = page.locator('[data-step-key="certificate"] a[href*="certloom-templates"]')
        await ck(await manage_designs.get_attribute('target') == '_blank' and 'noopener' in ((await manage_designs.get_attribute('rel')) or ''), 'manageDesignsPreservesIssuanceInNewTab')
        signer = page.locator('[data-picker="signer"] [data-picker-search]')
        await ck(await signer.is_visible(), 'signerLivesWithCertificate')
        await signer.focus(); await page.wait_for_selector('[data-picker="signer"] [role="option"]')
        await page.locator('[data-picker="signer"] [role="option"]').first.click()
        await page.locator('[data-step-next]').click()

        # Email remains focused but overall journey is shorter.
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 5 of 6', 'emailDedicatedStep')
        await ck(await page.locator('[data-email-design-field]').is_visible(), 'emailDesignVisibleForReviewNext')
        manage_email = page.locator('[data-step-key="email"] a[href*="certloom-email-designs"]')
        await ck(await manage_email.get_attribute('target') == '_blank' and 'noopener' in ((await manage_email.get_attribute('rel')) or ''), 'manageEmailDesignsPreservesIssuanceInNewTab')
        await page.locator('[data-email-mode="later"]').click()
        await ck(await page.locator('[data-email-design-field]').is_hidden(), 'emailDesignHidesWhenDeferred')
        await ck('Do not email now' in await page.locator('[data-summary-email]').inner_text(), 'emailSummaryTracksIntent')
        await page.locator('[data-email-mode="review"]').click()
        await ck(await page.locator('[data-email-design-field]').is_visible(), 'emailDesignReturnsForReviewNext')

        # Review is server-built and final step 6.
        await page.locator('[data-step-next]').click()
        await page.wait_for_selector('[data-review-content]:not([hidden])')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 6 of 6', 'reviewFinalStep')
        await ck('Independent Professional Recognition' in await page.locator('[data-review-content]').inner_text(), 'serverReviewUsesCurrentTitle')
        technical = page.locator('[data-review-technical]')
        await ck(await technical.is_visible() and not await technical.get_attribute('open'), 'technicalEvidenceCollapsedByDefault')
        await ck('aaaaaaaa' in (await page.locator('[data-review-technical-body]').text_content()), 'reviewFingerprintAuditable')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'reviewNoDesktopOverflow')
        await page.close()

        mobile = await browser.new_page(viewport={'width': 390, 'height': 844})
        mobile.set_default_timeout(7000)
        await mobile.set_content(fixture(), wait_until='load')
        await mobile.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await mobile.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position') == 'static', 'mobileSummaryStatic')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'mobileNoOverflow')
        next_button = mobile.locator('[data-step-next]')
        height = await next_button.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)')
        await ck(height >= 43.5, 'mobilePrimaryActionMeets44px')
        await mobile.locator('[data-quick-dialog="program"]').evaluate('(e)=>e.showModal()')
        await ck(await mobile.locator('[data-quick-dialog="program"]').evaluate('(e)=>e.scrollWidth <= e.clientWidth + 1'), 'mobileQuickDialogHasNoHorizontalScrollbar')
        await mobile.close(); await browser.close()

    if failed:
        print('FAIL  Phase 4 Issue Credential UX browser: ' + ', '.join(failed))
        print(f'\n{checks-len(failed)} passed, {len(failed)} failures')
        return 1
    print('PASS  Phase 4 Issue Credential UX browser: six-step REST workflow, merged Details, Program-owned issuer context, Certificate signing, Email and Review')
    print(f'\n{checks} checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
