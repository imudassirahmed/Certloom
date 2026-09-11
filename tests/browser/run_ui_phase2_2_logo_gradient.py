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
        expected=['rgb(1, 193, 116)','rgb(3, 205, 243)','rgb(19, 105, 252)']
        await ck((ROOT/'admin/assets/certloom-brand-mark.png').is_file(),'suppliedLogoAssetBundled')
        await ck((ROOT/'admin/assets/certloom-brand-mark.png').read_bytes().startswith(b'\x89PNG\r\n\x1a\n'),'suppliedLogoAssetIsPng')
        for selector,label in [
            ('#dashboard-surface .certloom-dashboard-hero','dashboard'),
            ('#header-family .certloom-page-hero','page'),
            ('#header-family .certloom-workspace-hero','workspace'),
            ('#header-family .certloom-credential-hero','credential'),
            ('#email-surface .certloom-email-hero','email')]:
            el=page.locator(selector)
            await ck('linear-gradient' not in await el.evaluate("e=>getComputedStyle(e).backgroundImage"),label+'UsesLightSurface')
            await ck('blur(' in await el.evaluate("e=>getComputedStyle(e).backdropFilter"),label+'UsesGlassBlur')
            rail=await el.evaluate("e=>{const s=getComputedStyle(e,'::before');return {content:s.content,bg:s.backgroundImage,height:s.height}}")
            await ck(rail['content'] not in ('none','normal',''),label+'BrandRailPresent')
            await ck(rail['height']=='4px' and all(rgb in rail['bg'] for rgb in expected),label+'BrandRailUsesLogoSpectrum')
            mark=await el.evaluate("e=>{const s=getComputedStyle(e,'::after');return {bg:s.backgroundImage,opacity:s.opacity}}")
            await ck('certloom-brand-mark.png' in mark['bg'],label+'UsesSuppliedLogoWatermark')
            await ck(float(mark['opacity']) <= .12,label+'WatermarkRemainsSubtle')
        await page.evaluate("""() => {
          document.body.insertAdjacentHTML('beforeend', `
            <div id="p22-primary-headers" class="certloom-admin-shell">
              <section id="p22-programs" class="certloom-page-hero certloom-domain-title-row"><div><span class="certloom-eyebrow">Credential structure</span><h1>Programs</h1><p>Program management.</p></div><div class="certloom-domain-title-actions"><a class="button button-primary">Add program</a></div></section>
              <section id="p22-design" class="certloom-page-hero certloom-template-page-head"><div><span class="certloom-eyebrow">Certificate design</span><h1>Certificate designs</h1><p>Design management.</p></div><div class="certloom-template-page-actions"><a class="button">Import</a><a class="button button-primary">New design</a></div></section>
              <section id="p22-settings" class="certloom-page-hero certloom-settings-hero"><div><span class="certloom-eyebrow">Configuration</span><h1>Settings</h1><p>Settings management.</p></div></section>
            </div>`);
        }""")
        for selector,label in [('#p22-programs','programs'),('#p22-design','design'),('#p22-settings','settings')]:
            el=page.locator(selector)
            await ck('linear-gradient' not in await el.evaluate("e=>getComputedStyle(e).backgroundImage"),label+'PrimaryWorkspaceUsesLightSurface')
            await ck('linear-gradient' in await el.evaluate("e=>getComputedStyle(e,'::before').backgroundImage"),label+'PrimaryWorkspaceUsesBrandRail')
            await ck('certloom-brand-mark.png' in await el.evaluate("e=>getComputedStyle(e,'::after').backgroundImage"),label+'PrimaryWorkspaceUsesLogoWatermark')
        await ck(await page.locator('#p22-design .certloom-template-page-actions .button:not(.button-primary)').evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','designSecondaryActionReadable')
        dash=page.locator('#dashboard-surface .certloom-dashboard-hero')
        await ck(await dash.locator('h1').evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','headingUsesNavyInk')
        await ck(await dash.locator('p').evaluate("e=>getComputedStyle(e).color")=='rgb(86, 99, 127)','copyUsesMutedInk')
        setup=page.locator('#dashboard-surface .certloom-dashboard-setup-link')
        await ck(await setup.evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','setupUsesDarkText')
        await ck('255, 255, 255' in await setup.evaluate("e=>getComputedStyle(e).backgroundColor"),'setupUsesLightSurface')
        primary=page.locator('#dashboard-surface .certloom-dashboard-actions .button-primary')
        await ck(await primary.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(20, 80, 224)','primaryActionUsesActionBlue')
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
        mobile_hero=mobile.locator('#dashboard-surface .certloom-dashboard-hero')
        await ck('linear-gradient' not in await mobile_hero.evaluate("e=>getComputedStyle(e).backgroundImage"),'mobileKeepsLightSurface')
        await ck('linear-gradient' in await mobile_hero.evaluate("e=>getComputedStyle(e,'::before').backgroundImage"),'mobileKeepsBrandRail')
        await ck('certloom-brand-mark.png' in await mobile_hero.evaluate("e=>getComputedStyle(e,'::after').backgroundImage"),'mobileKeepsLogoWatermark')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('#dashboard-surface .certloom-dashboard-hero').evaluate("e=>getComputedStyle(e).backgroundColor") in ('rgb(255, 255, 255)','rgba(0, 0, 0, 0)'),'forcedColorsDoesNotDependOnGradient')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 2.2 logo-gradient:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 2.2 light brand-glass: supplied-logo watermark, compact spectrum rail, readable controls, logo-family charts, responsive and forced-colors checks')
    print(f'\n{checks} UI Phase 2.2 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
