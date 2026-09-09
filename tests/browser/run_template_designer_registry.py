#!/usr/bin/env python3
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture_html(corrupt=False, schema=3):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(schema); env['CERTLOOM_DESIGNER_REGISTRY_CORRUPT']='1' if corrupt else '0'
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

        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(fixture_html(False),wait_until='load'); await page.wait_for_timeout(150)
        contract=await page.evaluate('window.certloomTemplateElementRegistry')
        await ck(contract.get('contract_version')==1,'contractVersion')
        await ck(len(contract.get('types',{}))==11,'crossSchemaCanonicalTypes')
        await ck(len(contract.get('palette',[]))==14,'crossSchemaPaletteDefinitions')
        dom_kinds=await page.locator('[data-certloom-designer-add]').evaluate_all('(els)=>els.map(e=>e.dataset.certloomDesignerAdd)')
        registry_kinds=[x['kind'] for x in contract['palette']]
        await ck(dom_kinds==registry_kinds,'domPaletteMatchesServerRegistryOrder')
        await ck(await page.locator('[data-certloom-designer-add]:disabled').count()==1 and await page.locator('[data-certloom-designer-add="rich_text"]').is_disabled() and not await page.locator('[data-certloom-designer-add="merge"]').is_disabled(),'schema3KeepsMergeAndFailsClosedRichText')

        # Mutating the public global after Designer boot must not alter its frozen snapshot.
        await page.evaluate('window.certloomTemplateElementRegistry.palette=[]; window.certloomTemplateElementRegistry.types={};')
        before=await page.locator('[data-certloom-designer-element]').count()
        await page.locator('[data-certloom-designer-add="text"]').click(); await page.wait_for_timeout(60)
        after=await page.locator('[data-certloom-designer-element]').count()
        await ck(after==before+1,'frozenSnapshotResistsGlobalMutation')
        await ck(await page.locator('[data-certloom-designer-content="text"]:not([hidden])').count()==1,'textInspectorRegistryDriven')
        await ck(await page.locator('[data-certloom-designer-style="text"]:not([hidden])').count()==1,'textStyleGroupRegistryDriven')

        await page.locator('[data-certloom-designer-add="rectangle"]').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-designer-content="shape"]:not([hidden])').count()==1,'shapeInspectorRegistryDriven')
        await ck(await page.locator('[data-certloom-designer-style="shape"]:not([hidden])').count()==1,'shapeStyleGroupRegistryDriven')

        # Media requirement is registry metadata; production picker converts to local attachment reference.
        await page.locator('[data-certloom-designer-add="image"]').click(); await page.wait_for_timeout(60)
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value()) if False else None
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); await page.wait_for_timeout(20)
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        await ck(any(e.get('type')=='image' and e.get('asset')=='attachment:321' for e in layout['elements']),'registryMediaToolUsesLocalAttachment')
        await ck(await page.locator('[data-certloom-designer-content="asset"]:not([hidden])').count()==1,'assetInspectorRegistryDriven')

        # Semantic aliases stay canonical rather than creating persisted pseudo-types.
        await page.locator('[data-certloom-designer-add="seal"]').click(); await page.wait_for_timeout(40)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        await ck(layout['elements'][-1]['type']=='image','sealAliasPersistsCanonicalImage')
        await page.locator('[data-certloom-designer-add="background"]').click(); await page.wait_for_timeout(40)
        await page.locator('[data-certloom-designer-form]').dispatch_event('submit'); layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value())
        await ck(layout['elements'][-1]['type']=='shape' and layout['elements'][-1].get('shape')=='rectangle','backgroundAliasPersistsCanonicalShape')

        await ck(not await page.locator('[data-certloom-designer]').evaluate('(e)=>e.classList.contains("has-registry-error")'),'validRegistryInitializesDesigner')
        await page.close()

        schema4=await browser.new_page(viewport={'width':1100,'height':760})
        await schema4.set_content(fixture_html(False,4),wait_until='load'); await schema4.wait_for_timeout(100)
        await ck(await schema4.locator('[data-certloom-designer-add]').count()==13 and await schema4.locator('[data-certloom-designer-add="merge"]').count()==0 and not await schema4.locator('[data-certloom-designer-add="rich_text"]').is_disabled(),'schema4NormalPaletteReplacesLegacyMergeWithRichText')
        await schema4.close()

        corrupt=await browser.new_page(viewport={'width':900,'height':700})
        await corrupt.set_content(fixture_html(True),wait_until='load'); await corrupt.wait_for_timeout(120)
        await ck(await corrupt.locator('[data-certloom-designer]').evaluate('(e)=>e.classList.contains("has-registry-error")'),'invalidContractFailsClosed')
        await ck(await corrupt.locator('[data-certloom-designer-error]').is_visible(),'invalidContractErrorVisible')
        await ck(await corrupt.locator('[data-certloom-designer-element]').count()==0,'invalidContractDoesNotRenderEditableElements')
        await corrupt.close()

        reduced=await browser.new_page(viewport={'width':360,'height':760}, reduced_motion='reduce')
        await reduced.set_content(fixture_html(False),wait_until='load'); await reduced.wait_for_timeout(80)
        await ck((await reduced.locator('[data-certloom-designer-workspace]').evaluate('(e)=>getComputedStyle(e).overflowX')) in ('auto','scroll'),'registryDesignerRemainsResponsive')
        await ck(await reduced.locator('[data-certloom-designer-add]').count()==14 and await reduced.locator('[data-certloom-designer-add="rich_text"]').is_disabled(),'registryPaletteSurvivesReducedMotionViewport')
        await reduced.close(); await browser.close()
    if failed:
        print('FAIL  DSN-08 typed Element Registry browser gate:', ', '.join(failed)); return 1
    print('PASS  DSN-08 typed Element Registry browser gate: server projection, frozen browser snapshot, registry-driven palette/inspectors, canonical aliases, media boundary and fail-closed corruption')
    print(f'\n{checks} Designer registry interaction checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
