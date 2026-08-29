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

        compose=await browser.new_page(viewport={'width':1440,'height':1000})
        await compose.set_content(fixture('compose'),wait_until='load')
        await ck(await compose.locator('h1').inner_text()=='Email Delivery','emailHeading')
        await ck(await compose.locator('.certloom-domain-preview-badge').count()==1,'domainGateBadge')
        warning=(await compose.locator('.notice-warning').inner_text()).lower()
        await ck('normalized public verification' in warning and 'pdf attachment authority remain closed' in warning,'authorityHonesty')
        await ck(await compose.locator('input[name="version_id"]:required').count()==1,'versionRequired')
        await ck(await compose.locator('input[name="destination"][type="email"]').count()==1,'destinationOverride')
        await ck(await compose.locator('input[name="delivery_kind"]').count()==3,'deliveryKinds')
        await ck(await compose.get_by_role('button',name='Preview email').count()==1,'previewAction')
        await ck(await compose.get_by_role('button',name='Queue email delivery').count()==0,'noPrematureSend')
        await ck('never sends mail or mutates credential truth' in (await compose.locator('.certloom-manual-actions').inner_text()).lower(),'mutationFreePromise')
        await ck(await compose.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeDesktopNoOverflow')
        await compose.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture('compose'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'composeMobileNoOverflow')
        cols=await mobile.locator('.certloom-manual-grid-2').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'composeMobileSingleColumn')
        btn=await mobile.get_by_role('button',name='Preview email').bounding_box(); actions=await mobile.locator('.certloom-manual-actions').bounding_box(); await ck(btn['width'] >= actions['width']-2,'mobilePrimaryActionWide')
        await mobile.close()

        preview=await browser.new_page(viewport={'width':960,'height':1000})
        await preview.set_content(fixture('preview'),wait_until='load')
        sha=(await preview.locator('[data-composition-sha]').inner_text()).strip()
        await ck(len(sha)==64 and set(sha)=={'a'},'compositionSha256')
        await ck(await preview.locator('input[name="delivery_kind"][value="resend"]:checked').count()==1,'reviewKindPreserved')
        proof=await preview.locator('.certloom-template-preview-proof').inner_text()
        await ck('r*******@example.test' in proof and 'resend' in proof,'maskedDestinationAndKindProof')
        body=await preview.locator('.certloom-email-preview-body').inner_text()
        await ck('Amina Khan' in body and 'CERT-2026-001' in body,'immutableSnapshotMerge')
        await ck('not yet authoritative' in body.lower(),'gatedVerificationCopy')
        await ck('https://example.test/verify/' not in await preview.content(),'noGatedVerificationUrlLeak')
        await ck(await preview.locator('details pre').count()==1,'plainTextFallback')
        await ck(await preview.get_by_role('button',name='Queue email delivery').count()==1,'explicitQueueAction')
        await ck('integrity-bound' in (await preview.locator('.notice-info').inner_text()).lower(),'reviewIntegrityCopy')
        await ck(await preview.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'previewNoOverflow')
        await preview.close()

        template=await browser.new_page(viewport={'width':900,'height':1000})
        await template.set_content(fixture('template'),wait_until='load')
        await ck(await template.locator('input[name="subject"][maxlength="200"]').count()==1,'subjectBound')
        await ck(await template.locator('textarea[name="html_body"]').count()==1,'htmlTemplate')
        await ck(await template.locator('textarea[name="text_body"]').count()==1,'textTemplate')
        tokens=await template.locator('.description').inner_text()
        await ck('{{verification_link}}' in tokens and '{{credential_number}}' in tokens,'documentedMergeTokens')
        await ck('immutable issued Version snapshot' in await template.locator('.certloom-email-template > p').inner_text(),'immutableTemplateTruth')
        await ck(await template.get_by_role('button',name='Save email template').count()==1,'templateSaveAction')
        await ck(await template.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'templateNoOverflow')
        await template.close()

        history=await browser.new_page(viewport={'width':920,'height':900})
        await history.set_content(fixture('history'),wait_until='load')
        await ck(await history.locator('.certloom-email-status.is-sent').count()==1,'sentStatus')
        await ck(await history.locator('.certloom-email-status.is-failed').count()==1,'failedStatus')
        await ck(await history.locator('.certloom-email-status.is-uncertain').count()==1,'uncertainStatus')
        await ck(await history.get_by_role('button',name='Retry failed').count()==1,'safeFailedRetry')
        await ck(await history.get_by_role('link',name='Review resend').count()==1,'uncertainRequiresReviewResend')
        await ck('Do not auto-retry' in await history.locator('.certloom-email-status.is-uncertain').locator('xpath=..').inner_text(),'uncertainNoAutoReplayCopy')
        await ck('example.test' in await history.locator('tbody').inner_text() and 'amina@example.test' not in await history.locator('tbody').inner_text(),'maskedHistoryDestination')
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
    print('PASS  Email Delivery browser gate: responsive mutation-free review, immutable composition proof, template editing, privacy-safe history, safe retry semantics and reduced motion')
    print(f'\n{checks} Email Delivery browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
