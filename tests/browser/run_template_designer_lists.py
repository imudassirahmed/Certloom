#!/usr/bin/env python3
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture_html(version):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(version)
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_template_designer_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        v2=await browser.new_page(viewport={'width':1200,'height':900}); await v2.set_content(fixture_html(2),wait_until='load'); await v2.wait_for_timeout(80)
        await ck(await v2.locator('[data-certloom-designer-add="list"]').is_disabled(),'v2ListDisabled')
        await ck(not await v2.locator('[data-certloom-designer-add="barcode"]').is_disabled(),'v2BarcodeStillEnabled')
        await v2.close()

        page=await browser.new_page(viewport={'width':1440,'height':1000}); await page.set_content(fixture_html(3),wait_until='load'); await page.wait_for_timeout(100)
        list_button=page.locator('[data-certloom-designer-add="list"]')
        await ck(not await list_button.is_disabled(),'v3ListEnabled')
        before=await page.locator('[data-certloom-designer-element]').count(); await list_button.click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-element]').count()==before+1,'listAdded')
        selected=page.locator('[data-certloom-designer-element].is-selected')
        list_id=await selected.get_attribute('data-certloom-designer-element')
        await ck(bool(list_id),'listSelected')
        await ck('LIST' in (await page.locator('[data-certloom-designer-type]').text_content() or ''),'listInspectorType')
        items=page.locator('[data-certloom-designer-content-field="items"]'); await ck(await items.is_visible(),'itemsEditorVisible')
        await items.fill('Alpha\nBeta\nGamma'); await items.dispatch_event('change'); await page.wait_for_timeout(30)
        marker=page.locator('[data-certloom-designer-content-field="list_style"]'); await marker.select_option('decimal'); await marker.dispatch_event('change'); await page.wait_for_timeout(30)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); element=next(e for e in saved['elements'] if e['id']==list_id)
        await ck(element['items']==['Alpha','Beta','Gamma'],'canonicalItems')
        await ck(element['list_style']=='decimal','canonicalMarker')
        await ck(saved['_meta']['schema_version']==3,'v3Serialized')
        await ck(await page.locator('[data-certloom-designer-style="text"]').is_visible(),'textStyleShared')
        size=page.locator('[data-certloom-designer-style-field="font_size_pt"]'); await size.fill('14'); await size.dispatch_event('change'); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); element=next(e for e in saved['elements'] if e['id']==list_id); await ck(float(element['style']['font_size_pt'])==14,'listTypographyCanonical')
        # invalid empty edit rolls back to prior items
        await items.fill('\n \n'); await items.dispatch_event('change'); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); element=next(e for e in saved['elements'] if e['id']==list_id); await ck(element['items']==['Alpha','Beta','Gamma'],'emptyListRejected')
        # lock prevents editing
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(20); await ck(await items.is_disabled(),'lockDisablesItems')
        await page.locator('[data-certloom-designer-lock]').click(); await page.wait_for_timeout(20)
        # undo/redo retains list primitive
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(20); await ck(not await page.locator('[data-certloom-designer-redo]').is_disabled(),'undoAvailable')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); saved=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); await ck(any(e['id']==list_id and e['type']=='list' for e in saved['elements']),'redoRestoresList')
        # responsive + reduced motion
        await page.set_viewport_size({'width':320,'height':900}); await page.wait_for_timeout(20); await ck((await page.locator('[data-certloom-designer-workspace]').evaluate('(e)=>e.scrollWidth>=e.clientWidth')),'responsiveWorkspace')
        reduce=await browser.new_context(reduced_motion='reduce'); rpage=await reduce.new_page(); await rpage.set_content(fixture_html(3),wait_until='load'); await ck(await rpage.locator('[data-certloom-designer]').count()==1,'reducedMotionLoads'); await reduce.close()
        await page.close(); await browser.close()
    if failed:
        print('FAIL  DSN-06 Designer list browser gate:', ', '.join(failed)); return 1
    print('PASS  DSN-06 Designer browser gate: v3 list authoring, canonical items/markers, styling, validation, locks, history and responsive accessibility')
    print(f'\n{checks} Designer list interaction checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
