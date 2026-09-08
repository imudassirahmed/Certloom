#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
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
        for selector,label in [
            ('#dashboard-surface .certloom-dashboard-hero','dashboard'),
            ('#header-family .certloom-page-hero','page'),
            ('#header-family .certloom-workspace-hero','workspace'),
            ('#header-family .certloom-credential-hero','credential'),
            ('#email-surface .certloom-email-hero','email')]:
            el=page.locator(selector)
            await ck(await el.evaluate("e=>getComputedStyle(e).backgroundImage")=='none',label+'HeaderNoGradient')
            await ck(await el.evaluate("e=>getComputedStyle(e).boxShadow")=='none',label+'HeaderNoShadow')
            await ck(await el.evaluate("e=>getComputedStyle(e).borderRadius")=='0px',label+'HeaderSquare')
            await ck(await el.evaluate("e=>getComputedStyle(e).paddingBottom")=='16px',label+'HeaderPadding')
        dash=page.locator('#dashboard-surface .certloom-dashboard-hero')
        await ck(await dash.locator('h1').evaluate("e=>getComputedStyle(e).color")=='rgb(1, 19, 61)','dashboardNavyHeading')
        await ck(await dash.locator('p').evaluate("e=>getComputedStyle(e).color")=='rgb(86, 99, 127)','dashboardMutedCopy')
        setup=page.locator('#dashboard-surface .certloom-dashboard-setup-link')
        await ck(await setup.evaluate("e=>getComputedStyle(e).position")=='static','setupActionNotFloatingGlassPill')
        await ck(await setup.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(255, 255, 255)','setupActionOpaque')
        await ck(await setup.evaluate("e=>getComputedStyle(e).borderRadius")=='4px','setupActionControlRadius')
        metric=page.locator('#dashboard-surface .certloom-metric-card').first
        await ck(await metric.evaluate("e=>getComputedStyle(e).borderRadius")=='0px','metricSquare')
        await ck(await metric.evaluate("e=>getComputedStyle(e).boxShadow")=='none','metricNoShadow')
        await ck(await metric.evaluate("e=>getComputedStyle(e).minHeight")=='120px','metricCompactHeight')
        await ck(await metric.locator('strong').evaluate("e=>getComputedStyle(e).fontSize")=='24px','metricCompactType')
        await ck(await page.locator('.certloom-metric-icon').first.evaluate("e=>getComputedStyle(e).color")=='rgb(20, 80, 224)','genericMetricUsesActionBlue')
        await ck(await page.locator('.certloom-metric-icon.is-valid').evaluate("e=>getComputedStyle(e).color")=='rgb(0, 115, 78)','validMetricUsesVerdictGreen')
        await ck(await page.locator('.certloom-metric-icon.is-withdrawn').evaluate("e=>getComputedStyle(e).color")=='rgb(150, 33, 74)','revokedMetricUsesRose')
        await ck(await page.locator('.certloom-metric-icon.is-expired').evaluate("e=>getComputedStyle(e).color")=='rgb(138, 90, 0)','expiredMetricUsesOchre')
        for selector,label in [('#dashboard-surface .certloom-panel','panel'),('#header-family .certloom-manual-card','manualCard'),('#dashboard-surface .certloom-dashboard-analytics-grid article','analyticsCell')]:
            el=page.locator(selector).first
            await ck(await el.evaluate("e=>getComputedStyle(e).boxShadow")=='none',label+'NoShadow')
            await ck(await el.evaluate("e=>getComputedStyle(e).borderRadius")=='0px',label+'Square')
        quick=page.locator('#dashboard-surface .certloom-dashboard-quick-grid a').first
        await ck(await quick.evaluate("e=>getComputedStyle(e).borderRadius")=='4px','quickActionControlRadius')
        await ck(await quick.evaluate("e=>getComputedStyle(e).boxShadow")=='none','quickActionNoShadow')
        await ck(await quick.evaluate("e=>getComputedStyle(e).minHeight")=='72px','quickActionCompactHeight')
        editor=page.locator('#header-family .certloom-editor-actions')
        await ck(await editor.evaluate("e=>getComputedStyle(e).backdropFilter")=='none','editorActionBarNoGlass')
        email_toolbar=page.locator('#email-surface .certloom-designer-toolbar')
        await ck(await email_toolbar.evaluate("e=>getComputedStyle(e).backdropFilter")=='none','emailToolbarNoGlass')
        await ck(await email_toolbar.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(255, 255, 255)','emailToolbarOpaque')
        await ck(await page.locator('#email-surface').evaluate("e=>getComputedStyle(e).getPropertyValue('--cl-ink').trim()")=='#01133D','emailDoesNotOverrideGlobalInkToken')
        await ck(await page.locator('#dashboard-surface .certloom-setup-reminder .dashicons').evaluate("e=>getComputedStyle(e).color")=='rgb(20, 80, 224)','setupReminderUsesBlueNotValidGreen')
        await ck(await page.locator('#dashboard-surface .ecapla-certverify-progress > span').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(20, 80, 224)','genericProgressUsesBlue')
        await ck(await page.locator('#dashboard-surface .certloom-domain-views .is-current').evaluate("e=>getComputedStyle(e).color")=='rgb(20, 80, 224)','currentTabUsesBlue')
        await ck(await page.locator('#dashboard-surface .ecapla-certverify-feature-icon').evaluate("e=>getComputedStyle(e).color")=='rgb(20, 80, 224)','featureIconUsesBlue')
        await ck(await page.locator('#dashboard-surface .certloom-credential-source.is-credential').evaluate("e=>getComputedStyle(e).color")=='rgb(86, 99, 127)','credentialSourceIsNeutralNotVerdictGreen')
        await ck(await page.locator('#dashboard-surface .certloom-settings-info-card > .dashicons').evaluate("e=>getComputedStyle(e).color")=='rgb(20, 80, 224)','settingsInfoUsesBlue')
        rail=await dash.evaluate("e=>getComputedStyle(e,'::before').backgroundImage")
        await ck('linear-gradient' in rail and 'rgb(20, 80, 224)' in rail and 'rgb(3, 205, 243)' in rail,'dashboardBrandRailUsesLogoSpectrum')
        email_rail=await page.locator('#email-surface .certloom-email-hero').evaluate("e=>getComputedStyle(e,'::before').backgroundImage")
        await ck('linear-gradient' in email_rail,'emailHeaderSharesBrandRail')
        await ck(await page.locator('#dashboard-surface .certloom-dashboard-mini-chart.is-issuance > span').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(20, 80, 224)','dashboardIssuanceChartUsesLogoBlue')
        await ck(await page.locator('#dashboard-surface .certloom-dashboard-mini-chart.is-verification > span').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(0, 158, 212)','dashboardVerificationChartUsesLogoCyan')
        await ck(await page.locator('#dashboard-surface .certloom-analytics-chart.is-issuance .certloom-analytics-bar').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(20, 80, 224)','analyticsIssuanceUsesLogoBlue')
        await ck(await page.locator('#dashboard-surface .certloom-analytics-chart.is-verification .certloom-analytics-bar').evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(0, 158, 212)','analyticsVerificationUsesLogoCyan')
        series=page.locator('#dashboard-surface .certloom-chart-series')
        await ck(await series.nth(0).evaluate("e=>getComputedStyle(e).stroke")=='rgb(20, 80, 224)','seriesOneBlue')
        await ck(await series.nth(1).evaluate("e=>getComputedStyle(e).stroke")=='rgb(0, 158, 212)','seriesTwoCyan')
        await ck(await series.nth(4).evaluate("e=>getComputedStyle(e).strokeDasharray")!='none','seriesFiveRepeatsWithDash')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.screenshot(path='/mnt/data/certloom-phase2-1-visual-audit.png',full_page=True)
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.locator('#dashboard-surface .certloom-dashboard-hero').evaluate("e=>getComputedStyle(e).paddingBottom")=='12px','mobileHeaderPadding')
        await ck(await mobile.locator('#dashboard-surface .certloom-dashboard-actions').evaluate("e=>Math.abs(e.getBoundingClientRect().width-e.parentElement.getBoundingClientRect().width)<1"),'mobileActionsUseAvailableWidth')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close()
        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.locator('.certloom-metric-card').first.evaluate("e=>getComputedStyle(e).transitionDuration")=='0s','reducedMotionMetricStatic')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('#dashboard-surface .certloom-dashboard-hero').count()==1 and await forced.locator('#email-surface .certloom-email-hero').count()==1,'forcedColorsHeadersRender')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 2.1 visual consistency:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 2.1 visual consistency: flat tokenized headers, compact dashboard metrics, opaque chrome, responsive/forced-color/reduced-motion checks')
    print(f'\n{checks} UI Phase 2.1 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
