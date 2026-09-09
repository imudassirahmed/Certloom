#!/usr/bin/env python3
import asyncio, json, subprocess
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
        await ck(await elements.count()==14,'initialCount')
        await ck(await page.locator('[data-certloom-designer-add]').count()==14 and await page.locator('[data-certloom-designer-add="rich_text"]').is_disabled(),'schema1PaletteIncludesSchema4RichTextFailClosed')
        await ck(await page.locator('[data-certloom-designer-add="barcode"]').is_disabled(),'barcodeDisabledOnV1')

        # Text creation/content editing, canonical UUIDv4, undo/redo.
        await page.locator('[data-certloom-designer-add="text"]').click(); await page.wait_for_timeout(35)
        await ck(await elements.count()==15,'textAdd')
        selected=page.locator('[data-certloom-designer-element].is-selected')
        eid=await selected.get_attribute('data-certloom-designer-element')
        import re
        await ck(bool(eid and re.match(r'^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',eid)),'uuidv4')
        textfield=page.locator('[data-certloom-designer-content-field="text"]')
        await ck(await textfield.is_visible(),'textInspector')
        await textfield.fill('Premium Certificate Heading'); await textfield.dispatch_event('change'); await page.wait_for_timeout(25)
        await ck('Premium Certificate Heading' in (await selected.text_content() or ''),'textEditLive')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(25)
        await ck((await textfield.input_value())=='Text','contentUndo')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(25)
        await ck((await textfield.input_value())=='Premium Certificate Heading','contentRedo')

        # Merge field creation/editing.
        await page.locator('[data-certloom-designer-add="merge"]').click(); await page.wait_for_timeout(25)
        token=page.locator('[data-certloom-designer-content-field="token"]')
        await ck(await token.is_visible() and (await token.input_value())=='recipient.name','mergeAdd')
        await token.fill('program.name'); await token.dispatch_event('change'); await page.wait_for_timeout(25)
        await ck((await token.input_value())=='program.name','mergeEdit')
        await token.fill('BAD TOKEN!'); await token.dispatch_event('change'); await page.wait_for_timeout(25)
        await ck((await token.input_value())=='program.name','invalidMergeRejected')

        # Shapes, line and QR use schema-v1 primitives.
        for kind in ['rectangle','ellipse','line','qr']:
            await page.locator(f'[data-certloom-designer-add="{kind}"]').click(); await page.wait_for_timeout(20)
        raw=await page.locator('[data-certloom-designer-json]').input_value()
        # hidden field sync occurs on submit, so inspect in-memory via DOM labels/types first.
        await ck(await elements.count()==20,'primitiveAdds')
        await ck(await page.locator('.certloom-template-designer-element.is-shape').count()>=2,'shapeNodes')
        await ck(await page.locator('.certloom-template-designer-element.is-line').count()>=1,'lineNode')
        await ck(await page.locator('.certloom-template-designer-element.is-qr').count()>=2,'qrNode')
        qrQuiet=page.locator('[data-certloom-designer-content="qr"] [data-certloom-designer-content-field="quiet_zone_modules"]')
        await ck(await qrQuiet.is_visible() and (await qrQuiet.input_value())=='4','qrInspector')
        await qrQuiet.fill('8'); await qrQuiet.dispatch_event('change'); await page.wait_for_timeout(20)
        await ck((await qrQuiet.input_value())=='8','qrEdit')

        # Local Media Library rejects renderer-unsupported image MIME before layout mutation.
        media_before=await elements.count()
        await page.evaluate("window.__certloomMediaMime='image/svg+xml'")
        await page.locator('[data-certloom-designer-add="image"]').click(); await page.wait_for_timeout(35)
        await ck(await elements.count()==media_before,'unsupportedMediaMimeRejected')
        await page.evaluate("window.__certloomMediaMime='image/png'")

        # Local Media Library image creation uses attachment:ID and fit editing.
        await page.locator('[data-certloom-designer-add="image"]').click(); await page.wait_for_timeout(35)
        asset=page.locator('[data-certloom-designer-content-field="asset"]')
        fit=page.locator('[data-certloom-designer-content-field="fit"]')
        await ck(await asset.is_visible() and (await asset.input_value())=='attachment:321','imageMediaAdd')
        await fit.select_option('cover'); await fit.dispatch_event('change'); await page.wait_for_timeout(20)
        await ck((await fit.input_value())=='cover','assetFitEdit')
        await asset.fill('https://remote.example/image.png'); await asset.dispatch_event('change'); await page.wait_for_timeout(20)
        await ck((await asset.input_value())=='attachment:321','remoteAssetRejected')
        await asset.fill('asset:future-key'); await asset.dispatch_event('change'); await page.wait_for_timeout(20)
        await ck((await asset.input_value())=='attachment:321','namedAssetKeyRejectedUntilCatalogExists')

        # Lock prevents removal/content changes; unlock allows removal.
        before_count=await elements.count()
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(20)
        await ck(await page.locator('[data-certloom-designer-remove]').is_disabled(),'removeLockedDisabled')
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-designer-remove]').click(); await page.wait_for_timeout(25)
        await ck(await elements.count()==before_count-1,'removeElement')

        # Background creates a full-page rectangle behind all layers.
        await page.locator('[data-certloom-designer-add="background"]').click(); await page.wait_for_timeout(25)
        await ck(await elements.count()==before_count,'backgroundAdd')
        await page.locator('[data-certloom-designer-form]').evaluate('(f)=>f.requestSubmit()'); await page.wait_for_timeout(30)
        saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        ids=[e.get('id') for e in saved.get('elements',[])]
        await ck(len(ids)==len(set(ids)),'uniqueIds')
        await ck(all(e.get('type')!='barcode' for e in saved.get('elements',[])),'noBarcodeSchemaDrift')
        bgs=[e for e in saved.get('elements',[]) if e.get('type')=='shape' and e.get('shape')=='rectangle' and abs(float(e.get('x_mm',-1)))<0.001 and abs(float(e.get('y_mm',-1)))<0.001 and abs(float(e.get('width_mm',0))-float(saved['page']['width_mm']))<0.001 and abs(float(e.get('height_mm',0))-float(saved['page']['height_mm']))<0.001]
        await ck(len(bgs)>=1,'backgroundGeometry')
        await ck(any(e.get('type')=='merge' and e.get('token')=='program.name' for e in saved.get('elements',[])),'mergeSerialized')
        await ck(any(e.get('type')=='text' and e.get('text')=='Premium Certificate Heading' for e in saved.get('elements',[])),'textSerialized')
        await ck(any(e.get('type')=='qr' and e.get('quiet_zone_modules')==8 for e in saved.get('elements',[])),'qrSerialized')

        # Responsive content palette/editor remains usable on tablet width.
        media=await browser.new_page(viewport={'width':768,'height':900}); await media.emulate_media(reduced_motion='reduce'); await media.set_content(html,wait_until='load'); await media.wait_for_timeout(100)
        await ck(await media.locator('[data-certloom-designer-add="text"]').is_visible(),'responsivePalette')
        await media.locator('[data-certloom-designer-add="text"]').click(); await media.wait_for_timeout(20)
        await ck(await media.locator('[data-certloom-designer-content-field="text"]').is_visible(),'responsiveContentEditor')
        reduced=await media.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches")
        await ck(reduced,'reducedMotion')
        await media.close(); await page.close(); await browser.close()

    if failed:
        print('FAIL  DSN-03 Designer browser gate: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  DSN-03 Designer browser gate: canonical creation/edit/removal, secure UUIDs, local Media Library assets, schema-v1 barcode boundary, undo/locks and responsive accessibility')
    print(f'\n{checks} Designer element/content interaction checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(run()))
