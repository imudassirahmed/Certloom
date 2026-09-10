#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.5 canvas interactions."""
import asyncio, json, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_canvas_interactions_fixture.php'
IDS={
 'a':'11111111-1111-4111-8111-111111111111',
 'b':'22222222-2222-4222-8222-222222222222',
 'c':'33333333-3333-4333-8333-333333333333',
 'd':'44444444-4444-4444-8444-444444444444',
}

def fixture_html():
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def page_point(page, x_mm, y_mm):
    box=await page.locator('[data-certloom-designer-page]').bounding_box()
    return box['x'] + box['width']*(x_mm/297.0), box['y'] + box['height']*(y_mm/210.0)

async def marquee(page,x1,y1,x2,y2,modifier=None):
    p1=await page_point(page,x1,y1); p2=await page_point(page,x2,y2)
    if modifier: await page.keyboard.down(modifier)
    await page.mouse.move(*p1); await page.mouse.down(); await page.mouse.move(*p2,steps=4); await page.wait_for_timeout(30)
    during=await page.locator('[data-certloom-designer-marquee]').count()
    await page.mouse.up();
    if modifier: await page.keyboard.up(modifier)
    await page.wait_for_timeout(70)
    return during

async def main():
    failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    html=fixture_html()
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':900}); page.set_default_timeout(5000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(250)
        safe=page.locator('[data-certloom-designer-safe-area]'); pan=page.locator('[data-certloom-designer-pan-tool]')
        await ck(await safe.is_visible() and await safe.get_attribute('aria-pressed')=='false','safeAreaControlVisibleOff')
        await ck(await pan.is_visible() and await pan.get_attribute('aria-pressed')=='false','panControlVisibleOff')
        initial_layout=await page.locator('[data-certloom-designer-json]').input_value(); initial_state=await page.locator('[data-certloom-designer-state-json]').input_value()
        await safe.click(); await page.wait_for_timeout(50)
        overlay=page.locator('[data-certloom-designer-safe-area-overlay]')
        await ck(await safe.get_attribute('aria-pressed')=='true' and await overlay.count()==1,'safeAreaTogglesOn')
        pb=await page.locator('[data-certloom-designer-page]').bounding_box(); sb=await overlay.bounding_box()
        expected=pb['width']*10/297.0
        await ck(abs((sb['x']-pb['x'])-expected)<=2.0 and abs((sb['y']-pb['y'])-expected)<=2.0,'safeAreaUsesTenMmInset')
        await ck('Saved' in ((await page.locator('[data-certloom-designer-dirty]').inner_text()) or ''),'safeAreaDoesNotDirtyDesign')
        await safe.click(); await page.wait_for_timeout(30); await ck(await overlay.count()==0,'safeAreaTogglesOff')

        during=await marquee(page,10,35,100,75)
        selected=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerElement).sort()")
        await ck(during==1,'marqueeVisibleDuringGesture')
        await ck(selected==sorted([IDS['a'],IDS['b']]),'marqueeSelectsIntersectingElements')
        await ck(await page.locator('[data-certloom-designer-marquee]').count()==0,'marqueeRemovedAfterGesture')
        await ck(await page.locator('[data-certloom-designer-undo]').is_disabled(),'selectionOnlyDoesNotCreateHistory')
        during2=await marquee(page,118,35,155,75,'Shift')
        selected2=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerElement).sort()")
        await ck(during2==1 and selected2==sorted([IDS['a'],IDS['b'],IDS['c']]),'modifierMarqueeAddsToSelection')
        await ck(await page.locator('[data-certloom-designer-json]').input_value()==initial_layout and await page.locator('[data-certloom-designer-state-json]').input_value()==initial_state,'marqueeIsTransientOnly')

        # Single-selection context menu: right-click, keyboard opening, Escape and canonical Lock/Undo.
        bnode=page.locator(f'[data-certloom-designer-element="{IDS["b"]}"]')
        await page.locator(f'[data-certloom-designer-layer-select="{IDS["b"]}"]').click(); await page.wait_for_timeout(40); await bnode.click(button='right'); await page.wait_for_timeout(50)
        menu=page.locator('[data-certloom-designer-context-menu]')
        await ck(await menu.is_visible() and await menu.get_attribute('role')=='menu','rightClickOpensAriaContextMenu')
        actions=await menu.locator('[data-certloom-designer-context-action]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerContextAction)")
        await ck(actions==['copy','cut','duplicate','front','back','lock','delete'],'contextMenuHasBoundedActions')
        focused=await page.evaluate("document.activeElement && document.activeElement.getAttribute('data-certloom-designer-context-action')")
        await ck(focused=='copy','contextMenuFocusesFirstEnabledAction')
        await page.keyboard.press('Escape'); await page.wait_for_timeout(30)
        await ck(await menu.is_hidden(),'escapeClosesContextMenuFromMenuButton')
        await ck((await page.evaluate("document.activeElement && document.activeElement.getAttribute('data-certloom-designer-element')"))==IDS['b'],'escapeRestoresCanvasFocus')
        await bnode.press('Shift+F10'); await page.wait_for_timeout(35); await ck(await menu.is_visible(),'shiftF10OpensContextMenu')
        await menu.locator('[data-certloom-designer-context-action="lock"]').click(); await page.wait_for_timeout(55)
        await ck('is-locked' in ((await bnode.get_attribute('class')) or ''),'contextLockUsesCanonicalLockPath')
        await ck(not await page.locator('[data-certloom-designer-undo]').is_disabled(),'contextLockCreatesUndoHistory')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(50)
        await ck('is-locked' not in ((await bnode.get_attribute('class')) or ''),'contextLockIsUndoable')

        # Explicit Pan and temporary Space+drag alter only the sticky workspace viewport.
        zoom=page.locator('[data-certloom-designer-zoom]'); await zoom.fill('200'); await page.wait_for_timeout(100)
        workspace=page.locator('[data-certloom-designer-workspace]')
        dims=await workspace.evaluate('e=>({cw:e.clientWidth,ch:e.clientHeight,sw:e.scrollWidth,sh:e.scrollHeight})')
        await ck(dims['sw']>dims['cw'] and dims['sh']>dims['ch'],'zoomCreatesLocalPanRange')
        await pan.click(); await page.wait_for_timeout(25); await ck(await pan.get_attribute('aria-pressed')=='true','explicitPanModeOn')
        wb=await workspace.bounding_box(); sx=wb['x']+wb['width']*.72; sy=min(wb['y']+wb['height']*.42,820)
        await page.mouse.move(sx,sy); await page.mouse.down(); await page.mouse.move(sx-150,sy-100,steps=4); await page.mouse.up(); await page.wait_for_timeout(50)
        sc=await workspace.evaluate('e=>({l:e.scrollLeft,t:e.scrollTop})')
        await ck(sc['l']>=100 and sc['t']>=60,'explicitPanScrollsStickyWorkspace')
        await ck(await page.locator('[data-certloom-designer-json]').input_value()==initial_layout,'panDoesNotMutateArtifactField')
        await workspace.focus(); await page.keyboard.press('Escape'); await ck(await pan.get_attribute('aria-pressed')=='false','escapeExitsExplicitPan')
        await workspace.evaluate('e=>{e.scrollLeft=0;e.scrollTop=0}'); await page.wait_for_timeout(20)
        await workspace.focus(); await page.keyboard.down('Space'); await page.mouse.move(sx,sy); await page.mouse.down(); await page.mouse.move(sx-120,sy-80,steps=3); await page.mouse.up(); await page.keyboard.up('Space'); await page.wait_for_timeout(40)
        sc2=await workspace.evaluate('e=>({l:e.scrollLeft,t:e.scrollTop})')
        await ck(sc2['l']>=80 and sc2['t']>=45 and await pan.get_attribute('aria-pressed')=='false','spaceDragTemporarilyPans')

        # Equal-spacing smart snap: C between B and D becomes 30/30 mm gaps at x=120.
        await zoom.fill('100'); await workspace.evaluate('e=>{e.scrollLeft=0;e.scrollTop=0}'); await page.wait_for_timeout(90)
        cnode=page.locator(f'[data-certloom-designer-element="{IDS["c"]}"]'); cb=await cnode.bounding_box(); cx=cb['x']+cb['width']/2; cy=cb['y']+cb['height']/2
        await page.mouse.move(cx,cy); await page.mouse.down(); await page.mouse.move(cx-25,cy,steps=4); await page.wait_for_timeout(35)
        guides=page.locator('.certloom-template-designer-spacing-guide')
        await ck(await guides.count()==2,'equalSpacingShowsTwoGapGuides')
        await ck('30 mm' in ((await page.locator('.certloom-template-designer-spacing-guide-label').inner_text()) or ''),'equalSpacingShowsGapLabel')
        await page.mouse.up(); await page.wait_for_timeout(65)
        xval=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs(xval-120.0)<0.01,'equalSpacingSnapsToDeterministicGeometry')
        await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]');f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}"""); await page.wait_for_timeout(35)
        saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); saved_c=next(e for e in saved['elements'] if e['id']==IDS['c'])
        await ck(abs(float(saved_c['x_mm'])-120.0)<0.01,'equalSpacingSerializesAtRealSaveBoundary')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(60)
        await ck(abs(float(await page.locator('[data-certloom-designer-field="x"]').input_value())-128.0)<0.01,'equalSpacingMoveIsUndoable')
        await page.close()

        # Locked elements stay marquee-selectable; hidden-in-editor elements are excluded; context guards fail closed.
        state=await browser.new_page(viewport={'width':1650,'height':900}); state.set_default_timeout(5000); await state.set_content(html,wait_until='load'); await state.wait_for_timeout(220)
        brow=state.locator(f'[data-certloom-designer-layer-row="{IDS["b"]}"]'); await brow.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await state.wait_for_timeout(40)
        drow=state.locator(f'[data-certloom-designer-layer-row="{IDS["d"]}"]'); await drow.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await state.wait_for_timeout(40)
        await marquee(state,5,30,205,80)
        ids=await state.locator('[data-certloom-designer-element][aria-pressed="true"]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerElement).sort()")
        await ck(IDS['b'] in ids,'lockedElementRemainsMarqueeSelectable')
        await ck(IDS['d'] not in ids and ids==sorted([IDS['a'],IDS['b'],IDS['c']]),'hiddenElementExcludedFromMarquee')
        anode=state.locator(f'[data-certloom-designer-element="{IDS["a"]}"]'); await anode.click(button='right'); await state.wait_for_timeout(40)
        smenu=state.locator('[data-certloom-designer-context-menu]')
        await ck(await smenu.locator('[data-certloom-designer-context-action="cut"]').is_disabled() and await smenu.locator('[data-certloom-designer-context-action="delete"]').is_disabled(),'multiSelectionWithLockDisablesDestructiveContextActions')
        await ck(not await smenu.locator('[data-certloom-designer-context-action="copy"]').is_disabled(),'multiSelectionStillAllowsCopy')
        await state.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        await mobile.locator(f'[data-certloom-designer-layer-select="{IDS["a"]}"]').click(); await mobile.wait_for_timeout(30)
        mobile_a=mobile.locator(f'[data-certloom-designer-element="{IDS["a"]}"]'); await mobile_a.focus(); await mobile_a.press('Shift+F10'); await mobile.wait_for_timeout(30)
        mh=await mobile.locator('[data-certloom-designer-context-action="copy"]').evaluate('e=>parseFloat(getComputedStyle(e).minHeight)||0')
        await ck(mh>=43.5,'mobileContextMenuTarget44')
        await mobile.emulate_media(forced_colors='active'); await mobile.locator('[data-certloom-designer-safe-area]').click(); await mobile.wait_for_timeout(30)
        await ck(await mobile.locator('[data-certloom-designer-safe-area-overlay]').count()==1,'forcedColorsSafeAreaStillVisible')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.5 canvas interactions Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.5 canvas interactions Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
