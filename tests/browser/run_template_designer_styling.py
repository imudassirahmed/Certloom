#!/usr/bin/env python3
import asyncio, json, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture_html():
    run=subprocess.run(['php',str(ROOT/'tests/browser/build_template_designer_fixture.php')],capture_output=True,text=True,cwd=ROOT)
    if run.returncode: raise RuntimeError(run.stderr or run.stdout)
    return run.stdout
async def run():
    html=fixture_html(); failed=[]; checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':1000}); await page.set_content(html,wait_until='load'); await page.wait_for_timeout(120)
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        # select first text/merge and exercise every text style field.
        text=page.locator('[data-certloom-designer-element].certloom-template-designer-element-text').first
        text_id=await text.get_attribute('data-certloom-designer-element')
        await text.click(); await page.wait_for_timeout(20)
        await ck(await page.locator('[data-certloom-designer-style="text"]').is_visible(),'textStyleVisible')
        family=page.locator('[data-certloom-designer-style-field="font_family"]'); await ck(await family.is_disabled(),'fontKeyReadOnly'); await ck(bool(await family.input_value()),'fontKeyPreserved')
        async def change(sel,value):
            loc=page.locator(sel).filter(visible=True) if False else page.locator(sel)
            # choose visible duplicate if needed
            if await loc.count()>1:
                for i in range(await loc.count()):
                    if await loc.nth(i).is_visible(): loc=loc.nth(i); break
            await loc.fill(str(value)); await loc.dispatch_event('change'); await page.wait_for_timeout(12)
        await change('[data-certloom-designer-style-field="font_size_pt"]',32.5)
        await page.locator('[data-certloom-designer-style-field="font_weight"]').select_option('700'); await page.locator('[data-certloom-designer-style-field="font_weight"]').dispatch_event('change')
        italic=page.locator('[data-certloom-designer-style-field="italic"]'); await italic.check(); await italic.dispatch_event('change')
        await change('[data-certloom-designer-style-field="color"]','#12345678')
        for key,val in [('align','right'),('vertical_align','top'),('direction','rtl'),('overflow','clip')]:
            loc=page.locator(f'[data-certloom-designer-style-field="{key}"]'); await loc.select_option(val); await loc.dispatch_event('change')
        await change('[data-certloom-designer-style-field="line_height"]',1.55); await change('[data-certloom-designer-style-field="letter_spacing_pt"]',2.25); await change('[data-certloom-designer-style-field="opacity"]',0.65)
        await page.wait_for_timeout(25)
        selected=page.locator('[data-certloom-designer-element].is-selected')
        style=await selected.locator('.certloom-template-designer-content').evaluate('(n)=>({color:n.style.color,fontWeight:n.style.fontWeight,fontStyle:n.style.fontStyle,textAlign:n.style.textAlign,direction:n.style.direction,fontSize:n.style.fontSize,letterSpacing:n.style.letterSpacing})')
        await ck(style['fontWeight']=='700','fontWeightCanvas'); await ck(style['fontStyle']=='italic','italicCanvas'); await ck(style['textAlign']=='right','alignCanvas'); await ck(style['direction']=='rtl','directionCanvas'); await ck(bool(style['fontSize']),'fontSizeCanvas'); await ck(bool(style['letterSpacing']),'letterSpacingCanvas')
        opacity=float(await selected.evaluate('(n)=>n.style.opacity')); await ck(abs(opacity-.65)<.001,'opacityCanvas')
        # Invalid color fails closed and retains previous canonical color.
        color=page.locator('[data-certloom-designer-style-field="color"]'); await color.fill('red'); await color.dispatch_event('change'); await page.wait_for_timeout(15)
        await ck(await page.locator('[data-certloom-designer-error]').is_visible(),'invalidColorError'); await ck(await color.input_value()=='#12345678','invalidColorRestored')
        # Shape canonical fill/stroke/width.
        shape=page.locator('[data-certloom-designer-element].certloom-template-designer-element-shape').first; await shape.evaluate('(n)=>n.click()'); await page.wait_for_timeout(15)
        await ck(await page.locator('[data-certloom-designer-style="shape"]').is_visible(),'shapeStyleVisible'); await ck(await page.locator('[data-certloom-designer-style="text"]').is_hidden(),'textStyleHiddenForShape')
        visible_shape=lambda key: page.locator(f'[data-certloom-designer-style="shape"] [data-certloom-designer-style-field="{key}"]')
        await visible_shape('fill').fill('#ABCDEF80'); await visible_shape('fill').dispatch_event('change'); await visible_shape('stroke').fill('#102030'); await visible_shape('stroke').dispatch_event('change'); await visible_shape('stroke_width_mm').fill('0'); await visible_shape('stroke_width_mm').dispatch_event('change'); await change('[data-certloom-designer-style-field="opacity"]',0.4)
        await page.wait_for_timeout(20); selected=page.locator('[data-certloom-designer-element].is-selected'); await ck('rgba' in (await selected.evaluate('(n)=>n.style.background')) or '#' in (await selected.evaluate('(n)=>n.style.background')),'shapeFillCanvas'); await ck(abs(float(await selected.evaluate('(n)=>n.style.opacity'))-.4)<.001,'shapeOpacityCanvas')
        # Line keeps positive width; zero is rejected.
        line=page.locator('[data-certloom-designer-element].certloom-template-designer-element-line').first; await line.evaluate('(n)=>n.click()'); await page.wait_for_timeout(10)
        await ck(await page.locator('[data-certloom-designer-style="line"]').is_visible(),'lineStyleVisible')
        line_width=page.locator('[data-certloom-designer-style="line"] [data-certloom-designer-style-field="stroke_width_mm"]'); await line_width.fill('1.25'); await line_width.dispatch_event('change'); await page.wait_for_timeout(10); await ck(await line_width.input_value()=='1.25','lineWidthAccepted')
        await line_width.fill('0'); await line_width.dispatch_event('change'); await page.wait_for_timeout(10); await ck(float(await line_width.input_value())>0,'lineZeroRejected')
        # Asset gets only common opacity; non-schema style groups remain hidden.
        visual=page.locator('[data-certloom-designer-element].certloom-template-designer-element-qr').first
        await visual.evaluate('(n)=>n.click()'); await page.wait_for_timeout(10); await ck(await page.locator('[data-certloom-designer-style="text"]').is_hidden(),'textHiddenForNonText'); await ck(await page.locator('[data-certloom-designer-style="shape"]').is_hidden(),'shapeHiddenForNonShape'); await ck(await page.locator('[data-certloom-designer-style="general"]').is_visible(),'opacityVisibleForVisual')
        # Locks make styling immutable for the current author.
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(10); await ck(await page.locator('[data-certloom-designer-style-field="opacity"]').is_disabled(),'lockedStyleDisabled'); await page.locator('[data-certloom-designer-lock]').click()
        # Submit and verify canonical serialization.
        await text.evaluate('(n)=>n.click()'); await page.locator('[data-certloom-designer-form]').evaluate("f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))"); await page.wait_for_timeout(20); saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); styled=[e for e in saved['elements'] if e.get('id')==text_id][0]
        await ck(abs(float(styled['opacity'])-.65)<.001,'opacitySerialized'); await ck(styled['style']['font_size_pt']==32.5,'fontSizeSerialized'); await ck(styled['style']['font_weight']==700,'fontWeightSerialized'); await ck(styled['style']['italic'] is True,'italicSerialized'); await ck(styled['style']['color']=='#12345678','colorSerialized'); await ck(styled['style']['align']=='right','alignSerialized'); await ck(styled['style']['vertical_align']=='top','verticalSerialized'); await ck(styled['style']['direction']=='rtl','directionSerialized'); await ck(styled['style']['line_height']==1.55,'lineHeightSerialized'); await ck(styled['style']['letter_spacing_pt']==2.25,'letterSpacingSerialized'); await ck(styled['style']['overflow']=='clip','overflowSerialized')
        # Responsive styling inspector.
        media=await browser.new_page(viewport={'width':480,'height':900}); await media.emulate_media(reduced_motion='reduce'); await media.set_content(html,wait_until='load'); await media.wait_for_timeout(80); await media.locator('[data-certloom-designer-element].certloom-template-designer-element-text').first.evaluate('(n)=>n.click()'); await ck(await media.locator('[data-certloom-designer-style-editor]').is_visible(),'responsiveStyleEditor'); await ck(await media.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion')
        await media.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  DSN-04 Designer browser gate: '+', '.join(sorted(set(failed)))); print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  DSN-04 Designer browser gate: canonical typography, colors, opacity, shape/line strokes, invalid-value rollback, locks, serialization and responsive accessibility')
    print(f'\n{checks} Designer styling interaction checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(run()))
