#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_i18n_locale_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(v,label):
            nonlocal checks; checks+=1
            if not v: failed.append(label)
        page=await browser.new_page(viewport={'width':1180,'height':900});await page.set_content(fixture(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Language-specific certificate designs').count()==1,'variantHeading')
        await ck(await page.get_by_text('RTL',exact=True).count()==1,'localeDirectionVisible')
        await ck(await page.locator('[data-certloom-locale-variant-list] .certloom-locale-variant-row').count()==2,'twoInitialVariants')
        await ck(await page.locator('[data-certloom-locale-fallback]').input_value()=='en_US','fallbackPreserved')
        options=await page.locator('[data-certloom-locale-fallback] option').evaluate_all('(els)=>els.map(e=>e.value)')
        await ck(options==['program_default','ar','en_US'],'fallbackOptionsFromVariants')
        await page.get_by_role('button',name='Add language variant').click()
        await ck(await page.locator('[data-certloom-locale-variant-list] .certloom-locale-variant-row').count()==3,'addVariant')
        last=page.locator('[data-certloom-locale-variant-list] .certloom-locale-variant-row').last
        await last.locator('input').fill('fr-FR')
        options2=await page.locator('[data-certloom-locale-fallback] option').evaluate_all('(els)=>els.map(e=>e.value)')
        await ck('fr_FR' in options2,'newLocaleAddsFallbackOption')
        await page.locator('[data-certloom-locale-fallback]').select_option('fr_FR')
        await ck(await page.locator('[data-certloom-locale-fallback]').input_value()=='fr_FR','newFallbackSelectable')
        await last.get_by_role('button',name='Remove').click()
        await ck(await page.locator('[data-certloom-locale-variant-list] .certloom-locale-variant-row').count()==2,'removeVariant')
        await ck(await page.locator('[data-certloom-locale-fallback]').input_value()=='program_default','removedFallbackFailsSafeToProgramDefault')
        await ck(await page.locator('[data-mixed-ltr]').get_attribute('dir')=='ltr','mixedLatinFirstDirection')
        await ck(await page.locator('[data-mixed-rtl]').get_attribute('dir')=='rtl','mixedArabicFirstDirection')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        rtl=await browser.new_page(viewport={'width':900,'height':780});await rtl.set_content(fixture(),wait_until='load');await rtl.evaluate("document.documentElement.setAttribute('dir','rtl')")
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await ck(await rtl.locator('[data-mixed-rtl]').is_visible(),'rtlMixedContentVisible');await rtl.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844});await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        heights=await mobile.locator('.certloom-locale-variant-row input,.certloom-locale-variant-row select,.certloom-locale-variant-actions .button,.certloom-locale-variant-actions select').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'mobileControlsAtLeast44')
        rows=mobile.locator('.certloom-locale-variant-row');b1=await rows.nth(0).locator('input').bounding_box();b2=await rows.nth(0).locator('select').bounding_box();await ck(bool(b1 and b2 and abs(b1['x']-b2['x'])<1),'mobileVariantSingleColumn');await mobile.close()
        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce');await reduced.set_content(fixture(),wait_until='load');await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionActive');await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active');await forced.set_content(fixture(),wait_until='load');await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive');await ck(await forced.locator('.certloom-program-locale-summary .certloom-program-readonly-value').is_visible(),'forcedColorsLocaleSummaryVisible');await forced.close();await browser.close()
    if failed:
        print('FAIL  I18N-02 browser gate:',', '.join(failed));print(f'\n{checks-len(failed)} passed, {len(failed)} failures');return 1
    print('PASS  I18N-02 browser gate: locale variants, declared fallback, mixed direction, responsive/RTL/accessibility media')
    print(f'\n{checks} I18N-02 browser checks, 0 failures');return 0
raise SystemExit(asyncio.run(main()))
