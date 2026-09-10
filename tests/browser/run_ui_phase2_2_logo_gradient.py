#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
ARTIFACT_DIR=Path(os.environ.get('CERTLOOM_BROWSER_ARTIFACT_DIR', str(ROOT/'build'/'browser-artifacts')))
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase2_1_visual_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1440,'height':1150})
        await page.set_content(fixture(),wait_until='load')
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        expected=['rgb(0, 72, 239)','rgb(19, 105, 252)','rgb(103, 156, 252)','rgb(3, 205, 243)','rgb(1, 193, 116)']
        for selector,label in [
            ('#dashboard-surface .certloom-dashboard-hero','dashboard'),
            ('#header-family .certloom-page-hero','page'),
            ('#header-family .certloom-workspace-hero','workspace'),
            ('#header-family .certloom-credential-hero','credential'),
            ('#email-surface .certloom-email-hero','email')]:
            el=page.locator(selector)
            bg=await el.evaluate("e=>getComputedStyle(e).backgroundImage")
            await ck('linear-gradient' in bg,label+'UsesGradient')
            for rgb in expected:
                await ck(rgb in bg,label+'Contains'+rgb.replace(' ','').replace(',','_').replace('(','_').replace(')',''))
            await ck('rgb(1, 19, 61)' not in bg and 'rgb(10, 52, 168)' not in bg,label+'NoBlueHeavyNavyExperiment')
        await page.evaluate("""() => {
          document.body.insertAdjacentHTML('beforeend', `
            <div id="p22-primary-headers" class="certloom-admin-shell">
              <section id="p22-programs" class="certloom-page-hero certloom-domain-title-row"><div><span class="certloom-eyebrow">Credential structure</span><h1>Programs</h1><p>Program management.</p></div><div class="certloom-domain-title-actions"><a class="button button-primary">Add program</a></div></section>
              <section id="p22-design" class="certloom-page-hero certloom-template-page-head"><div><span class="certloom-eyebrow">Certificate design</span><h1>Certificate designs</h1><p>Design management.</p></div><div class="certloom-template-page-actions"><a class="button">Import</a><a class="button button-primary">New design</a></div></section>
              <section id="p22-settings" class="certloom-page-hero certloom-settings-hero"><div><span class="certloom-eyebrow">Configuration</span><h1>Settings</h1><p>Settings management.</p></div></section>
            </div>`);
        }""")
        for selector,label in [('#p22-programs','programs'),('#p22-design','design'),('#p22-settings','settings')]:
            bg=await page.locator(selector).evaluate("e=>getComputedStyle(e).backgroundImage")
            await ck(all(rgb in bg for rgb in expected),label+'PrimaryWorkspaceUsesExactLogoGradient')
        await ck(await page.locator('#p22-design .certloom-template-page-actions .button:not(.button-primary)').evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','designSecondaryActionReadableOnBrightEnd')
        dash=page.locator('#dashboard-surface .certloom-dashboard-hero')
        await ck(await dash.locator('h1').evaluate("e=>getComputedStyle(e).color")=='rgb(255, 255, 255)','headingWhiteOnDarkStart')
        setup=page.locator('#dashboard-surface .certloom-dashboard-setup-link')
        await ck(await setup.evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','setupUsesDarkText')
        await ck('255, 255, 255' in await setup.evaluate("e=>getComputedStyle(e).backgroundColor"),'setupUsesLightSurface')
        secondary=page.locator('#dashboard-surface .certloom-dashboard-actions .button-secondary')
        await ck(await secondary.evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','secondaryActionUsesDarkText')
        await ck('255, 255, 255' in await secondary.evaluate("e=>getComputedStyle(e).backgroundColor"),'secondaryActionUsesLightSurface')
        await ck(await page.locator('#dashboard-surface .certloom-dashboard-mini-chart.is-issuance > span').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(0, 72, 239)','issuanceChartLogoBlue')
        await ck(await page.locator('#dashboard-surface .certloom-dashboard-mini-chart.is-verification > span').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(3, 205, 243)','verificationChartLogoCyan')
        series=page.locator('#dashboard-surface .certloom-chart-series')
        await ck(await series.nth(0).evaluate("e=>getComputedStyle(e).stroke")=='rgb(0, 72, 239)','seriesOneLogoBlue')
        await ck(await series.nth(1).evaluate("e=>getComputedStyle(e).stroke")=='rgb(3, 205, 243)','seriesTwoLogoCyan')
        await ck(await series.nth(2).evaluate("e=>getComputedStyle(e).stroke")=='rgb(19, 105, 252)','seriesThreeLogoBlue')
        await ck(await series.nth(3).evaluate("e=>getComputedStyle(e).stroke")=='rgb(1, 193, 116)','seriesFourLogoEmerald')
        await ck(await series.nth(4).evaluate("e=>getComputedStyle(e).strokeDasharray")!='none','seriesFiveRepeatsWithPattern')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        ARTIFACT_DIR.mkdir(parents=True, exist_ok=True)
        await page.screenshot(path=str(ARTIFACT_DIR/'certloom-phase2-2-logo-gradient-review.png'),full_page=True)
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        mobile_bg=await mobile.locator('#dashboard-surface .certloom-dashboard-hero').evaluate("e=>getComputedStyle(e).backgroundImage")
        await ck(all(rgb in mobile_bg for rgb in expected),'mobileKeepsExactLogoGradient')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('#dashboard-surface .certloom-dashboard-hero').evaluate("e=>getComputedStyle(e).backgroundColor") in ('rgb(255, 255, 255)','rgba(0, 0, 0, 0)'),'forcedColorsDoesNotDependOnGradient')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 2.2 logo-gradient:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 2.2 logo-gradient: exact logo sequence, readable controls, logo-family charts, responsive and forced-colors checks')
    print(f'\n{checks} UI Phase 2.2 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
