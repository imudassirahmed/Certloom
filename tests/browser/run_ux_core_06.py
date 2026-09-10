#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_06_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1180,'height':1000}); await page.set_content(fixture(),wait_until='load')
        tabs=await page.locator('.fixture-tabs a').all_inner_texts(); await ck(tabs==['Programs','Batches','Issuers','Signers'],'programTabOrder')
        await ck(await page.locator('[data-test="hierarchy"] [data-level]').count()==4,'fourHierarchyLevels')
        hierarchy=await page.locator('[data-test="hierarchy"]').inner_text(); await ck('Global → Issuer → Program → Batch' in hierarchy,'hierarchyExplained')
        await ck('Inherit Global design' in await page.locator('[data-test="issuer-design"]').inner_text(),'issuerInheritanceOption')
        await ck('Inherit Issuer / Global design' in await page.locator('[data-test="program-design"]').inner_text(),'programInheritanceOption')
        await ck('Inherit Program design' in await page.locator('[data-test="batch-design"]').inner_text(),'batchInheritanceOption')
        await ck(await page.locator('#program-form').evaluate('(f)=>f.checkValidity()'),'closedQuickFieldDoesNotBlockProgramSave')
        await ck(not await page.locator('[data-certloom-quick-field-label]').evaluate('(e)=>e.required'),'quickFieldLabelNotNativeRequiredWhenClosed')
        integ=page.locator('[data-test="integration"]'); await ck(await integ.locator('select[name="program_id"]').count()==1 and await integ.locator('select[name="batch_id"]').count()==1,'integrationProgramOptionalBatch')
        await ck(await integ.locator('select[name="template_id"]').count()==0,'integrationHasNoCertificateDesignSelector')
        await ck('Design follows Global → Issuer → Program → Batch' in await integ.inner_text(),'integrationExplainsHierarchy')
        await ck(await integ.locator('[data-test="legacy-fallback"]').count()==1,'legacyFallbackDisclosed')
        auto=page.locator('[data-test="automation"]'); await ck(all([await auto.locator('select[name="issuer_id"]').count()==1,await auto.locator('select[name="automation_program_id"]').count()==1,await auto.locator('select[name="automation_batch_id"]').count()==1]),'automationContextScopes')
        await ck(await auto.locator('select[name="template_id"]').count()==1,'automationEmailMessageSelector')
        await ck('not the certificate design' in await auto.inner_text(),'automationSeparatesEmailFromCertificateDesign')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':360,'height':780}); await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        cols=await mobile.locator('.fixture-hierarchy').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length'); await ck(cols==1,'mobileHierarchyStacks')
        heights=await mobile.locator('.fixture-tabs a').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)'); await ck(all(h>=44 for h in heights),'mobileTabsAccessible')
        await ck(await mobile.locator('.fixture-tabs').evaluate('(e)=>e.scrollWidth>=e.clientWidth'),'mobileTabStripContained')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-06 browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-CORE-06 browser gate: hierarchy, navigation, integration scope and automation distinctions are coherent')
    print(f'\n{checks} UX-CORE-06 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
