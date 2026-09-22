#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    p=subprocess.run(['php',str(ROOT/'tests/browser/build_restore_continuation_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':900,'height':700}); await page.set_content(fixture(),wait_until='load')
        async def ck(v,n):
            nonlocal checks; checks+=1
            if not v: failed.append(n)
        button=page.locator('[data-certverify-continue-button]'); status=page.locator('[data-certverify-auto-continue-status]')
        await ck(await button.is_visible(),'manualContinueVisibleImmediately')
        await ck((await button.bounding_box())['height']>=43.5,'manualContinue44px')
        await ck('automatically' in ((await status.text_content()) or '').lower(),'automaticFallbackExplained')
        await page.wait_for_timeout(900)
        await ck(await page.evaluate('window.__submits')==1,'automaticContinuationAttemptedOnce')
        await ck(await button.is_disabled(),'buttonDisabledDuringAttempt')
        await ck('continuing restore' in ((await status.text_content()) or '').lower(),'inFlightStatus')
        await page.wait_for_timeout(6500)
        await ck(not await button.is_disabled(),'stalledAttemptRestoresManualAction')
        await ck('retry safely' in ((await status.text_content()) or '').lower(),'stalledAttemptExplainsRetry')
        await button.click(); await page.wait_for_timeout(50)
        await ck(await page.evaluate('window.__submits')==2,'manualRetrySubmitsExactlyOnce')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'noOverflow')
        await browser.close()
    if failed:
        print('FAIL  UX-C03 restore continuation browser gate: '+', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-C03 restore continuation browser gate: automatic progress, visible retry, stall recovery and accessible action')
    print(f'\n{checks} UX-C03 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
