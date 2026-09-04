#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture(view):
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_email_designs_library_fixture.php'),view],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0;failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1440,'height':980})
        await page.set_content(fixture('library'),wait_until='load')
        await ck(await page.get_by_role('heading',name='Email designs',exact=True).count()==1,'heading')
        await ck(await page.locator('.certloom-email-tabs a').count()==2,'twoTabsOnly')
        await ck(await page.locator('.certloom-email-tabs a.is-active').inner_text()=='Library','libraryActive')
        new_href=await page.get_by_role('link',name='New Template',exact=True).get_attribute('href')
        await ck('email_view=designer' in (new_href or ''),'newTemplateOpensDesigner')
        await ck(await page.locator('.certloom-email-card').count()==2,'factoryCards')
        await ck(await page.get_by_role('button',name='Customize',exact=True).count()==2,'factoryCustomize')
        await ck(await page.locator('.certloom-email-card__actions > a.button-primary').count()==0,'factoryNotDirectlyEdited')
        await ck(await page.locator('[name="scope_type"],[name="issuer_id"],[name="program_id"],[name="batch_id"]').count()==0,'noAssignmentControls')
        # Preview opens from the real compiled card content.
        await page.get_by_role('button',name='Preview',exact=True).first.click()
        dialog=page.locator('[data-certloom-email-preview-dialog]')
        await ck(await dialog.evaluate('(d)=>d.open'),'previewDialog')
        await ck('Signature Credential' in await page.locator('[data-certloom-email-preview-title]').inner_text(),'previewTitle')
        await page.locator('[data-certloom-email-preview-dialog] [data-certloom-email-dialog-close]').click()
        # Import is a deliberate modal and states that assignment authority does not travel.
        await page.get_by_role('button',name='Import',exact=True).click()
        imp=page.locator('[data-certloom-email-import-dialog]')
        await ck(await imp.evaluate('(d)=>d.open'),'importDialog')
        await ck('Assignments are never imported' in await imp.inner_text(),'assignmentFreeImportCopy')
        await ck(await imp.locator('input[type=file][accept*="json"]').count()==1,'jsonPackagePicker')
        await imp.locator('[data-certloom-email-dialog-close]').click()
        usage=page.locator('a[href*="design_type=email"]').first
        usage_href=await usage.get_attribute('href')
        await ck('page=certloom-design-rules' in (usage_href or '') and 'design_type=email' in (usage_href or '') and 'design_id=certloom-issued-signature' in (usage_href or ''),'usageDeepLink')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'libraryDesktopNoOverflow')
        await page.set_content(fixture('mine'),wait_until='load')
        await ck(await page.locator('.certloom-email-tabs a.is-active').inner_text()=='My Templates','mineActive')
        await ck(await page.locator('.certloom-email-card').count()==2,'customerCards')
        await ck(await page.get_by_role('link',name='Edit',exact=True).count()==2,'customerEdit')
        await ck(await page.get_by_text('Published',exact=True).count()>=1 and await page.get_by_text('Unpublished',exact=True).count()>=1,'publishedLifecycleVisible')
        await ck(await page.locator('form:has(input[name=published][value="0"]) button').count()==1,'unpublishAction')
        await ck(await page.locator('form:has(input[name=published][value="1"]) button').count()==1,'publishAction')
        await ck(await page.locator('a').filter(has_text='Export').count()==2,'exportAction')
        await ck(await page.locator('button').filter(has_text='Duplicate').count()==2,'duplicateAction')
        await ck(await page.locator('button').filter(has_text='Delete').count()==2,'deleteAction')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture('mine'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        btn=await mobile.get_by_role('link',name='New Template',exact=True).bounding_box()
        await ck(bool(btn and btn['height']>=36),'mobilePrimaryActionUsable')
        await mobile.close();await browser.close()
    if failed:
        print('FAIL  Email Designs Library browser gate:',', '.join(failed));return 1
    print('PASS  Email Designs Library browser gate: Library/My Templates separation, visual previews, publish lifecycle, assignment-free import/export and responsive management')
    print(f'\n{checks} Email Designs Library browser checks, 0 failures');return 0
raise SystemExit(asyncio.run(run()))
