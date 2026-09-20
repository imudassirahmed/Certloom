#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture(name):
    r=subprocess.run(['php',str(ROOT/'tests/browser'/name)],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        # Credentials overview keeps its compact authenticated REST search contract.
        page=await browser.new_page(viewport={'width':1414,'height':900})
        await page.set_content(fixture('build_ui_phase4_credentials_overview_fixture.php'),wait_until='load'); await page.wait_for_timeout(80)
        main=page.locator('.certloom-credential-search-filter input[type="search"]'); psearch=page.locator('.certloom-credential-program-filter .certloom-context-lookup__search'); pselect=page.locator('.certloom-credential-program-filter select'); button=page.locator('.certloom-credential-filters > button[type="submit"]')
        heights=[await x.evaluate('(e)=>Math.round(e.getBoundingClientRect().height)') for x in [main,psearch,pselect,button]]
        await ck(max(heights)-min(heights)<=1 and heights[0]==42,'credentialFilterControlsShare42pxLine')
        tops=[await x.evaluate('(e)=>Math.round(e.getBoundingClientRect().top)') for x in [main,psearch,pselect,button]]
        await ck(max(tops)-min(tops)<=2,'credentialFilterControlsAlignVertically')
        await main.fill('amina'); await page.wait_for_timeout(340)
        await ck(await page.locator('.fixture-live-result').count()==1,'credentialTypingUpdatesResultsWithoutReload')
        await psearch.fill('mba'); await page.wait_for_timeout(300); calls=await page.evaluate('window.__restCalls')
        await ck(any('/admin/credentials' in c['url'] and 's=amina' in c['url'] for c in calls),'credentialSearchUsesAuthenticatedRestEndpoint')
        await ck(any('/admin/context' in c['url'] and 'kind=program' in c['url'] and 'query=mba' in c['url'] for c in calls),'programLookupUsesSharedRestEndpoint')
        await ck(all(c['options'].get('headers',{}).get('X-WP-Nonce')=='fixture-rest' for c in calls if '/wp-json/' in c['url']),'credentialsRestRequestsCarryWpNonce')
        await page.close()

        # 4.79 Issue Credential: compact workspace + sticky summary, all live context through REST.
        issue=await browser.new_page(viewport={'width':1440,'height':1000}); issue.set_default_timeout(6000)
        await issue.set_content(fixture('build_issue_credential_479_fixture.php'),wait_until='load'); await issue.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await issue.locator('.certloom-issue-v2-form').count()==0,'legacyOnePageIssueFormRemoved')
        cols=await issue.locator('.certloom-issue-app-body').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns')
        await ck(len(cols.split())==2,'issueAppUsesWorkspaceAndSummaryDesktopLayout')
        await ck(await issue.locator('[data-issue-step]').count()==6,'issueJourneyUsesSixFocusedSteps')
        await ck(await issue.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position')=='sticky','desktopSummaryStaysSticky')

        rec=issue.locator('[data-picker="recipient"] [data-picker-search]'); await rec.focus(); await issue.wait_for_selector('[data-picker="recipient"] [role="option"]'); await issue.locator('[data-picker="recipient"] [role="option"]').click(); await issue.locator('[data-step-next]').click()
        program=issue.locator('[data-picker="program"] [data-picker-search]'); await program.focus(); await issue.wait_for_selector('[data-picker="program"] [role="option"]'); await issue.locator('[data-picker="program"] [role="option"]',has_text='Program Alpha').click(); await issue.wait_for_function("window.__issueCalls.some(c=>c.url.includes('kind=batch')&&c.url.includes('program_id=1'))")
        batch=issue.locator('[data-picker="batch"] [data-picker-search]'); await batch.focus(); await issue.wait_for_selector('[data-picker="batch"] [role="option"]')
        await ck(await issue.locator('[data-picker="batch"] [role="option"]').count()==1 and 'Alpha Batch' in await issue.locator('[data-picker="batch"] [role="option"]').inner_text(),'programALoadsOnlyItsBatch')
        await issue.locator('[data-picker="batch"] [role="option"]').click()
        await program.fill('Beta'); await issue.wait_for_timeout(300); await issue.wait_for_selector('[data-picker="program"] [role="option"]'); await issue.locator('[data-picker="program"] [role="option"]',has_text='Program Beta').click()
        await ck((await issue.locator('[data-summary-batch]').inner_text()).strip() in ('','—'),'changingProgramClearsStaleBatchImmediately')
        await issue.wait_for_function("window.__issueCalls.some(c=>c.url.includes('kind=batch')&&c.url.includes('program_id=2'))"); await batch.focus(); await issue.wait_for_selector('[data-picker="batch"] [role="option"]')
        await ck(await issue.locator('[data-picker="batch"] [role="option"]').count()==1 and 'Beta Batch' in await issue.locator('[data-picker="batch"] [role="option"]').inner_text(),'programBLoadsOnlyItsBatch')
        await issue.locator('[data-picker="batch"] [role="option"]').click(); await ck('Beta Certificate' in await issue.locator('[data-summary-title]').inner_text(),'programSelectionPrefillsCredentialTitle')

        # Reach Certificate and prove assigned design preview is driven by current unsaved issuance state.
        await issue.locator('[data-step-next]').click(); title=issue.locator('[data-field="credential_title"]'); await ck(await title.input_value()=='Beta Certificate','programTitleRemainsDirectlyEditable')
        await title.fill('Beta Executive Credential'); await issue.locator('[data-scoped-input="address"]').fill('21 Clifton Road'); await issue.locator('[data-step-next]').click()
        await issue.locator('[data-presentation="design"]').click(); await issue.locator('[data-design-source="assigned"]').click()
        templ=issue.locator('[data-picker="template"] [data-picker-search]'); await templ.focus(); await issue.wait_for_selector('[data-picker="template"] [role="option"]'); await issue.locator('[data-picker="template"] [role="option"]').click(); await issue.wait_for_selector('[data-preview-document]')
        await ck('Beta Executive Credential' in await issue.locator('[data-preview-stage]').inner_text(),'previewUsesCurrentUnsavedTitle')
        await ck('21 Clifton Road' in await issue.locator('[data-preview-stage]').inner_text(),'previewUsesCurrentCredentialData')
        await ck('Assigned' in await issue.locator('[data-design-source-summary]').inner_text(),'assignedDesignProvenanceShownInline')
        await ck(await issue.locator('[data-preview-stage]').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1'),'previewStageDoesNotCreateHorizontalOverflow')
        icalls=await issue.evaluate('window.__issueCalls')
        await ck(any('/admin/issuance-preview' in c['url'] and c['body'].get('design_source')=='assigned' and c['body'].get('template_id')==77 for c in icalls),'assignedPreviewUsesIssuancePreviewRest')
        await ck(all(c['headers'].get('X-WP-Nonce')=='fixture-rest' for c in icalls if '/wp-json/' in c['url']),'issueRestRequestsCarryWpNonce')
        await issue.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture('build_issue_credential_479_fixture.php'),wait_until='load'); await mobile.wait_for_selector('[data-issue-body]:not([hidden])')
        await ck(await mobile.locator('.certloom-issue-summary').evaluate('(e)=>getComputedStyle(e).position')=='static','mobileSummaryReturnsToNormalFlow')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileIssueAppHasNoDocumentOverflow')
        await mobile.close()

        dash=await browser.new_page(viewport={'width':1280,'height':800}); css=(ROOT/'assets/css/certloom-tokens.css').read_text()+"\n"+(ROOT/'admin/assets/certloom-foundation.css').read_text(); await dash.set_content('<style>'+css+'</style><div class="certloom-admin-shell"><div class="certloom-dashboard-date-line is-expiry"><span class="certloom-dashboard-date-dot"></span></div></div>'); color=await dash.locator('.certloom-dashboard-date-dot').evaluate('(e)=>getComputedStyle(e).backgroundColor'); await ck(color=='rgb(192, 39, 31)','dashboardExpiryDotUsesErrorRed'); await dash.close(); await browser.close()
    if failed:
        print('FAIL  alpha.4.79 live-search/compact issuance browser gate:',', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  alpha.4.79 live-search/compact issuance browser gate: authenticated REST search, Program→Batch refresh, compact six-step issuance, assigned preview provenance and responsive summary')
    print(f'\n{checks} alpha.4.79 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
