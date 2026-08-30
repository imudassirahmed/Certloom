#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_ADMIN_UX_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_admin_ux_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        page=await browser.new_page(viewport={'width':1200,'height':800})
        await page.set_content(fixture('simple_credential'),wait_until='load')
        labels=await page.locator('[data-primary-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck(labels==['Dashboard','Credentials','People & Programs','Design','Insights & Integrations','Settings'],'simplePrimaryNav')
        await ck(len(labels)==6 and not any(x in labels for x in ['Recipients','Manual Issuance','Data Tools']),'sixPrimarySectionsNoSpecialists')
        await ck(await page.locator('.certloom-ui-mode-choice.is-current').inner_text()=='Simple','simpleModeCurrent')
        await ck(await page.locator('.certloom-credential-primary-tab').count()==4,'simpleFourPrimaryTabs')
        await ck(await page.locator('.certloom-credential-subtabs a').count()==4,'credentialFourSubtabs')
        await ck(await page.locator('.certloom-credential-panel').count()==1,'onlySelectedPanelRendered')
        await ck(await page.locator('.certloom-credential-primary-tab[aria-current="page"]').inner_text()=='Credential','credentialPrimaryCurrent')
        await ck(await page.locator('.certloom-credential-subtabs a[aria-current="page"]').inner_text()=='Recipient','recipientSubtabCurrent')
        heights=await page.locator('.certloom-ui-mode-choice,.certloom-credential-primary-tab,.certloom-credential-subtabs a').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'adminTargetsAtLeast44')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        history=await browser.new_page(viewport={'width':1000,'height':720})
        await history.set_content(fixture('simple_history'),wait_until='load')
        await ck(await history.locator('.certloom-credential-subtabs a').count()==2,'historyTwoSubtabs')
        await ck(await history.locator('.certloom-credential-primary-tab[aria-current="page"]').inner_text()=='History','historyPrimaryCurrent')
        await ck(await history.locator('.certloom-credential-subtabs a[aria-current="page"]').inner_text()=='Versions','versionsSubtabCurrent')
        await history.close()

        advanced=await browser.new_page(viewport={'width':1240,'height':760})
        await advanced.set_content(fixture('advanced'),wait_until='load')
        advlabels=await advanced.locator('[data-primary-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck(advlabels==['Dashboard','Credentials','People & Programs','Design','Insights & Integrations','Settings'],'advancedKeepsSixSectionShell')
        await ck(await advanced.locator('.nav-tab').count()==8,'advancedEightDirectTabs')
        await ck(await advanced.locator('.certloom-ui-mode-choice.is-current').inner_text()=='Advanced','advancedModeCurrent')
        await advanced.close()

        settings=await browser.new_page(viewport={'width':1100,'height':760})
        await settings.set_content(fixture('settings'),wait_until='load')
        await ck(await settings.locator('.certloom-settings-local-nav a').count()==6,'settingsLocalNavigator')
        await settings.locator('#certloom-settings-search').fill('number')
        await settings.wait_for_timeout(30)
        await ck(not await settings.locator('#certloom-settings-search-results').get_attribute('hidden'),'settingsSearchOpens')
        await ck(await settings.locator('#certloom-settings-search-results a').count()==1,'settingsSearchBoundedMatch')
        await ck('Certificate numbering' in await settings.locator('#certloom-settings-search-results').inner_text(),'settingsSearchFindsHeading')
        searchh=await settings.locator('#certloom-settings-search').evaluate('(e)=>e.getBoundingClientRect().height')
        navh=await settings.locator('.certloom-settings-local-nav a').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(searchh>=44 and all(h>=44 for h in navh),'settingsTargetsAtLeast44')
        await settings.locator('#certloom-settings-search').fill('zzzz')
        await settings.wait_for_timeout(20)
        await ck('No settings sections match.' in await settings.locator('#certloom-settings-search-results').inner_text(),'settingsEmptyState')
        await settings.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture('simple_credential'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await ck(await mobile.locator('.certloom-credential-tabs-simple').evaluate('(e)=>e.scrollWidth>=e.clientWidth'),'mobileTabsRemainContained')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture('simple_credential'),wait_until='load')
        duration=await reduced.locator('.fixture-motion').evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(duration in ('0s','0.00001s','0.001s') or duration.startswith('0.00'),'reducedMotionRespected')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture('simple_credential'),wait_until='load')
        await ck(await forced.locator('.certloom-credential-primary-tab').count()==4,'forcedColorsTabsRender')
        await ck(await forced.locator('.certloom-ui-mode-choice').count()==2,'forcedColorsModeControlsRender')
        await forced.close(); await browser.close()

    if failed:
        print('FAIL  Admin UX browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  Admin UX browser gate: six-section Simple/Advanced shell, contextual Credential subtabs, settings search, accessibility, mobile, reduced motion and forced colors')
    print(f'\n{checks} Admin UX browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
