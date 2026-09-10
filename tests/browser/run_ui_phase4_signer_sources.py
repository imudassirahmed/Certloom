#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.7 signer snapshot/signature-source behavior."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_signer_sources_fixture.php'

def fixture(schema):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(schema)
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,text=True,capture_output=True,env=env)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def snapshot(page):
    await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]');f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}""")
    await page.wait_for_timeout(50)
    return json.loads(await page.locator('[data-certloom-designer-json]').input_value())

async def main():
    failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks; checks+=1
        if not value: failed.append(name)
    html4=fixture(4); html3=fixture(3)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':900}); page.set_default_timeout(5000)
        await page.set_content(html4,wait_until='load'); await page.wait_for_timeout(220)
        sig_add=page.locator('[data-certloom-designer-add="signature"]')
        await ck(await sig_add.count()==1 and not await sig_add.is_disabled(),'schema4SignatureInsertAvailable')
        before=await snapshot(page); before_count=len(before['elements'])
        await sig_add.click(); await page.wait_for_timeout(110)
        saved=await snapshot(page); await ck(len(saved['elements'])==before_count+1,'schema4SignatureInsertAddsOneElement')
        sig=saved['elements'][-1]
        await ck(sig.get('type')=='signature' and sig.get('source')=='signer.signature' and 'asset' not in sig,'schema4SignatureDefaultsDirectlyToCredentialSigner')
        sid=sig['id']; selected=page.locator(f'[data-certloom-designer-element="{sid}"]')
        await ck(await selected.count()==1 and await selected.get_attribute('aria-pressed')=='true','newSignerSignatureSelected')
        status=page.locator('[data-certloom-designer-signature-status]')
        await ck(await status.is_visible() and 'credential Signer snapshot' in ((await status.inner_text()) or ''),'propertiesTruthfullyShowsSignerSnapshotSource')
        context=page.locator('[data-certloom-designer-context-group="signature"]')
        await ck(await context.is_visible(),'signatureContextToolbarVisible')
        await ck(await page.locator('[data-certloom-designer-content="signature"]').is_visible(),'signaturePropertiesGroupVisible')
        title=(await selected.inner_text()) or ''
        await ck('Credential Signer' in title,'canvasPreviewLabelsDynamicSignerSource')

        canonical_before=json.dumps(saved,sort_keys=True,separators=(',',':'))
        await page.locator('[data-certloom-designer-sample-data]').select_option('rtl'); await page.wait_for_timeout(80)
        sample_after=await snapshot(page)
        await ck(json.dumps(sample_after,sort_keys=True,separators=(',',':'))==canonical_before,'sampleProfileNeverMutatesSignerSourceLayout')

        # Switch through the existing WP Media Library boundary, then back to signer source.
        fixed=page.locator('[data-certloom-designer-content="signature"] [data-certloom-designer-media]')
        await fixed.click(); await page.wait_for_timeout(90)
        fixed_saved=await snapshot(page); fixed_sig=next(e for e in fixed_saved['elements'] if e['id']==sid)
        await ck(fixed_sig.get('asset')=='attachment:321' and 'source' not in fixed_sig,'fixedMediaSwitchRemovesDynamicSource')
        await ck('fixed WordPress Media Library image' in ((await status.inner_text()) or ''),'propertiesTruthfullyShowsFixedAssetSource')
        source=page.locator('[data-certloom-designer-content="signature"] [data-certloom-designer-signature-source="signer"]')
        await source.click(); await page.wait_for_timeout(90)
        signer_saved=await snapshot(page); signer_sig=next(e for e in signer_saved['elements'] if e['id']==sid)
        await ck(signer_sig.get('source')=='signer.signature' and 'asset' not in signer_sig,'signerSwitchRemovesFixedAsset')
        await ck('credential Signer snapshot' in ((await status.inner_text()) or ''),'sourceStatusRestoredAfterSwitch')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(80)
        undo_saved=await snapshot(page); undo_sig=next(e for e in undo_saved['elements'] if e['id']==sid)
        await ck(undo_sig.get('asset')=='attachment:321' and 'source' not in undo_sig,'signatureSourceSwitchParticipatesInUndo')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(80)
        redo_saved=await snapshot(page); redo_sig=next(e for e in redo_saved['elements'] if e['id']==sid)
        await ck(redo_sig.get('source')=='signer.signature' and 'asset' not in redo_sig,'signatureSourceSwitchParticipatesInRedo')

        # Layer lock must protect both source choices.
        lock=page.locator(f'[data-certloom-designer-layer-row="{sid}"] [data-certloom-designer-layer-row-action="lock"]')
        await lock.click(); await page.wait_for_timeout(60)
        await ck(await source.is_disabled() and await fixed.is_disabled(),'lockedSignatureDisablesBothSourceMutations')
        await ck(await context.locator('[data-certloom-designer-signature-source]').is_disabled(),'lockedSignatureContextControlDisabled')
        await lock.click(); await page.wait_for_timeout(60)
        await ck(not await source.is_disabled(),'unlockRestoresSignatureSourceEditing')
        await page.close()

        old=await browser.new_page(viewport={'width':1280,'height':800}); old.set_default_timeout(5000)
        await old.set_content(html3,wait_until='load'); await old.wait_for_timeout(190)
        old_add=old.locator('[data-certloom-designer-add="signature"]'); await old_add.click(); await old.wait_for_timeout(100)
        old_saved=await snapshot(old); old_sig=old_saved['elements'][-1]
        await ck(old_sig.get('type')=='signature' and old_sig.get('asset')=='attachment:321' and 'source' not in old_sig,'schema3SignatureInsertPreservesFixedMediaBehavior')
        old_source=old.locator('[data-certloom-designer-content="signature"] [data-certloom-designer-signature-source="signer"]')
        await ck(await old_source.is_visible() and await old_source.is_disabled(),'schema3CredentialSignerSourceFailsClosedInUI')
        await ck('fixed WordPress Media Library image' in ((await old.locator('[data-certloom-designer-signature-status]').inner_text()) or ''),'schema3StatusRemainsFixedAsset')
        await old.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000)
        await mobile.set_content(html4,wait_until='load'); await mobile.wait_for_timeout(190); await mobile.locator('[data-certloom-designer-add="signature"]').click(); await mobile.wait_for_timeout(80)
        msource=mobile.locator('[data-certloom-designer-content="signature"] [data-certloom-designer-signature-source="signer"]')
        mmedia=mobile.locator('[data-certloom-designer-content="signature"] [data-certloom-designer-media]')
        sh=await msource.evaluate("e=>parseFloat(getComputedStyle(e).minHeight)||e.getBoundingClientRect().height")
        mh=await mmedia.evaluate("e=>parseFloat(getComputedStyle(e).minHeight)||e.getBoundingClientRect().height")
        await ck(sh>=43.5 and mh>=43.5,'mobileSignatureSourceControlsMeet44pxContract')
        await mobile.emulate_media(forced_colors='active'); await mobile.wait_for_timeout(40)
        outline=await msource.evaluate("e=>getComputedStyle(e).borderStyle")
        await ck(outline!='none','forcedColorsKeepsSignerSourceActionPerceivable')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.7 signer sources Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.7 signer sources Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
