#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_EMAIL_DELIVERY_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_email_delivery_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        choose=await browser.new_page(viewport={'width':1180,'height':900})
        await choose.set_content(fixture('choose'),wait_until='load')
        await ck(await choose.locator('h1').inner_text()=='Send credential email','humanHeading')
        await ck(await choose.get_by_role('heading',name='Choose a credential first').count()==1,'directEntryGuidance')
        await ck(await choose.get_by_role('link',name='Choose credential').count()==1,'credentialContinuation')
        await ck(await choose.locator('input[name="version_id"]').count()==0,'noRawVersionIdEntry')
        await ck(await choose.get_by_role('heading',name='Reuse the same messages in Automations').count()==1,'automationBridgeDirectEntry')
        await ck(await choose.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'chooseDesktopNoOverflow')
        await choose.close()

        compose=await browser.new_page(viewport={'width':1440,'height':1000})
        await compose.set_content(fixture('compose'),wait_until='load')
        await ck(await compose.locator('input[name="version_id"][type="hidden"]').count()==1,'credentialContextHidden')
        await ck(await compose.locator('select[name="template_id"]:required').count()==1,'reusableTemplateSelector')
        await ck(await compose.locator('select[name="template_id"] option').count()==2,'multipleReusableTemplates')
        await ck(await compose.locator('input[name="destination"][type="email"]').count()==1,'destinationOverride')
        await ck(await compose.locator('input[name="delivery_kind"]').count()==3,'deliveryKinds')
        await ck(await compose.get_by_role('button',name='Preview email').count()==1,'previewAction')
        await ck(await compose.get_by_role('button',name='Send reviewed email').count()==0,'noPrematureSend')
        await ck('does not send anything' in (await compose.locator('.certloom-email-compose-actions').inner_text()).lower(),'mutationFreePromise')
        await ck(await compose.locator('input[name="subject"],textarea[name="html_body"],textarea[name="text_body"]').count()==0,'noCompetingTemplateEditor')
        await ck(await compose.get_by_role('link',name='Manage messages').count()==1 and await compose.get_by_role('link',name='Open Automations').count()==1,'singleTemplateAuthorityLinks')
        await ck(await compose.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeDesktopNoOverflow')
        await compose.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture('compose'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeMobileNoOverflow')
        cols=await mobile.locator('.certloom-email-compose-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'composeMobileSingleColumn')
        pills=await mobile.locator('.certloom-choice-pills').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(pills==1,'deliveryKindsMobileStack')
        btn=await mobile.get_by_role('button',name='Preview email').bounding_box(); actions=await mobile.locator('.certloom-email-compose-actions').bounding_box()
        await ck(btn['width'] >= actions['width']-2,'mobilePrimaryActionWide')
        await mobile.close()

        preview=await browser.new_page(viewport={'width':960,'height':1100})
        await preview.set_content(fixture('preview'),wait_until='load')
        await ck(await preview.locator('select[name="template_id"] option:checked').inner_text()=='Graduation message','selectedReusableTemplatePreserved')
        await ck(await preview.locator('input[name="delivery_kind"][value="resend"]:checked').count()==1,'reviewKindPreserved')
        meta=await preview.locator('.certloom-email-review-meta').inner_text()
        await ck('r*******@example.test' in meta and 'Resend' in meta and 'Graduation message' in meta,'humanReviewMeta')
        await ck(await preview.locator('.certloom-email-context-note').count()==2,'contextualAvailabilityInsteadOfGlobalWarning')
        notes=(await preview.locator('.certloom-email-context-note').all_inner_texts())
        await ck(any('online verification link' in n.lower() for n in notes) and any('no certificate file' in n.lower() for n in notes),'availabilityPlainLanguage')
        body=await preview.locator('.certloom-email-preview-body').inner_text()
        await ck('Amina Khan' in body and 'CERT-2026-001' in body,'immutableSnapshotMerge')
        await ck('Online verification is not available' in body,'recipientFacingVerificationCopy')
        await ck('normalized' not in body.lower() and 'authority' not in body.lower(),'noArchitectureJargonInRecipientMessage')
        await ck('https://example.test/verify/' not in await preview.content(),'noGatedVerificationUrlLeak')
        await ck(await preview.locator('.certloom-email-plain').count()==1,'plainTextFallback')
        tech=preview.locator('.certloom-technical-details')
        await ck(await tech.count()==1 and not await tech.get_attribute('open'),'technicalDetailsCollapsed')
        await tech.locator('summary').click()
        sha=(await preview.locator('[data-certloom-composition-sha]').inner_text()).strip()
        await ck(len(sha)==64 and set(sha)=={'a'},'compositionSha256')
        await ck(await preview.get_by_role('button',name='Send reviewed email').count()==1,'explicitSendAction')
        await ck('require another preview' in (await preview.locator('.certloom-email-confirm').inner_text()).lower(),'reviewIntegrityCopy')
        await ck(await preview.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'previewNoOverflow')
        await preview.close()

        history=await browser.new_page(viewport={'width':1000,'height':900})
        await history.set_content(fixture('history'),wait_until='load')
        await ck(await history.locator('.certloom-email-status.is-sent').count()==1,'sentStatus')
        await ck(await history.locator('.certloom-email-status.is-failed').count()==1,'failedStatus')
        await ck(await history.locator('.certloom-email-status.is-uncertain').count()==1,'uncertainStatus')
        await ck(await history.get_by_role('button',name='Retry failed').count()==1,'safeFailedRetry')
        await ck(await history.get_by_role('link',name='Review resend').count()==1,'uncertainRequiresReviewResend')
        await ck('Review before sending again' in await history.locator('.certloom-email-status.is-uncertain').locator('xpath=..').inner_text(),'uncertainNoAutoReplayCopy')
        table=await history.locator('tbody').inner_text()
        await ck('CERT-2026-001' in table and 'Graduation message' in table,'humanCredentialAndMessageHistory')
        await ck('example.test' in table and 'amina@example.test' not in table,'maskedHistoryDestination')
        await ck(await history.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'historyNoOverflow')
        await history.close()

        reduced=await browser.new_page(viewport={'width':360,'height':780},reduced_motion='reduce')
        await reduced.set_content(fixture('preview'),wait_until='load')
        duration=await reduced.locator('.fixture-motion').evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(float(duration.rstrip('s').split(',')[0]) <= 0.001,'reducedMotionDisablesTransitions')
        await ck(await reduced.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reducedMotionMobileNoOverflow')
        await reduced.close(); await browser.close()
    if failed:
        print('FAIL  Email Delivery browser gate:', ', '.join(failed)); return 1
    print('PASS  Email Delivery browser gate: reusable Automations templates, credential-connected entry, human review, privacy-safe history, responsive layout and safe retry semantics')
    print(f'\n{checks} Email Delivery browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
