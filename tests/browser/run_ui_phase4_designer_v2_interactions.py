#!/usr/bin/env python3
"""Chromium acceptance for the Phase 4 Designer-v2 interaction tranche."""
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
        await page.set_content(html, wait_until='load'); await page.wait_for_timeout(220)

        rows = page.locator('[data-certloom-designer-layer-row]')
        initial = await rows.count()
        await ck(initial >= 10, 'fixtureHasLayerDepth')
        await ck(await page.locator('[data-certloom-designer-layer-row-action]').count() == initial * 9, 'nineDirectActionsPerLayer')
        await ck((await rows.first.get_attribute('draggable')) == 'true', 'unlockedLayerDraggable')
        await ck(await page.locator('[data-certloom-designer-true-size]').is_visible(), 'trueSizeVisible')
        await ck('Ctrl/Cmd+D' in (await page.locator('[data-certloom-designer-duplicate]').get_attribute('title') or ''), 'duplicateShortcutTooltip')

        order_before = await rows.evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-layer-row'))")
        moved_id = order_before[0]
        await rows.first.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(30)
        await rows.first.locator('[data-certloom-designer-layer-row-action="backward"]').click(); await page.wait_for_timeout(60)
        order_after = await page.locator('[data-certloom-designer-layer-row]').evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-layer-row'))")
        await ck(order_after[1] == moved_id, 'rowBackwardActionReordersCanonically')
        await ck(order_after[0] != moved_id, 'rowOrderActuallyChanges')

        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await ck((await row.get_attribute('draggable')) == 'false', 'lockedLayerNotDraggable')
        await ck(await row.locator('[data-certloom-designer-layer-row-action="backward"]').is_disabled(), 'lockedLayerReorderDisabled')
        await ck((await row.locator('[data-certloom-designer-layer-row-action="lock"]').get_attribute('aria-pressed')) == 'true', 'lockStateExposed')
        await row.locator('[data-certloom-designer-layer-row-action="lock"]').click(); await page.wait_for_timeout(50)
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await ck((await row.get_attribute('draggable')) == 'true', 'unlockRestoresDrag')

        # Row duplicate/delete are non-keyboard equivalents and preserve history.
        count_before = await page.locator('[data-certloom-designer-element]').count()
        await row.locator('[data-certloom-designer-layer-row-action="duplicate"]').click(); await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before + 1, 'rowDuplicateAddsOne')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'rowDuplicateIsUndoable')
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

        # Bracket shortcuts mirror layer buttons.
        row = page.locator(f'[data-certloom-designer-layer-row="{moved_id}"]')
        await row.locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(20)
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus()
        idx_before = (await page.locator('[data-certloom-designer-layer-row]').evaluate_all("(els,id)=>els.map(e=>e.dataset.certloomDesignerLayerRow).indexOf(id)", moved_id))
        await page.keyboard.press('Control+['); await page.wait_for_timeout(50)
        idx_after = (await page.locator('[data-certloom-designer-layer-row]').evaluate_all("(els,id)=>els.map(e=>e.dataset.certloomDesignerLayerRow).indexOf(id)", moved_id))
        await ck(idx_after > idx_before, 'ctrlBracketMovesBackward')

        # Canvas selection shortcuts.
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus(); await page.keyboard.press('Control+a'); await page.wait_for_timeout(40)
        selected_count = await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count()
        total_count = await page.locator('[data-certloom-designer-element]').count()
        await ck(selected_count == total_count, 'ctrlASelectsAllCanvasElements')
        await page.keyboard.press('Escape'); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-element][aria-pressed="true"]').count() == 0, 'escapeClearsSelection')

        # Zoom has both keyboard and visible controls.
        await page.locator('[data-certloom-designer-true-size]').click(); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'trueSizeButtonSets100')
        await page.locator(f'[data-certloom-designer-element="{moved_id}"]').focus(); await page.keyboard.press('+'); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '110%', 'plusZoomsIn')
        await page.keyboard.press('-'); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'minusZoomsOut')
        await page.keyboard.press('1'); await page.wait_for_timeout(40)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'oneRestoresTrueSize')
        await page.keyboard.press('0'); await page.wait_for_timeout(60)
        fit_value = int((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip().rstrip('%'))
        await ck(25 <= fit_value <= 200, 'zeroFitsWithinZoomBounds')

        # Actual drag reorder also uses the same z-order model.
        await page.locator('[data-certloom-designer-true-size]').click(); await page.wait_for_timeout(30)
        current_rows = page.locator('[data-certloom-designer-layer-row]')
        drag_id = (await current_rows.first.get_attribute('data-certloom-designer-layer-row'))
        target = current_rows.nth(2)
        await current_rows.first.drag_to(target); await page.wait_for_timeout(100)
        drag_order = await page.locator('[data-certloom-designer-layer-row]').evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerLayerRow)")
        await ck(drag_order.index(drag_id) > 0, 'dragReordersLayer')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'dragReorderIsUndoable')

        # Mobile fallback: row action controls stay touchable.
        mobile = await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html, wait_until='load'); await mobile.wait_for_timeout(180)
        heights = await mobile.locator('[data-certloom-designer-layer-row-action]').first.evaluate_all("els=>els.map(e=>e.getBoundingClientRect().height)")
        await ck(bool(heights) and min(heights) >= 43.5, 'mobileLayerAction44')
        await ck(await mobile.locator('[data-certloom-designer-layer-row]').first.get_attribute('draggable') == 'true', 'mobileKeepsNonDragAlternativesAndDragState')
        await mobile.close(); await page.close(); await browser.close()

    if failed:
        print('FAIL  UI Phase 4 Designer v2 interactions Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures')
        return 1
    print('PASS  UI Phase 4 Designer v2 interactions Chromium')
    print(f'\n{checks} checks, 0 failures')
    return 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
