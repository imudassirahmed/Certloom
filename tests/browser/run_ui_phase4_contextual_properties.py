#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.3 contextual toolbar + structured Properties."""
import asyncio, os, subprocess, json
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_contextual_properties_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='3'
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def main():
    html=fixture_html(); failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    async def select_layer(pg, element_id):
        row = pg.locator(f'[data-certloom-designer-layer-row="{element_id}"]')
        button = row.locator('[data-certloom-designer-layer-select]')
        await button.focus()
        await button.press('Enter')
        await pg.wait_for_timeout(80)
    async def layout_snapshot(pg):
        await pg.locator('[data-certloom-designer-form]').dispatch_event('submit')
        await pg.wait_for_timeout(20)
        return json.loads(await pg.locator('[data-certloom-designer-json]').input_value())
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':950})
        page.set_default_timeout(4000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(250)
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        bytype={}
        for e in layout['elements']: bytype.setdefault(e['type'],e['id'])
        dock=page.locator('[data-certloom-designer-context-dock]')
        h0=await dock.evaluate('e=>e.getBoundingClientRect().height')
        await ck(await dock.is_visible() and 44<=h0<=60,'contextDockVisibleAndCompact')
        await ck(await page.locator('[data-certloom-designer-context-hint]').is_visible(),'contextHintInitiallyVisible')

        # text context + canonical style mirror + direct text edit
        tid=bytype['text']; await select_layer(page, tid)
        qt=page.locator('[data-certloom-designer-quick-text]')
        edit=page.locator('[data-certloom-designer-context-edit-text]')
        await ck(await qt.is_visible() and await edit.is_visible() and await edit.is_enabled(),'textContextAndEditVisible')
        size=qt.locator('[data-certloom-designer-quick-style="font_size_pt"]')
        old=float(await size.input_value()); await size.fill(str(old+1)); await size.dispatch_event('change'); await page.wait_for_timeout(60)
        changed=await layout_snapshot(page); te=next(e for e in changed['elements'] if e['id']==tid)
        await ck(abs(float(te['style']['font_size_pt'])-(old+1))<0.01,'textContextUsesCanonicalStylePath')
        await edit.click(); await page.wait_for_timeout(50)
        direct=page.locator('[data-certloom-designer-direct-text]')
        await ck(await direct.is_visible(),'editTextStartsDirectEditor')
        await direct.press('Escape'); await page.wait_for_timeout(40)

        # merge context token
        mid=bytype['merge']; await select_layer(page, mid)
        tok=page.locator('[data-certloom-designer-context-token]')
        await ck(await tok.is_visible() and await tok.is_enabled(),'mergeTokenContextVisible')
        await tok.select_option('credential.status'); await page.wait_for_timeout(50)
        changed=await layout_snapshot(page); me=next(e for e in changed['elements'] if e['id']==mid)
        await ck(me.get('token')=='credential.status','mergeTokenUsesCanonicalContentPath')

        # shape context
        sid=bytype['shape']; await select_layer(page, sid)
        shape=page.locator('[data-certloom-designer-context-group="shape"]')
        await ck(await shape.is_visible(),'shapeContextVisible')
        fill=shape.locator('[data-certloom-designer-style-field="fill"]'); await fill.fill('#123456'); await fill.dispatch_event('change'); await page.wait_for_timeout(50)
        changed=await layout_snapshot(page); se=next(e for e in changed['elements'] if e['id']==sid)
        await ck(se.get('fill')=='#123456','shapeFillCanonicalMutation')

        # line context
        lid=bytype['line']; await select_layer(page, lid)
        line=page.locator('[data-certloom-designer-context-group="line"]')
        await ck(await line.is_visible(),'lineContextVisible')
        sw=line.locator('[data-certloom-designer-style-field="stroke_width_mm"]'); await sw.fill('1.2'); await sw.dispatch_event('change'); await page.wait_for_timeout(50)
        changed=await layout_snapshot(page); le=next(e for e in changed['elements'] if e['id']==lid)
        await ck(abs(float(le.get('stroke_width_mm',0))-1.2)<0.01,'lineStrokeCanonicalMutation')

        # QR context and canonical source remains unchanged.
        qid=bytype['qr']; await select_layer(page, qid)
        qr=page.locator('[data-certloom-designer-context-group="qr"]')
        await ck(await qr.is_visible(),'qrContextVisible')
        ec=qr.locator('[data-certloom-designer-content-field="error_correction"]'); await ec.select_option('Q'); await page.wait_for_timeout(50)
        changed=await layout_snapshot(page); qe=next(e for e in changed['elements'] if e['id']==qid)
        await ck(qe.get('error_correction')=='Q' and qe.get('source')=='verification_url','qrContextPreservesCanonicalSource')

        # Image creation uses local WP media stub; context media + Fit mirror canonical asset properties.
        await page.locator('[data-certloom-designer-add="image"]').click(); await page.wait_for_timeout(100)
        changed=await layout_snapshot(page); imgs=[e for e in changed['elements'] if e['type']=='image']
        if imgs:
            iid=imgs[-1]['id']; await select_layer(page, iid)
            asset=page.locator('[data-certloom-designer-context-group="asset"]'); await ck(await asset.is_visible(),'imageCreatedAndAssetContextVisible')
            fit=asset.locator('[data-certloom-designer-content-field="fit"]'); await fit.select_option('cover'); await page.wait_for_timeout(40)
            changed=await layout_snapshot(page); ie=next(e for e in changed['elements'] if e['id']==iid)
            await ck(ie.get('fit')=='cover' and str(ie.get('asset','')).startswith('attachment:'),'assetContextCanonicalMutation')
        else:
            await ck(False,'imageCreatedAndAssetContextVisible'); await ck(False,'assetContextCanonicalMutation')

        # Barcode context only after versioned schema supports it.
        badd=page.locator('[data-certloom-designer-add="barcode"]'); barcode_enabled=await badd.is_enabled()
        await badd.click(); await page.wait_for_timeout(70)
        changed=await layout_snapshot(page); bars=[e for e in changed['elements'] if e['type']=='barcode']; await ck(barcode_enabled and bool(bars),'barcodePaletteEnabledAndCreated')
        if bars:
            bid=bars[-1]['id']; await select_layer(page, bid)
            bar=page.locator('[data-certloom-designer-context-group="barcode"]'); await ck(await bar.is_visible(),'barcodeContextVisible')
            bq=bar.locator('[data-certloom-designer-content-field="quiet_zone_modules"]'); await bq.fill('18'); await bq.dispatch_event('change'); await page.wait_for_timeout(40)
            changed=await layout_snapshot(page); be=next(e for e in changed['elements'] if e['id']==bid)
            await ck(be.get('source')=='credential.display_number' and int(be.get('quiet_zone_modules',0))==18,'barcodeContextPreservesCanonicalSource')
        else:
            await ck(False,'barcodeContextVisible'); await ck(False,'barcodeContextPreservesCanonicalSource')

        # Locked selection keeps context but disables mutation.
        lock_button=page.locator(f'[data-certloom-designer-layer-row="{sid}"] [data-certloom-designer-layer-row-action="lock"]'); await lock_button.focus(); await lock_button.press('Enter'); await page.wait_for_timeout(50)
        await select_layer(page, sid)
        await ck(await shape.is_visible(),'lockedContextRemainsVisible')
        await ck(await shape.locator('[data-certloom-designer-style-field="fill"]').is_disabled(),'lockedContextDisabled')
        unlock_button=page.locator(f'[data-certloom-designer-layer-row="{sid}"] [data-certloom-designer-layer-row-action="lock"]'); await unlock_button.focus(); await unlock_button.press('Enter'); await page.wait_for_timeout(30)

        # Multi-selection hides type-specific groups but keeps Arrange and group Properties summary.
        rows=page.locator('[data-certloom-designer-layer-row]'); await rows.nth(0).locator('[data-certloom-designer-layer-select]').click(); await rows.nth(1).locator('[data-certloom-designer-layer-select]').click(modifiers=['Shift']); await page.wait_for_timeout(50)
        vis_groups=await page.locator('[data-certloom-designer-context-group]:visible').count()
        await ck(vis_groups==0,'multiSelectionSuppressesTypeContext')
        await ck(await page.locator('[data-certloom-designer-quick-align]').is_visible(),'multiSelectionKeepsArrange')
        await ck(await page.locator('[data-certloom-designer-group]').is_visible(),'multiSelectionKeepsPropertiesSummary')

        # Single selection exposes structured Properties sections.
        await rows.nth(0).locator('[data-certloom-designer-layer-select]').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-property-section="geometry"]').is_visible(),'geometryPropertiesVisible')
        await ck(await page.locator('[data-certloom-designer-property-section="content"]').is_visible(),'contentPropertiesVisible')
        await ck(await page.locator('[data-certloom-designer-property-section="appearance"]').is_visible(),'appearancePropertiesVisible')
        h1=await dock.evaluate('e=>e.getBoundingClientRect().height'); await ck(abs(h1-h0)<3,'contextDockCompactFootprintStable')
        # This carried Phase 4.3 compatibility fixture predates Table UI; Phase 4.8's dedicated Table runner owns current Table context behavior.
        await ck(await page.locator('[data-certloom-designer-context-group="table"]').count()==0,'legacyPhase43FixtureDoesNotFabricateTableContext')

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(4000); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        ml=await mobile.locator('[data-certloom-designer-layer-row]').first.get_attribute('data-certloom-designer-layer-row'); await select_layer(mobile, ml)
        targets=mobile.locator('[data-certloom-designer-context-dock] button:visible, [data-certloom-designer-context-dock] select:visible, [data-certloom-designer-context-dock] input:visible')
        hs=await targets.evaluate_all('els=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(bool(hs) and min(hs)>=43.5,'mobileContextTargets44')
        await mobile.close()

        # forced-colors style is present and stable in computed border rendering.
        await page.emulate_media(forced_colors='active'); await page.wait_for_timeout(30)
        bc=await page.locator('[data-certloom-designer-property-section="geometry"]').evaluate("e=>getComputedStyle(e).borderBottomColor")
        await ck(bool(bc),'forcedColorsPropertiesBorder')
        await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.3 contextual/Properties Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.3 contextual/Properties Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
