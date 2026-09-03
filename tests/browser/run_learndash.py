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
        await ck(await page.get_by_text('Every source uses the same Recipient → Program → credential workflow.',exact=False).count()>=1,'sharedHumanWorkflow')
        await ck(await page.get_by_text('Explicit account or External ID — no name/email guessing',exact=True).count()==1,'explicitIdentity')
        cards=page.locator('.certloom-integration-source-card')
        ld_card=cards.filter(has_text='LearnDash').first
        tutor_card=cards.filter(has_text='Tutor LMS').first
        await ck(await ld_card.get_by_text('Available',exact=True).count()==1,'learndashAvailable')
        await ck('1' in await ld_card.locator('.certloom-integration-source-meta').inner_text(),'sourceMappingCount')
        await ck(await tutor_card.get_by_text('Not detected',exact=True).count()==1,'tutorNotDetected')
        setup=page.locator('#certloom-learndash-setup')
        await ck(await setup.locator('#certloom-learndash-title').inner_text()=='LearnDash','learndashSetupHeading')
        await ck(await setup.get_by_text('Map a LearnDash course to a Program and optional Batch. The certificate design follows the shared inheritance hierarchy automatically.',exact=True).count()==1,'setupPurpose')
        course=setup.locator('select[name="course_id"]')
        program=setup.locator('select[name="program_id"]')
        batch=setup.locator('select[name="batch_id"]')
        await ck(await course.count()==1 and await course.is_enabled(),'courseSelect')
        await ck(await program.count()==1 and await program.is_enabled(),'programSelect')
        await ck(await batch.count()==1 and await batch.is_enabled(),'batchSelect')
        await ck(await setup.locator('select[name="template_id"]').count()==0,'noIntegrationTemplateSelect')
        await ck(await course.locator('option').count()==3,'courseOptions')
        await ck(await program.locator('option').count()==3,'programOptions')
        await ck(await batch.locator('option').count()>=1,'batchOptions')
        await ck(await course.get_attribute('data-certloom-context-lookup')=='learndash_course','courseServerLookup')
        await ck(await program.get_attribute('data-certloom-context-lookup')=='program','programServerLookup')
        await ck(await batch.get_attribute('data-certloom-context-lookup')=='batch','batchServerLookup')
        await ck(await page.get_by_text('Global → Issuer → Program → Batch',exact=False).count()>=1,'designHierarchyExplained')
        await ck(await setup.get_by_text('Issue automatically after course completion',exact=True).count()==1,'automaticIssuanceChoice')
        await ck(await setup.get_by_role('button',name='Save mapping').count()==1,'saveButton')
        await ck(await setup.get_by_text('LearnDash Professional Course',exact=True).count()>=2,'mappingCourseLabel')
        summary=(await setup.inner_text()).lower()
        await ck('professional certification program' in summary and 'design follows hierarchy' in summary,'mappingSummary')
        await ck('legacy design fallback retained until this mapping is re-saved' in summary,'legacyFallbackDisclosure')
        await ck(await setup.get_by_text('On',exact=True).count()==1,'mappingEnabled')
        await ck(await setup.get_by_role('button',name='Remove').count()==1,'removeButton')
        body=(await page.locator('body').inner_text()).lower()
        await ck('supported lms adapters' not in body,'noLegacyAdapterContract')
        await ck('production credential-writing checks' not in body and 'automatic issuance remains safely locked' not in body,'noAuthorityJargonInSimple')
        await ck('match by email' not in body,'noEmailInference')
        await ck('destinations never decide who earned a credential' in body,'sourceDestinationBoundary')
        await ck(await setup.locator('.certloom-technical-details').count()==1 and not await setup.locator('.certloom-technical-details').get_attribute('open'),'technicalStatusProgressivelyDisclosed')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(html,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await ck(await mobile.locator('#certloom-learndash-title').is_visible(),'mobileLearnDashVisible')
        await ck(await mobile.locator('#certloom-learndash-setup select[name="course_id"]').is_visible(),'mobileCourseSelect')
        save=mobile.locator('#certloom-learndash-setup').get_by_role('button',name='Save mapping')
        await ck(await save.is_visible(),'mobileSaveVisible')
        await ck((await save.bounding_box())['height'] >= 44,'mobileSaveTarget')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':760,'height':900})
        await rtl.set_content(html.replace('<html lang="en">','<html lang="ar" dir="rtl">'),wait_until='load')
        await ck(await rtl.evaluate("document.documentElement.dir === 'rtl'"),'rtlDirection')
        await ck(await rtl.locator('#certloom-learndash-title').is_visible(),'rtlLearnDashVisible')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await rtl.close()

        forced=await browser.new_page(viewport={'width':760,'height':900},forced_colors='active')
        await forced.set_content(html,wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('#certloom-learndash-setup').get_by_role('button',name='Save mapping').is_visible(),'forcedColorsSaveVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  INT-03 LearnDash browser gate:',', '.join(failed)); return 1
    print('PASS  INT-03 LearnDash browser gate: source-first mapping, explicit Recipient identity, scalable lookups, progressive-disclosure language, responsive layout, RTL and forced colors')
    print(f'\n{checks} INT-03 LearnDash browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
