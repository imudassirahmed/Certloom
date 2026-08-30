#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state='purpose'):
    env=os.environ.copy(); env['CERTLOOM_WIZ_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_setup_wizard_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.locator('.certloom-wizard-progress-step').count()==7,'sevenSteps')
        await ck('Purpose' in (await page.locator('.certloom-wizard-progress-step[aria-current="step"]').inner_text()),'purposeCurrent')
        await ck(await page.locator('input[name="workspace_mode"]:checked').get_attribute('value')=='simple','simpleDefault')
        enabled=page.locator('#certloom-wizard-subcategory option:not([disabled])')
        await ck(await enabled.count()==1 and await enabled.first.get_attribute('value')=='custom','customSubcategoryInitiallyBounded')
        await ck(await page.locator('[data-certloom-scheme-field]').evaluate('(e)=>e.hidden'),'schemeHiddenForCustom')
        await page.locator('#certloom-wizard-purpose').select_option('certification_standards'); await page.wait_for_timeout(30)
        enabled=page.locator('#certloom-wizard-subcategory option:not([disabled])')
        await ck(await enabled.count()==5,'certificationSubcategoryCount')
        await ck(not await page.locator('[data-certloom-scheme-field]').evaluate('(e)=>e.hidden'),'schemeShownForStandards')
        await ck(await page.locator('[data-certloom-wizard-summary-purpose]').inner_text()=='Certification & Standards','livePurposeSummary')
        await ck(await page.locator('[data-certloom-wizard-summary-type]').inner_text()=='Management systems','liveTypeSummary')
        await ck(await page.locator('[data-certloom-wizard-summary-terminology]').inner_text()=='Certification','liveTerminologySummary')
        await ck(await page.locator('[data-certloom-wizard-summary-design]').inner_text()=='Formal certification','liveDesignSummary')
        await page.locator('#certloom-wizard-purpose').select_option('training_courses'); await page.wait_for_timeout(30)
        enabled=page.locator('#certloom-wizard-subcategory option:not([disabled])')
        await ck(await enabled.count()==5 and await enabled.first.get_attribute('value')=='online_course','trainingSubcategoryBounded')
        await ck(await page.locator('[data-certloom-scheme-field]').evaluate('(e)=>e.hidden'),'schemeHiddenOutsideStandards')
        await ck(await page.locator('[data-certloom-wizard-summary-terminology]').inner_text()=='Course','trainingTerminologyLive')
        await ck(await page.locator('#save-later').evaluate('(e)=>e.form && e.form.id==="certloom-wizard-step-form"'),'saveLaterSubmitsCurrentForm')
        await ck(await page.locator('#save-later').get_attribute('name')=='wizard_finish_later','saveLaterHasServerIntent')
        await ck(await page.get_by_text('Program creation remains protected').count()==1,'programGateDisclosure')
        heights=await page.locator('.certloom-wizard-progress-step,.certloom-wizard-actions .button').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=40 for h in heights),'desktopTargetsUsable')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        adv=await browser.new_page(viewport={'width':1100,'height':780})
        await adv.set_content(fixture('advanced'),wait_until='load')
        await ck(await adv.locator('input[name="workspace_mode"]:checked').get_attribute('value')=='advanced','advancedModeReflectsCanonicalPreference')
        await adv.close()

        review=await browser.new_page(viewport={'width':1100,'height':780})
        await review.set_content(fixture('review'),wait_until='load')
        await ck(await review.locator('.certloom-wizard-review-table').count()==1,'reviewDiffTable')
        await ck(await review.get_by_text('Authority stays fail-closed').count()==1,'reviewAuthorityDisclosure')
        dialogs=[]
        review.on('dialog',lambda d:(dialogs.append(d.message),asyncio.create_task(d.dismiss())))
        await review.locator('#apply-review').click(); await review.wait_for_timeout(30)
        await ck(len(dialogs)==1 and 'reviewed setup changes' in dialogs[0].lower(),'applyReviewConfirmation')
        await review.close()

        completed=await browser.new_page(viewport={'width':1000,'height':760})
        await completed.set_content(fixture('completed'),wait_until='load')
        await ck(await completed.get_by_text('Setup reviewed').count()==1,'completedBadge')
        await ck(await completed.get_by_role('button',name='Review setup again').count()==1,'rerunAction')
        await ck(await completed.locator('.certloom-wizard-progress-step.is-skipped').count()==1,'skippedProgressDistinct')
        await completed.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoRootOverflow')
        await ck(await mobile.locator('.certloom-wizard-progress').evaluate('(e)=>e.scrollWidth>e.clientWidth'),'mobileProgressContainedScrollable')
        mobile_heights=await mobile.locator('.certloom-wizard-actions .button,#save-later,.certloom-wizard-progress-step').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in mobile_heights),'mobileTargets44')
        await ck(await mobile.locator('.certloom-wizard-layout').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length==1'),'mobileOneColumn')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':900,'height':760})
        await rtl.set_content(fixture('rtl'),wait_until='load')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await ck(await rtl.locator('.certloom-wizard-progress').get_attribute('aria-label')=='Setup progress','rtlSemanticProgress')
        await rtl.close()

        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        duration=await reduced.locator('.certloom-wizard-progress-step').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(duration in ('0s','0.00001s','0.001s') or duration.startswith('0.00'),'reducedMotion')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.certloom-wizard-progress').count()==1,'forcedColorsProgress')
        await ck(await forced.locator('.certloom-wizard-choice-row label').count()==2,'forcedColorsModeChoices')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  WIZ-01/02 browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  WIZ-01/02 browser gate: adaptive purpose flow, review confirmation, rerun, mobile, RTL, reduced motion and forced colors')
    print(f'\n{checks} WIZ-01/02 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
