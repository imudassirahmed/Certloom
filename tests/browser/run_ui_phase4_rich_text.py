#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.6 Schema 4 rich inline text/token runs."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
RICH=ROOT/'tests'/'browser'/'build_ui_phase4_rich_text_fixture.php'
STICKY=ROOT/'tests'/'browser'/'build_ui_phase4_sticky_workspace_fixture.php'

def fixture(builder, schema=None):
    env=os.environ.copy()
    if schema is not None: env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(schema)
    p=subprocess.run(['php',str(builder)],cwd=ROOT,text=True,capture_output=True,env=env)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def submit_snapshot(page):
    await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]');f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}""")
    await page.wait_for_timeout(40)
    return json.loads(await page.locator('[data-certloom-designer-json]').input_value())

async def main():
    failed=[]; checks=0
    async def ck(v,name):
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    rich_html=fixture(RICH)
    schema3_html=fixture(STICKY,3)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':900}); page.set_default_timeout(5000)
        await page.set_content(rich_html,wait_until='load'); await page.wait_for_timeout(220)
        rich_add=page.locator('[data-certloom-designer-add="rich_text"]')
        await ck(await rich_add.count()==1 and not await rich_add.is_disabled(),'schema4RichInsertVisibleEnabled')
        await ck(await page.locator('[data-certloom-designer-add="merge"]').count()==0,'schema4LegacyMergeHiddenFromNormalInsert')
        await ck(await page.locator('[data-certloom-designer-add]').count()==13,'schema4PaletteReplacesMergeWithoutGrowingNormalPalette')
        await rich_add.click(); await page.wait_for_timeout(100)
        selected=page.locator('[data-certloom-designer-element][aria-pressed="true"]')
        await ck(await selected.count()==1,'richInsertSelectsNewElement')
        rid=await selected.get_attribute('data-certloom-designer-element')
        await ck(bool(rid),'richInsertHasCanonicalSecureId')
        await ck('RICH TEXT' in ((await page.locator('[data-certloom-designer-type]').inner_text()) or ''),'richInspectorTypeVisible')
        runs=page.locator('[data-certloom-designer-rich-run]')
        await ck(await runs.count()==2,'richDefaultHasTextAndTokenRuns')
        kinds=await page.locator('[data-certloom-designer-rich-value]').evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-rich-value'))")
        await ck(kinds==['text','token'],'richDefaultRunOrderStructured')
        await ck((await page.locator('[data-certloom-designer-rich-value="text"]').first.input_value())=='This certifies that ','richDefaultLiteralLocalized')
        await ck((await page.locator('[data-certloom-designer-rich-value="token"]').first.input_value())=='recipient.name','richDefaultTokenMachineKey')
        token_preview=page.locator(f'[data-certloom-designer-element="{rid}"] [data-certloom-designer-rich-token="recipient.name"]')
        await ck(await token_preview.count()==1 and 'Alexandria' in ((await token_preview.inner_text()) or ''),'richTokenUsesSyntheticSampleOnCanvas')
        await ck(await page.locator('[data-certloom-designer-context-rich-text]').is_visible(),'richContextEditControlVisible')

        # Caret workflow: split literal at caret and insert a structured field run.
        first_text=page.locator('[data-certloom-designer-rich-value="text"]').first
        await first_text.evaluate("e=>{e.focus();e.setSelectionRange(5,5)}")
        await page.locator('[data-certloom-designer-rich-insert-field]').first.click(); await page.wait_for_timeout(90)
        await ck(await runs.count()==4,'caretInsertionSplitsTextWithoutFlattening')
        kinds2=await page.locator('[data-certloom-designer-rich-value]').evaluate_all("els=>els.map(e=>e.getAttribute('data-certloom-designer-rich-value'))")
        await ck(kinds2==['text','token','text','token'],'caretInsertionPreservesOrderedRunKinds')
        values2=await page.locator('[data-certloom-designer-rich-value]').evaluate_all("els=>els.map(e=>e.value)")
        await ck(values2[0]=='This ' and values2[2]=='certifies that ','caretSplitPreservesLiteralTextExactly')
        focused=await page.evaluate("document.activeElement && document.activeElement.getAttribute('data-certloom-designer-rich-value')")
        await ck(focused=='token','caretInsertionFocusesNewFieldChooser')
        inserted=page.locator('[data-certloom-designer-rich-value="token"]').first
        await inserted.fill('program.name'); await inserted.evaluate("e=>e.dispatchEvent(new Event('change',{bubbles:true}))"); await page.wait_for_timeout(90)
        await ck(await page.locator(f'[data-certloom-designer-element="{rid}"] [data-certloom-designer-rich-token="program.name"]').count()==1,'fieldChangeUpdatesCanonicalTokenPreview')
        await ck('Professional Credential Program' in ((await selected.inner_text()) or ''),'fieldChangeUsesActiveSampleValue')

        # Add, style, reorder/delete, undo.
        await page.locator('[data-certloom-designer-rich-add="text"]').click(); await page.wait_for_timeout(70)
        await ck(await runs.count()==5,'addTextRunUsesStructuredEditor')
        last_text=page.locator('[data-certloom-designer-rich-value="text"]').last
        await last_text.fill(' — honors'); await last_text.evaluate("e=>e.dispatchEvent(new Event('change',{bubbles:true}))"); await page.wait_for_timeout(70)
        row1=page.locator('[data-certloom-designer-rich-run="1"]'); await row1.locator('summary').click();
        weight=row1.locator('[data-certloom-designer-rich-style="font_weight"]'); await weight.select_option('700'); await page.wait_for_timeout(70)
        await ck('700'==await weight.input_value(),'inlineWeightOverrideEditable')
        await row1.locator('[data-certloom-designer-rich-action="down"]').click(); await page.wait_for_timeout(70)
        await ck((await page.locator('[data-certloom-designer-rich-value]').nth(2).get_attribute('data-certloom-designer-rich-value'))=='token','runReorderPreservesStructuredToken')
        last_row=page.locator('[data-certloom-designer-rich-run]').last; await last_row.locator('[data-certloom-designer-rich-action="delete"]').click(); await page.wait_for_timeout(70)
        await ck(await runs.count()==4,'runDeleteRemovesOnlyChosenRun')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(80)
        await ck(await runs.count()==5,'richRunMutationParticipatesInUndoHistory')

        saved=await submit_snapshot(page); rich_el=next(e for e in saved['elements'] if e['id']==rid)
        await ck(rich_el['type']=='rich_text' and isinstance(rich_el.get('runs'),list),'saveSerializesStructuredRichRuns')
        await ck(all(r.get('kind') in ('text','token') for r in rich_el['runs']),'saveContainsOnlyBoundedRunKinds')
        await ck('rich_preset' not in rich_el and 'html' not in rich_el,'saveContainsNoPresetOrHtmlAuthority')
        await ck(any(r.get('token')=='program.name' for r in rich_el['runs']),'savePreservesMachineTokenNotSampleText')
        serialized_before=json.dumps(saved,sort_keys=True,separators=(',',':'))
        sample=page.locator('[data-certloom-designer-sample-data]'); await sample.select_option('rtl'); await page.wait_for_timeout(70)
        saved_after=await submit_snapshot(page); await ck(json.dumps(saved_after,sort_keys=True,separators=(',',':'))==serialized_before,'sampleProfileDoesNotMutateRichLayout')

        # Lock safety in the real Layers authority.
        lock=page.locator(f'[data-certloom-designer-layer-row="{rid}"] [data-certloom-designer-layer-row-action="lock"]'); await lock.click(); await page.wait_for_timeout(70)
        await ck(await page.locator('[data-certloom-designer-rich-value]').first.is_disabled(),'lockedRichRunsAreReadOnly')
        await ck(await page.locator('[data-certloom-designer-context-rich-text]').is_disabled(),'lockedRichContextActionDisabled')
        unlock=page.locator(f'[data-certloom-designer-layer-row="{rid}"] [data-certloom-designer-layer-row-action="lock"]'); await unlock.click(); await page.wait_for_timeout(50)
        await ck(not await page.locator('[data-certloom-designer-rich-value]').first.is_disabled(),'unlockRestoresRichEditing')
        await page.close()

        old=await browser.new_page(viewport={'width':1280,'height':800}); old.set_default_timeout(5000); await old.set_content(schema3_html,wait_until='load'); await old.wait_for_timeout(160)
        await ck(await old.locator('[data-certloom-designer-add="rich_text"]').count()==1 and await old.locator('[data-certloom-designer-add="rich_text"]').is_disabled(),'schema3RichInsertFailsClosed')
        await ck(await old.locator('[data-certloom-designer-add="merge"]').count()==1 and not await old.locator('[data-certloom-designer-add="merge"]').is_disabled(),'schema3LegacyMergeInsertRemainsAvailable')
        await old.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(rich_html,wait_until='load'); await mobile.wait_for_timeout(170)
        await mobile.locator('[data-certloom-designer-add="rich_text"]').click(); await mobile.wait_for_timeout(70)
        add_h=await mobile.locator('[data-certloom-designer-rich-add="text"]').evaluate("e=>parseFloat(getComputedStyle(e).minHeight)||0")
        insert_h=await mobile.locator('[data-certloom-designer-rich-insert-field]').first.evaluate("e=>parseFloat(getComputedStyle(e).minHeight)||0")
        await ck(add_h>=43.5 and insert_h>=43.5,'mobileRichEditorControlsMeet44pxContract')
        await mobile.emulate_media(forced_colors='active'); await mobile.wait_for_timeout(30)
        token=mobile.locator('.certloom-template-designer-rich-preview-run.is-token').first
        outline=await token.evaluate("e=>getComputedStyle(e).outlineStyle")
        await ck(await token.count()==1 and outline!='none','forcedColorsKeepsEditorTokenDistinguishable')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.6 rich text Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.6 rich text Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
