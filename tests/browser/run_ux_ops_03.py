#!/usr/bin/env python3
import asyncio, base64, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]

def fixture(retry=False, saved=None):
    env=os.environ.copy()
    env['CERTLOOM_UXOPS_FIXTURE_RETRY']='1' if retry else '0'
    if saved is not None:
        env['CERTLOOM_UXOPS_FIXTURE_STORAGE']=base64.b64encode(saved.encode()).decode()
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_ops_03_fixture.php')],cwd=ROOT,text=True,capture_output=True,env=env)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def main():
    failed=[]; checks=0
    async def ck(value,name):
        nonlocal checks
        checks+=1
        if not value: failed.append(name)
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1180,'height':900})
        await page.set_content(fixture(),wait_until='load')

        top=page.locator('#toplevel_page_certloom')
        await ck(await top.evaluate("e=>e.classList.contains('wp-has-current-submenu')&&e.classList.contains('wp-menu-open')&&!e.classList.contains('wp-not-current-submenu')"),'parentOpen')
        await ck(await top.locator('a.menu-top').get_attribute('aria-current')=='page','parentAriaCurrent')
        await ck(await top.locator('.wp-submenu').get_attribute('hidden') is None,'submenuVisible')

        await page.locator('#program-title').fill('BSc Computer Science')
        await page.locator('#program-code').fill('BSCS')
        await page.locator('#program-active').uncheck()
        await page.locator('#program-mode').select_option(['portal','sharing'])
        await page.locator('#certloom-domain-editor form').evaluate('f=>f.requestSubmit()')
        raw=await page.evaluate("window.__certloomFixtureStorage.getItem('certloomDomainDraft:certloom_domain_create_program:new')")
        await ck(bool(raw) and 'checkable' in raw and 'multiple' in raw,'completeDraftStored')
        await page.set_content(fixture(True, raw),wait_until='load')
        await ck(await page.locator('#program-title').input_value()=='BSc Computer Science','textRestored')
        await ck(await page.locator('#program-code').input_value()=='BSCS','codeRestored')
        await ck(not await page.locator('#program-active').is_checked(),'uncheckedRestored')
        selected=await page.locator('#program-mode').locator('option:checked').all_text_contents()
        await ck(selected==['Portal','Sharing'],'multiSelectRestored')

        picker=page.locator('#issuer-color-picker')
        await picker.evaluate("e=>{e.value='#abcdef';e.dispatchEvent(new Event('input',{bubbles:true}))}")
        await ck(await page.locator('#issuer-color').input_value()=='#ABCDEF','colorUpdatesHidden')
        await ck(await page.locator('[data-certloom-color-label]').inner_text()=='#ABCDEF','colorLabelUpdates')
        await page.locator('[data-certloom-color-clear]').click()
        await ck(await page.locator('#issuer-color').input_value()=='','colorClears')
        await page.locator('[data-certloom-media-select]').first.click()
        await ck(await page.locator('[data-certloom-media-id]').first.input_value()=='77','mediaIdSelected')
        await ck('issuer-logo.png' in await page.locator('[data-certloom-media-preview]').first.inner_text(),'mediaFilenameShown')
        await page.locator('[data-certloom-media-clear]').first.click()
        await ck(await page.locator('[data-certloom-media-id]').first.input_value()=='','mediaClears')

        editor=page.locator('[data-certloom-scope-field-editor="program"]')
        await editor.locator('[data-certloom-scope-field-picker]').select_option('student_id')
        await editor.locator('[data-certloom-scope-field-add]').click()
        student=editor.locator('[data-certloom-scope-field-row="student_id"]')
        await ck(await student.is_visible(),'existingFieldAdded')
        await ck(await student.locator('[data-certloom-scope-field-rule]').input_value()=='optional','existingFieldDefaultsOptional')
        await student.locator('[data-certloom-scope-field-remove]').click()
        await ck(await student.is_hidden(),'existingFieldRemoved')
        await editor.locator('[data-certloom-quick-field-open]').click()
        dialog=editor.locator('[data-certloom-quick-field-dialog]')
        await ck(await dialog.evaluate('e=>e.open'),'quickDialogOpens')
        await dialog.locator('[data-certloom-quick-field-label]').fill('Graduation Year')
        await dialog.locator('[data-certloom-quick-field-type]').select_option('date')
        await dialog.locator('[data-certloom-quick-field-create]').click()
        created=editor.locator('[data-certloom-scope-field-row="graduation_year"]')
        await created.wait_for(state='visible')
        await ck('Personal · Private' in await created.inner_text(),'quickFieldPrivacyConservative')
        await ck(await created.locator('[data-certloom-scope-field-rule]').input_value()=='optional','quickFieldAddedOptional')
        await ck('Manage all fields' in await editor.inner_text(),'fieldManagerRouteVisible')

        success=page.locator('[data-card="success"]'); failure=page.locator('[data-card="failure"]')
        await success.locator('[data-certloom-card-publish]').click()
        await success.locator('[data-certloom-card-feedback]').filter(has_text='Design published').wait_for()
        await ck(await success.locator('.certloom-template-publish-state').inner_text()=='Published','publishStateUpdates')
        switch=success.locator('[role="switch"]')
        await ck(await switch.get_attribute('aria-checked')=='true','publishSwitchTurnsOn')
        await ck(await switch.get_attribute('data-certloom-card-unpublish') is not None,'publishedSwitchCanReturnToDraft')
        await switch.click()
        await success.locator('[data-certloom-card-feedback]').filter(has_text='Design moved to Draft').wait_for()
        await ck(await success.locator('.certloom-template-publish-state').inner_text()=='Draft','unpublishStateUpdates')
        await ck(await switch.get_attribute('aria-checked')=='false','publishSwitchTurnsOff')
        await failure.locator('[data-certloom-card-publish]').click()
        await failure.locator('[data-certloom-card-feedback]').filter(has_text='changed after you opened it').wait_for()
        await ck(await failure.locator('[data-certloom-card-publish]').is_enabled(),'failedPublishCanRetry')
        await ck('changed after you opened it' in await failure.locator('[data-certloom-card-feedback]').inner_text(),'exactPublishFailureShown')

        rules=page.locator('.certloom-design-rules')
        copy=(await rules.inner_text()).lower()
        await ck('fallback depth' not in copy and 'layout hash' not in copy,'designRulesAvoidInternalVocabulary')
        await ck('design rules' in copy and 'no change' in copy and 'inherit from parent' in copy,'designRulesUseHumanCopy')
        await ck('certificate design' in copy and 'email design' in copy,'designRulesKeepAuthoritiesVisibleAndIndependent')

        await ck(await page.get_by_role('heading',name='Setup complete',exact=True).is_visible(),'setupCompleteLanding')
        await ck(await page.get_by_role('button',name='Run setup again',exact=True).is_visible(),'setupRestartAction')
        await ck(await page.locator('.certloom-wizard-progress').count()==0,'completedSetupNoWizardProgress')

        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileContained')
        await ck(await mobile.locator('.certloom-template-library-filter-primary').evaluate("e=>getComputedStyle(e).gridTemplateColumns.split(' ').length===1"),'mobileFilterSingleColumn')
        await mobile.locator('[data-certloom-quick-field-open]').click(); await mobile.keyboard.press('Escape')
        await ck(not await mobile.locator('[data-certloom-quick-field-dialog]').evaluate('e=>e.open'),'quickDialogEscapeCloses')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':900,'height':700}); await rtl.set_content(fixture(),wait_until='load'); await rtl.evaluate("document.documentElement.dir='rtl'")
        await ck(await rtl.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'rtlContained'); await rtl.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active'); await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('[role="switch"]').count()==2,'forcedColorsActionsPresent'); await forced.close()
        await browser.close()
    if failed:
        print('FAIL  UX-OPS-03 browser gate: '+', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-OPS-03 browser gate: parent state, complete non-destructive retry, visual/media controls, scoped fields, in-card publishing, central Design Rules copy, completed Setup and responsive/RTL/forced-colors')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
