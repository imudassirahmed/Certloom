#!/usr/bin/env python3
import asyncio, json, os, re, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture_html(version):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(version)
    run=subprocess.run(['php',str(ROOT/'tests/browser/build_template_designer_fixture.php')],capture_output=True,text=True,cwd=ROOT,env=env)
    if run.returncode: raise RuntimeError(run.stderr or run.stdout)
    return run.stdout

async def run():
    failed=[]; checks=0
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        # v1 remains immutable and barcode cannot be authored.
        v1=await browser.new_page(viewport={'width':1200,'height':900}); await v1.set_content(fixture_html(1),wait_until='load'); await v1.wait_for_timeout(100)
        b1=v1.locator('[data-certloom-designer-add="barcode"]')
        await ck(await b1.is_disabled(),'v1BarcodeDisabled')
        await ck('layout v1' in (await v1.locator('[data-certloom-designer-page-readout]').text_content() or ''),'v1Readout')
        saved1=json.loads(await v1.locator('[data-certloom-designer-json]').input_value()); await ck(saved1['_meta']['schema_version']==1,'v1SerializedMeta')
        await v1.close()

        # v2 enables the controlled Code 128 primitive.
        page=await browser.new_page(viewport={'width':1440,'height':1000}); await page.set_content(fixture_html(2),wait_until='load'); await page.wait_for_timeout(120)
        barcode=page.locator('[data-certloom-designer-add="barcode"]')
        await ck(not await barcode.is_disabled(),'v2BarcodeEnabled')
        await ck('layout v2' in (await page.locator('[data-certloom-designer-page-readout]').text_content() or ''),'v2Readout')
        before=await page.locator('[data-certloom-designer-element]').count(); await barcode.click(); await page.wait_for_timeout(35)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before+1,'barcodeAdded')
        selected=page.locator('[data-certloom-designer-element].is-selected')
        await ck('is-barcode' in (await selected.get_attribute('class') or ''),'barcodeCanvasType')
        eid=await selected.get_attribute('data-certloom-designer-element'); await ck(bool(eid and re.match(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',eid)),'barcodeUuid')
        section=page.locator('[data-certloom-designer-content="barcode"]'); await ck(await section.is_visible(),'barcodeInspectorVisible')
        readonly=section.locator('input[readonly]'); await ck(await readonly.count()==2,'sourceSymbologyReadOnly')
        values=[await readonly.nth(i).input_value() for i in range(await readonly.count())]
        await ck('Code 128' in values and 'credential.display_number' in values,'sourceSymbologyFixed')
        quiet=section.locator('[data-certloom-designer-content-field="quiet_zone_modules"]'); await ck(await quiet.input_value()=='10','barcodeQuietDefault')
        await quiet.fill('20'); await quiet.dispatch_event('change'); await page.wait_for_timeout(20); await ck(await quiet.input_value()=='20','barcodeQuietEdit')
        await quiet.fill('9'); await quiet.dispatch_event('change'); await page.wait_for_timeout(20); await ck(await quiet.input_value()=='20','barcodeQuietLowerBound')
        await quiet.fill('51'); await quiet.dispatch_event('change'); await page.wait_for_timeout(20); await ck(await quiet.input_value()=='20','barcodeQuietUpperBound')
        # Geometry defaults are usable and can be rotated while staying canonical.
        width=page.locator('[data-certloom-designer-field="width"]'); height=page.locator('[data-certloom-designer-field="height"]'); rotation=page.locator('[data-certloom-designer-field="rotation"]')
        await ck(abs(float(await width.input_value())-110)<.01 and abs(float(await height.input_value())-18)<.01,'barcodeGeometryDefault')
        await rotation.fill('5'); await rotation.dispatch_event('change'); await page.wait_for_timeout(20); await ck(abs(float(await rotation.input_value())-5)<.01,'barcodeRotation')
        # Undo/redo carries barcode creation/content changes as normal canonical Designer history.
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(20); await ck(abs(float(await rotation.input_value()))<.01,'rotationUndo')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(20); await ck(abs(float(await rotation.input_value())-5)<.01,'rotationRedo')
        # Locks keep schema-v2 barcode selectable but immutable for the current author.
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(15); await ck(await quiet.is_disabled(),'barcodeLockedContent')
        await ck(await page.locator('[data-certloom-designer-remove]').is_disabled(),'barcodeLockedRemove')
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(15); await ck(not await quiet.is_disabled(),'barcodeUnlocked')
        # Save serializes exact v2 semantics, never a public-id or arbitrary symbology.
        await page.locator('[data-certloom-designer-form]').evaluate('(f)=>f.requestSubmit()'); await page.wait_for_timeout(30)
        saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); bars=[e for e in saved['elements'] if e.get('type')=='barcode']
        await ck(saved['_meta']['schema_version']==2,'v2MetaSerialized')
        await ck(len(bars)==1,'oneBarcodeSerialized')
        await ck(bars[0].get('source')=='credential.display_number','barcodeSourceSerialized')
        await ck(bars[0].get('symbology')=='code128','barcodeSymbologySerialized')
        await ck(bars[0].get('quiet_zone_modules')==20,'barcodeQuietSerialized')
        await ck(abs(float(bars[0].get('rotation_deg',0))-5)<.01,'barcodeRotationSerialized')
        # Removal participates in normal undo path.
        await page.locator('[data-certloom-designer-remove]').click(); await page.wait_for_timeout(20); await ck(await page.locator('[data-certloom-designer-element="'+eid+'"]').count()==0,'barcodeRemoved')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(20); await ck(await page.locator('[data-certloom-designer-element="'+eid+'"]').count()==1,'barcodeRemoveUndo')
        # Responsive/reduced-motion v2 authoring remains available.
        media=await browser.new_page(viewport={'width':480,'height':900}); await media.emulate_media(reduced_motion='reduce'); await media.set_content(fixture_html(2),wait_until='load'); await media.wait_for_timeout(90)
        await ck(await media.locator('[data-certloom-designer-add="barcode"]').is_visible(),'responsiveBarcodePalette')
        await ck(not await media.locator('[data-certloom-designer-add="barcode"]').is_disabled(),'responsiveBarcodeEnabled')
        await ck(await media.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion')
        await media.locator('[data-certloom-designer-add="barcode"]').click(); await media.wait_for_timeout(20); await ck(await media.locator('[data-certloom-designer-content="barcode"]').is_visible(),'responsiveBarcodeInspector')
        await media.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  DSN-05 Designer schema browser gate: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  DSN-05 Designer schema browser gate: v1/v2 boundary, controlled Code 128 authoring, locks, history, serialization and responsive accessibility')
    print(f'\n{checks} Designer schema interaction checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(run()))
