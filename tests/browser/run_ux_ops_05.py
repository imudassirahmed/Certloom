#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_ops_05_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(v,label):
            nonlocal checks; checks+=1
            if not v: failed.append(label)
        page=await browser.new_page(viewport={'width':1130,'height':760})
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(120)
        form=page.locator('#program-form'); submit=page.locator('#submit')
        await ck(await submit.evaluate('(b)=>b.form&&b.form.id==="program-form"'),'programSubmitStillOwnedByOuterForm')
        await ck(await form.locator('form').count()==0,'quickDialogDoesNotCreateNestedForm')
        await page.get_by_role('button',name='Create new field').click(); dialog=page.locator('[data-certloom-quick-field-dialog]')
        await ck(await dialog.evaluate('(d)=>d.open'),'quickDialogOpens')
        head=await dialog.locator('.certloom-dialog-head').bounding_box(); box=await dialog.bounding_box()
        await ck(bool(head and box and head['x']-box['x']>=27 and head['y']-box['y']>=23),'quickDialogHasRealInnerPadding')
        for key in ['label','type','sensitivity','exposure']:
            control=dialog.locator(f'[data-certloom-quick-field-{key}]'); wrapper=control.locator('xpath=..'); label=wrapper.locator('label'); cb=await control.bounding_box(); lb=await label.bounding_box()
            await ck(bool(cb and lb and lb['y']+lb['height']<=cb['y']-5),f'{key}LabelAboveControl')
        type_box=await dialog.locator('[data-certloom-quick-field-type]').bounding_box(); sens_box=await dialog.locator('[data-certloom-quick-field-sensitivity]').bounding_box()
        await ck(bool(type_box and sens_box and abs(type_box['y']-sens_box['y'])<2),'quickDialogMiddleFieldsAligned')
        await page.get_by_role('button',name='Cancel').click(); await ck(not await dialog.evaluate('(d)=>d.open'),'quickDialogClosesWithoutTouchingProgramForm')
        await submit.click(); await page.wait_for_timeout(80)
        feedback=page.locator('[data-certloom-domain-form-feedback]')
        await ck(await feedback.is_visible(),'programInvalidFeedbackVisible')
        await ck('highlighted required field' in (await feedback.inner_text()).lower(),'programInvalidFeedbackActionable')
        await ck('is-invalid' in (await page.locator('#program-title').locator('xpath=..').get_attribute('class') or ''),'programInvalidFieldHighlighted')
        await ck(not await submit.is_disabled(),'invalidProgramDoesNotEnterFalseSavingState')
        await page.locator('#program-title').fill('Professional Certificate Program'); await submit.click(); await page.wait_for_timeout(80)
        await ck(await submit.is_disabled(),'programSubmitDisabledDuringSaving')
        await ck((await feedback.inner_text()).strip()=='Adding program…','programSavingFeedbackVisible')
        await ck((await submit.input_value())=='Adding program…','programButtonShowsProgress')
        await ck((await submit.get_attribute('aria-busy'))=='true','programButtonBusySemantic')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(100); await mobile.get_by_role('button',name='Create new field').click()
        await ck(await mobile.locator('.certloom-quick-field-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileQuickFieldsStack')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileQuickDialogNoOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-OPS-05 browser:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-OPS-05 browser: quick-field dialog preserves the Program form, spacing is collision-resistant, and Add Program has visible validation/saving states')
    print(f'\n{checks} UX-OPS-05 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
