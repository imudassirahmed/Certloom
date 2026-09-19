#!/usr/bin/env python3
"""Phase 4 browser UX contract for the alpha.4.79 eight-step Issue Credential app."""
import asyncio
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]


def fixture():
    r = subprocess.run(
        ['php', str(ROOT / 'tests/browser/build_ui_phase4_issue_credential_fixture.php')],
        cwd=ROOT, capture_output=True, text=True,
    )
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
        browser = await p.chromium.launch(
            headless=True,
            executable_path='/usr/bin/chromium',
            args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        )
        page = await browser.new_page(viewport={'width': 1440, 'height': 1000})
        page.set_default_timeout(7000)
        await page.set_content(fixture(), wait_until='load')
        await page.wait_for_selector('[data-issue-body]:not([hidden])')

        await ck(await page.locator('[data-issue-step]').count() == 8, 'eightFocusedSteps')
        await ck(await page.locator('[data-issue-step-button]').count() == 8, 'stepperMatchesPanels')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 1 of 8', 'startsAtRecipient')
        await ck(await page.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position') == 'sticky', 'desktopSummarySticky')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'desktopNoOverflow')

        # Validation is contextual rather than dumping every field at once.
        await page.locator('[data-step-next]').click()
        await ck('Choose an existing Recipient' in await page.locator('[data-issue-message]').inner_text(), 'recipientValidationSpecific')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 1 of 8', 'validationKeepsCurrentStep')

        # Existing Recipient via REST.
        rec = page.locator('[data-picker="recipient"] [data-picker-search]')
        await rec.focus(); await page.wait_for_selector('[data-picker="recipient"] [role="option"]')
        await page.locator('[data-picker="recipient"] [role="option"]').first.click()
        await ck('Amina Khan' in await page.locator('[data-summary-recipient]').inner_text(), 'recipientSummaryLive')
        await page.locator('[data-step-next]').click()

        # Independent credential is a first-class path, not an advanced mega-form disclosure.
        await page.locator('[data-credential-basis="independent"]').click()
        await ck(await page.locator('[data-program-context]').is_hidden(), 'programControlsHideForIndependentCredential')
        await ck(await page.locator('[data-independent-note]').is_visible(), 'independentGuidanceVisible')
        await page.locator('[data-step-next]').click()
        title = page.locator('[data-field="credential_title"]')
        await title.fill('Independent Professional Recognition')
        await ck('Independent Professional Recognition' in await page.locator('[data-summary-title]').inner_text(), 'independentTitleUpdatesSummary')
        expiry = page.locator('[data-field="expiry_mode"]')
        await ck(await expiry.input_value() == 'permanent', 'independentDefaultsPermanent')
        await page.locator('[data-step-next]').click()

        # Data/results remains a separate progressive step with optional one-off data.
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 4 of 8', 'dataResultsSeparateStep')
        await ck(await page.locator('[data-oneoff-fields]').is_visible(), 'oneOffDataAvailableWithoutMainFormClutter')
        await page.locator('[data-add-oneoff]').click()
        await ck(await page.locator('.certloom-oneoff-row').count() == 1, 'oneOffFieldCanBeAddedInline')
        await page.locator('.certloom-oneoff-row [data-oneoff-key]').fill('membership_level')
        await page.locator('.certloom-oneoff-row [data-oneoff-value]').fill('Gold')
        await page.locator('[data-step-next]').click()

        # Details-only is usable without renderer authority or a design preview.
        await ck(await page.locator('[data-presentation="details_only"]').get_attribute('class') and 'is-active' in (await page.locator('[data-presentation="details_only"]').get_attribute('class')), 'detailsOnlyDefault')
        await ck('No design preview is needed' in await page.locator('[data-preview-status]').inner_text(), 'detailsOnlyDoesNotPretendRendererPreview')
        await page.locator('[data-step-next]').click()

        # Independent authority remains explicit and searchable.
        await ck(await page.locator('[data-independent-issuer]').is_visible(), 'independentIssuerPickerVisible')
        issuer = page.locator('[data-picker="issuer"] [data-picker-search]')
        await issuer.focus(); await page.wait_for_selector('[data-picker="issuer"] [role="option"]')
        await page.locator('[data-picker="issuer"] [role="option"]').first.click()
        signer = page.locator('[data-picker="signer"] [data-picker-search]')
        await signer.focus(); await page.wait_for_selector('[data-picker="signer"] [role="option"]')
        await page.locator('[data-picker="signer"] [role="option"]').first.click()
        await page.locator('[data-step-next]').click()

        # Email intent is its own step and design controls are progressive.
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 7 of 8', 'emailDedicatedStep')
        await ck(await page.locator('[data-email-design-field]').is_visible(), 'emailDesignVisibleForReviewNext')
        await page.locator('[data-email-mode="later"]').click()
        await ck(await page.locator('[data-email-design-field]').is_hidden(), 'emailDesignHidesWhenDeferred')
        await ck('Do not email now' in await page.locator('[data-summary-email]').inner_text(), 'emailSummaryTracksIntent')
        await page.locator('[data-email-mode="review"]').click()
        await ck(await page.locator('[data-email-design-field]').is_visible(), 'emailDesignReturnsForReviewNext')

        # Review is server-built and specialist evidence is progressive disclosure.
        await page.locator('[data-step-next]').click()
        await page.wait_for_selector('[data-review-content]:not([hidden])')
        await ck(await page.locator('[data-step-status]').inner_text() == 'Step 8 of 8', 'reviewFinalStep')
        await ck('Independent Professional Recognition' in await page.locator('[data-review-content]').inner_text(), 'serverReviewUsesCurrentTitle')
        technical = page.locator('[data-review-technical]')
        await ck(await technical.is_visible() and not await technical.get_attribute('open'), 'technicalEvidenceCollapsedByDefault')
        await ck('aaaaaaaa' in (await page.locator('[data-review-technical-body]').text_content()), 'reviewFingerprintAuditable')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'reviewNoDesktopOverflow')
        await page.close()

        # Mobile keeps one-column fields/actions and removes sticky summary behavior.
        mobile = await browser.new_page(viewport={'width': 390, 'height': 844})
        mobile.set_default_timeout(7000)
        await mobile.set_content(fixture(), wait_until='load')
        await mobile.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await mobile.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position') == 'static', 'mobileSummaryStatic')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'mobileNoOverflow')
        next_button = mobile.locator('[data-step-next]')
        height = await next_button.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)')
        await ck(height >= 43.5, 'mobilePrimaryActionMeets44px')
        await mobile.close()
        await browser.close()

    if failed:
        print('FAIL  alpha.4.79 Phase 4 Issue Credential UX browser: ' + ', '.join(failed))
        print(f'\n{checks-len(failed)} passed, {len(failed)} failures')
        return 1
    print('PASS  alpha.4.79 Phase 4 Issue Credential UX browser: progressive eight-step REST workflow, independent path, Email and Review disclosure')
    print(f'\n{checks} checks, 0 failures')
    return 0


raise SystemExit(asyncio.run(run()))
