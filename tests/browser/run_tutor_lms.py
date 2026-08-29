#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_tutor_lms_fixture.php')],cwd=ROOT,capture_output=True,text=True)
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
        page=await browser.new_page(viewport={'width':1280,'height':980})
        await page.set_content(html,wait_until='load')
        await ck(await page.get_by_role('heading',name='Integrations').count()==1,'heading')
        await ck(await page.get_by_text('INT-02/03 · LMS adapters',exact=True).count()==1,'contract')
        await ck(await page.get_by_role('heading',name='Tutor LMS',exact=True).count()==1,'tutorHeading')
        await ck(await page.get_by_text('Detected',exact=True).count()==1,'detected')
        await ck(await page.get_by_text('Automatic issuance remains safely locked',exact=True).count()==1,'authorityLock')
        await ck(await page.get_by_text('Tutor LMS 3.8.1 is available. Certloom currently has 1 enabled course mapping(s).',exact=True).count()==1,'versionAndCount')
        course=page.locator('#certloom-tutor-course')
        program=page.locator('#certloom-tutor-program')
        template=page.locator('#certloom-tutor-template')
        await ck(await course.count()==1 and await course.is_enabled(),'courseSelect')
        await ck(await program.count()==1 and await program.is_enabled(),'programSelect')
        await ck(await template.count()==1 and await template.is_enabled(),'templateSelect')
        await ck(await course.locator('option').count()==3,'courseOptions')
        await ck(await program.locator('option').count()==3,'programOptions')
        await ck(await template.locator('option').count()==3,'templateOptions')
        await ck(await page.get_by_role('button',name='Save course mapping').is_visible(),'saveButton')
        await ck(await page.get_by_text('Professional Certificate Course',exact=True).count()>=2,'mappingCourseLabel')
        await ck(await page.get_by_text('Professional Certification Program',exact=True).count()>=2,'mappingProgramLabel')
        await ck(await page.get_by_text('Executive Landscape',exact=True).count()>=2,'mappingTemplateLabel')
        await ck(await page.get_by_text('Enabled',exact=True).count()==1,'mappingEnabled')
        await ck(await page.get_by_role('button',name='Remove').count()==1,'removeButton')
        body=(await page.locator('body').inner_text()).lower()
        await ck('learner email' not in body and 'match by email' not in body,'noEmailInference')
        await ck('production credential-writing checks' in body,'authorityTruth')
        await ck('learner names, email addresses and unrelated lms data are not copied' in body,'privacyCopy')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await ck(await mobile.get_by_role('heading',name='Tutor LMS',exact=True).is_visible(),'mobileTutorVisible')
        await ck(await mobile.locator('#certloom-tutor-course').is_visible(),'mobileCourseSelect')
        await ck(await mobile.get_by_role('button',name='Save course mapping').is_visible(),'mobileSaveVisible')
        await ck((await mobile.get_by_role('button',name='Save course mapping').bounding_box())['height'] >= 44,'mobileSaveTarget')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':760,'height':900})
        await rtl.set_content(html.replace('<html lang="en">','<html lang="ar" dir="rtl">'),wait_until='load')
        await ck(await rtl.evaluate("document.documentElement.dir === 'rtl'"),'rtlDirection')
        await ck(await rtl.get_by_role('heading',name='Tutor LMS',exact=True).is_visible(),'rtlTutorVisible')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await rtl.close()

        forced=await browser.new_page(viewport={'width':760,'height':900},forced_colors='active')
        await forced.set_content(html,wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.get_by_role('button',name='Save course mapping').is_visible(),'forcedColorsSaveVisible')
        await forced.close()
        await browser.close()
    if failed:
        print('FAIL  INT-02 Tutor LMS browser gate:',', '.join(failed)); return 1
    print('PASS  INT-02 Tutor LMS browser gate: mapping UI, authority truth, privacy, responsive layout, RTL and forced-colors behavior')
    print(f'\n{checks} INT-02 Tutor LMS browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
