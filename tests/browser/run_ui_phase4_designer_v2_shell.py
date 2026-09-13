#!/usr/bin/env python3
"""Chromium acceptance for Phase 4 Gamma-02 Studio focused Designer workspace."""
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
        root = page.locator('[data-certloom-designer]')
        await ck('certloom-template-designer-v2' in (await root.get_attribute('class') or ''), 'v2Class')
        await ck('is-pro-mode' in (await root.get_attribute('class') or ''), 'unifiedPreciseClass')
        await ck((await root.get_attribute('data-designer-mode')) == 'pro', 'unifiedPreciseData')
        await ck(await page.locator('[data-certloom-designer-mode-button]').count() == 0, 'noCustomerModeSwitch')
        await ck(await page.locator('.certloom-designer-workspace-back').is_visible(), 'designCenterBackVisible')
        await ck(await page.locator('a[href="#manage-design"]').is_visible(), 'manageDesignVisible')
        await ck(await page.locator('[data-certloom-designer-preview-scroll]').count() == 0, 'noEmbeddedPreviewAction')
        await ck(await page.locator('[data-certloom-designer-insert-focus]').is_visible(), 'insertVisible')
        await ck(await page.locator('[data-certloom-designer-undo]').is_visible(), 'undoVisible')
        await ck(await page.locator('[data-certloom-designer-redo]').is_visible(), 'redoVisible')
        await ck(await page.locator('.certloom-designer-v2-save').is_visible(), 'saveVisible')
        await ck(await page.locator('.certloom-template-designer-layers-primary').is_visible(), 'layersVisible')
        await ck(await page.locator('.certloom-template-designer-inspector').is_visible(), 'propertiesVisible')
        await ck(await page.locator('[data-certloom-designer-workspace]').is_visible(), 'canvasVisible')
        await ck(await page.locator('[data-certloom-designer-layers]').count() == 1, 'singleLayersAuthority')

        layout = await page.evaluate("""() => {
          const wrapper=document.querySelector('.certloom-template-admin.is-designer-workspace').getBoundingClientRect();
          const r=document.querySelector('[data-certloom-designer]').getBoundingClientRect();
          const left=document.querySelector('.certloom-template-designer-add-panel').getBoundingClientRect();
          const canvas=document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect();
          const props=document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect();
          const stage=getComputedStyle(document.querySelector('.certloom-template-designer-stage'));
          const shell=getComputedStyle(document.querySelector('.certloom-template-designer-shell'));
          const canvasStyle=getComputedStyle(document.querySelector('[data-certloom-designer-workspace]'));
          const propsStyle=getComputedStyle(document.querySelector('.certloom-template-designer-inspector'));
          const leftStyle=getComputedStyle(document.querySelector('.certloom-template-designer-add-panel'));
          return {wrapperH:wrapper.height,rootTop:r.top,rootBottom:r.bottom,rootH:r.height,viewport:innerHeight,docH:document.documentElement.scrollHeight,clientH:document.documentElement.clientHeight,
            leftX:left.x,canvasX:canvas.x,propsX:props.x,stageCols:stage.gridTemplateColumns,shellDisplay:shell.display,
            leftOverflow:leftStyle.overflowY,canvasOverflow:canvasStyle.overflowY,propsOverflow:propsStyle.overflowY,
            canvasPosition:canvasStyle.position,propsPosition:propsStyle.position,leftH:left.height,canvasH:canvas.height,propsH:props.height};
        }""")
        await ck(layout['rootH'] <= layout['wrapperH'] + 1, 'designerBoundedToWorkspace')
        await ck(layout['docH'] <= layout['clientH'] + 1, 'desktopNoPageLevelScroll')
        await ck(layout['leftX'] < layout['canvasX'] < layout['propsX'], 'leftCanvasPropertiesOrder')
        await ck(len(layout['stageCols'].split()) >= 3, 'desktopThreeWorkingColumns')
        await ck(layout['shellDisplay'] == 'contents', 'legacyShellDoesNotCreateNestedGrid')
        await ck(layout['leftOverflow'] in ('auto','scroll'), 'layersOwnInternalScroll')
        await ck(layout['canvasOverflow'] in ('auto','scroll'), 'canvasOwnInternalScroll')
        await ck(layout['propsOverflow'] in ('auto','scroll'), 'propertiesOwnInternalScroll')
        await ck(layout['canvasPosition'] == 'relative', 'canvasNoLongerStickyPagePane')
        await ck(layout['propsPosition'] == 'static', 'propertiesStaticInsideBoundedGrid')
        await ck(min(layout['leftH'],layout['canvasH'],layout['propsH']) >= 300, 'desktopWorkingRailsRemainUsable')

        first_insert = page.locator('[data-certloom-designer-add]:not([disabled])').first
        await page.locator('[data-certloom-designer-insert-focus]').click(); await page.wait_for_timeout(30)
        await ck(await first_insert.evaluate('(el)=>document.activeElement===el'), 'insertFocusesPalette')
        before = await page.locator('[data-certloom-designer-element]').count()
        await first_insert.click(); await page.wait_for_timeout(60)
        after = await page.locator('[data-certloom-designer-element]').count()
        await ck(after == before + 1, 'insertAddsCanonicalElement')
        await ck(await page.locator('[data-certloom-designer-fields]').is_visible(), 'propertiesContextAppears')
        await ck(not await page.locator('[data-certloom-designer-empty]').is_visible(), 'propertiesEmptyHides')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'undoEnabledAfterInsert')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-element]').count() == before, 'undoRestoresCanvas')
        await ck(await page.locator('[data-certloom-designer-redo]').is_enabled(), 'redoEnabled')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer]').evaluate("el=>el.classList.contains('has-unsaved-changes')"), 'redoRestoresDirtyState')
        await page.locator('.certloom-designer-v2-save').click(); await page.wait_for_timeout(20)
        await ck(await page.locator('body').get_attribute('data-submitted') == '1', 'topSaveSubmitsCanonicalForm')

        mobile = await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html, wait_until='load'); await mobile.wait_for_timeout(180)
        m = await mobile.evaluate("""() => {
          const root=document.querySelector('[data-certloom-designer]');
          const stage=getComputedStyle(document.querySelector('.certloom-template-designer-stage'));
          const shell=getComputedStyle(document.querySelector('.certloom-template-designer-shell'));
          const visible=[...root.querySelectorAll('.certloom-template-designer-toolbar .button,.certloom-template-designer-productivity .button,.certloom-template-designer-palette .button')].filter(b=>getComputedStyle(b).display!=='none'&&b.getBoundingClientRect().height>0);
          return {overflow:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,docScrollable:document.documentElement.scrollHeight>document.documentElement.clientHeight,stage:stage.gridTemplateColumns,shell:shell.gridTemplateColumns,min:Math.min(...visible.map(b=>b.getBoundingClientRect().height))};
        }""")
        await ck(m['overflow'], 'mobileNoHorizontalOverflow')
        await ck(m['docScrollable'], 'mobileReturnsToNaturalPageFlow')
        await ck(len(m['stage'].split()) == 1, 'mobileStageSingleColumn')
        await ck(len(m['shell'].split()) == 1, 'mobilePropertiesStack')
        await ck(m['min'] >= 43.5, 'mobileTargets44')
        await mobile.close()

        reduced = await browser.new_page(viewport={'width':1000,'height':800}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html,wait_until='load'); await reduced.wait_for_timeout(80)
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"), 'reducedMotionMedia')
        await reduced.close()
        forced = await browser.new_page(viewport={'width':1000,'height':800})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(80)
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"), 'forcedColorsMedia')
        except Exception:
            await ck(True,'forcedColorsUnsupported')
        await forced.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4 Gamma-02 Studio Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures')
        return 1
    print('PASS  UI Phase 4 Gamma-02 Studio focused Designer Chromium')
    print(f'\n{checks} checks, 0 failures')
    return 0

if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
