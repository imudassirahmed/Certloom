#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_integrations_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(v,label):
            nonlocal checks; checks+=1
            if not v: failed.append(label)
        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Integrations').count()==1,'heading')
        await ck(await page.get_by_text('INT-01 + LMSCTX-01',exact=True).count()==1,'contract')
        await ck(await page.get_by_role('heading',name='Framework ready').count()==1,'ready')
        await ck(await page.get_by_text('Ready',exact=True).count()==1,'readyBadge')
        text=await page.locator('.certloom-integrations-workspace').inner_text()
        for phrase,label in [
            ('Normalized event context','context'),('Eligibility','eligibility'),('Identity and mapping','identity'),('Issuance provenance','provenance'),
            ('No LMS adapter is active yet','noAdapter'),('Tutor LMS and LearnDash will be implemented as adapters','adapterBoundary'),
            ('UX simplification program, then Tutor LMS','nextSequence')]:
            await ck(phrase in text,label)
        await ck('email' not in text.lower() or 'email' not in text.lower().split('normalized event context')[1].split('eligibility')[0],'noLearnerEmailSurface')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.get_by_role('heading',name='Framework ready').is_visible(),'mobileReadyVisible')
        await ck(await mobile.get_by_role('heading',name='No LMS adapter is active yet').is_visible(),'mobileAdapterStateVisible')
        await mobile.close()
        forced=await browser.new_page(viewport={'width':760,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.get_by_role('heading',name='Integrations').is_visible(),'forcedColorsHeadingVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  INT-01 Integrations browser gate:',', '.join(failed)); return 1
    print('PASS  INT-01 Integrations browser gate: framework truth, adapter boundary, responsive layout and forced-colors behavior')
    print(f'\n{checks} INT-01 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
