#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_learndash_fixture.php')],cwd=ROOT,capture_output=True,text=True)
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
        page=await browser.new_page(viewport={'width':1280,'height':1000})
        await page.set_content(html,wait_until='load')
        await ck(await page.get_by_role('heading',name='Integrations').count()==1,'heading')
        await ck(await page.get_by_text('INT-02/03 · LMS adapters',exact=True).count()==1,'contract')
        await ck(await page.get_by_role('heading',name='LearnDash',exact=True).count()==1,'learndashHeading')
        await ck(await page.get_by_text('Detected',exact=True).count()==1,'detected')
        await ck(await page.get_by_text('Not installed',exact=True).count()==1,'tutorNotInstalled')
        await ck(await page.get_by_text('Automatic issuance remains safely locked',exact=True).count()==1,'authorityLock')
        await ck(await page.get_by_text('LearnDash 4.25.1 is available. Certloom currently has 1 enabled course mapping(s).',exact=True).count()==1,'versionAndCount')
        course=page.locator('#certloom-learndash-course')
        program=page.locator('#certloom-learndash-program')
        template=page.locator('#certloom-learndash-template')
        await ck(await course.count()==1 and await course.is_enabled(),'courseSelect')
        await ck(await program.count()==1 and await program.is_enabled(),'programSelect')
        await ck(await template.count()==1 and await template.is_enabled(),'templateSelect')
        await ck(await course.locator('option').count()==3,'courseOptions')
        await ck(await program.locator('option').count()==3,'programOptions')
        await ck(await template.locator('option').count()==3,'templateOptions')
        await ck(await page.get_by_role('button',name='Save course mapping').count()==1,'saveButton')
        await ck(await page.get_by_text('LearnDash Professional Course',exact=True).count()>=2,'mappingCourseLabel')
        await ck(await page.get_by_text('Professional Certification Program',exact=True).count()>=2,'mappingProgramLabel')
        await ck(await page.get_by_text('Executive Landscape',exact=True).count()>=2,'mappingTemplateLabel')
        await ck(await page.get_by_text('Enabled',exact=True).count()==1,'mappingEnabled')
        await ck(await page.get_by_role('button',name='Remove').count()==1,'removeButton')
        body=(await page.locator('body').inner_text()).lower()
        await ck('match by email' not in body,'noEmailInference')
        await ck('write-authority acceptance gate' in body,'authorityTruth')
        await ck('learner names, email addresses and arbitrary lms metadata are not copied' in body,'privacyCopy')
        await ck('tutor lms and learndash share one issuance architecture' in body,'sharedArchitecture')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await ck(await mobile.get_by_role('heading',name='LearnDash',exact=True).is_visible(),'mobileLearnDashVisible')
        await ck(await mobile.locator('#certloom-learndash-course').is_visible(),'mobileCourseSelect')
        save=mobile.get_by_role('button',name='Save course mapping')
        await ck(await save.is_visible(),'mobileSaveVisible')
        await ck((await save.bounding_box())['height'] >= 44,'mobileSaveTarget')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':760,'height':900})
        await rtl.set_content(html.replace('<html lang="en">','<html lang="ar" dir="rtl">'),wait_until='load')
        await ck(await rtl.evaluate("document.documentElement.dir === 'rtl'"),'rtlDirection')
        await ck(await rtl.get_by_role('heading',name='LearnDash',exact=True).is_visible(),'rtlLearnDashVisible')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await rtl.close()

        forced=await browser.new_page(viewport={'width':760,'height':900},forced_colors='active')
        await forced.set_content(html,wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.get_by_role('button',name='Save course mapping').is_visible(),'forcedColorsSaveVisible')
        await forced.close()
        await browser.close()
    if failed:
        print('FAIL  INT-03 LearnDash browser gate:',', '.join(failed)); return 1
    print('PASS  INT-03 LearnDash browser gate: mapping UI, authority truth, privacy, responsive layout, RTL and forced-colors behavior')
    print(f'\n{checks} INT-03 LearnDash browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
