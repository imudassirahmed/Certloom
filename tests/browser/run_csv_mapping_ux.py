#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_csv_mapping_ux_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.locator('h1').inner_text()=='Map CSV columns','title')
        groups=await page.locator('select#map-0 optgroup').evaluate_all('(els)=>els.map(e=>e.label)')
        await ck(groups==['Recipient','Credential','Administrative','Verification','Custom fields'],'groupOrder')
        labels=await page.locator('select#map-0 option').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        for label in ['Full name','First name','Middle name','Last name','Email','External ID','Certificate number','Grade','Public visibility','Department']:
            await ck(label in labels,'option:'+label)
        expected=['holder_name','first_name','last_name','email','grade','cert_number','department','']
        for i,val in enumerate(expected):
            await ck(await page.locator(f'#map-{i}').input_value()==val,f'autoMap{i}')
        await ck(await page.locator('.certloom-mapping-status').count()==7,'autoMappedBadges')
        await ck(await page.get_by_role('button',name='Import certificates').count()==1,'importAction')
        heights=await page.locator('select,.button-primary').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=39.5 for h in heights),'desktopControlsCompact40')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoPageOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        scroll=mobile.locator('.ecapla-certverify-table-scroll')
        await ck(await scroll.evaluate('(e)=>e.scrollWidth>e.clientWidth'),'mobileTableContainedScroll')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        mobile_heights=await mobile.locator('select,.button-primary').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=43.5 for h in mobile_heights),'mobileTargets44')
        await mobile.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.certloom-mapping-status').first.is_visible(),'forcedColorsMappingStatus')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  CSV mapping UX browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  CSV mapping UX browser gate: grouped choices, auto-mapping, mobile containment and high contrast')
    print(f'\n{checks} CSV mapping browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
