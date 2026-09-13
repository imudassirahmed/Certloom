#!/usr/bin/env python3
"""Real Chromium FLD-02 bounded Program/Batch lookup acceptance."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_scoped_context_lookup_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1100,'height':760})
        await page.set_content(fixture(),wait_until='load')
        program=page.locator('select[name="program_id"]'); batch=page.locator('select[name="batch_id"]'); template=page.locator('select[name="template_id"]')
        psearch=page.locator('select[name="program_id"] + .certloom-context-lookup__status').locator('xpath=preceding-sibling::input[1]')
        # Robustly locate generated searches by wrapper/kind order.
        searches=page.locator('.certloom-context-lookup__search')
        psearch=searches.nth(0); bsearch=searches.nth(1); tsearch=searches.nth(2); hsearch=searches.nth(3)
        await ck(await searches.count()==10,'tenInitialProgressiveSearches')
        await ck(await psearch.get_attribute('aria-label')=='Search Programs','programSearchAccessibleName')
        await ck(await bsearch.get_attribute('aria-label')=='Search Batches','batchSearchAccessibleName')
        await ck(await tsearch.get_attribute('aria-label')=='Search published Templates','templateSearchAccessibleName')
        await ck(await hsearch.get_attribute('aria-label')=='Search all Programs','historicalProgramSearchAccessibleName')
        await ck(await batch.is_disabled() and await bsearch.is_disabled(),'batchDisabledUntilProgram')
        await ck('Choose a Program first.' in await page.locator('.certloom-context-lookup__status').nth(1).inner_text(),'batchDependencyAnnounced')

        await psearch.fill('Scale')
        await page.wait_for_timeout(300)
        await ck(await program.locator('option[value="101"]').count()==1 and await program.locator('option[value="102"]').count()==1,'programServerResults')
        await ck('matches.' in await page.locator('.certloom-context-lookup__status').nth(0).inner_text(),'programResultCountAnnounced')
        await program.select_option('101')
        await program.dispatch_event('change')
        await page.wait_for_timeout(80)
        await ck(not await batch.is_disabled() and not await bsearch.is_disabled(),'batchEnabledAfterProgram')
        await ck(await batch.locator('option[value="501"]').count()==1 and await batch.locator('option[value="502"]').count()==1,'batchProgram101Results')
        await ck(await batch.locator('option[data-program-id="101"]').count()==2,'batchCarriesProgramOwnership')

        await batch.select_option('501')
        await program.select_option('102')
        await program.dispatch_event('change')
        await page.wait_for_timeout(80)
        await ck(await batch.input_value()=='0','programChangeClearsStaleBatchSelection')
        await ck(await batch.locator('option[value="601"]').count()==1 and await batch.locator('option[value="501"]').count()==0,'batchResultsReboundToProgram102')

        await tsearch.fill('modern'); await page.wait_for_timeout(300)
        await ck(await template.locator('option[value="801"]').count()==1 and await template.locator('option[value="802"]').count()==1,'templateServerResults')
        await ck(await template.locator('option[value="77"]').count()==1,'templatePreservesExistingSelection')
        await hsearch.fill('legacy'); await page.wait_for_timeout(300)
        calls_mid=await page.evaluate('window.__lookupCalls')
        await ck(any(c.get('kind')=='program' and c.get('program_status')=='all' for c in calls_mid),'historicalProgramFilterRequestsAllStatus')

        # Broader SCALE-01 relationship selectors share the same bounded widget.
        isearch=searches.nth(4); ssearch=searches.nth(5); scopesearch=searches.nth(6); tutorsearch=searches.nth(7); ldsearch=searches.nth(8); localesearch=searches.nth(9)
        await isearch.fill('ecapla'); await page.wait_for_timeout(300)
        await ck(await page.locator('select[name="issuer_id"] option[value="901"]').count()==1,'issuerCompleteRegistrySearch')
        await ssearch.fill('amina'); await page.wait_for_timeout(300)
        await ck(await page.locator('select[name="signer_id"] option[value="911"]').count()==1,'signerCompleteRegistrySearch')
        await scopesearch.fill('scale'); await page.wait_for_timeout(300)
        scope=page.locator('select[name="scope"]')
        await ck(await scope.locator('option[value="program:101"]').count()==1,'mixedSignerScopeStringValueRendered')
        await scope.select_option('program:101')
        await ck(await scope.input_value()=='program:101','mixedSignerScopeStringValueSelectable')
        await tutorsearch.fill('leadership'); await page.wait_for_timeout(300)
        await ck(await page.locator('select[name="tutor_course_id"] option[value="921"]').count()==1,'tutorCourseCompleteRegistrySearch')
        await ldsearch.fill('compliance'); await page.wait_for_timeout(300)
        await ck(await page.locator('select[name="learndash_course_id"] option[value="931"]').count()==1,'learnDashCourseCompleteRegistrySearch')
        await localesearch.fill('modern'); await page.wait_for_timeout(300)
        await ck(await page.locator('select[name="profile_template_variant_template_id[]"]').first.locator('option[value="801"]').count()==1,'localeVariantTemplateSearch')
        await page.locator('[data-certloom-add-locale-variant]').click()
        await page.wait_for_timeout(40)
        await ck(await page.locator('.certloom-context-lookup__search').count()==11,'dynamicLocaleVariantLookupInitialized')
        newrow=page.locator('.certloom-locale-variant-row').last
        await ck(await newrow.locator('.certloom-context-lookup__search').count()==1,'dynamicLocaleVariantGetsSearchWidget')

        # A slower earlier request must never overwrite a later result.
        await psearch.fill('slow'); await page.wait_for_timeout(235)
        await psearch.fill('fast'); await page.wait_for_timeout(260)
        await ck(await program.locator('option[value="302"]').count()==1,'latestAsyncResultKept')
        await ck(await program.locator('option[value="301"]').count()==0,'staleAsyncResultDiscarded')
        calls=await page.evaluate('window.__lookupCalls')
        await ck(any(c.get('kind')=='batch' and c.get('program_id')=='101' for c in calls),'batchRequestServerBoundToProgram')
        await ck(all(c.get('action')=='certloom_lookup_scoped_context' for c in calls),'boundedLookupActionOnly')
        await ck(any(c.get('kind')=='template' for c in calls),'publishedTemplateUsesBoundedLookup')
        await ck(any(c.get('kind')=='signer_scope' for c in calls) and any(c.get('kind')=='issuer' for c in calls) and any(c.get('kind')=='signer' for c in calls),'broaderRelationshipLookupsUseSameEndpoint')
        await ck(any(c.get('kind')=='tutor_course' for c in calls) and any(c.get('kind')=='learndash_course' for c in calls),'lmsCourseLookupsUseSameEndpoint')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':720})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('.fixture-grid').first.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')==1,'mobileSingleColumn')
        await ck(await mobile.locator('.certloom-context-lookup__search').first.is_visible(),'mobileSearchVisible')
        await mobile.close()

        forced=await browser.new_page(viewport={'width':720,'height':560},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-context-lookup__search').first.is_visible(),'forcedColorsSearchVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  FLD-02 scoped context Chromium gate:', ', '.join(failed)); return 1
    print('PASS  FLD-02 scoped context Chromium gate: bounded Program/Template search, Program-constrained Batch lookup, historical Program status policy, stale-response safety, accessible live state and responsive layout')
    print(f'\n{checks} scoped-context browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
