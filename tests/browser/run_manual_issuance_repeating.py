#!/usr/bin/env python3
"""Chromium acceptance for Manual Issuance Subjects/Results row entry."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests/browser/build_manual_issuance_repeating_fixture.php'
def fixture():
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def main():
    checks=0; failed=[]
    async def ck(v,n):
        nonlocal checks; checks+=1
        if not v: failed.append(n)
    html=fixture()
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':900}); page.set_default_timeout(5000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(120)
        results=page.locator('[data-certloom-repeating-rowset="results"]'); add=results.locator('[data-certloom-repeating-add]')
        await ck(await results.locator('[data-certloom-repeating-row]').count()==1,'initialResultRow')
        subjects=page.locator('[data-certloom-repeating-rowset="subjects"]')
        await ck(await subjects.locator('[data-certloom-repeating-row]').count()==0 and await subjects.locator('[data-certloom-repeating-empty]').is_visible(),'emptySubjectsDoNotRenderPermanentEditorRow')
        await ck(not await add.is_disabled(),'addInitiallyEnabled')
        await add.click(); await page.wait_for_timeout(20)
        await ck(await results.locator('[data-certloom-repeating-row]').count()==2,'addRowAppends')
        second=results.locator('[data-certloom-repeating-row]').nth(1)
        await ck(await second.locator('input[name="repeating_data[results][1][subject]"]').count()==1,'newRowGetsUniqueStableIndex')
        await ck(await second.locator('input').first.evaluate('(e)=>document.activeElement===e'),'newRowFocusesFirstField')
        sadd=subjects.locator('[data-certloom-repeating-add]'); await sadd.click(); await page.wait_for_timeout(20)
        await ck(await subjects.locator('[data-certloom-repeating-row]').count()==1 and not await subjects.locator('[data-certloom-repeating-empty]').is_visible(),'firstAddRevealsSubjectEditorOnDemand')
        await second.locator('[data-certloom-repeating-remove]').click(); await page.wait_for_timeout(20)
        await ck(await results.locator('[data-certloom-repeating-row]').count()==1,'removeRowRemovesOnlyTarget')
        await ck(await add.evaluate('(e)=>document.activeElement===e'),'removeRestoresFocusToAdd')
        for _ in range(49):
            if not await add.is_disabled(): await add.click()
        await page.wait_for_timeout(40)
        await ck(await results.locator('[data-certloom-repeating-row]').count()==50,'rowsetStopsAt50Rows')
        await ck(await add.is_disabled() and await add.get_attribute('aria-disabled')=='true','addDisablesAt50Rows')
        last=results.locator('[data-certloom-repeating-row]').last
        await last.locator('[data-certloom-repeating-remove]').click(); await page.wait_for_timeout(20)
        await ck(await results.locator('[data-certloom-repeating-row]').count()==49 and not await add.is_disabled(),'removalReopensCapacity')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoHorizontalOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(100)
        mrow=mobile.locator('[data-certloom-repeating-rowset="results"] [data-certloom-repeating-row]').first
        cols=await mrow.locator('.certloom-manual-repeating-fields').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'mobileFieldsSingleColumn')
        madd=mobile.locator('[data-certloom-repeating-rowset="results"] [data-certloom-repeating-add]'); mremove=mrow.locator('[data-certloom-repeating-remove]')
        ah=await madd.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)'); rh=await mremove.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)')
        await ck(ah>=43.5 and rh>=43.5,'mobileActionsMeet44px')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoHorizontalOverflow')
        await mobile.emulate_media(forced_colors='active'); await mobile.wait_for_timeout(30)
        border=await mrow.evaluate('(e)=>getComputedStyle(e).borderStyle')
        await ck(border!='none','forcedColorsPreservesRowBoundary')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  Manual Issuance repeating Chromium: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  Manual Issuance repeating Chromium'); print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
