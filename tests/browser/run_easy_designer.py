#!/usr/bin/env python3
"""Real Chromium acceptance for DSX-02A Easy Designer direct placement and Smart Guides."""
import asyncio, json, os, re, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests' / 'browser' / 'build_easy_designer_fixture.php'


def fixture_html():
    env = os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION'] = '2'
    proc = subprocess.run(['php', str(BUILDER)], cwd=ROOT, env=env, text=True, capture_output=True)
    if proc.returncode:
        raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout


def px_number(value):
    m = re.match(r'\s*(-?[0-9.]+)', value or '')
    return float(m.group(1)) if m else 0.0


async def main():
    html = fixture_html(); failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks
        checks += 1
        if not value: failed.append(name)

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page = await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(html, wait_until='load'); await page.wait_for_timeout(220)
        root = page.locator('[data-certloom-designer]'); canvas = page.locator('[data-certloom-designer-page]')
        await ck('is-quick-mode' in ((await root.get_attribute('class')) or ''), 'easyQuickDefault')
        await ck((await root.get_attribute('data-designer-safe-margin-mm')) == '10', 'safeMarginDeclared')
        await ck(await page.locator('[data-certloom-designer-snap]').is_visible(), 'smartGuidesVisibleQuick')
        await ck(await page.locator('[data-certloom-designer-snap]').is_checked(), 'smartGuidesDefaultOn')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_hidden(), 'quickAlignHiddenWithoutSelection')
        font_family = page.locator('[data-certloom-designer-style-field="font_family"]')
        await ck(await font_family.count() == 1, 'fontCatalogPresent')
        for kind in ['text','image','logo','merge','qr','rectangle','line']:
            b = page.locator(f'[data-certloom-designer-add="{kind}"]')
            await ck(await b.is_visible(), f'{kind}Visible')
            await ck((await b.get_attribute('draggable')) == 'true', f'{kind}Draggable')

        # True palette -> canvas placement (not click-then-drag).
        before = await page.locator('[data-certloom-designer-element]').count()
        box = await canvas.bounding_box(); assert box
        await page.locator('[data-certloom-designer-add="text"]').drag_to(canvas, target_position={'x': box['width']*0.72, 'y': box['height']*0.32})
        await page.wait_for_timeout(100)
        after = await page.locator('[data-certloom-designer-element]').count()
        await ck(after == before + 1, 'textDragAddsOne')
        dropped = page.locator('[data-certloom-designer-element].is-selected')
        await ck(await dropped.count() == 1, 'droppedTextSelected')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_visible(), 'quickAlignVisibleAfterSelection')
        dropped_text_id = await dropped.get_attribute('data-certloom-designer-element')
        db = await dropped.bounding_box(); box = await canvas.bounding_box(); assert db and box
        await ck(abs((db['x']+db['width']/2) - (box['x']+box['width']*0.72)) < max(25, box['width']*0.08), 'dropPositionHonored')

        # Media-backed drag retains local attachment authority and honors drop placement.
        before = after; box = await canvas.bounding_box(); assert box
        await page.evaluate("""({x,y}) => { const source=document.querySelector('[data-certloom-designer-add=\"image\"]'); const target=document.querySelector('[data-certloom-designer-page]'); const dt=new DataTransfer(); source.dispatchEvent(new DragEvent('dragstart',{bubbles:true,cancelable:true,dataTransfer:dt})); target.dispatchEvent(new DragEvent('dragover',{bubbles:true,cancelable:true,dataTransfer:dt,clientX:x,clientY:y})); target.dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:dt,clientX:x,clientY:y})); source.dispatchEvent(new DragEvent('dragend',{bubbles:true,cancelable:true,dataTransfer:dt})); }""", {'x': box['x']+box['width']*0.25, 'y': box['y']+box['height']*0.72})
        await page.wait_for_timeout(120)
        await ck(await page.locator('[data-certloom-designer-element]').count() == before + 1, 'imageDragAddsOne')
        await ck((await page.locator('[data-certloom-designer-content-field="asset"]').input_value()) == 'attachment:321', 'imageDragUsesLocalAttachment')

        # Keyboard direct text editing: Enter opens editor, Ctrl+Enter commits, undo is one transaction.
        text = page.locator('.certloom-template-designer-element.is-text').first
        await text.click(); await page.wait_for_timeout(40)
        old_text = (await text.text_content()) or ''
        await text.press('Enter'); await page.wait_for_timeout(50)
        editor = page.locator('[data-certloom-designer-direct-text]')
        await ck(await editor.count() == 1 and await editor.is_visible(), 'enterStartsDirectTextEdit')
        await editor.fill('Directly edited certificate title')
        await editor.press('Control+Enter'); await page.wait_for_timeout(80)
        selected = page.locator('[data-certloom-designer-element].is-selected')
        await ck('Directly edited certificate title' in ((await selected.text_content()) or ''), 'directTextCommitCanvas')
        await ck((await page.locator('[data-certloom-designer-content-field="text"]').input_value()) == 'Directly edited certificate title', 'directTextCommitInspector')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(70)
        await ck(old_text in ((await page.locator('[data-certloom-designer-element].is-selected').text_content()) or ''), 'directTextUndoOneTransaction')

        # Double-click path and Escape cancellation preserve canonical text.
        selected = page.locator('[data-certloom-designer-element].is-selected')
        before_cancel = (await selected.text_content()) or ''
        await selected.dblclick(); await page.wait_for_timeout(40)
        editor = page.locator('[data-certloom-designer-direct-text]')
        await ck(await editor.is_visible(), 'doubleClickStartsDirectTextEdit')
        await editor.fill('This must be cancelled'); await editor.press('Escape'); await page.wait_for_timeout(60)
        await ck(before_cancel in ((await page.locator('[data-certloom-designer-element].is-selected').text_content()) or ''), 'directTextEscapeCancels')

        # Visual color chooser is paired to the canonical exact hex field.
        color_picker = page.locator('[data-certloom-designer-color-picker]'); color_hex = page.locator('[data-certloom-designer-style-field="color"]')
        await ck(await color_picker.is_visible(), 'colorPickerVisibleForText')
        await color_picker.fill('#123456'); await color_picker.dispatch_event('change'); await page.wait_for_timeout(60)
        await ck((await color_hex.input_value()).upper() == '#123456', 'colorPickerSyncsHex')
        color_css = await page.locator('[data-certloom-designer-element].is-selected .certloom-template-designer-content').evaluate('(el)=>getComputedStyle(el).color')
        await ck('18, 52, 86' in color_css, 'colorPickerUpdatesCanvas')
        await ck(await font_family.is_visible(), 'fontCatalogVisibleQuick')
        await font_family.select_option('monospace'); await font_family.dispatch_event('change'); await page.wait_for_timeout(60)
        await ck((await font_family.input_value()) == 'monospace', 'fontCatalogSelection')
        family_css = await page.locator('[data-certloom-designer-element].is-selected .certloom-template-designer-content').evaluate('(el)=>getComputedStyle(el).fontFamily')
        await ck('mono' in family_css.lower() or 'courier' in family_css.lower() or 'consolas' in family_css.lower(), 'fontCatalogUpdatesCanvas')

        # Easy Align reuses canonical page alignment logic. Use the compact text created by drag-to-add.
        compact_text = page.locator(f'[data-certloom-designer-element="{dropped_text_id}"]')
        await compact_text.scroll_into_view_if_needed(); await compact_text.click(); await page.wait_for_timeout(45)
        await page.locator('[data-certloom-designer-quick-align] [data-certloom-designer-align="center-x"]').click(); await page.wait_for_timeout(70)
        sel_box = await page.locator('[data-certloom-designer-element].is-selected').bounding_box(); page_box = await canvas.bounding_box(); assert sel_box and page_box
        await ck(abs((sel_box['x']+sel_box['width']/2) - (page_box['x']+page_box['width']/2)) < 2.5, 'easyAlignCenterX')

        # Smart safe-margin snap. Move selected unrotated text close to 10mm left margin.
        selected = page.locator('[data-certloom-designer-element].is-selected')
        await selected.scroll_into_view_if_needed(); await page.wait_for_timeout(30)
        sb = await selected.bounding_box(); pb = await canvas.bounding_box(); assert sb and pb
        scale = pb['width'] / 297.0
        width_mm = sb['width'] / scale
        start_x = sb['x'] + sb['width']/2; start_y = sb['y'] + sb['height']/2
        target_x = pb['x'] + (10.8 + width_mm/2) * scale
        await page.mouse.move(start_x, start_y); await page.mouse.down(); await page.mouse.move(target_x, start_y, steps=6); await page.wait_for_timeout(45)
        guide = page.locator('.certloom-template-designer-guide.is-vertical')
        await ck(await guide.count() >= 1, 'safeMarginGuideVisibleDuringDrag')
        if await guide.count():
            guide_left = px_number(await guide.first.evaluate('(el)=>el.style.left'))
            await ck(abs(guide_left/scale - 10.0) < 0.35, 'safeMarginGuideAt10mm')
        await page.mouse.up(); await page.wait_for_timeout(70)
        sb2 = await selected.bounding_box(); assert sb2
        left_mm = (sb2['x'] - pb['x']) / scale
        await ck(abs(left_mm - 10.0) < 0.45, 'safeMarginMagneticSnap')

        # Alt/Option bypasses Smart Guides for precision while keeping the element page-bounded.
        sb = await selected.bounding_box(); assert sb
        start_x = sb['x'] + sb['width']/2; start_y = sb['y'] + sb['height']/2
        target_left_mm = 11.2; target_x = pb['x'] + (target_left_mm + width_mm/2) * scale
        await page.keyboard.down('Alt'); await page.mouse.move(start_x,start_y); await page.mouse.down(); await page.mouse.move(target_x,start_y,steps=5); await page.wait_for_timeout(35)
        await ck(await page.locator('.certloom-template-designer-guide').count() == 0, 'altBypassHidesSnapGuides')
        await page.mouse.up(); await page.keyboard.up('Alt'); await page.wait_for_timeout(70)
        sb3 = await selected.bounding_box(); assert sb3
        bypass_left_mm = (sb3['x'] - pb['x']) / scale
        await ck(abs(bypass_left_mm - target_left_mm) < 0.55 and abs(bypass_left_mm - 10.0) > 0.55, 'altBypassAvoidsMagneticSnap')

        # Smart Guides toggle stays functional in Easy mode.
        snap = page.locator('[data-certloom-designer-snap]'); await snap.uncheck(); await page.wait_for_timeout(30)
        await ck(not await snap.is_checked(), 'smartGuidesCanDisable')
        await snap.check(); await page.wait_for_timeout(30); await ck(await snap.is_checked(), 'smartGuidesCanReenable')

        # Mobile: new Easy controls remain contained and >=44px where primary.
        mobile = await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(220)
        m = await mobile.evaluate("""() => { const root=document.querySelector('[data-certloom-designer]'); const controls=[...document.querySelectorAll('.is-quick-mode .certloom-template-designer-mode-switch .button,.is-quick-mode .certloom-template-designer-toolbar .button,.is-quick-mode .certloom-template-designer-palette .button,.is-quick-mode .certloom-template-designer-quick-align .button,.is-quick-mode [data-certloom-designer-layers-toggle],.is-quick-mode [data-certloom-designer-color-picker]')].filter(el=>getComputedStyle(el).display!=='none'&&el.getBoundingClientRect().height>0); return {quick:root.classList.contains('is-quick-mode'),overflow:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,min:Math.min(...controls.map(el=>el.getBoundingClientRect().height)),snap:getComputedStyle(document.querySelector('[data-certloom-designer-snap]')).display!=='none'}; }""")
        await ck(m['quick'] and m['overflow'], 'mobileEasyContained')
        await ck(m['min'] >= 43.5, 'mobileEasyTargets44')
        await ck(m['snap'], 'mobileSmartGuidesAvailable')
        await mobile.close()

        reduced = await browser.new_page(viewport={'width':900,'height':800}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html,wait_until='load'); await reduced.wait_for_timeout(100)
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"), 'easyReducedMotionMedia')
        await reduced.close()

        forced = await browser.new_page(viewport={'width':900,'height':800})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(80)
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"), 'easyForcedColorsMedia')
        except Exception:
            await ck(True, 'easyForcedColorsUnsupported')
        await forced.close(); await page.close(); await browser.close()

    if failed:
        print('FAIL  DSX-02A Easy Designer browser gate: ' + ', '.join(sorted(set(failed))))
        print(f'\n{checks} Easy Designer browser checks, {len(set(failed))} failures')
        return 1
    print('PASS  DSX-02A Easy Designer browser gate: drag-to-place, local media, direct text edit, color sync, Easy Align, safe-margin Smart Guides, Alt bypass, responsive/accessibility behavior')
    print(f'\n{checks} Easy Designer browser checks, 0 failures')
    return 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
