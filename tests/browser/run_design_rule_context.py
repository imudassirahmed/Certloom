#!/usr/bin/env python3
import asyncio, pathlib, subprocess
from playwright.async_api import async_playwright
ROOT=pathlib.Path(__file__).resolve().parents[2]
def fixture(): return subprocess.check_output(['php',str(ROOT/'tests/browser/build_design_rule_context_fixture.php')],cwd=ROOT,text=True)
async def main():
    failed=[]; checks=0
    async def ck(cond,name):
        nonlocal checks; checks+=1
        if not cond: failed.append(name)
    async with async_playwright() as p:
        b=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await b.new_page(viewport={'width':1200,'height':850}); await page.set_content(fixture(),wait_until='load')
        card=page.locator('.certloom-context-design-card'); opener=page.get_by_role('button',name='Change designs'); dialog=page.locator('[data-certloom-context-design-dialog]')
        await ck(await card.count()==1,'designsCard')
        await ck(await card.get_by_text('Executive',exact=True).count()==1,'certificateSummary')
        await ck(await card.get_by_text('Professional issued',exact=True).count()==1,'emailSummary')
        await ck(await card.get_by_text('Global → Issuer → Program → Batch',exact=False).count()==1,'hierarchyExplained')
        await ck('page=certloom-design-rules' in await dialog.locator('a',has_text='Open Design Rules').get_attribute('href'),'centralRulesDeepLink')
        await opener.click(); await page.wait_for_timeout(20)
        await ck(await dialog.evaluate('(d)=>d.open'),'dialogOpen')
        cert=dialog.locator('[data-context-choice="certificate"]')
        await ck(await cert.evaluate('(e)=>document.activeElement===e'),'certificateGetsInitialFocus')
        await cert.select_option('design:12'); await page.wait_for_timeout(30)
        await ck(await dialog.locator('[data-context-preview="certificate"] strong').inner_text()=='Heritage','certificateLivePreview')
        # Save certificate only: email must be sent as no_change rather than rewritten.
        await dialog.get_by_role('button',name='Save changes').click(); await page.wait_for_timeout(30)
        applies=[r for r in await page.evaluate('window.__requests') if r.get('action')=='certloom_design_rules_apply_ajax']
        await ck(applies[-1]['certificate_choice']=='design:12','certificateMutationSubmitted')
        await ck(applies[-1]['email_choice']=='no_change','untouchedEmailIsNoChange')
        await ck(await card.locator('[data-context-summary-label="certificate"]').inner_text()=='Heritage','certificateSummaryUpdatedInPlace')
        await ck(await card.locator('[data-context-summary-source="certificate"]').inner_text()=='Direct Program rule','certificateSourceUpdatedInPlace')
        await ck(await dialog.locator('[data-context-feedback]').inner_text()=='Designs updated.','saveFeedback')
        # Change purpose: choices must be rebuilt from purpose-compatible published designs only.
        purpose=dialog.locator('[data-context-purpose]'); await purpose.select_option('credential_revoked'); await page.wait_for_timeout(30)
        email=dialog.locator('[data-context-choice="email"]')
        await ck(await email.locator('option').all_inner_texts()==['Inherit from parent','Compliance revoked'],'purposeCompatibleEmailChoices')
        await email.select_option('design:email-revoked'); await page.wait_for_timeout(30)
        await ck(await dialog.locator('[data-context-preview="email"] strong').inner_text()=='Compliance revoked','emailLivePreview')
        await dialog.get_by_role('button',name='Save changes').click(); await page.wait_for_timeout(30)
        applies=[r for r in await page.evaluate('window.__requests') if r.get('action')=='certloom_design_rules_apply_ajax']
        await ck(applies[-1]['certificate_choice']=='no_change','previousCertificateNotRewritten')
        await ck(applies[-1]['email_choice']=='design:email-revoked','emailMutationSubmitted')
        # Issued summary must not be overwritten by editing another purpose.
        await ck(await card.locator('[data-context-summary-label="email"]').inner_text()=='Professional issued','issuedSummaryStableWhenEditingRevoked')
        # Second save without changes is local feedback; no apply request.
        before=len(applies); await dialog.get_by_role('button',name='Save changes').click(); await page.wait_for_timeout(20)
        after=len([r for r in await page.evaluate('window.__requests') if r.get('action')=='certloom_design_rules_apply_ajax'])
        await ck(after==before,'noChangeDoesNotWrite')
        await ck(await dialog.locator('[data-context-feedback]').inner_text()=='No design changes to save.','noChangeFeedback')
        # Failed mutation stays explicit and does not update summary.
        await purpose.select_option('credential_issued'); await page.wait_for_timeout(20); await email.select_option('inherit'); await page.wait_for_timeout(20)
        await page.evaluate('window.__failApply=true'); old=await card.locator('[data-context-summary-label="email"]').inner_text(); await dialog.get_by_role('button',name='Save changes').click(); await page.wait_for_timeout(30)
        await ck(await dialog.locator('[data-context-feedback]').inner_text()=='Simulated save failure','errorFeedback')
        await ck('is-error' in (await dialog.locator('[data-context-feedback]').get_attribute('class') or ''),'errorStateClass')
        await ck(await card.locator('[data-context-summary-label="email"]').inner_text()==old,'failedSaveDoesNotUpdateSummary')
        await ck(await dialog.evaluate('(d)=>d.open'),'failedSaveKeepsDialogOpen')
        await page.evaluate('window.__failApply=false')
        # Closing returns focus and does not submit the surrounding Program form.
        await dialog.get_by_role('button',name='Close').click(); await page.wait_for_timeout(20)
        await ck(not await dialog.evaluate('(d)=>d.open'),'dialogClose')
        await ck(await opener.evaluate('(e)=>document.activeElement===e'),'focusRestoredToOpener')
        await ck(not bool(await page.evaluate('window.__entitySubmitted||false')),'entityFormNotSubmittedByDesignDialog')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'desktopNoOverflow')
        await page.close()
        mobile=await b.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.get_by_role('button',name='Change designs').click(); await mobile.wait_for_timeout(20)
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileNoOverflow')
        await ck(await mobile.locator('.certloom-context-design-summary').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileSummaryStacks')
        await ck(await mobile.locator('.certloom-context-design-dialog__body').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileDialogStacks')
        heights=await mobile.locator('.certloom-context-design-dialog__foot .button').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'mobileDialogActionsAtLeast44'); await mobile.close()
        rtl=await b.new_page(viewport={'width':900,'height':800}); await rtl.set_content(fixture(),wait_until='load'); await rtl.locator('html').evaluate("e=>e.setAttribute('dir','rtl')")
        await ck(await rtl.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'rtlNoOverflow'); await rtl.close()
        reduced=await b.new_page(viewport={'width':900,'height':800},reduced_motion='reduce'); await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionActive'); await reduced.close()
        forced=await b.new_page(viewport={'width':900,'height':800},forced_colors='active'); await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-context-design-card').is_visible(),'forcedColorsCardVisible'); await forced.close(); await b.close()
    if failed:
        print('FAIL  DRCUX-01 contextual Designs browser gate:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  DRCUX-01 contextual Designs browser gate: shared modal, purpose-aware previews, no-change mutation safety, in-place summaries, focus and responsive accessibility')
    print(f'\n{checks} DRCUX-01 contextual Designs browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
