#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_integrations_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(v,label):
            nonlocal checks; checks+=1
            if not v: failed.append(label)
        html=fixture()
        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(html,wait_until='load')
        await ck(await page.get_by_role('heading',name='Integrations',exact=True).count()==1,'heading')
        text=await page.locator('.certloom-integrations-workspace').inner_text()
        for phrase,label in [
            ('Source confirms eligibility','sourceFlow'),
            ('Certloom resolves the Recipient','recipientFlow'),
            ('Issue once, automate next','issuanceFlow'),
            ('Explicit account or External ID — no name/email guessing','identityBoundary'),
            ('One Certloom credential, then optional delivery','singleCredential'),
            ('Tutor LMS','tutor'),('LearnDash','learndash'),('WooCommerce','woocommerce'),
            ('Coursera','coursera'),('Webhook / API','webhook'),
            ('Publishing destinations','destinations'),('Credly','credly'),
            ('Destinations never decide who earned a credential.','destinationBoundary')]:
            await ck(phrase in text,label)
        await ck(text.count('Not detected')==3,'threeLocalSourcesUnavailable')
        await ck('production credential-writing authority' not in text,'simpleHidesAuthorityJargon')
        await ck('Canonical credential first' not in text,'simpleHidesCanonicalJargon')
        await ck(await page.locator('#certloom-tutor-course').count()==0,'noMappingFormWithoutTutor')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(html,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.get_by_role('heading',name='Integrations',exact=True).is_visible(),'mobileHeadingVisible')
        await ck(await mobile.get_by_role('heading',name='Publishing destinations',exact=True).is_visible(),'mobileDestinationsVisible')
        await mobile.close()
        forced=await browser.new_page(viewport={'width':760,'height':700},forced_colors='active')
        await forced.set_content(html,wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.get_by_role('heading',name='Integrations',exact=True).is_visible(),'forcedColorsHeadingVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE Integrations browser gate:',', '.join(failed)); return 1
    print('PASS  UX-CORE Integrations browser gate: source → Recipient → credential → destination flow, Simple-language boundary, responsive layout and forced-colors behavior')
    print(f'\n{checks} Integrations browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
