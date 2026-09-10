#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.2 layer organization + Designer clipboard."""
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
        await ck(await page.locator('[data-certloom-designer-copy]').is_visible(),'copyVisible')
        await ck(await page.locator('[data-certloom-designer-cut]').is_visible(),'cutVisible')
        await ck(await page.locator('[data-certloom-designer-paste]').is_visible(),'pasteVisible')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="rename"]').count()==initial,'renamePerLayer')
        await ck(await page.locator('[data-certloom-designer-layer-row-action="visibility"]').count()==initial,'visibilityPerLayer')

        first_id=await rows.first.get_attribute('data-certloom-designer-layer-row')
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')
        await first_row.locator('[data-certloom-designer-layer-row-action="rename"]').click(); await page.wait_for_timeout(50)
        input_=page.locator(f'[data-certloom-designer-layer-rename-input="{first_id}"]')
        await ck(await input_.is_visible(),'renameEditorVisible')
        await ck(await input_.evaluate('e=>document.activeElement===e'),'renameReceivesFocus')
        await input_.fill('Primary recipient title')
        await input_.press('Enter'); await page.wait_for_timeout(70)
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')
        await ck('Primary recipient title' in await first_row.locator('[data-certloom-designer-layer-select]').inner_text(),'renameApplied')
        layout=await page.locator('[data-certloom-designer-json]').input_value(); await ck('Primary recipient title' not in layout,'renameNotArtifactContent')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(60)
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')
        await ck('Primary recipient title' not in await first_row.locator('[data-certloom-designer-layer-select]').inner_text(),'renameUndoable')

        # Visibility is editor-only and undoable.
        await first_row.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{first_id}"]').count()==0,'hiddenRemovedFromEditorCanvas')
        first_row=page.locator(f'[data-certloom-designer-layer-row="{first_id}"]')
        await ck('is-hidden-in-editor' in (await first_row.get_attribute('class') or ''),'hiddenLayerStateVisible')
        layout=await page.locator('[data-certloom-designer-json]').input_value(); await ck(first_id in layout,'hiddenDoesNotRemoveArtifactElement')
        await first_row.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{first_id}"]').count()==1,'showRestoresEditorCanvas')

        # Multi-selection copy/paste uses internal clipboard and new IDs.
        rows=page.locator('[data-certloom-designer-layer-row]')
        id1=await rows.nth(0).get_attribute('data-certloom-designer-layer-row'); id2=await rows.nth(1).get_attribute('data-certloom-designer-layer-row')
        await rows.nth(0).locator('[data-certloom-designer-layer-select]').click(); await rows.nth(1).locator('[data-certloom-designer-layer-select]').click(modifiers=['Shift']); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-copy]').is_enabled(),'copyEnabledForSelection')
        before_canvas=await page.locator('[data-certloom-designer-element]').count()
        await page.locator('[data-certloom-designer-copy]').click(); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-paste]').is_enabled(),'pasteEnabledAfterCopy')
        await page.locator('[data-certloom-designer-paste]').click(); await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas+2,'multiPasteAddsSelectionCount')
        pressed=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count(); await ck(pressed==2,'pastedElementsSelected')
        pasted_ids=await page.locator('[data-certloom-designer-element][aria-pressed="true"]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerElement)")
        await ck(id1 not in pasted_ids and id2 not in pasted_ids and len(set(pasted_ids))==2,'pasteUsesFreshIds')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas,'pasteUndoable')

        # Keyboard copy/cut/paste; form controls retain native shortcut path.
        row=page.locator(f'[data-certloom-designer-layer-row="{id1}"]'); await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        canvas=page.locator(f'[data-certloom-designer-element="{id1}"]'); await canvas.focus(); await page.keyboard.press('Control+c'); await page.wait_for_timeout(20)
        await page.keyboard.press('Control+v'); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas+1,'keyboardCopyPaste')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(50)
        row=page.locator(f'[data-certloom-designer-layer-row="{id1}"]'); await row.locator('[data-certloom-designer-layer-select]').click(); canvas=page.locator(f'[data-certloom-designer-element="{id1}"]'); await canvas.focus(); await page.keyboard.press('Control+x'); await page.wait_for_timeout(70)
        await ck(await page.locator(f'[data-certloom-designer-element="{id1}"]').count()==0,'keyboardCutRemoves')
        await page.locator('[data-certloom-designer-paste]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before_canvas,'pasteAfterCutRestoresCount')

        # Locked selection refuses Cut.
        lock_row=page.locator('[data-certloom-designer-layer-row]').first
        lock_id=await lock_row.get_attribute('data-certloom-designer-layer-row')
        await lock_row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        lock_row=page.locator(f'[data-certloom-designer-layer-row="{lock_id}"]'); await lock_row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-cut]').is_disabled(),'cutDisabledWhenLocked')
        await lock_row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(40)

        # Rename cancellation and form-control shortcut safety.
        first=page.locator('[data-certloom-designer-layer-row]').first; rid=await first.get_attribute('data-certloom-designer-layer-row')
        await first.locator('[data-certloom-designer-layer-row-action="rename"]').click(); inp=page.locator(f'[data-certloom-designer-layer-rename-input="{rid}"]'); await inp.fill('Do not save'); await inp.press('Escape'); await page.wait_for_timeout(50)
        await ck(await page.locator(f'[data-certloom-designer-layer-rename-input="{rid}"]').count()==0,'renameEscapeCancels')
        text_input=page.locator('[data-certloom-designer-field="x"]'); await text_input.focus(); before=await page.locator('[data-certloom-designer-element]').count(); await page.keyboard.press('Control+v'); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before,'formControlPasteNotHijacked')

        # Manual-save serialization keeps organization data outside canonical layout.
        statepage=await browser.new_page(viewport={'width':1200,'height':850}); await statepage.set_content(html,wait_until='load'); await statepage.wait_for_timeout(180)
        srow=statepage.locator('[data-certloom-designer-layer-row]').first; sid=await srow.get_attribute('data-certloom-designer-layer-row')
        await srow.locator('[data-certloom-designer-layer-row-action="rename"]').click(); sinput=statepage.locator(f'[data-certloom-designer-layer-rename-input="{sid}"]'); await sinput.fill('Serialized layer label'); await sinput.press('Enter'); await statepage.wait_for_timeout(40)
        srow=statepage.locator(f'[data-certloom-designer-layer-row="{sid}"]'); await srow.locator('[data-certloom-designer-layer-row-action="visibility"]').click(); await statepage.wait_for_timeout(40)
        await statepage.locator('[data-certloom-designer-form]').evaluate("f=>f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}))"); await statepage.wait_for_timeout(40)
        saved_state=await statepage.locator('[data-certloom-designer-state-json]').input_value(); saved_layout=await statepage.locator('[data-certloom-designer-json]').input_value()
        await ck('Serialized layer label' in saved_state and 'layer_labels' in saved_state and 'hidden_ids' in saved_state and sid in saved_state,'organizationStateSerialized')
        await ck('Serialized layer label' not in saved_layout and sid in saved_layout,'organizationStateOutsideArtifact')
        await statepage.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        await mobile.locator('[data-certloom-designer-layer-row]').first.locator('[data-certloom-designer-layer-row-action="rename"]').click(); await mobile.wait_for_timeout(30)
        dims=await mobile.locator('[data-certloom-designer-layer-rename-editor] .button').evaluate_all("els=>els.map(e=>e.getBoundingClientRect().height)")
        await ck(bool(dims) and min(dims)>=43.5,'mobileRenameButtons44')
        action_h=await mobile.locator('[data-certloom-designer-layer-row-action="visibility"]').first.evaluate("e=>e.getBoundingClientRect().height")
        await ck(action_h>=43.5,'mobileVisibility44')
        await mobile.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.2 layer organization Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.2 layer organization Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
