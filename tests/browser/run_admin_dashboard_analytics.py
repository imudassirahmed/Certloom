#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(mode='present'):
    env=os.environ.copy(); env['CERTLOOM_DASHBOARD_ANALYTICS']=mode
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_admin_dashboard_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
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
        await page.set_content(fixture('present'),wait_until='load')
        preview=page.locator('.certloom-dashboard-analytics-preview')
        await ck(await preview.count()==1,'analyticsPreviewPresent')
        await ck(await page.get_by_role('heading',name='Credential analytics').count()==1,'analyticsHeading')
        await ck(await preview.get_by_text('Last 30 days',exact=True).count()==1,'fixedThirtyDayWindow')
        await ck((await preview.get_by_text('Open Analytics').get_attribute('href') or '').endswith('admin.php?page=certloom-analytics'),'analyticsDeepLink')
        text=await preview.inner_text()
        for value,label in [('126','issuedMetric'),('342','verificationMetric'),('38','failedPlusInvalidMetric'),('30','expiry90Metric')]:
            await ck(value in text,label)
        await ck(await preview.locator('.certloom-dashboard-analytics-grid article').count()==4,'fourMetrics')
        await ck(await preview.locator('.certloom-dashboard-analytics-charts section').count()==2,'twoCharts')
        await ck(await page.get_by_role('heading',name='Issuance trend').count()==1,'issuanceTrendHeading')
        await ck(await page.get_by_role('heading',name='Verification trend').count()==1,'verificationTrendHeading')
        await ck(await preview.locator('.certloom-dashboard-mini-chart').count()==2,'twoMiniCharts')
        await ck(await preview.locator('.certloom-dashboard-mini-chart').nth(0).locator(':scope > span').count()==30,'issuanceThirtyBars')
        await ck(await preview.locator('.certloom-dashboard-mini-chart').nth(1).locator(':scope > span').count()==30,'verificationThirtyBars')
        await ck(await preview.locator('.certloom-dashboard-mini-chart').nth(0).get_attribute('role')=='img','issuanceChartRole')
        await ck('Daily issued credentials' in (await preview.locator('.certloom-dashboard-mini-chart').nth(0).get_attribute('aria-label') or ''),'issuanceChartLabel')
        await ck('Daily verification lookups' in (await preview.locator('.certloom-dashboard-mini-chart').nth(1).get_attribute('aria-label') or ''),'verificationChartLabel')
        sr=await preview.locator('.certloom-dashboard-mini-chart .screen-reader-text').all_inner_texts()
        await ck(len(sr)==60 and any('issued' in x for x in sr) and any('lookups' in x for x in sr),'chartTextEquivalents')
        await ck('CERT-TOP-171' in text and '48' in text,'topCredential')
        await ck('Leadership' in text and '52' in text,'topProgram')
        await ck('Premium A4' in text and '69' in text,'topTemplate')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        hidden=await browser.new_page(viewport={'width':1200,'height':900})
        await hidden.set_content(fixture('absent'),wait_until='load')
        await ck(await hidden.locator('.certloom-dashboard-analytics-preview').count()==0,'noAnalyticsNoPreview')
        await ck(await hidden.get_by_role('heading',name='Dashboard').count()==1,'baseDashboardStillRenders')
        await ck(await hidden.locator('.certloom-metric-grid').count()==1,'baseMetricsStillRender')
        await hidden.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture('present'),wait_until='load')
        gridcols=await mobile.locator('.certloom-dashboard-analytics-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(gridcols==1,'mobileMetricOneColumn')
        chartcols=await mobile.locator('.certloom-dashboard-analytics-charts').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(chartcols==1,'mobileChartsOneColumn')
        leadercols=await mobile.locator('.certloom-dashboard-analytics-leaders').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(leadercols==1,'mobileLeadersOneColumn')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close()

        forced=await browser.new_page(viewport={'width':760,'height':700},forced_colors='active')
        await forced.set_content(fixture('present'),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-dashboard-analytics-grid article').count()==4,'forcedColorsMetricsVisible')
        await forced.close(); await browser.close()

    if failed:
        print('FAIL  ADM-02 Dashboard Analytics browser gate:',', '.join(failed)); return 1
    print('PASS  ADM-02 Dashboard Analytics browser gate: capability/failure-safe preview semantics, issuance + verification summaries, failed lookups, expiry forecast, leaders, charts, responsive layout and accessibility')
    print(f'\n{checks} ADM-02 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
