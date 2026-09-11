#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    p=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase4_credentials_overview_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1414,'height':1000})
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(80)
        nav=page.locator('.certloom-product-subnav')
        await ck(await nav.evaluate('(e)=>e.classList.contains("is-below-hero")'),'subnavMarkedBelowHero')
        await ck(await nav.evaluate('(e)=>e.parentElement.classList.contains("certloom-domain-screen")'),'subnavInsideWorkspace')
        await ck(await nav.evaluate('(e)=>e.previousElementSibling&&e.previousElementSibling.classList.contains("certloom-page-hero")'),'subnavImmediatelyAfterHero')
        hero_box=await page.locator('.certloom-page-hero').bounding_box(); nav_box=await nav.bounding_box()
        await ck(hero_box and nav_box and nav_box['y']>=hero_box['y']+hero_box['height'],'subnavVisuallyBelowHero')
        await ck(await page.locator('.certloom-registry-secondary-actions').count()==0,'noDuplicateImportCta')
        await ck(await page.locator('.certloom-credential-search-filter .certloom-filter-label').inner_text()=='Search','searchFilterVisibleLabel')
        await ck(await page.locator('.certloom-credential-program-filter .certloom-filter-label').inner_text()=='Program','programFilterVisibleLabel')
        await ck(await page.locator('.certloom-credential-program-filter .certloom-context-lookup').count()==1,'programLookupGrouped')
        filter_box=await page.locator('.certloom-credential-filters').bounding_box()
        await ck(filter_box and filter_box['width']>700,'desktopFilterUsesAvailableWidth')
        table_box=await page.locator('.certloom-domain-table-wrap').bounding_box(); tech_box=await page.locator('.certloom-domain-gate-notice-deferred').bounding_box()
        await ck(table_box and tech_box and tech_box['y']>=table_box['y']+table_box['height'],'technicalStatusDeferredAfterRegistry')
        await ck(await page.locator('[data-view-current]').count()==1,'currentCredentialViewActionPresent')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoPageOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(80)
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await ck(await mobile.locator('.certloom-product-subnav').evaluate('(e)=>e.scrollWidth>=e.clientWidth'),'mobileSubnavContained')
        await ck(await mobile.locator('.certloom-credential-filters').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileFiltersStack')
        await browser.close()
    if failed:
        print('FAIL  alpha.4.25 Credentials overview browser gate:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  alpha.4.25 Credentials overview browser gate: hero/subtabs hierarchy, filters, deferred technical status, View continuity and responsive containment')
    print(f'\n{checks} alpha.4.25 Credentials overview browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
