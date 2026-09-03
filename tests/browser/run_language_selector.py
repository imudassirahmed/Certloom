#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    p=subprocess.run(['php',str(ROOT/'tests/browser/build_language_selector_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def main():
    failed=[]; checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1000,'height':760});await page.set_content(fixture(),wait_until='load')
        async def ck(v,n):
            nonlocal checks; checks+=1
            if not v: failed.append(n)
        main=page.locator('[data-certloom-credential-locale]')
        await ck(await main.evaluate('(e)=>e.tagName')=='SELECT','mainLocaleIsSelect')
        labels=await main.locator('option').all_text_contents()
        await ck(any('Arabic' in x for x in labels) and any('Urdu' in x for x in labels) and any('French' in x for x in labels),'humanReadableChoices')
        await ck('interface still follows the WordPress user language' in (await page.locator('.description').first.inner_text()),'interfaceBoundaryExplained')
        row=page.locator('.certloom-locale-variant-row').first; sel=row.locator('[name="profile_template_variant_locale[]"]')
        await sel.select_option('ar'); await page.wait_for_timeout(30)
        fallback=page.locator('[data-certloom-locale-fallback]')
        await ck(await fallback.locator('option[value="ar"]').count()==1,'fallbackReflectsSelectedLocale')
        await page.get_by_role('button',name='Add language variant').click(); await page.wait_for_timeout(30)
        await ck(await page.locator('[name="profile_template_variant_locale[]"]').count()==2,'addedVariantUsesSelect')
        await ck(await page.locator('[name="profile_template_variant_locale[]"]').nth(1).evaluate('(e)=>document.activeElement===e'),'newVariantFocusesLanguageSelect')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'desktopNoOverflow')
        mobile=await browser.new_page(viewport={'width':390,'height':760});await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileNoOverflow')
        await ck((await mobile.locator('[data-certloom-add-locale-variant]').count())==1,'mobileAddActionAvailable')
        await browser.close()
    if failed:
        print('FAIL  UX-C08 language selector browser gate: '+', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-C08 language selector browser gate: real selectors, readable locale choices, variant fallback and responsive interaction')
    print(f'\n{checks} UX-C08 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
