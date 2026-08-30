#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_BULK_ISSUANCE_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_bulk_issuance_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        # Upload/preflight is explicitly mutation-free and bounded to a reviewed CSV contract.
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(fixture('upload'),wait_until='load')
        await ck(await page.locator('h1').inner_text()=='Bulk Issuance','bulkHeading')
        await ck(await page.locator('.certloom-domain-preview-badge').count()==1,'gatedDomainBadge')
        await ck('PDF/PNG/JPEG authority remain closed' in await page.locator('.notice-warning').inner_text(),'authorityHonesty')
        await ck(await page.locator('select[name="program_id"]:required').count()==1,'activeProgramRequired')
        await ck(await page.locator('select[name="template_id"]:required').count()==1,'publishedTemplateRequired')
        await ck(await page.locator('input[name="valid_from"][type="date"]:required').count()==1,'effectiveDateRequired')
        await ck(await page.locator('input[name="expiry_mode"]').count()==3,'threeExpiryModes')
        await ck(await page.locator('input[name="bulk_csv"][type="file"]:required').count()==1,'csvRequired')
        await ck('custom:machine_key' in await page.locator('.certloom-bulk-schema').inner_text(),'customColumnContract')
        await ck(await page.get_by_role('button',name='Review CSV').count()==1,'reviewCsvAction')
        await ck(await page.get_by_role('button',name='Issue reviewed batch').count()==0,'noPrematureIssue')
        await ck('no credentials are created during review' in (await page.locator('.certloom-manual-actions').inner_text()).lower(),'mutationFreePromise')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'uploadDesktopNoOverflow')
        await page.close()

        # Mobile upload must remain usable with the dense CSV/shared-context form.
        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture('upload'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'uploadMobileNoOverflow')
        cols=await mobile.locator('.certloom-manual-grid-2').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'uploadMobileSingleColumn')
        await ck((await mobile.locator('.certloom-bulk-schema').bounding_box())['width'] <= 340,'schemaFitsMobile')
        await mobile.close()

        # Review keeps integrity proof available but collapsed and exposes the only mutation action.
        review=await browser.new_page(viewport={'width':900,'height':900})
        await review.set_content(fixture('review'),wait_until='load')
        tech=review.locator('.certloom-technical-details')
        await ck(await tech.count()==1 and not await tech.get_attribute('open'),'technicalDetailsCollapsed')
        await tech.locator('summary').click()
        batch=(await review.locator('[data-batch-sha]').inner_text()).strip(); plan=(await review.locator('[data-plan-sha]').inner_text()).strip()
        await ck(len(batch)==64 and set(batch)=={'b'},'batchSha256')
        await ck(len(plan)==64 and set(plan)=={'c'},'planSha256')
        await ck(await review.locator('.certloom-bulk-metrics > div').count()==3,'reviewMetrics')
        await ck('250' in await review.locator('.certloom-bulk-metrics').inner_text(),'reviewReadyCount')
        await ck('checks every row again before creating a credential' in await review.locator('.notice-info').inner_text(),'perRowRevalidationPromise')
        await ck(await review.get_by_role('button',name='Issue reviewed batch').count()==1,'explicitFinalIssueAction')
        await ck(await review.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reviewNoOverflow')
        await review.close()

        # Durable progress gives honest committed counts and bounded row-level errors.
        progress=await browser.new_page(viewport={'width':900,'height':900})
        await progress.set_content(fixture('progress'),wait_until='load')
        await ck('running' in (await progress.locator('.certloom-bulk-status').inner_text()).lower(),'runningState')
        await ck(await progress.locator('.certloom-bulk-metrics > div').count()==4,'progressFourMetrics')
        txt=await progress.locator('.certloom-bulk-metrics').inner_text()
        await ck(all(x in txt for x in ('175','172','3','2')),'progressCommittedCounts')
        await ck(await progress.locator('.certloom-bulk-errors li').count()==3,'rowLevelErrors')
        await ck(await progress.get_by_role('link',name='Refresh progress').count()==1,'refreshProgressAction')
        await ck('still processing this batch' in (await progress.locator('p').last.inner_text()).lower(),'progressCopy')
        await ck(await progress.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'progressNoOverflow')
        await progress.close()

        # Results drive administrators to Credential Management and do not claim retry/cancel UX yet.
        results=await browser.new_page(viewport={'width':760,'height':900})
        await results.set_content(fixture('results'),wait_until='load')
        await ck('partially succeeded' in (await results.locator('.certloom-bulk-status').inner_text()).lower(),'partialSuccessState')
        await ck(await results.get_by_role('link',name='Open Credential Management').count()==1,'credentialManagementAction')
        await ck(await results.get_by_role('link',name='Issue another batch').count()==1,'issueAnotherBatchAction')
        await ck(await results.get_by_role('button',name='Retry failed rows').count()==0,'retryUxStillDeferred')
        await ck(await results.get_by_role('button',name='Cancel bulk issuance').count()==0,'cancelUxStillDeferred')
        await ck('Email Delivery or the Recipient Portal' in await results.locator('p').last.inner_text(),'nextActionsTruth')
        await ck(await results.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'resultsNoOverflow')
        await results.close()

        reduced=await browser.new_page(viewport={'width':360,'height':780},reduced_motion='reduce')
        await reduced.set_content(fixture('upload'),wait_until='load')
        duration=await reduced.locator('.fixture-motion').evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(float(duration.rstrip('s').split(',')[0]) <= 0.001,'reducedMotionDisablesTransitions')
        await ck(await reduced.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reducedMotionMobileNoOverflow')
        await reduced.close(); await browser.close()
    if failed:
        print('FAIL  Bulk Issuance browser gate:', ', '.join(failed)); return 1
    print('PASS  Bulk Issuance browser gate: responsive upload/review/progress/results, exact review proofs, row-level outcomes, authority honesty and reduced motion')
    print(f'\n{checks} Bulk Issuance browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
