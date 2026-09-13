#!/usr/bin/env python3
"""Chromium acceptance for revised Gamma-02 Studio compact Layers and keyboard clipboard."""
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests' / 'browser' / 'build_ui_phase4_designer_v2_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='3'
    proc=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if proc.returncode: raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout

async def main():
    html=fixture_html(); failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks
        checks+=1
        if not value: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':950})
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(250)
        rows=page.locator('[data-certloom-designer-layer-row]'); initial=await rows.count()
        await ck(initial>=10,'fixtureDepth')
        await ck(await page.locator('[data-certloom-designer-layer-row-action]').count()==initial*4,'fourCompactActionsPerLayer')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="visibility"]').count()==initial,'visibilityPerLayer')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="lock"]').count()==initial,'lockPerLayer')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="duplicate"]').count()==initial,'duplicatePerLayer')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="delete"]').count()==initial,'deletePerLayer')
        await ck(await page.locator('[data-certloom-designer-copy]').count()==0 and await page.locator('[data-certloom-designer-cut]').count()==0 and await page.locator('[data-certloom-designer-paste]').count()==0,'retiredClipboardToolbarDoesNotClutterStudio')

        first_id=await rows.first.get_attribute('data-certloom-designer-layer-row')
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')

        # Visibility is editor-only and reversible from the compact icon row.
        await first_row.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{first_id}"]').count()==0,'hiddenRemovedFromEditorCanvas')
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')
        await ck('is-hidden-in-editor' in (await first_row.get_attribute('class') or ''),'hiddenLayerStateVisible')
        layout=await page.locator('[data-certloom-designer-json]').input_value(); await ck(first_id in layout,'hiddenDoesNotRemoveArtifactElement')
        await first_row.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{first_id}"]').count()==1,'showRestoresEditorCanvas')

        # Multi-selection uses keyboard clipboard without permanent toolbar clutter and creates fresh IDs.
        rows=page.locator('[data-certloom-designer-layer-row]')
        id1=await rows.nth(0).get_attribute('data-certloom-designer-layer-row'); id2=await rows.nth(1).get_attribute('data-certloom-designer-layer-row')
        await rows.nth(0).locator('[data-certloom-designer-layer-select]').click(); await rows.nth(1).locator('[data-certloom-designer-layer-select]').click(modifiers=['Shift']); await page.wait_for_timeout(40)
        before_canvas=await page.locator('[data-certloom-designer-element]').count()
        await page.locator(f'[data-certloom-designer-element="{id1}"]').focus(); await page.keyboard.press('Control+c'); await page.keyboard.press('Control+v'); await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas+2,'multiKeyboardPasteAddsSelectionCount')
        pressed=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count(); await ck(pressed==2,'pastedElementsSelected')
        pasted_ids=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerElement)")
        await ck(id1 not in pasted_ids and id2 not in pasted_ids and len(set(pasted_ids))==2,'pasteUsesFreshIds')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas,'pasteUndoable')

        # Single-element copy/cut/paste remain keyboard-capable.
        row=page.locator(f'[data-certloom-designer-layer-row="{id1}"]'); await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        canvas=page.locator(f'[data-certloom-designer-element="{id1}"]'); await canvas.focus(); await page.keyboard.press('Control+c'); await page.keyboard.press('Control+v'); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas+1,'keyboardCopyPaste')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(50)
        row=page.locator(f'[data-certloom-designer-layer-row="{id1}"]'); await row.locator('[data-certloom-designer-layer-select]').click(); canvas=page.locator(f'[data-certloom-designer-element="{id1}"]'); await canvas.focus(); await page.keyboard.press('Control+x'); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{id1}"]').count()==0,'keyboardCutRemoves')
        await page.keyboard.press('Control+v'); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas,'pasteAfterCutRestoresCount')

        # Locked layers reject destructive keyboard edits and expose state compactly.
        lock_row=page.locator('[data-certloom-designer-layer-row]').first
        lock_id=await lock_row.get_attribute('data-certloom-designer-layer-row')
        await lock_row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        lock_row=page.locator(f'[data-certloom-designer-layer-row="{lock_id}"]')
        await ck((await lock_row.get_attribute('draggable'))=='false','lockedLayerNotDraggable')
        await ck(await lock_row.locator('[data-certloom-designer-layer-row-action="delete"]').is_disabled(),'lockedDeleteDisabled')
        await ck(await lock_row.locator('[data-certloom-designer-layer-row-action="duplicate"]').is_disabled(),'lockedDuplicateDisabled')
        await lock_row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        locked_count=await page.locator('[data-certloom-designer-element]').count(); await page.locator(f'[data-certloom-designer-element="{lock_id}"]').focus(); await page.keyboard.press('Control+x'); await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element]').count()==locked_count,'lockedKeyboardCutFailsClosed')
        await lock_row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(40)

        # Form controls retain native clipboard path rather than triggering Designer paste.
        geometry=page.locator('[data-certloom-designer-property-section="geometry"]')
        if 'is-open' not in (await geometry.get_attribute('class') or ''):
            await geometry.locator('.certloom-studio-accordion-trigger').click(); await page.wait_for_timeout(20)
        text_input=page.locator('[data-certloom-designer-field="x"]'); await text_input.focus(); before=await page.locator('[data-certloom-designer-element]').count(); await page.keyboard.press('Control+v'); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before,'formControlPasteNotHijacked')

        # Manual-save serialization keeps visibility/lock editor organization outside canonical layout.
        statepage=await browser.new_page(viewport={'width':1200,'height':850}); await statepage.set_content(html,wait_until='load'); await statepage.wait_for_timeout(180)
        srow=statepage.locator('[data-certloom-designer-layer-row]').first; sid=await srow.get_attribute('data-certloom-designer-layer-row')
        await srow.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await statepage.wait_for_timeout(40)
        srow=statepage.locator(f'[data-certloom-designer-layer-row="{sid}"]'); await srow.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await statepage.wait_for_timeout(40)
        await statepage.locator('[data-certloom-designer-form]').evaluate("f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))"); await statepage.wait_for_timeout(40)
        saved_state=await statepage.locator('[data-certloom-designer-state-json]').input_value(); saved_layout=await statepage.locator('[data-certloom-designer-json]').input_value()
        await ck('hidden_ids' in saved_state and 'locked_ids' in saved_state and sid in saved_state,'organizationStateSerialized')
        await ck(sid in saved_layout,'organizationStateDoesNotRemoveArtifactElement')
        await statepage.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        action_h=await mobile.locator('[data-certloom-designer-layer-row-action="visibility"]').first.evaluate("e=>e.getBoundingClientRect().height")
        await ck(action_h>=30,'mobileCompactLayerActionTouchable')
        await ck(await mobile.locator('[data-certloom-designer-layer-row-action]').count()==await mobile.locator('[data-certloom-designer-layer-row]').count()*4,'mobileRetainsAllFourLayerActions')
        await mobile.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4 Gamma-02R compact Layers Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4 Gamma-02R compact Layers Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
