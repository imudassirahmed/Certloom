#!/usr/bin/env python3
import asyncio, pathlib, subprocess
from playwright.async_api import async_playwright
ROOT=pathlib.Path(__file__).resolve().parents[2]
def fixture(): return subprocess.check_output(['php',str(ROOT/'tests/browser/build_design_rules_fixture.php')],cwd=ROOT,text=True)
async def main():
    failed=[]; checks=0
    async def ck(cond,name):
        nonlocal checks; checks+=1
        if not cond: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1280,'height':900}); await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(50)
        await ck(await page.get_by_role('heading',name='Design Rules').count()==1,'heading')
        await ck(await page.get_by_role('heading',name='Global defaults').count()==1,'globalDefaults')
        await ck(await page.locator('.certloom-design-rule-global-card').count()==2,'twoGlobalVisualCards')
        await ck(await page.get_by_text('Global → Issuer → Program → Batch',exact=True).count()==1,'hierarchyExplained')
        await ck(await page.get_by_text('Only Credential issued is an automatic trigger in this release.',exact=True).count()==1,'triggerHonesty')
        # Global changes must preview against global:0 rather than silently leaving stale HTML.
        global_cert=page.locator('.certloom-design-rules-global-form [data-certloom-rule-choice="certificate"]')
        await global_cert.select_option('design:12'); await page.wait_for_timeout(30)
        reqs=await page.evaluate('window.__requests')
        global_cert_reqs=[r for r in reqs if r.get('action')=='certloom_design_rules_preview' and r.get('kind')=='certificate' and 'global' in r.get('targets_json','')]
        await ck(bool(global_cert_reqs),'globalCertificateLivePreviewRequest')
        await ck('"scope_id":0' in global_cert_reqs[-1]['targets_json'],'globalZeroTargetAccepted')
        await ck(await page.locator('.certloom-design-rules-global-form [data-certloom-rule-preview="certificate"] strong').inner_text()=='Heritage','globalCertificatePreviewUpdated')
        global_purpose=page.locator('.certloom-design-rules-global-form [data-certloom-rule-purpose]')
        await global_purpose.select_option('credential_revoked'); await page.wait_for_timeout(30)
        global_email=page.locator('.certloom-design-rules-global-form [data-certloom-rule-choice="email"]')
        await ck(await global_email.locator('option').all_inner_texts()==['No explicit global design','Compliance revoked'],'globalEmailPurposeFiltersDesigns')
        # Bulk selection semantics.
        form=page.locator('.certloom-design-rules-form'); apply=page.locator('[data-certloom-rule-apply]')
        await ck(await apply.is_disabled(),'applyDisabledWithoutSelection')
        issuer1=page.locator('input[value="issuer:1"]'); await issuer1.check(); await page.wait_for_timeout(30)
        await ck((await page.locator('[data-certloom-rule-selected]').inner_text())=='1 selected','oneSelected')
        await ck(not await apply.is_disabled(),'applyEnabledWithSelection')
        await ck(await form.locator('[data-certloom-rule-preview="certificate"] footer').count()==1,'certificatePreviewAfterSelection')
        await ck(await form.locator('[data-certloom-rule-preview="email"] footer').count()==1,'emailPreviewAfterSelection')
        await page.locator('input[value="program:10"]').check(); await page.wait_for_timeout(30)
        await ck((await page.locator('[data-certloom-rule-selected]').inner_text())=='2 selected','bulkSelectionCount')
        await ck(await form.locator('.certloom-rule-preview-varies').count()>=1,'bulkVariesExplained')
        # Independent no-change/inherit/specific controls.
        cert_select=form.locator('[data-certloom-rule-choice="certificate"]'); email_select=form.locator('[data-certloom-rule-choice="email"]')
        await ck((await cert_select.locator('option').all_inner_texts())[:2]==['No change','Inherit from parent'],'certificateBulkSemantics')
        await ck((await email_select.locator('option').all_inner_texts())[:2]==['No change','Inherit from parent'],'emailBulkSemantics')
        await cert_select.select_option('design:12'); await page.wait_for_timeout(20)
        await ck(await form.locator('[data-certloom-rule-preview="certificate"] strong').inner_text()=='Heritage','specificCertificateLivePreview')
        # Edit is single-record focus, not another modal implementation.
        await page.locator('[data-certloom-rule-target]').nth(3).get_by_role('button',name='Edit').click(); await page.wait_for_timeout(30)
        await ck((await page.locator('[data-certloom-rule-selected]').inner_text())=='1 selected','editReducesToSingleRecord')
        await ck(await page.locator('[data-certloom-rule-target] input:checked').count()==1,'singleCheckboxAfterEdit')
        # Load more and selection persistence.
        await issuer1.check(); await page.wait_for_timeout(20)
        await page.locator('[data-certloom-rule-group="issuer"] [data-certloom-rule-load-more]').click(); await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-rule-list="issuer"] [data-certloom-rule-target]').count()==3,'loadMoreAppends')
        await ck(await page.locator('input[value="issuer:1"]').is_checked(),'loadMorePreservesSelection')
        # Server-backed search replaces rows but preserves off-screen selections.
        search=page.locator('[data-certloom-rule-search]'); await search.fill('Alpha'); await page.wait_for_timeout(360)
        await ck(await page.locator('[data-certloom-rule-list="issuer"] strong',has_text='Alpha Academy').count()==1,'serverSearchIssuer')
        await ck(await page.locator('[data-certloom-rule-list="program"] strong',has_text='Alpha Leadership').count()==1,'serverSearchProgram')
        await ck(await page.locator('[data-certloom-rule-list="batch"] strong',has_text='Alpha Cohort').count()==1,'serverSearchBatch')
        await ck((await page.locator('[data-certloom-rule-selected]').inner_text())=='2 selected','offscreenSelectionsPersist')
        # Add a searched target and ensure submit serializes all Set members, even hidden/offscreen ones.
        await page.locator('input[value="program:12"]').check(); await page.wait_for_timeout(20)
        await apply.click(); await page.wait_for_timeout(20)
        hidden=await form.locator('[data-certloom-rule-selection-hidden]').evaluate_all('(els)=>els.map(e=>e.value).sort()')
        await ck(hidden==['issuer:1','program:11','program:12'],'submissionPreservesAllSelectedKeys')
        await page.locator('[data-certloom-rule-clear]').click();
        await ck((await page.locator('[data-certloom-rule-selected]').inner_text())=='0 selected','clearSelection')
        await ck(await apply.is_disabled(),'clearDisablesApply')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        # Sticky editor on desktop.
        await ck(await page.locator('[data-certloom-rule-editor]').evaluate('(e)=>getComputedStyle(e).position')=='sticky','desktopStickyEditor')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(30)
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('[data-certloom-rule-editor]').evaluate('(e)=>getComputedStyle(e).position')=='static','mobileEditorInFlow')
        await ck(all(h>=40 for h in await mobile.locator('.certloom-design-rule-target .button').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')),'mobileEditTargets')
        await mobile.close()
        rtl=await browser.new_page(viewport={'width':900,'height':800}); await rtl.set_content(fixture(),wait_until='load'); await rtl.locator('html').evaluate("e=>e.setAttribute('dir','rtl')")
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow'); await rtl.close()
        reduced=await browser.new_page(viewport={'width':900,'height':800},reduced_motion='reduce'); await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionActive'); await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':800},forced_colors='active'); await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-design-rule-editor').is_visible(),'forcedColorsEditorVisible'); await forced.close()
        await browser.close()
    if failed:
        print('FAIL  DRUX-01 Design Rules browser gate:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  DRUX-01 Design Rules browser gate: global live previews, independent bulk semantics, persistent server search/load-more selection and responsive accessibility')
    print(f'\n{checks} DRUX-01 Design Rules browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
