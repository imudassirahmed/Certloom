#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_CREDENTIAL_MANAGEMENT_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_credential_management_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        registry=await browser.new_page(viewport={'width':1440,'height':1000})
        await registry.set_content(fixture('registry'),wait_until='load')
        await ck(await registry.locator('.certloom-domain-metric').count()==5,'registryFiveMetrics')
        await ck(await registry.locator('.certloom-credential-views a').count()==10,'registryStatusViews')
        await ck((await registry.locator('.certloom-credential-views .is-current').inner_text()).startswith('All'),'registryCurrentAll')
        await ck(await registry.locator('input[type="search"]').count()==1,'registrySearch')
        await ck(await registry.locator('select[name="program_id"]').count()==1,'registryProgramFilter')
        await ck(await registry.locator('.certloom-credential-status.is-valid').count()==1,'registryStatusBadge')
        await ck(await registry.locator('.certloom-credential-source.is-credential').count()==1,'registryCredentialSourceLabel')
        await ck(await registry.locator('.certloom-credential-source.is-legacy').count()==1,'registryLegacySourceLabel')
        await ck(await registry.locator('.certloom-credential-status.is-revoked').count()==1,'registryHistoricalUsesCanonicalRevokedStatus')
        await ck(await registry.locator('thead th').all_inner_texts()==['Credential','Recipient','Program / batch','Issue date','Expiry date','Credential status','Record version','Added to Certloom'],'registryExplicitColumns')
        await ck(await registry.locator('.certloom-credential-row-actions a').filter(has_text='View').count()==2,'registryViewActionsUnderTitle')
        await ck(await registry.get_by_role('link',name='Manage').count()==2,'registryManageActionsUnderTitle')
        await ck(await registry.locator('.certloom-credential-row-actions').count()==2,'registryModernRowActions')
        await ck(await registry.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'registryDesktopNoOverflow')
        await registry.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture('registry'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'registryMobileNoDocumentOverflow')
        await ck(await mobile.locator('.certloom-domain-table-wrap').evaluate('(e)=>e.scrollWidth >= e.clientWidth'),'registryTableOwnsHorizontalOverflow')
        metric_cols=await mobile.locator('.certloom-domain-metrics').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(metric_cols==1,'registryMobileMetricsSingleColumn')
        await mobile.close()

        detail=await browser.new_page(viewport={'width':1100,'height':900})
        await detail.set_content(fixture('detail'),wait_until='load')
        await ck(await detail.locator('.certloom-credential-tabs .nav-tab').count()==8,'detailEightWorkspaceTabs')
        await ck(await detail.locator('.certloom-credential-card-grid .certloom-credential-info-card').count()==4,'detailFourOverviewCards')
        await ck(await detail.locator('.certloom-credential-snapshot').count()==1,'detailSnapshotProof')
        await ck('gated' in (await detail.locator('.certloom-credential-snapshot').inner_text()).lower(),'detailArtifactAuthorityHonest')
        await ck(await detail.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'detailNoOverflow')
        await detail.close()

        historical=await browser.new_page(viewport={'width':800,'height':900})
        await historical.set_content(fixture('historical'),wait_until='load')
        await ck('Historical Version selected' in await historical.locator('.notice-info').inner_text(),'historicalReadOnlyNotice')
        await ck('historical' in (await historical.locator('.certloom-credential-eyebrow').inner_text()).lower(),'historicalHeroState')
        await ck(await historical.get_by_role('link',name='Return to current Version').count()==1,'historicalReturnCurrentAction')
        await ck(await historical.get_by_role('button',name='Confirm lifecycle action').count()==0,'historicalNoLifecycleMutation')
        await historical.close()

        lifecycle=await browser.new_page(viewport={'width':800,'height':900})
        await lifecycle.set_content(fixture('lifecycle_review'),wait_until='load')
        await ck((await lifecycle.locator('.certloom-credential-review-badge').inner_text()).lower()=='review required','lifecycleReviewBadge')
        await ck('issued / active' in await lifecycle.locator('.certloom-credential-review').inner_text(),'lifecycleBeforeState')
        await ck('issued / suspended' in await lifecycle.locator('.certloom-credential-review').inner_text(),'lifecycleAfterState')
        await ck(await lifecycle.locator('input[name="lifecycle_review"][type="hidden"]').count()==1,'lifecycleIntegrityPayload')
        await ck(await lifecycle.get_by_role('button',name='Confirm lifecycle action').count()==1,'lifecycleExplicitConfirm')
        await ck(await lifecycle.locator('textarea').count()==0,'lifecycleReasonNotEditableAfterReview')
        await lifecycle.close()

        relationship=await browser.new_page(viewport={'width':800,'height':900})
        await relationship.set_content(fixture('relationship_review'),wait_until='load')
        await ck('successor immutable version' in (await relationship.locator('.certloom-credential-panel').inner_text()).lower(),'relationshipImmutableSuccessorCopy')
        await ck(await relationship.locator('input[name="relationship_review"][type="hidden"]').count()==1,'relationshipIntegrityPayload')
        await ck('Corrected enrollment' in await relationship.locator('.certloom-credential-review').inner_text(),'relationshipFrozenPublicReason')
        await ck('Approved by registrar' in await relationship.locator('.certloom-credential-review').inner_text(),'relationshipFrozenInternalNote')
        await ck(await relationship.get_by_role('button',name='Confirm and create successor Version').count()==1,'relationshipExplicitConfirm')
        await ck(await relationship.locator('textarea').count()==0,'relationshipReasonNotEditableAfterReview')
        await relationship.close()

        reduced=await browser.new_page(viewport={'width':360,'height':780},reduced_motion='reduce')
        await reduced.set_content(fixture('detail'),wait_until='load')
        await ck(await reduced.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reducedMotionMobileNoOverflow')
        await reduced.close(); await browser.close()
    if failed:
        print('FAIL  Credential Management browser gate:', ', '.join(failed)); return 1
    print('PASS  Credential Management browser gate: responsive registry/workspace, immutable-history cues, reviewed lifecycle/correction confirmation and authority honesty')
    print(f'\n{checks} Credential Management browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
