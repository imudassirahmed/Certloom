#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_ops_04_fixture.php')],cwd=ROOT,capture_output=True,text=True)
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
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(120)
        # Quick field dialog: proper space + privacy policy.
        await page.get_by_role('button',name='Create new field').click()
        dialog=page.locator('[data-certloom-quick-field-dialog]')
        await ck(await dialog.evaluate('(d)=>d.open'),'quickDialogOpens')
        box=await dialog.bounding_box(); await ck(bool(box and box['width']>=650),'quickDialogComfortableWidth')
        label_input=dialog.locator('[data-certloom-quick-field-label]'); label_box=await label_input.bounding_box(); grid_box=await dialog.locator('.certloom-quick-field-grid').bounding_box()
        await ck(bool(label_box and grid_box and label_box['width']>grid_box['width']*.9),'fieldLabelUsesFullRow')
        field_wrap=label_input.locator('xpath=..'); field_label=field_wrap.locator('label'); label_text_box=await field_label.bounding_box()
        await ck(bool(label_text_box and label_box and label_text_box['y']+label_text_box['height']<=label_box['y']-5),'fieldLabelStacksAboveControl')
        type_wrap=dialog.locator('[data-certloom-quick-field-type]').locator('xpath=..'); sens_wrap=dialog.locator('[data-certloom-quick-field-sensitivity]').locator('xpath=..')
        type_box=await type_wrap.bounding_box(); sens_box=await sens_wrap.bounding_box(); await ck(bool(type_box and sens_box and abs(type_box['y']-sens_box['y'])<2),'typeSensitivityAlignedAsRow')
        sens=dialog.locator('[data-certloom-quick-field-sensitivity]'); exposure=dialog.locator('[data-certloom-quick-field-exposure]')
        await sens.select_option('internal'); await page.wait_for_timeout(20)
        await ck(await exposure.input_value()=='private' and await exposure.is_disabled(),'internalForcesPrivate')
        await sens.select_option('personal'); await page.wait_for_timeout(20)
        await ck(not await exposure.is_disabled(),'nonInternalAllowsVisibilityChoice')
        await exposure.select_option('verification'); await dialog.locator('[data-certloom-quick-field-label]').fill('Student ID'); await dialog.get_by_role('button',name='Create & add').click(); await page.wait_for_timeout(120)
        await ck(await page.locator('[data-certloom-scope-field-row="student_id"]').count()==1,'quickFieldAdded')
        await ck('Personal · Verification only' in (await page.locator('[data-certloom-scope-field-row="student_id"] .certloom-scoped-field-meta').inner_text()),'quickFieldPolicyVisible')
        await ck(await page.locator('[data-certloom-scoped-field-empty]').is_hidden(),'emptyStateClears')
        await page.wait_for_timeout(500)
        await ck(not await dialog.evaluate('(d)=>d.open'),'quickDialogClosesAfterCreate')
        # Recipient identity: searchable account linkage preserves an already-selected account
        # even when the current search page contains another user.
        # The enhancer wraps the select, so locate the search from the generated wrapper.
        wp_lookup=page.locator('select[data-certloom-context-lookup="wp_user"]').locator('xpath=..')
        await ck(await wp_lookup.locator('.certloom-context-lookup__search').count()==1,'wpUserLookupEnhanced')
        await wp_lookup.locator('.certloom-context-lookup__search').fill('Sam'); await page.wait_for_timeout(650)
        wp_select=page.locator('select[data-certloom-context-lookup="wp_user"]')
        await ck(await wp_select.input_value()=='42','wpUserCurrentSelectionPreserved')
        await ck(await wp_select.locator('option[value="77"]').count()==1,'wpUserSearchResultLoaded')
        await ck(await page.get_by_role('link',name='Issue credential',exact=True).count()==1,'recipientIssueActionVisible')
        # Template program filter: search and selected Program have their own aligned strip.
        program_search=page.locator('.certloom-library-filter-program .certloom-context-lookup__search'); program_select=page.locator('.certloom-library-filter-program select')
        await ck(await program_search.count()==1,'programLookupEnhanced')
        a=await program_search.bounding_box(); b=await program_select.bounding_box(); await ck(bool(a and b and abs(a['y']-b['y'])<2 and abs(a['height']-b['height'])<2),'programSearchSelectAligned')
        primary=await page.locator('.certloom-template-library-filter-primary input, .certloom-template-library-filter-primary select').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect()).map(r=>({y:r.y,h:r.height}))')
        await ck(len(primary)==4 and max(abs(x['y']-primary[0]['y']) for x in primary)<2,'primaryFiltersAligned')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(100)
        await ck(await mobile.locator('.certloom-library-filter-program .certloom-context-lookup').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileProgramLookupStacks')
        await mobile.get_by_role('button',name='Create new field').click(); await ck(await mobile.locator('.certloom-quick-field-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileQuickFieldSingleColumn')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileNoOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-OPS-04 browser:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-OPS-04 browser: quick-field privacy/layout and redesigned Template Program filter are responsive and coherent')
    print(f'\n{checks} UX-OPS-04 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
