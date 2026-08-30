#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_MANUAL_ISSUANCE_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_manual_issuance_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        # Compose desktop: actual workflow controls + accessible bounded recipient search.
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(fixture('compose'),wait_until='load')
        await ck(await page.locator('.certloom-manual-steps li').count()==4,'composeFourSteps')
        await ck(await page.locator('.certloom-manual-steps [aria-current="step"]').inner_text()=='Compose','composeCurrentStep')
        await ck(await page.locator('form[role="search"] #certloom-recipient-search').count()==1,'recipientSearchExists')
        await ck(await page.locator('label[for="certloom-recipient-search"]').count()==1,'recipientSearchLabelled')
        await ck(await page.locator('select[name="recipient_id"]').count()==1,'existingRecipientSelector')
        await ck(await page.locator('input[name="recipient_mode"]').count()==2,'existingNewRecipientModes')
        await ck(await page.locator('select[name="program_id"]:required').count()==1,'activeProgramControl')
        await ck(await page.locator('select[name="template_id"]:required').count()==1,'publishedTemplateControl')
        await ck(await page.locator('input[name="valid_from"][type="date"]').count()==1,'backdateControl')
        await ck(await page.locator('input[name="expiry_mode"]').count()==3,'threeExpiryModes')
        await ck(await page.get_by_role('button',name='Review exact issuance').count()==1,'reviewAction')
        await ck(await page.get_by_role('button',name='Issue credential').count()==0,'noPrematureIssueAction')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeDesktopNoDocumentOverflow')
        await page.close()

        # Compose mobile must collapse grids without clipping the document.
        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture('compose'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeMobileNoDocumentOverflow')
        cols=await mobile.locator('.certloom-manual-grid-2').first.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'composeMobileSingleColumn')
        await ck(await mobile.locator('.certloom-manual-recipient-search').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')==1,'searchMobileSingleColumn')
        await mobile.close()

        # Review: integrity proof stays available but collapsed; Preview and final action remain obvious.
        review=await browser.new_page(viewport={'width':768,'height':1000})
        await review.set_content(fixture('review'),wait_until='load')
        await ck(await review.locator('.certloom-manual-steps [aria-current="step"]').inner_text()=='Review','reviewCurrentStep')
        tech=review.locator('.certloom-technical-details')
        await ck(await tech.count()==1 and not await tech.get_attribute('open'),'technicalDetailsCollapsed')
        await ck(await tech.get_by_text('Review SHA-256',exact=True).count()==1 and await tech.get_by_text('Render Plan SHA-256',exact=True).count()==1,'technicalProofAvailable')
        await tech.locator('summary').click()
        sha=(await review.locator('[data-review-sha]').inner_text()).strip()
        await ck(len(sha)==64 and set(sha)=={'b'},'reviewSha256Proof')
        await ck(await review.locator('.certloom-template-preview-card').count()==1,'canonicalPreviewCard')
        await ck('browser preview' in (await review.locator('.certloom-template-preview-authority').inner_text()).lower(),'previewAuthorityHonest')
        await ck(await review.get_by_role('button',name='Issue credential').count()==1,'explicitIssueAction')
        await ck(await review.get_by_role('link',name='Back to compose').count()==1,'reviewBackAction')
        await ck(await review.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reviewNoDocumentOverflow')
        await review.close()

        # Receipt: committed identity is visible, public/artifact authorities remain visibly gated.
        receipt=await browser.new_page(viewport={'width':900,'height':900})
        await receipt.set_content(fixture('receipt'),wait_until='load')
        await ck(await receipt.locator('.certloom-manual-steps [aria-current="step"]').inner_text()=='Receipt','receiptCurrentStep')
        await ck('Issued' in await receipt.locator('.certloom-template-state-published').inner_text(),'issuedBadge')
        await ck(await receipt.locator('.certloom-manual-receipt-url code').count()==1,'gatedVerificationUrlIsCode')
        await ck(await receipt.locator('.certloom-manual-receipt-url a').count()==0,'gatedVerificationUrlNotLiveLink')
        await ck('PDF/PNG/JPEG exports are intentionally unavailable' in await receipt.locator('.notice-info').inner_text(),'artifactAuthorityHonesty')
        await ck(await receipt.get_by_role('link',name='Issue another credential').count()==1,'issueAnotherAction')
        await ck(await receipt.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'receiptNoDocumentOverflow')
        await receipt.close()

        reduced=await browser.new_page(viewport={'width':360,'height':780},reduced_motion='reduce')
        await reduced.set_content(fixture('compose'),wait_until='load')
        duration=await reduced.locator('.fixture-motion').evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(duration in ('0s','0s, 0s'),'reducedMotionDisablesTransitions')
        await ck(await reduced.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reducedMotionMobileNoOverflow')
        await reduced.close(); await browser.close()
    if failed:
        print('FAIL  Manual Issuance browser gate:', ', '.join(failed)); return 1
    print('PASS  Manual Issuance browser gate: responsive Compose/Review/Receipt, recipient search, exact-review proof, authority honesty and reduced motion')
    print(f'\n{checks} Manual Issuance browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
