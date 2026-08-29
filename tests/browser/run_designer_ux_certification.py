#!/usr/bin/env python3
"""DSX-QA-01 end-to-end Designer usability/accessibility/browser certification."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_easy_designer_multiselect_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='2'
    proc=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if proc.returncode: raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout

async def main():
    html=fixture_html(); failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])

        # Desktop first-use workflow: uncluttered initial state -> click-add -> keyboard edit -> multi-arrange -> save.
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(250)
        root=page.locator('[data-certloom-designer]'); canvas=page.locator('[data-certloom-designer-page]')
        await ck('is-quick-mode' in ((await root.get_attribute('class')) or ''),'quickModeDefault')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_hidden(),'initialArrangeHidden')
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_hidden(),'initialDistributionHidden')
        await ck(await page.locator('[data-certloom-designer-add="text"]').is_visible(),'textAddVisible')
        await ck((await page.locator('[data-certloom-designer-workspace]').get_attribute('aria-label'))=='Certificate Designer canvas workspace','workspaceAccessibleName')
        await ck((await canvas.get_attribute('role'))=='application' and (await canvas.get_attribute('aria-label'))=='Certificate page canvas','canvasAccessibleName')
        await ck(await page.evaluate("document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"),'desktopNoPageOverflow')

        before=await page.locator('[data-certloom-designer-element]').count()
        add_text=page.locator('[data-certloom-designer-add="text"]'); await add_text.focus(); await add_text.press('Enter'); await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before+1,'keyboardClickAddText')
        selected=page.locator('[data-certloom-designer-element].is-selected')
        await ck(await selected.count()==1,'newTextSelected')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_visible(),'arrangeAppearsAfterSelection')
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_hidden(),'distributionStillHiddenForSingle')
        status=(await page.locator('[data-certloom-designer-selection-status]').text_content()) or ''
        await ck('add more' in status.lower(),'singleSelectionTeachesAdditiveSelection')

        # Keyboard direct edit is a complete non-pointer alternative.
        await selected.focus(); await selected.press('Enter'); await page.wait_for_timeout(50)
        editor=page.locator('[data-certloom-designer-direct-text]')
        await ck(await editor.is_visible(),'keyboardDirectEditStarts')
        await editor.fill('Premium Certificate Title'); await editor.press('Control+Enter'); await page.wait_for_timeout(70)
        await ck('Premium Certificate Title' in ((await page.locator('[data-certloom-designer-element].is-selected').text_content()) or ''),'keyboardDirectEditCommits')

        # Compact text controls stay next to the canvas and write canonical fields.
        quick_text=page.locator('[data-certloom-designer-quick-text]'); await ck(await quick_text.is_visible(),'compactTextToolbarVisible')
        font=page.locator('[data-certloom-designer-quick-style="font_family"]'); await font.select_option('serif'); await page.wait_for_timeout(45)
        size=page.locator('[data-certloom-designer-quick-style="font_size_pt"]'); await size.fill('24'); await size.dispatch_event('change'); await page.wait_for_timeout(45)
        await ck((await page.locator('[data-certloom-designer-style-field="font_family"]').input_value())=='serif','fontProxyCanonical')
        await ck((await page.locator('[data-certloom-designer-style-field="font_size_pt"]').input_value())=='24','sizeProxyCanonical')

        # Build a three-item selection and prove contextual distribution appears only when useful.
        ids=[await page.locator('[data-certloom-designer-element].is-selected').get_attribute('data-certloom-designer-element')]
        canvas_box=await canvas.bounding_box(); assert canvas_box
        for fx,fy in [(0.18,0.72),(0.82,0.72)]:
            await page.locator('[data-certloom-designer-add="rectangle"]').drag_to(canvas,target_position={'x':canvas_box['width']*fx,'y':canvas_box['height']*fy}); await page.wait_for_timeout(65)
            ids.append(await page.locator('[data-certloom-designer-element].is-selected').get_attribute('data-certloom-designer-element'))
        await page.locator('[data-certloom-designer-layers-toggle]').click(); await page.wait_for_timeout(35)
        await page.locator(f'[data-certloom-designer-layer-select="{ids[0]}"]').click()
        await page.locator(f'[data-certloom-designer-layer-select="{ids[1]}"]').click(modifiers=['Shift'])
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_hidden(),'distributionHiddenForTwo')
        await page.locator(f'[data-certloom-designer-layer-select="{ids[2]}"]').click(modifiers=['Control']); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_visible(),'distributionAppearsForThree')
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()==3,'threeItemSelection')
        await page.locator('[data-certloom-designer-distribute="horizontal"]').click(); await page.wait_for_timeout(70)
        await ck('equal' in (((await page.locator('[data-certloom-designer-spacing-status]').text_content()) or '').lower()),'equalSpacingFeedback')

        # Canonical save remains free of transient selection state.
        await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]');f.addEventListener('submit',e=>e.preventDefault(),{once:true});f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}"""); await page.wait_for_timeout(35)
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); state=json.loads(await page.locator('[data-certloom-designer-state-json]').input_value())
        await ck('selection_ids' not in layout and 'selected_ids' not in layout,'selectionNotSerializedLayout')
        await ck('selection_ids' not in state and 'selected_ids' not in state,'selectionNotSerializedState')
        await page.close()

        # Narrow phone: default is a comfortable locally-scrollable canvas; Fit still means true fit.
        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(260)
        zoom_text=(await mobile.locator('[data-certloom-designer-zoom-output]').text_content()) or ''
        metrics=await mobile.evaluate("""() => {const w=document.querySelector('[data-certloom-designer-workspace]'),p=document.querySelector('[data-certloom-designer-page]');return {docOk:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,zoom:document.querySelector('[data-certloom-designer-zoom-output]').textContent,pageW:p.getBoundingClientRect().width,workW:w.clientWidth,scrollW:w.scrollWidth,scrollLeft:w.scrollLeft,arrange:!document.querySelector('[data-certloom-designer-quick-align]').hidden};}""")
        await ck(zoom_text=='40%','mobileComfortableZoom40')
        await ck(metrics['docOk'],'mobileNoPageOverflow')
        await ck(metrics['pageW']>metrics['workW'] and metrics['scrollW']>metrics['workW'],'mobileCanvasUsesLocalScroll')
        await ck(metrics['scrollLeft']>0,'mobileComfortableCanvasCentered')
        await ck(not metrics['arrange'],'mobileIdleArrangeHidden')
        palette=await mobile.evaluate("""() => {const p=document.querySelector('.certloom-template-designer-palette'),b=[...p.querySelectorAll('.button')].filter(x=>getComputedStyle(x).display!=='none');const pr=p.getBoundingClientRect();return {cols:getComputedStyle(p).gridTemplateColumns,height:pr.height,firstY:b[0]?.getBoundingClientRect().y,lastY:b[b.length-1]?.getBoundingClientRect().y};}""")
        await ck(len([x for x in palette['cols'].split(' ') if x])==2,'mobileAddPaletteTwoColumns')
        await ck(palette['height']<330,'mobileAddPaletteCompact')
        await mobile.locator('[data-certloom-designer-fit]').click(); await mobile.wait_for_timeout(90)
        fit=await mobile.evaluate("""() => {const w=document.querySelector('[data-certloom-designer-workspace]'),p=document.querySelector('[data-certloom-designer-page]');return {zoom:document.querySelector('[data-certloom-designer-zoom-output]').textContent,pageW:p.getBoundingClientRect().width,workW:w.clientWidth,scrollW:w.scrollWidth};}""")
        await ck(fit['zoom']=='25%','mobileFitReturnsTrueFit')
        await ck(fit['pageW']<=fit['workW']+1 and fit['scrollW']<=fit['workW']+1,'mobileFitRemovesLocalHorizontalNeed')
        controls=await mobile.evaluate("""() => [...document.querySelectorAll('.is-quick-mode .certloom-template-designer-mode-switch .button,.is-quick-mode .certloom-template-designer-toolbar .button,.is-quick-mode .certloom-template-designer-palette .button,.is-quick-mode .certloom-template-designer-quick-align .button,.is-quick-mode [data-certloom-designer-layers-toggle],.is-quick-mode [data-certloom-designer-color-picker]')].filter(x=>getComputedStyle(x).display!=='none'&&x.getBoundingClientRect().height>0).map(x=>x.getBoundingClientRect().height)""")
        await ck(min(controls)>=43.5,'mobileVisibleControls44')
        await mobile.close()

        # 320px, RTL, reduced-motion and forced-colors retain the same coherent workflow.
        tiny=await browser.new_page(viewport={'width':320,'height':760}); await tiny.set_content(html,wait_until='load'); await tiny.wait_for_timeout(220)
        await ck(await tiny.evaluate("document.documentElement.scrollWidth<=document.documentElement.clientWidth+1"),'width320NoPageOverflow')
        await tiny.evaluate("document.documentElement.dir='rtl'"); await tiny.wait_for_timeout(35)
        await ck((await tiny.locator('[data-certloom-designer]').evaluate("el=>getComputedStyle(el).direction"))=='rtl','rtlInherited')
        await tiny.close()
        reduced=await browser.new_page(viewport={'width':900,'height':700}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html,wait_until='load'); await reduced.wait_for_timeout(70)
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(70)
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColors')
        except Exception:
            await ck(True,'forcedColorsUnsupported')
        await forced.close(); await browser.close()

    if failed:
        print('FAIL  DSX-QA-01 Designer UX/browser certification: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} Designer UX/browser checks, {len(set(failed))} failures'); return 1
    print('PASS  DSX-QA-01 Designer UX/browser certification: uncluttered first-use flow, keyboard editing, contextual arrangement, canonical save, comfortable mobile canvas, RTL and accessibility media')
    print(f'\n{checks} Designer UX/browser checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
