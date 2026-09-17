#!/usr/bin/env python3
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
JS=(ROOT/'admin/assets/font-picker.js').read_text()
CSS=(ROOT/'admin/assets/font-picker.css').read_text()

def html():
    # 1,200 families intentionally exceeds normal catalogs by a large margin.
    return f'''<!doctype html><html><head><meta charset="utf-8"><style>:root{{--cl-rule:#ddd;--cl-r-sm:6px;--cl-r-panel:10px;--cl-surface:#fff;--cl-ink:#123;--cl-float:none;--cl-paper:#f5f5f5;--cl-sans:Arial,sans-serif}}{CSS}</style></head><body>
<label id="cert"><span>Certificate font</span><select data-certloom-font-select><option value="font-0000" data-font-key="font-0000">Font 0000</option></select></label>
<label id="email"><span>Email font</span><select data-certloom-email-font-select><option value="Font 0000,Arial,sans-serif" data-font-key="font-0000">Font 0000</option></select></label>
<script>
const catalog={{}};for(let i=0;i<1200;i++){{const n=String(i).padStart(4,'0');catalog['font-'+n]={{label:'Font '+n,css:'"Font '+n+'",Arial,sans-serif',group:i<400?'A':i<800?'B':'C',local_names:[]}};}}
window.certloomTemplateFontCatalog=catalog;window.certloomEmailDesignerL10n={{fontCatalog:catalog}};
window.certloomFontPickerConfig={{ajaxUrl:'/mock',nonce:'nonce',searchFonts:'Search fonts',addCustomFont:'Upload custom font',customFonts:'Custom fonts',familyName:'Family name',weight:'Weight',style:'Style',normal:'Normal',italic:'Italic',fontFile:'Font file',upload:'Upload font',uploading:'Uploading…'}};
window.fetch=async()=>({{json:async()=>({{success:true,data:{{key:'custom-fedcba9876543210',definition:{{label:'Uploaded Fixture',css:'"CertloomCustom_fedcba9876543210",Arial,sans-serif',group:'Custom fonts',custom:true,preview_family:'CertloomCustom_fedcba9876543210',faces:[{{weight:400,style:'normal',format:'ttf',sha256:'{'c'*64}',url:'data:font/ttf;base64,AAEAAA=='}}]}}}}}})}});
</script><script>{JS}</script></body></html>'''

async def main():
    checks=0;failed=[]
    async def ck(ok,label):
        nonlocal checks;checks+=1
        if not ok: failed.append(label)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1280,'height':800})
        errors=[];page.on('pageerror',lambda e:errors.append(str(e)))
        await page.set_content(html(),wait_until='load');await page.wait_for_timeout(100)
        await ck(await page.locator('.certloom-font-picker').count()==2,'bothStudiosReceiveVisualPicker')
        buttons=page.locator('.certloom-font-picker__button');await buttons.nth(0).click();await page.wait_for_timeout(80)
        rows=page.locator('#cert .certloom-font-picker__row')
        count=await rows.count();await ck(count<=20,'largeCatalogVirtualizesVisibleRows')
        await ck(await page.locator('#cert .certloom-font-picker__spacer').evaluate("el=>parseFloat(el.style.height)>=57600"),'virtualSpacerRepresentsFullCatalog')
        await ck('Font 0000' in await rows.first.inner_text(),'firstPreviewRowVisible')
        await ck('Font 0000' in (await rows.first.locator('.certloom-font-picker__row-sample').get_attribute('style') or ''),'previewRowUsesItsOwnTypeface')
        metrics=await page.evaluate('CertloomFontPicker.metrics()');await ck(metrics['maxRowsRendered']<=20,'renderMetricsStayBounded');await ck(metrics['lastRenderMs']<100,'virtualWindowRenderStaysShort')
        viewport=page.locator('#cert [data-cfp-viewport]');await viewport.evaluate("el=>el.scrollTop=48000");await page.wait_for_timeout(80)
        keys=await rows.evaluate_all("els=>els.map(el=>el.dataset.fontKey)");await ck(any(int(k.split('-')[1])>=990 for k in keys),'scrollingVirtualWindowRecyclesRows')
        search=page.locator('#cert [data-cfp-search]');await search.fill('1199');await page.wait_for_timeout(120);await ck(await rows.count()==1 and 'Font 1199' in await rows.first.inner_text(),'debouncedSearchFindsDeepCatalogEntry')
        # Dynamic Email Inspector controls should be enhanced automatically.
        await page.evaluate("""() => {const s=document.createElement('select');s.setAttribute('data-certloom-email-font-select','');s.innerHTML='<option value=\"Font 0000,Arial,sans-serif\" data-font-key=\"font-0000\">Font 0000</option>';document.body.appendChild(s);}""")
        await page.wait_for_timeout(80);await ck(await page.locator('.certloom-font-picker').count()==3,'dynamicInspectorFontControlEnhanced')
        panel=page.locator('#cert .certloom-font-picker__panel')
        # Closed pickers must not repaint their 1,200-family lists when the catalog changes.
        if await panel.is_visible(): await buttons.nth(0).click()
        before_update=await page.evaluate('CertloomFontPicker.metrics()')
        await page.evaluate("document.dispatchEvent(new CustomEvent('certloom:fonts-updated'))")
        await page.wait_for_timeout(80)
        after_update=await page.evaluate('CertloomFontPicker.metrics()')
        await ck(after_update['renderPasses']==before_update['renderPasses'],'closedPickersDoNotRepaintOnCatalogUpdate')
        await buttons.nth(1).click();await page.wait_for_timeout(80)
        email_metrics=await page.evaluate('CertloomFontPicker.metrics()')
        await ck(email_metrics['maxRowsRendered']<=20 and email_metrics['lastRenderMs']<100,'secondStudioReusesBoundedVirtualRendering')
        await buttons.nth(1).click()
        # Mock an upload and ensure both catalog modes receive/select it without reloading the page.
        if not await panel.is_visible(): await buttons.nth(0).click()
        await page.locator('#cert [data-cfp-custom]').click();await page.locator('#cert [data-cfp-family]').fill('Uploaded Fixture')
        await page.locator('#cert [data-cfp-file]').set_input_files({'name':'fixture.ttf','mimeType':'font/ttf','buffer':b'\x00\x01\x00\x00fixture-font'})
        await page.locator('#cert [data-cfp-upload]').click();await page.wait_for_timeout(120)
        cert_value=await page.locator('#cert [data-certloom-font-select]').input_value();await ck(cert_value=='custom-fedcba9876543210','customUploadSelectsStableCertificateKey')
        await ck(await page.locator('#email [data-certloom-email-font-select] option[data-font-key="custom-fedcba9876543210"]').count()==1,'customUploadPropagatesToEmailCatalog')
        await ck(not errors,'noBrowserRuntimeErrors')
        await browser.close()
    for name in failed: print('FAIL ',name)
    print(f'{checks-failed.__len__()} passed, {len(failed)} failures')
    return 1 if failed else 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
