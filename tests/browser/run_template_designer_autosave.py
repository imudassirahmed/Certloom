#!/usr/bin/env python3
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture_html(recovery=False, autosave_status=''):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='3'; env['CERTLOOM_DESIGNER_RECOVERY']='1' if recovery else '0'
    if autosave_status: env['CERTLOOM_DESIGNER_AUTOSAVE_STATUS']=autosave_status
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_template_designer_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        recovered=await browser.new_page(viewport={'width':1200,'height':900})
        await recovered.set_content(fixture_html(True),wait_until='load'); await recovered.wait_for_timeout(150)
        await ck('Unsaved' in (await recovered.locator('[data-certloom-designer-dirty]').text_content() or ''),'recoveryRemainsUnsaved')
        await ck('Recovered' in (await recovered.locator('[data-certloom-designer-autosave-status]').text_content() or ''),'recoveryStatusVisible')
        await recovered.locator('[data-certloom-designer-form]').dispatch_event('submit')
        recovered_layout=json.loads(await recovered.locator('[data-certloom-designer-json]').input_value())
        await ck(abs(float(recovered_layout['elements'][0]['opacity'])-.73)<.001,'serverVettedRecoveryHydratesDesigner')
        await recovered.close()

        page=await browser.new_page(viewport={'width':1200,'height':900})
        await page.set_content(fixture_html(False),wait_until='load'); await page.wait_for_timeout(100)
        await page.locator('[data-certloom-designer-add="text"]').click(); await page.wait_for_timeout(2200)
        requests=await page.evaluate('window.__certloomAutosaveRequests')
        autos=[x for x in requests if x.get('action')=='certloom_template_designer_autosave']
        await ck(len(autos)>=1,'debouncedAutosaveRequestSent')
        if autos:
            await ck(autos[-1].get('expected_revision_id')=='1001','autosaveCarriesRevisionId')
            await ck(len(autos[-1].get('expected_revision_sha256',''))==64,'autosaveCarriesRevisionHash')
            await ck('elements' in json.loads(autos[-1].get('layout_json','{}')),'autosaveCarriesCanonicalLayout')
        await ck('Recovery checkpoint saved' in (await page.locator('[data-certloom-designer-autosave-status]').text_content() or ''),'autosaveSuccessVisible')
        page.once('dialog', lambda dialog: asyncio.create_task(dialog.accept()))
        await page.locator('[data-certloom-designer-reset]').click(); await page.wait_for_timeout(100)
        requests=await page.evaluate('window.__certloomAutosaveRequests')
        await ck(any(x.get('action')=='certloom_template_designer_autosave_discard' for x in requests),'resetDiscardsServerCheckpoint')
        await page.close()

        stale=await browser.new_page(viewport={'width':1200,'height':900})
        await stale.set_content(fixture_html(False,'stale'),wait_until='load'); await stale.wait_for_timeout(150)
        await ck(not await stale.locator('[data-certloom-designer-conflict-banner]').is_visible(),'staleCheckpointDoesNotMasqueradeAsLiveConflict')
        await ck(not await stale.locator('[data-certloom-designer]').evaluate('(e)=>e.classList.contains("has-autosave-conflict")'),'staleCheckpointDoesNotEnterConflictState')
        stale_requests=await stale.evaluate('window.__certloomAutosaveRequests')
        stale_discards=[x for x in stale_requests if x.get('action')=='certloom_template_designer_autosave_discard']
        await ck(bool(stale_discards),'staleCheckpointCleanupRequested')
        await ck(bool(stale_discards) and not stale_discards[-1].get('session_id'),'staleCheckpointCleanupDoesNotClaimFreshSessionOwnership')
        await ck('Save conflict' not in (await stale.locator('[data-certloom-designer-dirty]').text_content() or ''),'staleCheckpointDoesNotPolluteHeaderState')
        await stale.close()

        cleanup=await browser.new_page(viewport={'width':1200,'height':900})
        await cleanup.set_content(fixture_html(False,'cleanup_failed'),wait_until='load'); await cleanup.wait_for_timeout(150)
        cleanup_requests=await cleanup.evaluate('window.__certloomAutosaveRequests')
        cleanup_discards=[x for x in cleanup_requests if x.get('action')=='certloom_template_designer_autosave_discard']
        await ck(bool(cleanup_discards) and not cleanup_discards[-1].get('session_id'),'cleanupFailedRetryUsesServerVettedObsoleteCleanup')
        await ck(not await cleanup.locator('[data-certloom-designer-conflict-banner]').is_visible(),'cleanupFailedRetryDoesNotBecomeLiveConflict')
        await ck('Save conflict' not in (await cleanup.locator('[data-certloom-designer-dirty]').text_content() or ''),'cleanupFailedRetryDoesNotPolluteHeaderState')
        await cleanup.close()

        cleanup_error=await browser.new_page(viewport={'width':1200,'height':900})
        cleanup_error_html=fixture_html(False,'cleanup_failed').replace("window.__certloomAutosaveMode='success'","window.__certloomAutosaveMode='cleanup_fail'")
        await cleanup_error.set_content(cleanup_error_html,wait_until='load'); await cleanup_error.wait_for_timeout(150)
        await ck(not await cleanup_error.locator('[data-certloom-designer-conflict-banner]').is_visible(),'cleanupTransportFailureDoesNotMasqueradeAsLiveConflict')
        await ck('Save conflict' not in (await cleanup_error.locator('[data-certloom-designer-dirty]').text_content() or ''),'cleanupTransportFailureDoesNotPolluteHeaderState')
        await ck('temporarily unavailable' in (await cleanup_error.locator('[data-certloom-designer-autosave-status]').text_content() or ''),'cleanupTransportFailureReportsRecoveryCleanupProblem')
        await cleanup_error.close()

        conflict=await browser.new_page(viewport={'width':1200,'height':900})
        await conflict.set_content(fixture_html(False),wait_until='load'); await conflict.evaluate("window.__certloomAutosaveMode='conflict'")
        await conflict.locator('[data-certloom-designer-add="text"]').click(); await conflict.wait_for_timeout(2200)
        await ck(await conflict.locator('[data-certloom-designer]').evaluate('(e)=>e.classList.contains("has-autosave-conflict")'),'conflictStateFailsClosed')
        await ck((await conflict.locator('[data-certloom-designer-autosave-status]').text_content() or '').strip()=='' and await conflict.locator('[data-certloom-designer-conflict-banner]').is_visible() and 'Template changed in another session' in (await conflict.locator('[data-certloom-designer-conflict-banner]').text_content() or ''),'conflictMessageVisible')
        await ck('Save conflict' in (await conflict.locator('[data-certloom-designer-dirty]').text_content() or ''),'conflictPreservesBrowserChanges')
        await conflict.close(); await browser.close()
    if failed:
        print('FAIL  DSN-07 Designer autosave browser gate:', ', '.join(failed)); return 1
    print('PASS  DSN-07 Designer browser gate: vetted recovery, debounced autosave, exact concurrency proof, reset cleanup and conflict preservation')
    print(f'\n{checks} Designer autosave interaction checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
