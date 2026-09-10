#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.4 Insert palette + synthetic sample-data selector."""
import asyncio, os, subprocess, json
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_insert_sample_data_fixture.php'

def fixture_html(schema=3):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(schema)
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def main():
    failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':950})
        page.set_default_timeout(5000)
        await page.set_content(fixture_html(3),wait_until='load'); await page.wait_for_timeout(220)
        sample=page.locator('[data-certloom-designer-sample-data]')
        await ck(await sample.is_visible(),'sampleSelectorVisible')
        options=await sample.locator('option').evaluate_all('(els)=>els.map(e=>({v:e.value,t:e.textContent.trim()}))')
        expected=['standard','long','very-long-name','rtl','organization','long-organization','long-iso-scope','school-result']
        await ck([o['v'] for o in options]==expected,'eightSampleProfileIds')
        await ck(options[0]['t']=='Normal person' and options[-1]['t']=='School result','sampleProfileLabels')
        await ck(await page.locator('#certloom-designer-sample-data-note').inner_text()=='Preview only','samplePreviewOnlyLabel')

        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        merge_by_token={e.get('token'):e['id'] for e in layout['elements'] if e.get('type')=='merge'}
        rid=merge_by_token.get('recipient.name')
        pid=merge_by_token.get('program.name')
        await ck(bool(rid),'recipientMergeExists')
        if rid:
            rnode=page.locator(f'[data-certloom-designer-element="{rid}"]')
            await ck('Alexandria Noor Rahman' in await rnode.inner_text(),'normalPersonAppliedLive')
            before_layout=await page.locator('[data-certloom-designer-json]').input_value(); before_state=await page.locator('[data-certloom-designer-state-json]').input_value()
            await sample.select_option('very-long-name'); await page.wait_for_timeout(70)
            await ck('Al-Hassan' in await rnode.inner_text(),'veryLongNameAppliedLive')
            await sample.select_option('rtl'); await page.wait_for_timeout(70)
            await ck('محمد' in await rnode.inner_text(),'rtlPersonAppliedLive')
            await sample.select_option('organization'); await page.wait_for_timeout(70)
            await ck('Apex Manufacturing' in await rnode.inner_text(),'organizationAppliedLive')
            await ck(await page.locator('[data-certloom-designer-json]').input_value()==before_layout,'sampleDoesNotMutateLayoutField')
            await ck(await page.locator('[data-certloom-designer-state-json]').input_value()==before_state,'sampleDoesNotMutateAuthoringState')
            await ck('Saved draft' in await page.locator('[data-certloom-designer-dirty]').inner_text(),'sampleDoesNotDirtyDesign')
            await ck(await page.locator('[data-certloom-designer-undo]').is_disabled(),'sampleDoesNotCreateUndoHistory')
        if pid:
            pnode=page.locator(f'[data-certloom-designer-element="{pid}"]')
            await sample.select_option('long-iso-scope'); await page.wait_for_timeout(70)
            await ck('ISO/IEC 27001:2022' in await pnode.inner_text(),'longIsoScopeAppliedLive')
        else:
            await ck(False,'longIsoScopeAppliedLive')

        palette=page.locator('[data-certloom-designer-palette-item]')
        await ck(await palette.count()==16 and not await page.locator('[data-certloom-designer-add="merge"]').is_disabled() and await page.locator('[data-certloom-designer-add="rich_text"]').is_disabled() and await page.locator('[data-certloom-designer-add="table"]').is_disabled() and await page.locator('[data-certloom-designer-add="data_table"]').is_disabled(),'schema3PaletteKeepsLegacyMergeAndFailsClosedLaterSchemaTypes')
        await ck(await page.locator('[data-certloom-designer-add="table"]').count()==1 and await page.locator('[data-certloom-designer-add="table"]').is_disabled() and await page.locator('[data-certloom-designer-add="data_table"]').count()==1 and await page.locator('[data-certloom-designer-add="data_table"]').is_disabled(),'tableRegistryPresentButSchema3FailClosed')
        draggable=await palette.evaluate_all("els=>els.filter(e=>!e.disabled).every(e=>e.getAttribute('draggable')==='true')")
        await ck(draggable,'allEnabledPrimitivesDraggable')
        categories=set(await palette.evaluate_all("els=>els.map(e=>e.dataset.certloomDesignerPaletteCategory)"))
        await ck(categories=={'content','media','graphics'},'boundedInsertCategories')

        initial_count=await page.locator('[data-certloom-designer-element]').count()
        await page.locator('[data-certloom-designer-add="text"]').click(); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-element]').count()==initial_count+1,'textInsertsImmediately')
        await ck(await page.locator('dialog:visible').count()==0,'textInsertHasNoPreconfigurationDialog')
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='TEXT','textSelectedAfterInsert')

        canvas=page.locator('[data-certloom-designer-page]'); box=await canvas.bounding_box()
        await page.locator('[data-certloom-designer-add="ellipse"]').drag_to(canvas,target_position={'x':box['width']*0.73,'y':box['height']*0.30}); await page.wait_for_timeout(90)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='SHAPE','advancedEllipseDragCreatesCanonicalShape')
        await ck(await page.locator('[data-certloom-designer-content-field="shape"]').input_value()=='ellipse','ellipseDefaultPreserved')

        await page.locator('[data-certloom-designer-add="signature"]').click(); await page.wait_for_timeout(80)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='SIGNATURE','signatureInsertsThroughMediaPicker')
        await ck('attachment:321' in await page.locator('[data-certloom-designer-content-field="asset"]').input_value(),'signatureUsesLocalAttachmentIdentity')

        await page.locator('[data-certloom-designer-add="barcode"]').click(); await page.wait_for_timeout(60)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='BARCODE','barcodeInsertsOnSchema3')
        await page.locator('[data-certloom-designer-add="list"]').click(); await page.wait_for_timeout(60)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='LIST','listInsertsOnSchema3')
        await page.locator('[data-certloom-designer-add="background"]').click(); await page.wait_for_timeout(60)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='SHAPE','backgroundUsesCanonicalShapePrimitive')
        await ck(float(await page.locator('[data-certloom-designer-field="x"]').input_value())==0.0 and float(await page.locator('[data-certloom-designer-field="y"]').input_value())==0.0,'backgroundStartsAtPageOrigin')

        v1=await browser.new_page(viewport={'width':1280,'height':850}); v1.set_default_timeout(5000); await v1.set_content(fixture_html(1),wait_until='load'); await v1.wait_for_timeout(160)
        await ck(await v1.locator('[data-certloom-designer-add="barcode"]').is_disabled(),'schema1BarcodeStillDisabled')
        await ck(await v1.locator('[data-certloom-designer-add="list"]').is_disabled(),'schema1ListStillDisabled')
        await ck(await v1.locator('[data-certloom-designer-add="text"]').get_attribute('draggable')=='true','schema1SupportedPrimitiveDraggable')
        await v1.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(fixture_html(3),wait_until='load'); await mobile.wait_for_timeout(180)
        sh=await mobile.locator('[data-certloom-designer-sample-data]').evaluate('e=>e.getBoundingClientRect().height')
        ph=await mobile.locator('[data-certloom-designer-palette-item]').first.evaluate('e=>e.getBoundingClientRect().height')
        await ck(sh>=43.5,'mobileSampleTarget44')
        await ck(ph>=43.5,'mobileInsertTarget44')
        await mobile.close()

        await page.emulate_media(forced_colors='active'); await page.wait_for_timeout(30)
        border=await sample.evaluate('e=>getComputedStyle(e).borderColor')
        await ck(bool(border),'forcedColorsSampleControl')
        await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.4 Insert/sample-data Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.4 Insert/sample-data Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
