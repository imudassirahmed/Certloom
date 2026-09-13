#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase2_credentials_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1180,'height':960})
        await page.set_content(fixture(),wait_until='load')
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        review=page.locator('[data-certloom-manual-review]')
        msg=page.locator('[data-certloom-phase2-compatibility-message]')
        await ck(await review.is_disabled(),'detailsModeWaitsForRequiredCoreDetails')
        await ck(await msg.text_content()=='COMPLETE_CORE','detailsModeExplainsMissingCoreDetails')
        choices=page.locator('.certloom-presentation-choices')
        await ck(await choices.locator('.certloom-presentation-choice').count()==3,'desktopThreePresentationChoicesRemainAvailable')
        card=page.locator('.certloom-issue-v2-card')
        await ck(await card.evaluate("e=>getComputedStyle(e).borderRadius")=='0px','issueSurfaceIsFlatPanel')
        await page.locator('input[name="presentation_mode"][value="image"]').check()
        await ck(await page.locator('[data-certloom-presentation-panel="image"]').is_visible(),'imagePanelVisible')
        await ck(not await page.locator('[data-certloom-presentation-panel="design"]').is_visible(),'designPanelHiddenInImageMode')
        await ck(await page.locator('select[name="template_id"]').is_disabled(),'designSelectorDisabledInImageMode')
        await ck(await msg.text_content()=='COMPLETE_CORE','imageModeStillPrioritizesMissingCoreDetails')
        await page.locator('select[name="recipient_id"]').select_option('42')
        await ck(await msg.text_content()=='CHOOSE_PROGRAM','programBasisRequiresProgramAfterRecipient')
        await page.locator('select[name="program_id"]').select_option('11')
        await ck(await page.locator('input[name="title_source"][value="program"]').is_checked(),'programTitleInheritedByDefault')
        await ck(not await page.locator('[data-certloom-custom-title-panel]').is_visible(),'programTitleDoesNotRequireRepeatedTitleInput')
        await ck(await review.is_disabled(),'imageModeWaitsForCertificateImage')
        await ck(await msg.text_content()=='CHOOSE_IMAGE','imageModeRequiresCertificateImageAfterCoreDetails')
        await page.locator('input[name="certificate_image_id"]').evaluate("e=>{e.value='77';e.dispatchEvent(new Event('change',{bubbles:true}))}")
        await ck(not await review.is_disabled(),'imageModeCanReviewWithCoreDetailsAndImage')
        await ck(await msg.text_content()=='IMAGE_READY','imageReadyMessageIsExplicit')
        await page.locator('input[name="presentation_mode"][value="design"]').check()
        await ck(await page.locator('input[name="certificate_image_id"]').input_value()=='','switchingToDesignClearsImage')
        await ck(await page.locator('input[name="design_source"][value="inherited"]').is_checked(),'inheritedDesignIsDefaultInDesignMode')
        await ck(await page.locator('select[name="template_id"]').is_disabled(),'assignedDesignSelectorStaysDisabledForInheritedSource')
        await ck(await page.locator('[data-certloom-assigned-design-field]').is_hidden(),'assignedDesignFieldStaysHiddenForInheritedSource')
        await page.wait_for_function("document.querySelector('[data-certloom-phase2-compatibility-message]').textContent === 'COMPATIBLE'")
        await ck(await msg.text_content()=='COMPATIBLE','programInheritedDesignReadyWithoutTitleRetyping')
        await ck(await page.locator('.certloom-additional-details').get_attribute('open') is None,'additionalDetailsProgressivelyCollapsed')
        await ck(not await review.is_disabled(),'programDesignPathCanReviewWithInheritedTitle')
        await page.locator('.certloom-additional-details summary').click()
        await ck(await msg.text_content()=='COMPATIBLE','programCompatiblePathIsExplicit')
        await ck(await page.locator('.certloom-additional-details select[name="program_id"]').count()==0,'additionalDetailsDoesNotDuplicateProgram')
        program_expiry=page.locator('input[name="expiry_mode"][value="program"]')
        await ck(not await program_expiry.is_disabled(),'programValidityEnabledWithProgram')
        await page.locator('input[name="presentation_mode"][value="details_only"]').check()
        await ck(await page.locator('select[name="template_id"]').input_value()=='0','leavingDesignClearsOneOffDesign')
        await ck(not await review.is_disabled(),'returnToDetailsCanReviewWithoutArtwork')
        await ck(await msg.text_content()=='DETAILS_READY','detailsModeReadyMessageIsExplicit')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(len((await mobile.locator('.certloom-presentation-choices').evaluate("e=>getComputedStyle(e).gridTemplateColumns")).split())==1,'mobilePresentationChoicesStack')
        await ck(len((await mobile.locator('.certloom-file-intake-options').evaluate("e=>getComputedStyle(e).gridTemplateColumns")).split())==1,'mobileFileChoicesStack')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 2 credential workflows:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 2 credential workflows: presentation exclusivity, details-only review readiness, artwork requirements, progressive details and responsive file intake')
    print(f'\n{checks} UI Phase 2 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
