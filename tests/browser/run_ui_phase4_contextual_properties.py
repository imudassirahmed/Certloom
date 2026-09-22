#!/usr/bin/env python3
"""Chromium acceptance for the current Phase 4.3 right-Inspector contract."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_contextual_properties_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='3'
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def snapshot(page):
    await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]');f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}""")
    await page.wait_for_timeout(35)
    return json.loads(await page.locator('[data-certloom-designer-json]').input_value())

async def select_layer(page, element_id):
    button=page.locator(f'[data-certloom-designer-layer-select="{element_id}"]')
    await button.focus(); await button.press('Enter')
    await page.wait_for_timeout(55)

async def open_section(page, key):
    section=page.locator(f'[data-certloom-designer-property-section="{key}"]')
    if 'is-open' not in ((await section.get_attribute('class')) or ''):
        await section.locator('.certloom-studio-accordion-trigger').click()
        await page.wait_for_timeout(30)
    return section

async def open_insert(page):
    drawer=page.locator('[data-certloom-designer-insert-panel]')
    if await drawer.is_hidden():
        await page.locator('[data-certloom-designer-insert-focus]').click()
        await page.wait_for_timeout(30)

async def main():
    html=fixture_html(); failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks; checks+=1
        if not v: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':950}); page.set_default_timeout(5000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(240)
        inspector=page.locator('.certloom-template-designer-inspector')
        await ck(await inspector.is_visible(),'currentRightInspectorVisible')
        await ck(await page.locator('[data-certloom-designer-context-dock]').count()==0,'obsoleteContextDockAbsent')
        await ck(await page.locator('[data-certloom-designer-empty]').is_visible(),'emptyInspectorStartsWithSelectionHint')
        await ck(await page.locator('[data-certloom-designer-property-section]').count()==7,'sevenCurrentInspectorSectionsPresent')

        layout=await snapshot(page); bytype={}
        for e in layout['elements']: bytype.setdefault(e['type'],e['id'])

        # Text: Content opens by default; Geometry and Appearance mutate the same canonical element.
        tid=bytype['text']; await select_layer(page,tid)
        await ck((await page.locator('[data-certloom-designer-type]').inner_text()).strip()=='TEXT','textSelectionIdentifiedInInspector')
        await ck(await page.locator('[data-certloom-designer-content="text"]').is_visible(),'textContentGroupVisible')
        await ck('is-open' in ((await page.locator('[data-certloom-designer-property-section="content"]').get_attribute('class')) or ''),'textDefaultsToContentSection')
        await open_section(page,'geometry')
        x=page.locator('[data-certloom-designer-field="x"]'); oldx=float(await x.input_value()); await x.fill(str(oldx+1)); await x.dispatch_event('change'); await page.wait_for_timeout(45)
        changed=await snapshot(page); te=next(e for e in changed['elements'] if e['id']==tid)
        await ck(abs(float(te['x_mm'])-(oldx+1))<.01,'geometryInspectorUsesCanonicalMutationPath')
        await open_section(page,'appearance')
        size=page.locator('[data-certloom-designer-style="text"] [data-certloom-designer-style-field="font_size_pt"]'); oldsize=float(await size.input_value()); await size.fill(str(oldsize+1)); await size.dispatch_event('change'); await page.wait_for_timeout(45)
        changed=await snapshot(page); te=next(e for e in changed['elements'] if e['id']==tid)
        await ck(abs(float(te['style']['font_size_pt'])-(oldsize+1))<.01,'appearanceInspectorUsesCanonicalTextStylePath')

        # Legacy schema-3 merge remains readable/editable without restoring the removed quick context bar.
        mid=bytype['merge']; await select_layer(page,mid)
        merge=page.locator('[data-certloom-designer-content="merge"]'); token=merge.locator('[data-certloom-designer-content-field="token"]')
        await ck(await merge.is_visible() and await token.is_enabled(),'mergeContentAvailableInInspector')
        await token.fill('credential.status'); await token.dispatch_event('change'); await page.wait_for_timeout(45)
        changed=await snapshot(page); me=next(e for e in changed['elements'] if e['id']==mid)
        await ck(me.get('token')=='credential.status','mergeTokenUsesCanonicalContentPath')

        # Shape and line choose Appearance automatically and preserve exact controlled styles.
        sid=bytype['shape']; await select_layer(page,sid)
        shape=page.locator('[data-certloom-designer-style="shape"]')
        await ck(await shape.is_visible() and 'is-open' in ((await page.locator('[data-certloom-designer-property-section="appearance"]').get_attribute('class')) or ''),'shapeOpensAppearanceInspector')
        fill=shape.locator('[data-certloom-designer-style-field="fill"]'); await fill.fill('#123456'); await fill.dispatch_event('change'); await page.wait_for_timeout(45)
        changed=await snapshot(page); se=next(e for e in changed['elements'] if e['id']==sid)
        await ck(se.get('fill')=='#123456','shapeFillCanonicalMutation')

        lid=bytype['line']; await select_layer(page,lid); line=page.locator('[data-certloom-designer-style="line"]')
        await ck(await line.is_visible(),'lineAppearanceInspectorVisible')
        sw=line.locator('[data-certloom-designer-style-field="stroke_width_mm"]'); await sw.fill('1.2'); await sw.dispatch_event('change'); await page.wait_for_timeout(45)
        changed=await snapshot(page); le=next(e for e in changed['elements'] if e['id']==lid)
        await ck(abs(float(le.get('stroke_width_mm',0))-1.2)<.01,'lineStrokeCanonicalMutation')

        # QR content remains bound to immutable verification source while authoring error correction.
        qid=bytype['qr']; await select_layer(page,qid); qr=page.locator('[data-certloom-designer-content="qr"]')
        await ck(await qr.is_visible(),'qrContentInspectorVisible')
        ec=qr.locator('[data-certloom-designer-content-field="error_correction"]'); await ec.select_option('Q'); await page.wait_for_timeout(45)
        changed=await snapshot(page); qe=next(e for e in changed['elements'] if e['id']==qid)
        await ck(qe.get('error_correction')=='Q' and qe.get('source')=='verification_url','qrInspectorPreservesCanonicalSource')

        # Current Insert drawer -> Media Library -> current asset Inspector.
        await open_insert(page); await page.locator('[data-certloom-designer-add="image"]').click(); await page.wait_for_timeout(90)
        changed=await snapshot(page); imgs=[e for e in changed['elements'] if e['type']=='image']
        await ck(bool(imgs) and imgs[-1].get('asset')=='attachment:321','imageInsertUsesLocalMediaIdentity')
        if imgs:
            iid=imgs[-1]['id']; asset=page.locator('[data-certloom-designer-content="asset"]')
            await ck(await asset.is_visible(),'assetInspectorVisibleForNewImage')
            await ck(await asset.locator('[data-certloom-designer-issuer-media-source]').count()==2,'issuerLogoSealSourcesOfferedInCurrentInspector')
            fit=asset.locator('[data-certloom-designer-content-field="fit"]'); await fit.select_option('cover'); await page.wait_for_timeout(40)
            changed=await snapshot(page); ie=next(e for e in changed['elements'] if e['id']==iid)
            await ck(ie.get('fit')=='cover' and ie.get('asset')=='attachment:321','assetFitMutationPreservesLocalAsset')
        else:
            await ck(False,'assetInspectorVisibleForNewImage'); await ck(False,'issuerLogoSealSourcesOfferedInCurrentInspector'); await ck(False,'assetFitMutationPreservesLocalAsset')

        # Barcode remains schema-3 authorable and keeps its immutable display-number source.
        await open_insert(page); badd=page.locator('[data-certloom-designer-add="barcode"]'); await ck(not await badd.is_disabled(),'barcodeInsertAvailableOnSchema3'); await badd.click(); await page.wait_for_timeout(70)
        changed=await snapshot(page); bars=[e for e in changed['elements'] if e['type']=='barcode']
        await ck(bool(bars),'barcodeCreatedFromCurrentInsertDrawer')
        if bars:
            bid=bars[-1]['id']; bar=page.locator('[data-certloom-designer-content="barcode"]'); await ck(await bar.is_visible(),'barcodeContentInspectorVisible')
            quiet=bar.locator('[data-certloom-designer-content-field="quiet_zone_modules"]'); await quiet.fill('18'); await quiet.dispatch_event('change'); await page.wait_for_timeout(40)
            changed=await snapshot(page); be=next(e for e in changed['elements'] if e['id']==bid)
            await ck(be.get('source')=='credential.display_number' and int(be.get('quiet_zone_modules',0))==18,'barcodeInspectorPreservesCanonicalSource')
        else:
            await ck(False,'barcodeContentInspectorVisible'); await ck(False,'barcodeInspectorPreservesCanonicalSource')

        # Locked elements remain inspectable but mutation controls are disabled.
        await select_layer(page,sid); lock=page.locator(f'[data-certloom-designer-layer-row="{sid}"] [data-certloom-designer-layer-row-action="lock"]'); await lock.click(); await page.wait_for_timeout(55)
        await ck(await page.locator('[data-certloom-designer-fields]').is_visible(),'lockedElementStillInspectable')
        await ck(await page.locator('[data-certloom-designer-style="shape"] [data-certloom-designer-style-field="fill"]').is_disabled(),'lockedInspectorMutationDisabled')
        await lock.click(); await page.wait_for_timeout(35)

        # Multi-selection switches from single-element fields to the group summary; Arrange remains an available section.
        rows=page.locator('[data-certloom-designer-layer-row]'); await rows.nth(0).locator('[data-certloom-designer-layer-select]').click(); await rows.nth(1).locator('[data-certloom-designer-layer-select]').click(modifiers=['Shift']); await page.wait_for_timeout(55)
        await ck(await page.locator('[data-certloom-designer-group]').is_visible() and await page.locator('[data-certloom-designer-fields]').is_hidden(),'multiSelectionUsesGroupInspector')
        await ck((await page.locator('[data-certloom-designer-group-count]').inner_text()).strip()=='2','multiSelectionCountIsExplicit')
        await ck(await page.locator('[data-certloom-designer-property-section="arrange"] .certloom-studio-accordion-trigger').count()==1,'arrangeSectionRetainedForCurrentInspector')

        # Mobile Inspector controls meet touch targets; forced-colors keeps section boundaries perceivable.
        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        ml=await mobile.locator('[data-certloom-designer-layer-row]').first.get_attribute('data-certloom-designer-layer-row'); await select_layer(mobile,ml)
        heights=await mobile.locator('.certloom-template-designer-inspector button:visible,.certloom-template-designer-inspector input:not([type="hidden"]):visible,.certloom-template-designer-inspector select:visible').evaluate_all('els=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(bool(heights) and min(heights)>=43.5,'mobileInspectorTargetsMeet44pxContract')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'mobileInspectorDoesNotCreateHorizontalOverflow')
        await mobile.close()

        await page.emulate_media(forced_colors='active'); await page.wait_for_timeout(30)
        border=await page.locator('[data-certloom-designer-property-section="appearance"]').evaluate("e=>getComputedStyle(e).borderBottomStyle")
        await ck(border!='none','forcedColorsKeepsInspectorSectionBoundary')
        await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.3 current Inspector Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.3 current Inspector Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
