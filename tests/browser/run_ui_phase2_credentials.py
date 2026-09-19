#!/usr/bin/env python3
"""Phase 2 presentation/readiness contract on the alpha.4.79 Issue Credential app."""
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
        page=await browser.new_page(viewport={'width':1180,'height':960}); page.set_default_timeout(6000)
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_selector('[data-issue-body]:not([hidden])')
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        # Build the minimal reusable context through REST.
        rec=page.locator('[data-picker="recipient"] [data-picker-search]'); await rec.focus(); await page.wait_for_selector('[data-picker="recipient"] [role="option"]'); await page.locator('[data-picker="recipient"] [role="option"]').click(); await page.locator('[data-step-next]').click()
        program=page.locator('[data-picker="program"] [data-picker-search]'); await program.focus(); await page.wait_for_selector('[data-picker="program"] [role="option"]'); await page.locator('[data-picker="program"] [role="option"]',has_text='Program Beta').click(); await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/program-context')&&c.url.includes('program_id=2'))")
        await page.locator('[data-step-next]').click(); title=page.locator('[data-field="credential_title"]')
        await ck(await title.input_value()=='Beta Certificate','programTitlePrefillsAlwaysVisibleTitleInput')
        await title.fill('Beta Certificate — Executive'); await ck(await page.locator('[data-use-program-title]').count()==1,'directProgramTitleEditBecomesOneOffCustomTitle')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 4 of 8','credentialDataIsSeparateProgressiveStep')
        await ck(await page.locator('[data-scoped-field="grade"]').count()==1,'credentialGradeRemainsAvailableFromProfileContract')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 5 of 8','certificateAppearanceOwnStep')
        await ck(await page.locator('[data-presentation]').count()==3,'threePresentationChoicesRemainAvailable')
        await ck('is-active' in (await page.locator('[data-presentation="details_only"]').get_attribute('class') or ''),'detailsOnlyIsDefault')
        await ck(await page.locator('[data-image-controls]').is_hidden() and await page.locator('[data-design-controls]').is_hidden(),'detailsModeKeepsArtworkControlsHidden')
        await ck('No design preview is needed' in await page.locator('[data-preview-status]').inner_text(),'detailsOnlyDoesNotPretendDesignPreview')

        # Uploaded image is exclusive and cannot continue without a selected PNG/JPEG.
        await page.locator('[data-presentation="image"]').click(); await ck(await page.locator('[data-image-controls]').is_visible(),'imageControlsVisibleInImageMode'); await ck(await page.locator('[data-design-controls]').is_hidden(),'designControlsHiddenInImageMode')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 5 of 8','imageModeBlocksWithoutCertificateImage'); await ck('PNG or JPEG' in await page.locator('[data-issue-message]').inner_text(),'imageModeExplainsRequiredImage')

        # Details-only remains a valid renderer-independent path.
        await page.locator('[data-presentation="details_only"]').click(); await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 6 of 8','detailsOnlyCanContinueWithoutArtwork'); await page.locator('[data-step-prev]').click()

        # Certloom design supports inherited vs explicit assignment and live preview.
        await page.locator('[data-presentation="design"]').click(); await ck(await page.locator('[data-design-controls]').is_visible(),'designControlsVisibleInDesignMode'); await ck(await page.locator('[data-assigned-design]').is_hidden(),'assignedPickerHiddenForInheritedSource')
        await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/admin/issuance-preview'))")
        await page.wait_for_selector('[data-preview-document]'); await ck('Beta Certificate — Executive' in await page.locator('[data-preview-stage]').inner_text(),'inheritedDesignPreviewUsesCurrentCredentialTitle')
        await page.locator('[data-design-source="assigned"]').click(); await ck(await page.locator('[data-assigned-design]').is_visible(),'assignedDesignPickerShownOnlyWhenRequested')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 5 of 8','assignedDesignBlocksUntilPublishedDesignChosen'); await ck('published certificate design' in await page.locator('[data-issue-message]').inner_text(),'assignedDesignMissingChoiceMessageExplicit')
        templ=page.locator('[data-picker="template"] [data-picker-search]'); await templ.focus(); await page.wait_for_selector('[data-picker="template"] [role="option"]'); await page.locator('[data-picker="template"] [role="option"]').click(); await page.wait_for_selector('[data-preview-document]'); await ck('Assigned design' in await page.locator('[data-design-source-summary]').inner_text(),'assignedDesignProvenanceExplicit')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':900}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position')=='static','mobileSummaryNotSticky')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 2 credential workflows:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 2 credential workflows: REST context, editable Program title, exclusive certificate modes, artwork readiness and responsive flow')
    print(f'\n{checks} UI Phase 2 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
