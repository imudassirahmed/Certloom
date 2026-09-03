#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_email_designer_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0;failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1440,'height':1050})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Email designs',exact=True).count()==1,'workspaceHeading')
        await ck(await page.get_by_role('link',name='Create email design').get_attribute('href')=='https://example.test/wp-admin/admin.php?page=certloom-email-designs','createStartsCleanRoute')
        await ck(await page.locator('iframe[data-certloom-email-preview][sandbox=""]').count()==1,'sandboxedPreview')
        await ck(await page.locator('.certloom-email-block-card').count()==5,'structuredBlocksLoaded')
        await ck(await page.locator('.certloom-email-inheritance-broken').count()==1 and 'Needs attention' in await page.locator('.certloom-email-rule-warning').inner_text(),'brokenInheritanceVisible')
        # Token insertion goes to the active block field.
        text=page.locator('.certloom-email-block-card').filter(has=page.locator('textarea[data-block-key="html"]')).first.locator('textarea[data-block-key="html"]')
        await text.focus(); await text.press('End'); await page.locator('[data-certloom-email-token="recipient_name"]').click()
        await ck('{{recipient_name}}' in await text.input_value(),'mergeTokenInsertion')
        # Preview is isolated even with hostile HTML authored in a text block.
        await text.fill('<img src=x onerror="parent.__emailParentPwned=true"><p>Hello {{recipient_name}}</p>')
        await page.wait_for_timeout(150)
        await ck(await page.evaluate('window.__emailParentPwned===false'),'previewCannotExecuteAgainstParent')
        frame=page.frame_locator('iframe[data-certloom-email-preview]')
        await ck(await frame.locator('body').count()==1 and 'Sara Khan' in await frame.locator('body').inner_text(),'previewUsesSampleMergeData')
        await page.get_by_role('button',name='Mobile',exact=True).click()
        await ck('is-mobile' in (await page.locator('[data-certloom-email-preview-shell]').get_attribute('class') or ''),'mobilePreviewMode')
        await ck((await page.locator('[data-certloom-email-preview-label]').inner_text())=='Mobile preview','mobilePreviewLabel')
        # Purpose filter prevents an impossible inheritance save.
        purpose=page.locator('[data-certloom-assignment-purpose]')
        await purpose.select_option('credential_revoked')
        await ck(await page.locator('[data-certloom-assignment-submit]').is_disabled(),'noCompatibleDesignDisablesAssignment')
        await ck('No available email design matches' in await page.locator('[data-certloom-assignment-status]').inner_text(),'noCompatibleDesignExplained')
        await purpose.select_option('credential_issued')
        await ck(not await page.locator('[data-certloom-assignment-submit]').is_disabled(),'compatibleDesignReenablesAssignment')
        # Batch choice keeps Program as finder context without changing stored rule level.
        await page.locator('[data-certloom-assignment-scope]').select_option('batch')
        await ck(not await page.locator('[data-certloom-assignment-target="program"]').is_hidden() and not await page.locator('[data-certloom-assignment-target="batch"]').is_hidden(),'batchFinderShowsProgramAndBatch')
        await ck('rule itself is saved to the Batch' in await page.locator('[data-certloom-assignment-target="batch"] small').inner_text(),'batchFinderSemanticsExplained')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        # Empty-content submit fails locally with an inline, focusable explanation.
        empty=await browser.new_page(viewport={'width':1100,'height':900})
        await empty.set_content(fixture(),wait_until='load')
        while await empty.locator('[data-block-remove]').count(): await empty.locator('[data-block-remove]').first.click()
        await ck(await empty.locator('.certloom-email-content-empty').count()==1,'emptyContentState')
        await empty.get_by_role('button',name='Save email design').click()
        await ck(not await empty.locator('[data-certloom-email-content-status]').is_hidden() and 'Add at least one usable content block' in await empty.locator('[data-certloom-email-content-status]').inner_text(),'emptySaveExplainsRequiredContent')
        await empty.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        add=await mobile.get_by_role('button',name='Heading',exact=True).bounding_box(); token=await mobile.locator('[data-certloom-email-token="recipient_name"]').bounding_box()
        await ck(add['height']>=44 and token['height']>=44,'mobileTouchTargets')
        await mobile.close();await browser.close()
    if failed:
        print('FAIL  Email Designer browser gate:',', '.join(failed));return 1
    print('PASS  Email Designer browser gate: structured editing, sandboxed preview, merge data, fail-closed inheritance, empty-state validation and responsive touch UX')
    print(f'\n{checks} Email Designer browser checks, 0 failures');return 0
raise SystemExit(asyncio.run(run()))
