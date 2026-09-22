#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_05_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1180,'height':1000})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.locator('[data-test="credential-label"]').inner_text()=='Credential','credentialLabelText')
        await ck(await page.locator('[data-test="legacy-label"]').inner_text()=='Legacy','legacyLabelText')
        cred_gap=await page.evaluate('''() => {const a=document.querySelector('[data-test="credential-name"]').getBoundingClientRect();const b=document.querySelector('[data-test="credential-label"]').getBoundingClientRect();return b.left-a.right;}''')
        legacy_gap=await page.evaluate('''() => {const a=document.querySelector('[data-test="legacy-name"]').getBoundingClientRect();const b=document.querySelector('[data-test="legacy-label"]').getBoundingClientRect();return b.left-a.right;}''')
        await ck(cred_gap>=6 and legacy_gap>=6,'dashboardProvenanceHasVisibleGap')
        pill=await page.locator('[data-test="legacy-label"]').evaluate('(e)=>({display:getComputedStyle(e).display,border:getComputedStyle(e).borderTopWidth,radius:getComputedStyle(e).borderRadius,pad:getComputedStyle(e).paddingLeft})')
        await ck(pill['display']=='flex' or pill['display']=='inline-flex','provenancePillDisplay')
        await ck(float(pill['border'].replace('px',''))>=1 and float(pill['pad'].replace('px',''))>=7,'provenancePillStyled')
        async def gaps(selector):
            return await page.locator(selector).evaluate('''e=>{const x=[...e.children].map(n=>n.getBoundingClientRect());return x.slice(1).map((r,i)=>r.left-x[i].right)}''')
        lg=await gaps('[data-test="legacy-actions"]'); cg=await gaps('[data-test="credential-actions"]')
        await ck(all(x>=7 for x in lg),'legacyHeroActionsSeparated')
        await ck(all(x>=7 for x in cg),'credentialHeroActionsSeparated')
        await ck(await page.locator('.certloom-credential-record-nav a').count()==6,'recordEditorSixSections')
        await ck(await page.locator('.certloom-credential-tabs-simple').count()==0,'recordEditorNoSimpleActionsTabs')
        await ck(await page.locator('.certloom-locked-field input[readonly][aria-readonly="true"]').count()==4,'issuedFactsReadonly')
        await ck(await page.get_by_role('link',name='Edit Recipient profile').count()==1,'recipientEditContinuation')
        await ck(await page.locator('select[name="program_id"]').count()==1 and await page.locator('select[name="batch_id"]').count()==1,'programBatchEditableInline')
        await ck('Registrar · #7' in await page.locator('.certloom-audit-actor').inner_text(),'auditShowsHumanActor')
        audit=await page.locator('.certloom-audit-row').inner_text()
        await ck('BBA Foundation → BBA' in audit and 'August 2026 → September 2026' in audit,'auditShowsBeforeAfter')
        await ck('Corrected cohort assignment.' in audit,'auditShowsReason')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoDocumentOverflow')
        cols=await mobile.locator('.certloom-locked-field-grid').first.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==1,'mobileLockedFieldsSingleColumn')
        await ck(await mobile.locator('.certloom-credential-record-nav').evaluate('(e)=>e.scrollWidth>=e.clientWidth'),'mobileRecordNavContained')
        heights=await mobile.locator('.certloom-credential-hero-actions>.button').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'mobileHeroActionsAccessible')
        await mobile.close()

        forced=await browser.new_page(viewport={'width':900,'height':760},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.certloom-credential-source.is-legacy').count()>=1 and await forced.locator('.certloom-locked-record-badge').count()==1,'forcedColorsCoreSurfacesRemain')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-05 browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-CORE-05 browser gate: styled Credential/Legacy provenance, separated hero actions, one immutable record editor and human audit trail')
    print(f'\n{checks} UX-CORE-05 browser checks, 0 failures'); return 0

raise SystemExit(asyncio.run(run()))
