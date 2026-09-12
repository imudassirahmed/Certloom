#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_program_profiles_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(value,label):
            nonlocal checks; checks+=1
            if not value: failed.append(label)

        page=await browser.new_page(viewport={'width':1220,'height':900})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Program profile').count()==1,'heading')
        await ck(await page.get_by_role('heading',name='Program defaults').count()==1,'defaultsHeading')
        await ck(await page.locator('#certloom-program-purpose').input_value()=='training_courses','purposeCurrent')
        await ck(await page.locator('#certloom-program-subcategory').input_value()=='corporate_training','subcategoryCurrent')
        state=await page.locator('#certloom-program-subcategory option').evaluate_all('(els)=>els.map(o=>({purpose:o.dataset.purpose,hidden:o.hidden,disabled:o.disabled,selected:o.selected}))')
        await ck(all((not x['hidden'] and not x['disabled']) if x['purpose']=='training_courses' else (x['hidden'] and x['disabled']) for x in state),'initialSubcategoriesFiltered')
        await page.locator('#certloom-program-purpose').select_option('academic_credentials')
        await ck(await page.locator('#certloom-program-subcategory').input_value()=='degree','purposeChangeSelectsFirstValid')
        state2=await page.locator('#certloom-program-subcategory option').evaluate_all('(els)=>els.map(o=>({purpose:o.dataset.purpose,hidden:o.hidden,disabled:o.disabled}))')
        await ck(all((not x['hidden'] and not x['disabled']) if x['purpose']=='academic_credentials' else (x['hidden'] and x['disabled']) for x in state2),'changedSubcategoriesFiltered')
        labels=await page.locator('.certloom-program-profile label[for]').evaluate_all('(els)=>els.map(l=>l.htmlFor)')
        label_ok=True
        for target in labels:
            if await page.locator('#'+target).count()!=1:
                label_ok=False; break
        await ck(label_ok,'labelsResolveToControls')
        await ck(await page.get_by_role('heading',name='Credential lifecycle').count()==1,'lifecycleHeading')
        await ck(await page.locator('#certloom-program-validity-mode').input_value()=='duration','durationPolicyCurrent')
        await ck(await page.locator('#certloom-program-renewal').input_value()=='required','renewalPolicyCurrent')
        await ck(await page.locator('#certloom-program-repeat-policy').input_value()=='lifecycle','repeatPolicyCurrent')
        inline_cols=await page.locator('.certloom-inline-fields').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(inline_cols==2,'durationAmountAndUnitStayCompactOnDesktop')
        await page.locator('#certloom-program-validity-mode').select_option('permanent')
        await ck(await page.locator('[data-certloom-program-validity-duration]').is_hidden(),'permanentHidesDuration')
        await ck(await page.locator('#certloom-program-renewal').is_disabled() and await page.locator('#certloom-program-renewal').input_value()=='not_applicable','permanentDisablesRenewalCoherently')
        await page.locator('#certloom-program-validity-mode').select_option('duration')
        await ck(await page.locator('[data-certloom-program-validity-duration]').is_visible() and not await page.locator('#certloom-program-renewal').is_disabled(),'durationRestoresLifecycleControls')
        await ck(await page.get_by_text('Verification summary',exact=True).count()==1,'safeVisibilitySemanticsVisible')
        await ck(await page.get_by_text('Sequential',exact=True).count()==1,'numberingInheritanceVisible')
        await ck(await page.get_by_text('Academic Formal Landscape',exact=True).count()==1,'resolvedDesignVisible')
        await ck(await page.get_by_text('Source: Program · published revision 7',exact=True).count()==1,'designProvenanceVisible')
        await ck(await page.get_by_role('link',name='Manage Program design').count()==1,'manageDesignAction')
        await ck('immutable snapshots' in (await page.locator('.certloom-domain-editor-head').inner_text()).lower(),'immutableTruthCopy')
        sizes=await page.locator('.certloom-program-profile select,.certloom-program-profile input[type="text"]').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(x>=40 for x in sizes),'profileFieldsAtLeast40')
        await ck((await page.get_by_role('link',name='Manage Program design').evaluate('(e)=>e.getBoundingClientRect().height'))>=40,'designActionAtLeast40')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        grid_children=mobile.locator('.certloom-program-profile .certloom-domain-form-grid > .certloom-domain-field')
        first_box=await grid_children.nth(0).bounding_box(); second_box=await grid_children.nth(1).bounding_box()
        await ck(bool(first_box and second_box and abs(first_box['x']-second_box['x'])<1),'mobileSingleColumnGrid')
        inline_mobile=await mobile.locator('.certloom-inline-fields').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(inline_mobile==1,'mobileDurationControlsStack')
        await ck(await mobile.get_by_role('heading',name='Published certificate design').is_visible(),'mobileDesignVisible')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':900,'height':760},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionActive')
        await ck(await reduced.locator('#certloom-program-subcategory').is_visible(),'reducedMotionProfileVisible')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':900,'height':760},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-program-design-default').is_visible(),'forcedColorsDesignVisible')
        await ck(await forced.locator('#certloom-program-purpose').is_visible(),'forcedColorsPurposeVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  PRG-02 Program Profiles browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  PRG-02 Program Profiles browser gate: purpose/subcategory progressive disclosure, accessible controls, design provenance, responsive layout and accessibility media')
    print(f'\n{checks} Program Profile browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
