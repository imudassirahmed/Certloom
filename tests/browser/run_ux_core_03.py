#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_03_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1200,'height':980})
        await page.set_content(fixture(),wait_until='load')
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        row=page.locator('.certloom-premium-list-row').first
        await ck(await row.count()==1,'premiumListRowExists')
        await ck(await page.locator('.certloom-simple-program-card,.certloom-simple-credential-card,.certloom-recipient-card').count()==0,'noRejectedRegistryCards')
        primary=page.locator('.certloom-premium-list-actions .button-primary').first
        quiet=page.locator('.certloom-premium-row-link').first
        await ck(round((await primary.bounding_box())['height'])==36,'desktopPrimaryActionIsCompact36')
        await ck(round((await quiet.bounding_box())['height'])==36,'desktopQuietActionIsCompact36')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')

        controls=page.locator('.certloom-automations-workspace input[name="automation_name"],.certloom-automations-workspace select[name="source"],.certloom-automations-workspace .certloom-context-lookup__search,.certloom-automations-workspace .certloom-context-lookup select')
        heights=[]
        for i in range(await controls.count()): heights.append(round((await controls.nth(i).bounding_box())['height']))
        await ck(all(h==40 for h in heights),'automationControlsAll40px')
        borders=[]
        for i in range(await controls.count()): borders.append(await controls.nth(i).evaluate("el=>getComputedStyle(el).borderTopWidth"))
        await ck(all(b=='1px' for b in borders),'automationControlsAll1pxBorders')
        radii=[]
        for i in range(await controls.count()): radii.append(await controls.nth(i).evaluate("el=>getComputedStyle(el).borderTopLeftRadius"))
        await ck(all(r=='6px' for r in radii),'automationControlsUniformRadius')

        # Human form validation: prevent navigation and assert visible inline example/red state.
        form=page.locator('.certloom-automations-workspace form')
        await form.evaluate("f=>f.addEventListener('submit',e=>e.preventDefault())")
        await form.locator('button[type="submit"]').click()
        await page.wait_for_timeout(50)
        afield=form.locator('label').filter(has= form.locator('input[name="automation_name"]'))
        await ck(await form.locator('input[name="automation_name"]').get_attribute('aria-invalid')=='true','automationRequiredGetsAriaInvalid')
        await ck(await form.locator('.certloom-inline-field-error').count()==1,'automationRequiredGetsInlineError')
        await ck('Example: Send course completion email' in await form.locator('.certloom-inline-field-error').inner_text(),'automationInlineErrorUsesExample')
        await ck(await form.locator('input[name="automation_name"]').evaluate("el=>{const p=document.createElement('span');p.style.color='var(--cl-error)';document.body.appendChild(p);const expected=getComputedStyle(p).color;const actual=getComputedStyle(el).borderTopColor;p.remove();return actual===expected}"),'automationInvalidBorderUsesSemanticError')
        await form.locator('input[name="automation_name"]').fill('Completion email')
        await ck(await form.locator('.certloom-inline-field-error').count()==0,'automationErrorClearsWhenFixed')

        dform=page.locator('#certloom-domain-editor form')
        await dform.evaluate("f=>f.addEventListener('submit',e=>e.preventDefault())")
        await dform.locator('button[type="submit"]').click()
        await page.wait_for_timeout(50)
        await ck(await dform.locator('input[name="title"]').get_attribute('aria-invalid')=='true','entityRequiredGetsAriaInvalid')
        await ck('Example: Workplace Safety Certificate' in await dform.locator('.certloom-inline-field-error').inner_text(),'entityInlineErrorUsesExample')
        await ck(await dform.locator('input[name="title"]').evaluate("el=>{const p=document.createElement('span');p.style.color='var(--cl-error)';document.body.appendChild(p);const expected=getComputedStyle(p).color;const actual=getComputedStyle(el).borderTopColor;p.remove();return actual===expected}"),'entityInvalidBorderUsesSemanticError')

        dialog=page.locator('#quick')
        await ck(round((await dialog.locator('input').bounding_box())['height'])==40,'desktopDialogInput40')
        await ck(round((await dialog.locator('select').bounding_box())['height'])==40,'desktopDialogSelect40')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        mp=mobile.locator('.certloom-premium-list-actions .button-primary').first
        mq=mobile.locator('.certloom-premium-row-link').first
        await ck((await mp.bounding_box())['height']>=44,'mobilePrimaryTargetAtLeast44')
        await ck((await mq.bounding_box())['height']>=44,'mobileQuietTargetAtLeast44')
        await ck((await mobile.locator('#quick input').bounding_box())['height']>=44,'mobileDialogInputAtLeast44')
        await ck((await mobile.locator('#quick select').bounding_box())['height']>=44,'mobileDialogSelectAtLeast44')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-03 browser gate:', ', '.join(failed)); return 1
    print('PASS  UX-CORE-03 browser gate: compact premium lists, uniform forms and explicit required-field feedback')
    print(f'\n{checks} UX-CORE-03 browser checks, 0 failures')
    return 0
raise SystemExit(asyncio.run(run()))
