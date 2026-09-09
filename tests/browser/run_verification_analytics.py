#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(period):
    env=os.environ.copy();env['CERTLOOM_ANALYTICS_PERIOD']=str(period)
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_verification_analytics_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0;failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks;checks+=1
            if not ok:failed.append(label)

        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(fixture(30),wait_until='load')
        await ck(await page.locator('h1').inner_text()=='Verification Analytics','heading')
        await ck(await page.locator('.certloom-analytics-metrics article').count()==6,'sixSummaryMetrics')
        await ck('292' in await page.locator('.certloom-analytics-metrics').inner_text(),'totalLookupsMetric')
        await ck(await page.locator('#certloom-analytics-days option').count()==4,'boundedPeriods')
        await ck(await page.locator('#certloom-analytics-days').input_value()=='30','selected30Days')
        await ck('daily trend' in (await page.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'dailyTrendLabel')
        await ck(await page.locator('.certloom-analytics-chart').get_attribute('aria-label')=='Daily verification lookup totals','dailyChartAria')
        await ck(await page.locator('.certloom-analytics-table-wrap').get_attribute('aria-label')=='Daily verification trend table','dailyTableAria')
        await ck(await page.locator('.certloom-analytics-bar').count()==30,'dailyBuckets')
        await ck(await page.locator('.certloom-analytics-chart').evaluate('(e)=>e.classList.contains("is-verification")'),'verificationSeriesClass')
        await ck(await page.locator('.certloom-analytics-bar').first.evaluate('(e)=>getComputedStyle(e).backgroundColor')=='rgb(3, 205, 243)','verificationUsesLogoCyan')
        await ck(await page.locator('.certloom-analytics-metrics .is-success').evaluate('(e)=>getComputedStyle(e).borderTopColor')=='rgb(1, 193, 116)','successfulMetricUsesValidEmerald')
        await ck(await page.locator('.certloom-analytics-metrics .is-revoked').evaluate('(e)=>getComputedStyle(e).borderTopColor')=='rgb(150, 33, 74)','revokedMetricUsesRose')
        sources=await page.locator('.certloom-analytics-source-list').inner_text()
        await ck(all(x in sources for x in ['Manual / direct','QR scan','API','184','91','17']),'sourceBreakdown')
        privacy=(await page.locator('.certloom-analytics-sidebar').inner_text()).lower()
        await ck('raw ip addresses' in privacy and 'user-agents' in privacy and '730 days' in privacy,'privacyAndRetentionCopy')
        await ck(await page.get_by_role('heading',name='Most verified credentials').count()==1,'mostVerifiedHeading')
        await ck('CERT-2026-0007' in await page.locator('.certloom-analytics-most').inner_text(),'mostVerifiedCredential')
        await ck(await page.get_by_role('link',name='Open credential').count()==2,'credentialActions')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        weekly=await browser.new_page(viewport={'width':1100,'height':900})
        await weekly.set_content(fixture(90),wait_until='load')
        await ck(await weekly.locator('#certloom-analytics-days').input_value()=='90','selected90Days')
        await ck('weekly trend' in (await weekly.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'weeklyTrendLabel')
        await ck(await weekly.locator('.certloom-analytics-chart').get_attribute('aria-label')=='Weekly verification lookup totals','weeklyChartAria')
        await ck(await weekly.locator('.certloom-analytics-table-wrap').get_attribute('aria-label')=='Weekly verification trend table','weeklyTableAria')
        await ck((await weekly.locator('.certloom-analytics-table-wrap thead th').first.inner_text())=='Week starting','weeklyColumnHeading')
        await ck(await weekly.locator('.certloom-analytics-bar').count()==14,'weeklyBucketsBounded')
        await weekly.close()

        monthly=await browser.new_page(viewport={'width':1100,'height':900})
        await monthly.set_content(fixture(365),wait_until='load')
        await ck(await monthly.locator('#certloom-analytics-days').input_value()=='365','selected12Months')
        await ck('monthly trend' in (await monthly.locator('.certloom-analytics-trend .certloom-eyebrow').inner_text()).lower(),'monthlyTrendLabel')
        await ck(await monthly.locator('.certloom-analytics-chart').get_attribute('aria-label')=='Monthly verification lookup totals','monthlyChartAria')
        await ck(await monthly.locator('.certloom-analytics-table-wrap').get_attribute('aria-label')=='Monthly verification trend table','monthlyTableAria')
        await ck((await monthly.locator('.certloom-analytics-table-wrap thead th').first.inner_text())=='Month','monthlyColumnHeading')
        await ck(await monthly.locator('.certloom-analytics-bar').count()==13,'monthlyBucketsBounded')
        await monthly.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture(30),wait_until='load')
        cols=await mobile.locator('.certloom-analytics-metrics').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==2,'mobileMetricsTwoColumns')
        layout_cols=await mobile.locator('.certloom-analytics-layout').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(layout_cols==1,'mobileSingleColumnLayout')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileDocumentNoOverflow')
        region=mobile.locator('.certloom-analytics-table-wrap')
        await ck(await region.get_attribute('tabindex')=='0' and await region.get_attribute('role')=='region','focusableTrendRegion')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':760,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(30),wait_until='load')
        duration=await reduced.locator('.certloom-analytics-bar').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(float(duration.rstrip('s').split(',')[0])<=0.001,'reducedMotionDisablesTransitions')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':760,'height':700},forced_colors='active')
        await forced.set_content(fixture(30),wait_until='load')
        active=await forced.evaluate("matchMedia('(forced-colors: active)').matches")
        await ck(active,'forcedColorsActive')
        await ck(await forced.locator('.certloom-analytics-bar').count()==30,'forcedColorsKeepsTrendData')
        await forced.close();await browser.close()
    if failed:
        print('FAIL  Verification Analytics browser gate:',', '.join(failed));return 1
    print('PASS  Verification Analytics browser gate: summaries, daily/weekly/monthly trends, source attribution, privacy copy, most-verified table, responsive layout and accessibility')
    print(f'\n{checks} Verification Analytics browser checks, 0 failures')
    return 0
raise SystemExit(asyncio.run(run()))
