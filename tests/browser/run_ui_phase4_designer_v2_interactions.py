#!/usr/bin/env python3
"""Chromium acceptance for the revised Gamma-02 Certloom Studio interactions."""
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests' / 'browser' / 'build_ui_phase4_designer_v2_fixture.php'

def fixture_html():
    env = os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION'] = '3'
    proc = subprocess.run(['php', str(BUILDER)], cwd=ROOT, env=env, text=True, capture_output=True)
    if proc.returncode:
        raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout

async def main():
    html = fixture_html(); failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks
        checks += 1
        if not value: failed.append(name)
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page = await browser.new_page(viewport={'width':1440,'height':950})
        await page.set_content(html, wait_until='load'); await page.wait_for_timeout(250)

        rows = page.locator('[data-certloom-designer-layer-row]')
        initial = await rows.count()
        await ck(initial >= 10, 'fixtureHasLayerDepth')
        await ck(await page.locator('[data-certloom-designer-layer-row-action]').count() == initial * 3, 'threeCompactIconActionsPerLayer')
        await ck((await rows.first.get_attribute('draggable')) == 'true', 'unlockedLayerDraggable')
        await ck(await page.locator('[data-certloom-designer-fit]').is_visible(), 'fitControlVisible')

        # Dragging the Layers list is the direct compact reorder interaction.
        order_before = await rows.evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-layer-row'))")
        moved_id = order_before[0]
        await rows.first.drag_to(rows.nth(2)); await page.wait_for_timeout(100)
        order_after = await page.locator('[data-certloom-designer-layer-row]').evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-layer-row'))")
        await ck(order_after.index(moved_id) > 0, 'dragReordersLayer')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'dragReorderIsUndoable')

        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await ck((await row.get_attribute('draggable')) == 'false', 'lockedLayerNotDraggable')
        await ck(await row.locator('[data-certloom-designer-layer-row-action="delete"]').is_disabled(), 'lockedLayerDeleteDisabled')
        await ck((await row.locator('[data-certloom-designer-layer-row-action="lock"]').get_attribute('aria-pressed')) == 'true', 'lockStateExposed')
        await row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await ck((await row.get_attribute('draggable')) == 'true', 'unlockRestoresDrag')

        # Duplicate stays available from the Layer context menu without permanent row clutter.
        count_before = await page.locator('[data-certloom-designer-element]').count()
        await row.click(button='right'); await page.wait_for_timeout(40)
        layer_menu = page.locator('[data-certloom-designer-context-menu][data-surface="layer"]')
        await ck(await layer_menu.is_visible(), 'layerRightClickMenuVisible')
        duplicate_action = layer_menu.locator('[data-certloom-designer-context-action="duplicate"]')
        await ck(await duplicate_action.is_enabled(), 'layerRightClickDuplicateAvailable')
        await duplicate_action.click(); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before + 1, 'contextDuplicateAddsOne')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'contextDuplicateIsUndoable')
        first_after_duplicate = page.locator('[data-certloom-designer-layer-row]').first
        await first_after_duplicate.locator('[data-certloom-designer-layer-row-action="delete"]').click(); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before, 'rowDeleteRemovesDuplicate')

        # Ctrl/Cmd+D shares the canonical duplicate operation.
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        canvas_selected = page.locator(f'[data-certloom-designer-element="{moved_id}"]')
        await canvas_selected.focus(); await page.keyboard.press('Control+d'); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before + 1, 'ctrlDDuplicates')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before, 'duplicateShortcutUndoable')

        # Bracket shortcuts remain a keyboard-only fine layer-order control.
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus()
        idx_before = await page.locator('[data-certloom-designer-layer-row]').evaluate_all("(els,id)=>els.map(e=>e.dataset.certloomDesignerLayerRow).indexOf(id)", moved_id)
        await page.keyboard.press('Control+['); await page.wait_for_timeout(50)
        idx_after = await page.locator('[data-certloom-designer-layer-row]').evaluate_all("(els,id)=>els.map(e=>e.dataset.certloomDesignerLayerRow).indexOf(id)", moved_id)
        await ck(idx_after >= idx_before, 'ctrlBracketMovesBackwardOrStaysAtBackBoundary')

        # Canvas selection shortcuts.
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus(); await page.keyboard.press('Control+a'); await page.wait_for_timeout(40)
        selected_count = await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()
        total_count = await page.locator('[data-certloom-designer-element]').count()
        await ck(selected_count == total_count, 'ctrlASelectsAllCanvasElements')
        await page.keyboard.press('Escape'); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count() == 0, 'escapeClearsSelection')

        # Approved Studio zoom model: fit is 100%, +/- are relative to that baseline, Ctrl/Cmd+0 returns to fit.
        await page.locator('[data-certloom-designer-fit]').click(); await page.wait_for_timeout(50)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'fitButtonSets100Baseline')
        await page.locator('[data-certloom-designer-zoom-in]').click(); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '110%', 'zoomInButtonUsesRelativePercent')
        await page.locator('[data-certloom-designer-zoom-out]').click(); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'zoomOutReturnsToFitBaseline')
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus(); await page.keyboard.press('Control+0'); await page.wait_for_timeout(60)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'ctrlZeroReturnsToFitBaseline')

        # Arrange remains available contextually in the right Inspector rather than as row clutter.
        await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-designer-property-section="arrange"]').count() == 1, 'arrangeLivesInInspector')
        await ck(await page.locator('[data-certloom-designer-layer-action="front"]').count() >= 1, 'inspectorLayerOrderActionsAvailable')

        # Mobile fallback: natural page flow, compact row actions, and all alternatives remain present.
        mobile = await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html, wait_until='load'); await mobile.wait_for_timeout(200)
        heights = await mobile.locator('[data-certloom-designer-layer-row-action]').first.evaluate_all("els=>els.map(e=>e.getBoundingClientRect().height)")
        await ck(bool(heights) and min(heights) >= 30, 'mobileLayerActionsRemainTouchableAndCompact')
        await ck(await mobile.locator('[data-certloom-designer-layer-row]').first.get_attribute('draggable') == 'true', 'mobileKeepsDragStateAndIconAlternatives')
        await mobile.close(); await page.close(); await browser.close()

    if failed:
        print('FAIL  UI Phase 4 Gamma-02R Studio interactions Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures')
        return 1
    print('PASS  UI Phase 4 Gamma-02R Studio interactions Chromium')
    print(f'\n{checks} checks, 0 failures')
    return 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
