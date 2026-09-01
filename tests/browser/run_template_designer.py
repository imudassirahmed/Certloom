#!/usr/bin/env python3
import asyncio, json, subprocess, sys
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]

def fixture_html():
    run=subprocess.run(['php',str(ROOT/'tests/browser/build_template_designer_fixture.php')],capture_output=True,text=True,cwd=ROOT)
    if run.returncode:
        raise RuntimeError(run.stderr or run.stdout)
    return run.stdout

async def run():
    html=fixture_html(); failed=[]; checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(120)

        async def ck(ok,label):
            nonlocal checks
            checks+=1
            if not ok: failed.append(label)

        elements=page.locator('[data-certloom-designer-element]')
        await ck(await elements.count()==14,'elementCount')
        layers=page.locator('.certloom-template-designer-layer')
        await ck(await layers.count()==14,'layerCount')
        await ck(await page.locator('[data-certloom-designer-snap]').is_checked(),'snapDefault')

        first=elements.nth(12)
        await first.click(force=True); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-element].is-selected').count()==1,'selection')
        await ck(not await page.locator('[data-certloom-designer-align="left"]').is_disabled(),'alignmentEnabled')

        # Keyboard nudge + bounded undo/redo.
        x0=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await first.press('ArrowRight'); await page.wait_for_timeout(30)
        x1=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs((x1-x0)-1)<0.02,'keyboardNudge1mm')
        await page.keyboard.press('Control+z'); await page.wait_for_timeout(30)
        xu=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs(xu-x0)<0.02,'undoKeyboard')
        await page.keyboard.press('Control+Shift+z'); await page.wait_for_timeout(30)
        xr=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs(xr-x1)<0.02,'redoKeyboard')
        await first.press('Shift+ArrowRight'); await page.wait_for_timeout(30)
        xs=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs((xs-xr)-5)<0.02,'keyboardNudge5mm')
        await first.press('Alt+ArrowLeft'); await page.wait_for_timeout(30)
        xa=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs((xa-xs)+0.1)<0.03,'keyboardNudgeFine')

        # Page alignment on non-rotated first shape.
        await page.locator('[data-certloom-designer-align="left"]').click(); await page.wait_for_timeout(30)
        xl=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await ck(abs(xl)<0.02,'alignLeft')
        await page.locator('[data-certloom-designer-align="center-x"]').click(); await page.wait_for_timeout(30)
        box=await page.locator('[data-certloom-designer-element].is-selected').bounding_box(); page_box=await page.locator('[data-certloom-designer-page]').bounding_box()
        await ck(bool(box and page_box and abs((box['x']+box['width']/2)-(page_box['x']+page_box['width']/2))<1.5),'alignCenterX')

        # DSN-01 geometry interactions remain live under the DSN-02 productivity layer.
        selected=page.locator('[data-certloom-designer-element].is-selected')
        resize=selected.locator('[data-certloom-designer-resize="se"]')
        rb=await resize.bounding_box(); width_before=float(await page.locator('[data-certloom-designer-field="width"]').input_value())
        if not rb:
            failed.append('resizeHandle')
        else:
            await page.mouse.move(rb['x']+5,rb['y']+5); await page.mouse.down(); await page.mouse.move(rb['x']+22,rb['y']+14,steps=5); await page.mouse.up(); await page.wait_for_timeout(35)
            width_after=float(await page.locator('[data-certloom-designer-field="width"]').input_value())
            await ck(abs(width_after-width_before)>0.1,'resize')
        rotate=page.locator('[data-certloom-designer-element].is-selected [data-certloom-designer-rotate]')
        rotb=await rotate.bounding_box(); rot_before=float(await page.locator('[data-certloom-designer-field="rotation"]').input_value())
        if not rotb:
            failed.append('rotateHandle')
        else:
            await page.mouse.move(rotb['x']+5,rotb['y']+5); await page.mouse.down(); await page.mouse.move(rotb['x']+34,rotb['y']+25,steps=6); await page.mouse.up(); await page.wait_for_timeout(35)
            rot_after=float(await page.locator('[data-certloom-designer-field="rotation"]').input_value())
            await ck(abs(rot_after-rot_before)>0.1,'rotate')
        await page.locator('[data-certloom-designer-orientation]').select_option('portrait'); await page.wait_for_timeout(40)
        portrait_readout=await page.locator('[data-certloom-designer-page-readout]').text_content()
        await ck(bool(portrait_readout and 'portrait' in portrait_readout and '210 × 297' in portrait_readout),'pageSwitch')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(35)
        landscape_readout=await page.locator('[data-certloom-designer-page-readout]').text_content()
        await ck(bool(landscape_readout and 'landscape' in landscape_readout),'pageSwitchUndo')
        await page.locator('[data-certloom-designer-zoom]').fill('100'); await page.locator('[data-certloom-designer-zoom]').dispatch_event('input'); await page.wait_for_timeout(25)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').text_content())=='100%','zoom')

        # Layer ordering persists through canonical z values.
        layer_meta_before=await page.locator('.certloom-template-designer-layer.is-selected small').text_content()
        await page.locator('[data-certloom-designer-layer-action="front"]').click(); await page.wait_for_timeout(30)
        selected_layer=page.locator('.certloom-template-designer-layer.is-selected')
        await ck(await selected_layer.count()==1,'layerSelectionPersists')
        layer_meta_after=await selected_layer.locator('small').text_content()
        await ck(layer_meta_before!=layer_meta_after and 'z 13' in (layer_meta_after or ''),'bringFront')

        # Personal authoring locks block geometry, layer and keyboard transforms but remain selectable.
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(30)
        await ck((await page.locator('[data-certloom-designer-lock]').get_attribute('aria-pressed'))=='true','lockPressed')
        await ck(await page.locator('[data-certloom-designer-element].is-selected').get_attribute('class') is not None and 'is-locked' in (await page.locator('[data-certloom-designer-element].is-selected').get_attribute('class')),'lockedClass')
        await ck(await page.locator('[data-certloom-designer-field="x"]').is_disabled(),'lockedGeometryDisabled')
        await ck(await page.locator('[data-certloom-designer-layer-action="back"]').is_disabled(),'lockedLayerDisabled')
        locked_x=float(await page.locator('[data-certloom-designer-field="x"]').input_value())
        await page.locator('[data-certloom-designer-element].is-selected').press('ArrowRight'); await page.wait_for_timeout(30)
        await ck(abs(float(await page.locator('[data-certloom-designer-field="x"]').input_value())-locked_x)<0.02,'lockedKeyboardBlocked')
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(30)
        await ck((await page.locator('[data-certloom-designer-lock]').get_attribute('aria-pressed'))=='false','unlock')

        # Drag snapping: move selected element's visual center near page center; a vertical guide must appear before release.
        selected=page.locator('[data-certloom-designer-element].is-selected')
        sb=await selected.bounding_box(); pb=await page.locator('[data-certloom-designer-page]').bounding_box()
        if not sb or not pb:
            failed.append('snapBoxes')
        else:
            sx=sb['x']+sb['width']/2; sy=sb['y']+sb['height']/2
            target=pb['x']+pb['width']/2 + 2.0  # inside the 1.75mm snap threshold at current zoom.
            await page.mouse.move(sx,sy); await page.mouse.down(); await page.mouse.move(target,sy,steps=8); await page.wait_for_timeout(50)
            await ck(await page.locator('.certloom-template-designer-guide.is-vertical').count()>=1,'snapGuide')
            mid=await page.locator('[data-certloom-designer-element].is-selected').bounding_box()
            await page.mouse.up(); await page.wait_for_timeout(40)
            await ck(bool(mid and pb and abs((mid['x']+mid['width']/2)-(pb['x']+pb['width']/2))<2.5),'snapCenter')
            await ck(await page.locator('.certloom-template-designer-guide').count()==0,'guideClears')

        # Disable snapping and verify the preference becomes part of non-artifact state submitted with the revision.
        await page.locator('[data-certloom-designer-snap]').uncheck(); await page.wait_for_timeout(20)
        await ck(not await page.locator('[data-certloom-designer-snap]').is_checked(),'snapDisable')
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-designer-form]').evaluate('(f)=>f.requestSubmit()'); await page.wait_for_timeout(30)
        submitted=await page.evaluate("document.body.dataset.submitted==='1'")
        raw_layout=await page.locator('[data-certloom-designer-json]').input_value(); raw_state=await page.locator('[data-certloom-designer-state-json]').input_value()
        try: saved_layout=json.loads(raw_layout); saved_state=json.loads(raw_state)
        except Exception: saved_layout={}; saved_state={}
        await ck(submitted,'submit')
        await ck((await page.locator('[data-certloom-designer-dirty]').inner_text())=='Saving revision…','savePendingVisible')
        await ck(await page.locator('[data-certloom-designer-save]').is_disabled(),'saveButtonsBusyDuringSubmit')
        await ck(saved_layout.get('_meta',{}).get('schema')=='certloom-layout','canonicalSerialization')
        await ck(saved_state.get('version')==1 and saved_state.get('snap_enabled') is False and len(saved_state.get('locked_ids',[]))==1,'authoringStateSerialization')
        await ck('locked_ids' not in saved_layout and 'snap_enabled' not in saved_layout,'authoringStateOutsideArtifact')

        # Reset returns both canonical layout and personal workspace state to saved values and clears history.
        await page.locator('[data-certloom-designer-reset]').click(); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-snap]').is_checked(),'resetState')
        await ck(await page.locator('[data-certloom-designer-undo]').is_disabled(),'resetHistory')
        readout=await page.locator('[data-certloom-designer-page-readout]').text_content()
        await ck(bool(readout and 'A4 · landscape' in readout),'resetLayout')

        # Responsive/reduced-motion + keyboard accessible layer controls.
        media=await browser.new_page(viewport={'width':768,'height':900}); await media.emulate_media(reduced_motion='reduce'); await media.set_content(html,wait_until='load'); await media.wait_for_timeout(100)
        m=await media.evaluate("""() => ({reduced:matchMedia('(prefers-reduced-motion: reduce)').matches,workspaceFocusable:document.querySelector('[data-certloom-designer-workspace]').tabIndex===0,noTransition:getComputedStyle(document.querySelector('.certloom-template-designer-page')).transitionDuration==='0s',responsive:getComputedStyle(document.querySelector('.certloom-template-designer-shell')).gridTemplateColumns.split(' ').length===1,layerButton:document.querySelector('[data-certloom-designer-layer-select]').tagName==='BUTTON'})""")
        for k,v in m.items(): await ck(v,k)
        await media.close(); await page.close(); await browser.close()

    if failed:
        print('FAIL  DSN-02 Designer browser gate: '+', '.join(sorted(set(failed)))); print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  DSN-02 Designer browser gate: undo/redo, keyboard nudging, alignment, layers, personal locks, snapping guides, canonical/state separation, responsive/reduced-motion checks')
    print(f'\n{checks} Designer productivity interaction/accessibility checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(run()))
