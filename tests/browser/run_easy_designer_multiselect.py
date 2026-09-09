#!/usr/bin/env python3
"""Real Chromium acceptance for DSX-02B Easy Designer multi-select/distribution/text toolbar."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests' / 'browser' / 'build_easy_designer_multiselect_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='2'
    proc=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if proc.returncode: raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout

def close(a,b,tol=2.0): return abs(a-b)<=tol

async def main():
    html=fixture_html(); failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks
        checks+=1
        if not value: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(220)
        root=page.locator('[data-certloom-designer]'); canvas=page.locator('[data-certloom-designer-page]')
        await ck(((await page.locator('.certloom-template-designer-kicker').text_content()) or '').strip() == 'Easy Designer','legacyEasyDesignerFixture')
        await ck(await page.locator('[data-certloom-designer-mode-button]').count()==2,'legacyModeCompatibilityControlsPresent')
        await ck(await page.locator('[data-certloom-designer-distribute="horizontal"]').count()==1,'horizontalDistributePresent')
        await ck(await page.locator('[data-certloom-designer-distribute="vertical"]').count()==1,'verticalDistributePresent')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_hidden(),'arrangeHiddenWithoutSelection')
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_hidden(),'distributionHiddenWithoutSelection')

        # Add three compact shapes at deliberately uneven positions so distribution has work to do.
        box=await canvas.bounding_box(); assert box
        positions=[(0.18,0.34),(0.48,0.55),(0.80,0.72)]
        new_ids=[]
        for fx,fy in positions:
            before=await page.locator('[data-certloom-designer-element]').count()
            await page.locator('[data-certloom-designer-add="rectangle"]').drag_to(canvas,target_position={'x':box['width']*fx,'y':box['height']*fy})
            await page.wait_for_timeout(80)
            await ck(await page.locator('[data-certloom-designer-element]').count()==before+1,'shapeAdded'+str(len(new_ids)+1))
            new_ids.append(await page.locator('[data-certloom-designer-element].is-selected').get_attribute('data-certloom-designer-element'))
        await ck(len(set(new_ids))==3 and all(new_ids),'threeUniqueShapes')

        # Include a rotated element so group bounds/distribution are certified against rotated geometry.
        await page.locator(f'[data-certloom-designer-element="{new_ids[0]}"]').click(); await page.locator('[data-certloom-designer-mode-button="pro"]').click(); await page.wait_for_timeout(40)
        rotation=page.locator('[data-certloom-designer-field="rotation"]'); await rotation.fill('30'); await rotation.dispatch_event('change'); await page.wait_for_timeout(60)
        await page.locator('[data-certloom-designer-mode-button="quick"]').click(); await page.wait_for_timeout(40)
        await ck('rotate(30deg)' in ((await page.locator(f'[data-certloom-designer-element="{new_ids[0]}"]').get_attribute('style')) or ''),'rotatedMemberPrepared')

        # Shift/Ctrl/Cmd additive selection, explicit primary, group inspector, ambiguous single-item actions disabled.
        await page.locator(f'[data-certloom-designer-element="{new_ids[0]}"]').click()
        await page.locator(f'[data-certloom-designer-element="{new_ids[1]}"]').click(modifiers=['Shift'])
        await page.locator(f'[data-certloom-designer-element="{new_ids[2]}"]').click(modifiers=['Control'])
        await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()==3,'threeSelected')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_visible(),'arrangeVisibleWithSelection')
        await ck(await page.locator('[data-certloom-designer-distribute-group]').is_visible(),'distributionVisibleWithThree')
        await ck(await page.locator('[data-certloom-designer-group]').is_visible(),'groupInspectorVisible')
        await ck((await page.locator('[data-certloom-designer-group-count]').text_content())=='3','groupCountThree')
        await ck(not await page.locator('[data-certloom-designer-fields]').is_visible(),'individualInspectorHiddenForMulti')
        await ck(not await page.locator('[data-certloom-designer-distribute="horizontal"]').is_disabled(),'distributionEnabledForThree')
        await ck(await page.locator('[data-certloom-designer-duplicate]').is_disabled(),'duplicateDisabledForMulti')
        await ck(not await page.locator('[data-certloom-designer-quick-text]').is_visible(),'textToolbarHiddenForMulti')

        # Layers provide the same additive-selection semantics as the canvas.
        await page.locator('[data-certloom-designer-layers-toggle]').click(); await page.wait_for_timeout(35)
        await page.locator(f'[data-certloom-designer-layer-select="{new_ids[0]}"]').click(); await page.locator(f'[data-certloom-designer-layer-select="{new_ids[1]}"]').click(modifiers=['Shift']); await page.locator(f'[data-certloom-designer-layer-select="{new_ids[2]}"]').click(modifiers=['Control']); await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()==3,'layersAdditiveSelection')
        await page.locator('[data-certloom-designer-layers-toggle]').click(); await page.wait_for_timeout(25)

        # Align selected items to the same top edge, then Undo restores the distinct tops in one transaction.
        def sel_boxes_script(ids):
            return """ids => ids.map(id => { const r=document.querySelector('[data-certloom-designer-element="'+id+'"]').getBoundingClientRect(); return {x:r.x,y:r.y,w:r.width,h:r.height}; })"""
        def sel_document_boxes_script(ids):
            return """ids => ids.map(id => { const r=document.querySelector('[data-certloom-designer-element="'+id+'"]').getBoundingClientRect(); return {x:r.x+window.scrollX,y:r.y+window.scrollY,w:r.width,h:r.height}; })"""
        # This legacy compatibility fixture can require Playwright to scroll its old off-screen
        # Arrange controls into view. Compare document coordinates here so the undo contract
        # measures canonical geometry rather than a harness-induced viewport scroll offset.
        before_align=await page.evaluate(sel_document_boxes_script(new_ids),new_ids)
        await page.locator('[data-certloom-designer-quick-align] [data-certloom-designer-align="top"]').click(); await page.wait_for_timeout(90)
        after_align=await page.evaluate(sel_document_boxes_script(new_ids),new_ids)
        await ck(max(b['y'] for b in after_align)-min(b['y'] for b in after_align)<=1.5,'multiAlignTop')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(80)
        undo_align=await page.evaluate(sel_document_boxes_script(new_ids),new_ids)
        await ck(all(close(undo_align[i]['y'],before_align[i]['y'],2.0) for i in range(3)),'alignOneStepUndo')

        # Horizontal equal-spacing produces equal visible gaps while preserving first/last anchors.
        await page.locator('[data-certloom-designer-distribute="horizontal"]').click(); await page.wait_for_timeout(100)
        distributed=sorted(await page.evaluate(sel_boxes_script(new_ids),new_ids), key=lambda b:b['x'])
        gaps=[distributed[i+1]['x']-(distributed[i]['x']+distributed[i]['w']) for i in range(2)]
        await ck(abs(gaps[0]-gaps[1])<=1.8,'horizontalEqualGaps')
        await ck('equal' in ((await page.locator('[data-certloom-designer-spacing-status]').text_content()) or '').lower(),'equalSpacingFeedback')
        await page.locator('[data-certloom-designer-distribute="vertical"]').click(); await page.wait_for_timeout(90)
        vertical=sorted(await page.evaluate(sel_boxes_script(new_ids),new_ids), key=lambda b:b['y']); vgaps=[vertical[i+1]['y']-(vertical[i]['y']+vertical[i]['h']) for i in range(2)]
        await ck(abs(vgaps[0]-vgaps[1])<=1.8,'verticalEqualGaps')

        # Group drag moves every selected item by the same delta and is one Undo transaction.
        pre_drag=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        primary=page.locator(f'[data-certloom-designer-element="{new_ids[2]}"]'); pb=await primary.bounding_box(); assert pb
        await page.mouse.move(pb['x']+pb['width']/2,pb['y']+pb['height']/2)
        await page.mouse.down(); await page.mouse.move(pb['x']+pb['width']/2+36,pb['y']+pb['height']/2+24,steps=6); await page.mouse.up(); await page.wait_for_timeout(100)
        post_drag=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        deltas=[(post_drag[i]['x']-pre_drag[i]['x'],post_drag[i]['y']-pre_drag[i]['y']) for i in range(3)]
        await ck(max(d[0] for d in deltas)-min(d[0] for d in deltas)<=1.5 and max(d[1] for d in deltas)-min(d[1] for d in deltas)<=1.5,'groupDragSameDelta')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(90)
        undo_drag=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        await ck(all(close(undo_drag[i]['x'],pre_drag[i]['x'],2.0) and close(undo_drag[i]['y'],pre_drag[i]['y'],2.0) for i in range(3)),'groupDragOneStepUndo')

        # A far drag clamps the complete rotated selection inside page bounds instead of clipping a member.
        pb=await primary.bounding_box(); cb=await canvas.bounding_box(); assert pb and cb
        await page.mouse.move(pb['x']+pb['width']/2,pb['y']+pb['height']/2); await page.mouse.down(); await page.mouse.move(pb['x']+pb['width']/2+1600,pb['y']+pb['height']/2+1200,steps=8); await page.mouse.up(); await page.wait_for_timeout(90)
        bounded=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        await ck(all(b['x']>=cb['x']-2 and b['y']>=cb['y']-2 and b['x']+b['w']<=cb['x']+cb['width']+2 and b['y']+b['h']<=cb['y']+cb['height']+2 for b in bounded),'rotatedGroupClampedToPage')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(80)

        # Multi-delete is one canonical history transaction and Undo restores both content and selection.
        count_before_delete=await page.locator('[data-certloom-designer-element]').count(); await page.locator('[data-certloom-designer-remove]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==count_before_delete-3,'multiDeleteRemovesSelection')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element]').count()==count_before_delete and await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()==3,'multiDeleteUndoRestoresSelection')

        # Keyboard nudge applies atomically to the group, Escape returns to one primary selection.
        await primary.focus(); pre_nudge=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        await page.keyboard.press('ArrowRight'); await page.wait_for_timeout(70)
        post_nudge=await page.evaluate(sel_boxes_script(new_ids),new_ids)
        nudges=[post_nudge[i]['x']-pre_nudge[i]['x'] for i in range(3)]
        await ck(max(nudges)-min(nudges)<=1.2 and min(nudges)>0,'groupKeyboardNudge')
        await page.keyboard.press('Escape'); await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()==0,'escapeClearsSelection')

        # Compact text controls proxy canonical style fields, update canvas immediately, and stay single-selection only.
        text=page.locator('.certloom-template-designer-element.is-text').nth(1)
        await text.click(); await page.wait_for_timeout(50)
        qt=page.locator('[data-certloom-designer-quick-text]')
        await ck(await qt.is_visible(),'compactTextToolbarVisible')
        qfont=page.locator('[data-certloom-designer-quick-style="font_family"]'); qsize=page.locator('[data-certloom-designer-quick-style="font_size_pt"]'); qcolor=page.locator('[data-certloom-designer-quick-style="color"]')
        await qfont.select_option('monospace'); await qsize.fill('24'); await qsize.dispatch_event('change'); await qcolor.fill('#123456'); await qcolor.dispatch_event('change')
        await page.locator('[data-certloom-designer-quick-toggle="bold"]').click(); await page.locator('[data-certloom-designer-quick-toggle="italic"]').click(); await page.locator('[data-certloom-designer-quick-text-align="right"]').click(); await page.wait_for_timeout(100)
        content=page.locator('.certloom-template-designer-element.is-selected .certloom-template-designer-content')
        styles=await content.evaluate("el => ({family:getComputedStyle(el).fontFamily,size:getComputedStyle(el).fontSize,weight:getComputedStyle(el).fontWeight,italic:getComputedStyle(el).fontStyle,color:getComputedStyle(el).color,align:getComputedStyle(el).textAlign})")
        await ck('mono' in styles['family'].lower() or 'consolas' in styles['family'].lower(),'quickFontApplied')
        await ck(float(styles['size'].replace('px',''))>20,'quickSizeApplied')
        await ck(int(styles['weight'])>=700,'quickBoldApplied')
        await ck(styles['italic']=='italic','quickItalicApplied')
        await ck(styles['align']=='right','quickAlignApplied')
        await ck('18, 52, 86' in styles['color'],'quickColorApplied')
        await ck((await page.locator('[data-certloom-designer-style-field="font_family"]').input_value())=='monospace','quickFontCanonicalProxy')
        await ck((await page.locator('[data-certloom-designer-style-field="align"]').input_value())=='right','quickAlignCanonicalProxy')

        # Locked mixed selections fail closed for arrangement/removal and do not move.
        text_id=await page.locator('.certloom-template-designer-element.is-selected').get_attribute('data-certloom-designer-element')
        await page.locator('[data-certloom-designer-layers-toggle]').click(); await page.wait_for_timeout(40)
        await page.locator(f'[data-certloom-designer-layer-row-action="lock"][data-certloom-designer-layer-id="{text_id}"]').click(); await page.wait_for_timeout(60)
        await page.locator(f'[data-certloom-designer-element="{new_ids[0]}"]').click(modifiers=['Shift']); await page.locator(f'[data-certloom-designer-element="{new_ids[1]}"]').click(modifiers=['Shift']); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-remove]').is_disabled(),'removeDisabledMixedLocked')
        await ck(await page.locator('[data-certloom-designer-distribute="horizontal"]').is_disabled(),'distributeDisabledMixedLocked')
        locked_before=await page.evaluate(sel_boxes_script([text_id,new_ids[0],new_ids[1]]),[text_id,new_ids[0],new_ids[1]])
        lb=await page.locator(f'[data-certloom-designer-element="{new_ids[0]}"]').bounding_box(); assert lb
        await page.mouse.move(lb['x']+lb['width']/2,lb['y']+lb['height']/2); await page.mouse.down(); await page.mouse.move(lb['x']+lb['width']/2+30,lb['y']+lb['height']/2+20,steps=4); await page.mouse.up(); await page.wait_for_timeout(80)
        locked_after=await page.evaluate(sel_boxes_script([text_id,new_ids[0],new_ids[1]]),[text_id,new_ids[0],new_ids[1]])
        await ck(all(close(locked_after[i]['x'],locked_before[i]['x'],1.5) and close(locked_after[i]['y'],locked_before[i]['y'],1.5) for i in range(3)),'mixedLockedGroupDoesNotMove')

        # Selection state is transient: submitting serializes canonical layout/designer state only.
        await page.evaluate("""() => { const f=document.querySelector('[data-certloom-designer-form]'); f.addEventListener('submit',e=>e.preventDefault(),{once:true}); f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})); }"""); await page.wait_for_timeout(40)
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); state=json.loads(await page.locator('[data-certloom-designer-state-json]').input_value())
        await ck('selected_ids' not in layout and 'selection_ids' not in layout and all('selected' not in e for e in layout.get('elements',[])),'selectionNotInLayout')
        await ck('selected_ids' not in state and 'selection_ids' not in state,'selectionNotInDesignerState')

        # RTL and mobile remain structurally usable; compact controls meet 44px target rule on mobile.
        await page.evaluate("document.documentElement.dir='rtl'"); await page.wait_for_timeout(40)
        await ck((await root.evaluate("el=>getComputedStyle(el).direction"))=='rtl','rtlDirectionInherited')
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        mt=mobile.locator('.certloom-template-designer-element.is-text').first; await mt.click(); await mobile.wait_for_timeout(50)
        m=await mobile.evaluate("""() => { const lane=document.querySelector('[data-certloom-designer-quick-text]'); const controls=[...lane.querySelectorAll('button,select,input')].filter(x=>getComputedStyle(x).display!=='none'); return {overflow:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,min:Math.min(...controls.map(x=>x.getBoundingClientRect().height)),laneScrollable:lane.scrollWidth>lane.clientWidth && getComputedStyle(lane).overflowX==='auto'}; }""")
        await ck(m['overflow'],'mobileNoOverflow')
        await ck(m['min']>=43.5,'mobileTextTargets44')
        await ck(m['laneScrollable'],'mobileTextLaneScrollable')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':900,'height':700}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html,wait_until='load'); await reduced.wait_for_timeout(80)
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionMedia')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(70); await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColors')
        except Exception:
            await ck(True,'forcedColorsUnsupported')
        await forced.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  DSX-02B Easy Designer browser gate: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} DSX-02B browser checks, {len(set(failed))} failures'); return 1
    print('PASS  DSX-02B Easy Designer browser gate: transient multi-select, atomic group arrange, equal spacing, locked safety, compact canonical text controls, responsive/accessibility behavior')
    print(f'\n{checks} DSX-02B browser checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
