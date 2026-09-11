#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase4_issue_credential_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1440,'height':1100})
        await page.set_content(fixture(),wait_until='load')
        hero=page.locator('.certloom-issue-hero')
        nav=page.locator('.certloom-product-subnav')
        await ck(await hero.count()==1,'sharedHeroExists')
        await ck(await nav.evaluate('(e)=>e.previousElementSibling && e.previousElementSibling.classList.contains("certloom-issue-hero")'),'subnavImmediatelyBelowHeroInitially')
        await ck(await nav.evaluate('(e)=>e.classList.contains("is-below-hero")'),'subnavPlacementMarkedInitially')
        hero_box=await hero.bounding_box(); await ck(hero_box and hero_box['height']<=180,'issueHeroCompactDesktop')
        await ck(await page.locator('.certloom-ui-contract-badge').count()==0,'noUiContractBadge')
        await ck(await page.locator('.certloom-phase2-backend-boundary').count()==0,'noAlphaBoundaryBanner')
        await ck(await page.get_by_text('Current alpha engine',exact=True).count()==0,'noAlphaEngineBanner')
        steps=page.locator('.certloom-manual-steps')
        step_box=await steps.bounding_box(); await ck(step_box and step_box['height']<=42,'stepperCompact')
        current=page.locator('.certloom-manual-steps li.is-current')
        await ck('rgb(1, 193, 116)' in await current.evaluate('(e)=>getComputedStyle(e,"::before").backgroundColor'),'currentStepUsesBrandEmerald')
        await ck(await current.evaluate('(e)=>getComputedStyle(e).boxShadow')=='none','currentStepNotBoxed')
        card=page.locator('.certloom-issue-v2-card')
        box=await card.bounding_box(); await ck(box and box['width']>1000 and box['width']<=1142,'composeSurfaceUsesCompactTaskWidth')
        await ck(abs((box['x']+box['width']/2)-720)<5,'composeSurfaceCentered')
        fieldset=page.locator('.certloom-issue-v2-form > .certloom-manual-fieldset').first
        pad_top=float((await fieldset.evaluate('(e)=>getComputedStyle(e).paddingTop')).replace('px',''))
        await ck(pad_top<=14.5,'fieldsetVerticalPaddingReduced')
        lookup=page.locator('.certloom-recipient-compose .certloom-context-lookup')
        await ck(len((await lookup.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns')).split())==2,'recipientLookupCompactDesktop')
        presentation=page.locator('.certloom-presentation-choice').first
        pbox=await presentation.bounding_box(); await ck(pbox and pbox['height']<92,'presentationCardsCompact')
        await ck(await page.locator('.certloom-review-readiness').is_visible(),'actionableReadinessVisible')
        technical=page.locator('.certloom-issue-technical-status')
        await ck(await technical.count()==1 and not await technical.get_attribute('open'),'technicalStatusCollapsed')
        await ck(await technical.evaluate('(e)=>e.compareDocumentPosition(document.querySelector(".certloom-issue-v2-card")) & Node.DOCUMENT_POSITION_PRECEDING')!=0,'technicalStatusAfterCompose')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.locator('input[name="presentation_mode"][value="design"]').check()
        selected=page.locator('.certloom-presentation-choice:has(input[value="design"])')
        await ck('rgb(1, 193, 116)' in await selected.evaluate('(e)=>getComputedStyle(e).borderColor'),'selectedPresentationUsesBrandEmerald')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        mlookup=mobile.locator('.certloom-recipient-compose .certloom-context-lookup')
        await ck(len((await mlookup.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns')).split())==1,'recipientLookupStacksMobile')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoDocumentOverflow')
        await ck(await mobile.locator('.certloom-issue-actions .button').evaluate('(e)=>Math.round(e.getBoundingClientRect().width)')>300,'mobilePrimaryActionFullWidth')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  alpha.4.27 Issue Credential browser gate:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  alpha.4.27 Issue Credential browser gate: initial hero/subnav hierarchy, compact branded progress, tighter compose rhythm, compact Recipient lookup, deferred technical detail and responsive containment')
    print(f'\n{checks} alpha.4.27 Issue Credential browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
