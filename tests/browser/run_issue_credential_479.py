#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_issue_credential_479_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':1000}); page.set_default_timeout(6000)
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await page.locator('[data-issue-step]').count()==6,'sixStepPanels')
        await ck(await page.locator('[data-issue-step-button]').count()==6,'sixStepButtons')
        await ck(await page.locator('[data-step-status]').inner_text()=='Step 1 of 6','startsStepOne')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        rec=page.locator('[data-picker="recipient"] [data-picker-search]'); await rec.focus(); await page.wait_for_selector('[data-picker="recipient"] [role="option"]'); await page.locator('[data-picker="recipient"] [role="option"]').click()
        await ck('Amina Khan' in await page.locator('[data-summary-recipient]').inner_text(),'recipientSelectedViaRest')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 2 of 6','reachesContext')
        program=page.locator('[data-picker="program"] [data-picker-search]'); await program.focus(); await page.wait_for_selector('[data-picker="program"] [role="option"]'); await page.locator('[data-picker="program"] [role="option"]',has_text='Program Alpha').click()
        await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('kind=batch')&&c.url.includes('program_id=1'))")
        await page.wait_for_function("document.querySelector('[data-manage-program]').href.includes('program_id=1')")
        await ck('program_id=1' in (await page.locator('[data-manage-program]').get_attribute('href') or ''),'manageProgramLinkTracksContext')
        batch=page.locator('[data-picker="batch"] [data-picker-search]'); await batch.focus(); await page.wait_for_selector('[data-picker="batch"] [role="option"]')
        await ck(await page.locator('[data-picker="batch"] [role="option"]').count()==1 and 'Alpha Batch' in await page.locator('[data-picker="batch"] [role="option"]').inner_text(),'programALoadsOnlyAlphaBatch')
        await page.locator('[data-picker="batch"] [role="option"]').click(); await page.wait_for_function("document.querySelector('[data-summary-batch]').textContent.includes('Alpha Batch')")
        await program.fill('Beta'); await page.wait_for_timeout(300); await page.wait_for_selector('[data-picker="program"] [role="option"]'); await page.locator('[data-picker="program"] [role="option"]',has_text='Program Beta').click()
        await ck((await page.locator('[data-summary-batch]').inner_text()).strip() in ('','—'),'staleBatchClearsOnProgramChange')
        await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('kind=batch')&&c.url.includes('program_id=2'))"); await batch.focus(); await page.wait_for_selector('[data-picker="batch"] [role="option"]'); await page.locator('[data-picker="batch"] [role="option"]').click()
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 3 of 6','reachesCombinedDetails')
        title=page.locator('[data-field="credential_title"]'); await ck(await title.input_value()=='Beta Certificate','programTitlePrefilled'); await title.fill('Executive Track')
        await ck(await page.locator('[data-scoped-input="address"]').count()==1,'credentialDataMergedIntoDetails'); await page.locator('[data-scoped-input="address"]').fill('21 Clifton Road')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 4 of 6','reachesCertificateAndSigning')
        await ck(await page.locator('[data-inherited-issuer]').is_visible(),'programIssuerShownAsInheritedOnCertificate')
        await ck('Example Authority' in await page.locator('[data-inherited-issuer-name]').inner_text(),'programIssuerIdentityVisible')
        await page.locator('[data-presentation="design"]').click(); await page.locator('[data-design-source="assigned"]').click()
        templ=page.locator('[data-picker="template"] [data-picker-search]'); await templ.focus(); await page.wait_for_selector('[data-picker="template"] [role="option"]'); await page.locator('[data-picker="template"] [role="option"]').click(); await page.wait_for_selector('[data-preview-document]')
        await ck('Executive Track' in await page.locator('[data-preview-stage]').inner_text(),'previewUsesCurrentTitle'); await ck('21 Clifton Road' in await page.locator('[data-preview-stage]').inner_text(),'previewUsesCurrentCredentialData')
        signer=page.locator('[data-picker="signer"] [data-picker-search]'); await signer.focus(); await page.wait_for_selector('[data-picker="signer"] [role="option"]'); await page.locator('[data-picker="signer"] [role="option"]').click()
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 5 of 6','emailOwnStep'); await ck(await page.locator('[data-email-template] option').count()==2,'emailDesignsLoadedFromBootstrap')
        await page.locator('[data-step-next]').click(); await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/admin/issue/review'))"); await page.wait_for_selector('[data-review-content]:not([hidden])')
        await ck(await page.locator('[data-step-status]').inner_text()=='Step 6 of 6','reviewIsFinalSixthStep'); await ck('Executive Track' in await page.locator('[data-review-content]').inner_text(),'serverReviewShowsFinalTitle')
        tech=page.locator('[data-review-technical]'); await ck(await tech.is_visible(),'technicalReviewEvidenceAvailable'); await ck('aaaaaaaa' in (await page.locator('[data-review-technical-body]').text_content() or ''),'reviewFingerprintVisible')
        await page.locator('[data-issue-confirm]').click(); await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/admin/issue/issue'))"); await page.wait_for_selector('[data-issue-receipt]:not([hidden])')
        await ck('CERT-501' in await page.locator('[data-issue-receipt]').inner_text(),'canonicalReceiptRendered'); await ck(await page.get_by_role('link',name='Review email').count()==1,'postCommitEmailContinuation'); await ck(await page.get_by_role('link',name='Issue another credential').count()==1,'issueAnotherContinuation'); await ck(await page.get_by_role('link',name='Back to Credentials').count()==1,'credentialsContinuation')
        await ck(await page.evaluate("window.__issueCalls.some(c=>c.method==='POST' && c.url.includes('/admin/issue/issue'))"),'finalActionUsesIssueRest'); await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'completedDesktopNoOverflow')
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_selector('[data-issue-body]:not([hidden])'); await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow'); await ck(await mobile.locator('.certloom-issue-summary').evaluate("e=>getComputedStyle(e).position")=='static','mobileSummaryNotSticky'); await mobile.close(); await browser.close()
    if failed:
        print('FAIL  Issue Credential browser:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  Issue Credential browser: six-step REST journey, Program→Batch refresh, merged Details, Program-owned issuer, Certificate signing, server Review and canonical Issue')
    print(f'\n{checks} Issue Credential browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
