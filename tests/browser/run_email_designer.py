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
        await ck(await page.get_by_role('link',name='Email designs').count()==1,'backToLibrary')
        await ck(await page.locator('[data-certloom-designer-title]').inner_text()=='Professional Credential','designerIdentity')
        await ck(await page.get_by_text('Unpublished',exact=True).count()>=1,'unpublishedLifecycle')
        await ck(await page.locator('[data-certloom-email-designer] [name="scope_type"]').count()==0,'noAssignmentControls')
        await ck(await page.locator('[data-certloom-add-block]').count()==7,'boundedPrimitivePalette')
        initial=await page.locator('.certloom-canvas-block').count()
        await ck(initial>=3,'defaultBlocksLoaded')
        await ck(await page.locator('.certloom-canvas-drag-handle[draggable="true"]').count()==initial,'dragHandlesPresent')
        # Select the text block, edit it and insert dynamic data at the cursor.
        text_block=page.locator('.certloom-canvas-block').nth(1)
        await text_block.click()
        editor=page.locator('[data-certloom-email-inspector] textarea[data-field="html"]')
        await ck(await editor.count()==1,'contextualInspector')
        await editor.fill('<p>Hello </p>')
        await editor.focus(); await editor.press('End')
        await page.get_by_role('button',name='Recipient name',exact=True).click()
        await ck('{{recipient_name}}' in await editor.input_value(),'tokenInsertion')
        await ck('Sara Khan' in await text_block.inner_text(),'sampleDataOnCanvas')
        # Add and duplicate without leaving the designer.
        await page.locator('[data-certloom-add-block="heading"]').click()
        await ck(await page.locator('.certloom-canvas-block').count()==initial+1,'addBlock')
        await page.locator('[data-certloom-email-inspector] [data-inspector-copy]').click()
        await ck(await page.locator('.certloom-canvas-block').count()==initial+2,'duplicateBlock')
        # Pointer drag-and-drop reorders the same structured block model.
        await page.locator('.certloom-canvas-block').nth(0).click()
        first_handle=page.locator('.certloom-canvas-block').nth(0).locator('.certloom-canvas-drag-handle')
        third=page.locator('.certloom-canvas-block').nth(2)
        before=await page.locator('[data-certloom-blocks-json]').input_value()
        await first_handle.drag_to(third)
        after=await page.locator('[data-certloom-blocks-json]').input_value()
        await ck(before!=after,'dragReordersModel')
        # Keyboard-accessible move controls remain available.
        await ck(await page.get_by_role('button',name='Move up').count()>=1 and await page.get_by_role('button',name='Move down').count()>=1,'keyboardMoveControls')
        await page.get_by_role('button',name='Mobile',exact=True).click()
        await ck('is-mobile' in (await page.locator('[data-certloom-canvas-frame]').get_attribute('class') or ''),'mobilePreviewMode')
        await page.get_by_role('button',name='Preview',exact=True).click()
        frame=page.locator('iframe[data-certloom-preview-frame][sandbox=""]')
        await ck(await frame.count()==1,'sandboxedFullPreview')
        await ck(await page.locator('[data-certloom-designer-preview-dialog]').evaluate('(d)=>d.open'),'previewDialogOpen')
        await page.get_by_role('button',name='Close',exact=True).click()
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        # Empty-content save stays on the page and focuses a clear inline explanation.
        empty=await browser.new_page(viewport={'width':1100,'height':900})
        await empty.set_content(fixture(),wait_until='load')
        while await empty.locator('.certloom-canvas-block').count():
            await empty.locator('.certloom-canvas-block').first.click()
            await empty.locator('[data-certloom-email-inspector] [data-inspector-remove]').click()
        await ck(await empty.locator('.certloom-email-empty').count()>=1,'emptyCanvasState')
        await empty.get_by_role('button',name='Save',exact=True).click()
        feedback=empty.locator('[data-certloom-designer-feedback]')
        await ck(not await feedback.is_hidden() and 'Add at least one usable content block' in await feedback.inner_text(),'inlineEmptyValidation')
        await ck(await feedback.evaluate('(n)=>document.activeElement===n'),'validationFocus')
        await empty.locator('[data-certloom-add-block="heading"]').click()
        await ck(await feedback.is_hidden(),'validationClearsAfterRepair')
        await empty.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        async def mobile_overflow_snapshot():
            return await mobile.evaluate('''() => ({
                viewport: window.innerWidth,
                scrollWidth: document.documentElement.scrollWidth,
                offenders: [...document.querySelectorAll('*')].map((el) => {
                    const r = el.getBoundingClientRect();
                    return { tag: el.tagName, cls: String(el.className || ''), left: r.left, right: r.right, width: r.width };
                }).filter((x) => x.right > window.innerWidth + 1 || x.left < -11).slice(0, 8)
            })''')
        baseline_overflow=await mobile_overflow_snapshot()
        await ck(baseline_overflow['scrollWidth'] <= baseline_overflow['viewport'] + 1,'mobileNoOverflow')
        # Stress the toolbar with deliberately wider glyph metrics so responsive containment
        # cannot depend on whichever system UI font happens to exist on the host.
        await mobile.add_style_tag(content='body{font-family:monospace!important}')
        wide_font_overflow=await mobile_overflow_snapshot()
        await ck(wide_font_overflow['scrollWidth'] <= wide_font_overflow['viewport'] + 1,'mobileWideFontNoOverflow')
        if baseline_overflow['scrollWidth'] > baseline_overflow['viewport'] + 1 or wide_font_overflow['scrollWidth'] > wide_font_overflow['viewport'] + 1:
            print('EMAIL DESIGNER MOBILE OVERFLOW DIAGNOSTIC', {'baseline': baseline_overflow, 'wideFont': wide_font_overflow})
        box=await mobile.locator('[data-certloom-add-block="heading"]').bounding_box()
        await ck(bool(box and box['height']>=44),'mobileTouchTarget')
        await mobile.close();await browser.close()
    if failed:
        print('FAIL  Email Designer browser gate:',', '.join(failed));return 1
    print('PASS  Email Designer browser gate: dedicated canvas, contextual inspector, dynamic data, drag/keyboard reordering, sandboxed preview, inline validation and responsive UX')
    print(f'\n{checks} Email Designer browser checks, 0 failures');return 0
raise SystemExit(asyncio.run(run()))
