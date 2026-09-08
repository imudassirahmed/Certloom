#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture(period):
    env=os.environ.copy();env['CERTLOOM_ISSUANCE_ANALYTICS_PERIOD']=str(period)
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_issuance_analytics_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0;failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks;checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1440,'height':1100}); await page.set_content(fixture(30),wait_until='load')
        await ck(await page.locator('h1').inner_text()=='Issuance Analytics','heading')
        await ck(await page.locator('.certloom-analytics-tabs .nav-tab').count()==2,'twoAnalyticsTabs')
        await ck(await page.locator('.certloom-analytics-tabs .nav-tab-active').inner_text()=='Issuance','issuanceTabActive')
        await ck(await page.locator('.certloom-issuance-metrics article').count()==6,'sixSummaryMetrics')
        metrics=await page.locator('.certloom-issuance-metrics').inner_text(); await ck(all(x in metrics for x in ['126','48','72','14','30','44']),'summaryValues')
        await ck(await page.locator('#certloom-issuance-analytics-days option').count()==4,'boundedPeriods')
        await ck(await page.locator('#certloom-issuance-analytics-days').input_value()=='30','selected30')
        await ck('daily trend' in (await page.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'dailyTrend')
        await ck(await page.locator('.certloom-analytics-bar').count()==30,'dailyBuckets')
        await ck(await page.locator('.certloom-analytics-chart').evaluate('(e)=>e.classList.contains("is-issuance")'),'issuanceSeriesClass')
        await ck(await page.locator('.certloom-analytics-bar').first.evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(20, 80, 224)','issuanceUsesLogoBlue')
        source=await page.locator('.certloom-analytics-source-list').inner_text(); await ck(all(x in source for x in ['Manual','Bulk','Other','48','72','6']),'sourceBreakdown')
        await ck(await page.locator('.certloom-issuance-dimension-grid > section').count()==4,'fourDimensionPanels')
        dims=await page.locator('.certloom-issuance-dimensions').inner_text(); await ck(all(x in dims for x in ['Programs','Templates','Organizations','Batches','Leadership','Premium A4','Ecapla Academy','August Cohort']),'dimensionContent')
        await ck('Unattributed batch' in dims,'unattributedBatchVisible')
        forecast=await page.get_by_role('heading',name='90-day expiry forecast').locator('xpath=ancestor::section[1]').inner_text(); await ck(all(x in forecast for x in ['0–30 days','31–60 days','61–90 days','14','9','7']),'forecastBuckets')
        await ck('renewal reminders and re-certification remain the separate REN-01 workflow' in forecast,'renewalBoundaryCopy')
        truth=await page.get_by_role('heading',name='Issued credential details stay fixed').locator('xpath=ancestor::section[1]').inner_text(); await ck('never guesses' in truth and 'Unattributed' in truth,'historicalAccuracyCopy')
        await ck(await page.locator('.certloom-analytics-table-wrap[tabindex="0"]').count()>=2,'focusableTableRegions')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        weekly=await browser.new_page(viewport={'width':1100,'height':900}); await weekly.set_content(fixture(90),wait_until='load')
        await ck(await weekly.locator('#certloom-issuance-analytics-days').input_value()=='90','selected90')
        await ck('weekly trend' in (await weekly.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'weeklyTrend')
        await ck(await weekly.locator('.certloom-analytics-bar').count()==14,'weeklyBuckets')
        await ck((await weekly.locator('.certloom-analytics-table-wrap').first.locator('thead th').first.inner_text())=='Week starting','weeklyColumn')
        await weekly.close()
        monthly=await browser.new_page(viewport={'width':1100,'height':900}); await monthly.set_content(fixture(365),wait_until='load')
        await ck(await monthly.locator('#certloom-issuance-analytics-days').input_value()=='365','selected365')
        await ck('monthly trend' in (await monthly.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'monthlyTrend')
        await ck(await monthly.locator('.certloom-analytics-bar').count()==13,'monthlyBuckets')
        await ck((await monthly.locator('.certloom-analytics-table-wrap').first.locator('thead th').first.inner_text())=='Month','monthlyColumn')
        await monthly.close()
        mobile=await browser.new_page(viewport={'width':360,'height':800}); await mobile.set_content(fixture(30),wait_until='load')
        cols=await mobile.locator('.certloom-issuance-dimension-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length'); await ck(cols==1,'mobileDimensionOneColumn')
        forecast_cols=await mobile.locator('.certloom-expiry-buckets').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length'); await ck(forecast_cols==1,'mobileForecastOneColumn')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('.certloom-analytics-tabs').evaluate('(e)=>getComputedStyle(e).overflowX') in ['auto','scroll'],'mobileTabOverflowContained')
        await mobile.close()
        reduced=await browser.new_page(viewport={'width':760,'height':700},reduced_motion='reduce');await reduced.set_content(fixture(30),wait_until='load')
        duration=await reduced.locator('.certloom-analytics-bar').first.evaluate('(e)=>getComputedStyle(e).transitionDuration');await ck(float(duration.rstrip('s').split(',')[0])<=0.001,'reducedMotion')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':760,'height':700},forced_colors='active');await forced.set_content(fixture(30),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-expiry-buckets article').count()==3,'forcedColorsForecastVisible')
        await forced.close();await browser.close()
    if failed:
        print('FAIL  Issuance Analytics browser gate:',', '.join(failed));return 1
    print('PASS  Issuance Analytics browser gate: report tabs, summaries, daily/weekly/monthly trends, issuance dimensions, expiry forecast, responsive layout and accessibility')
    print(f'\n{checks} Issuance Analytics browser checks, 0 failures');return 0
raise SystemExit(asyncio.run(run()))
