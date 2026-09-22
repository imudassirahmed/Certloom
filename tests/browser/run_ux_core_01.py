#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_01_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(v,label):
            nonlocal checks; checks+=1
            if not v: failed.append(label)
        page=await browser.new_page(viewport={'width':1280,'height':900}); await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(100)
        nav=[(await page.locator('.test-nav a').nth(i).inner_text()).strip() for i in range(await page.locator('.test-nav a').count())]
        await ck(nav==['Home','Credentials','Programs','Design','Automations','Settings'],'simpleTaskNavigation')
        await ck('Legacy' not in ' '.join(nav),'legacyAbsentFromSimpleNavigation')
        await ck(await page.locator('.certloom-metric-grid-simple .certloom-metric-card').count()==4,'simpleDashboardFourHumanMetrics')
        await ck(await page.get_by_text('Recent credentials',exact=True).count()==1,'dashboardUsesCredentialLanguage')
        await ck(await page.locator('#dashboard').get_by_text('View credential',exact=True).count()==1,'dashboardDirectPublicAction')
        await ck(await page.locator('#dashboard').get_by_text('Manage',exact=True).count()==1,'dashboardDirectManageAction')
        metrics=await page.locator('.certloom-metric-grid-simple').bounding_box(); recent=await page.locator('.certloom-recent-panel').bounding_box()
        await ck(bool(metrics and recent and metrics['width']>800 and recent['width']>800),'dashboardUsesCalmWideSurfaces')
        await ck(await page.locator('.certloom-simple-program-card').count()==1,'programCollectionUsesCard')
        await ck(await page.locator('.certloom-simple-program-actions .button').count()==3,'programCardHasSetupDesignIssueContinuations')
        await ck(await page.locator('.certloom-program-flow-card').count()==4,'programWorkspaceConnectsFourSetupSteps')
        await ck(await page.get_by_text('Quick add',exact=True).count()==2,'programWorkspaceHasQuickEntry')
        await ck(await page.get_by_text('Issue credential',exact=True).count()>=2,'programWorkspaceContinuesToIssuance')
        pgrid=await page.locator('.certloom-program-flow-grid').bounding_box(); await ck(bool(pgrid and pgrid['width']>800),'programWorkspaceResponsiveDesktopGrid')
        await ck(await page.locator('#automations .certloom-template-card-grid .certloom-workspace-card').count()==2,'automationTemplatesPresentedAsReusableCatalog')
        await ck(await page.locator('#integrations > .certloom-integration-source-grid .certloom-integration-source-card').count()==5,'integrationsPresentedAsFiveQualificationSources')
        await ck(await page.locator('.certloom-integration-destination-grid .certloom-integration-destination-card').count()==1,'credlyPresentedSeparatelyAsDestination')
        await ck(await page.get_by_text('Publishing destinations',exact=True).count()==1,'destinationSectionHasHumanHeading')
        await ck(await page.get_by_text('Destinations run only after Certloom has issued the credential.',exact=True).count()==1,'automationsExplainsPostIssuanceDestinationBoundary')
        await ck(await page.get_by_text('Completion feeds canonical issuance.',exact=True).count()==2,'lmsSourcesExplainSingleIssuanceFlow')
        await ck(await page.locator('#credentials .certloom-simple-credential-grid').count()==1,'credentialsUsesRecipientAwareSimpleCollection')
        await ck(await page.locator('#credentials').get_by_text('View credential',exact=True).count()==1,'credentialContinuesDirectlyToPublicView')
        await ck(await page.locator('#credentials').get_by_text('Issue another',exact=True).count()==1,'credentialContinuesBackToIssuance')
        await ck(await page.locator('#delivery select[name="template_id"] option').count()==2,'manualDeliveryUsesReusableMessageCatalog')
        await ck(await page.locator('#delivery input[name="version_id"][type="hidden"]').count()==1,'manualDeliveryKeepsCredentialContextInternal')
        await ck(await page.locator('#delivery').get_by_text('Open Automations',exact=True).count()==1,'manualDeliveryContinuesIntoAutomations')
        await ck(await page.locator('#delivery input[name="subject"],#delivery textarea[name="html_body"]').count()==0,'manualDeliveryHasNoSecondTemplateEditor')
        await ck((await page.locator('.cvp-profile-recipient').inner_text())=='Ahmed Khan','publicProfileLeadsWithRecipient')
        await ck((await page.locator('.cvp-profile-program').inner_text())=='Professional Certificate','publicProfileLeadsWithProgram')
        await ck(not await page.locator('.cvp-profile-technical code').is_visible(),'credentialIdCollapsedByDefault')
        await page.locator('.cvp-profile-technical summary').click(); await ck(await page.locator('.cvp-profile-technical code').is_visible(),'credentialIdAvailableOnDemand')
        await ck(await page.get_by_text('Copy link',exact=True).count()==1,'publicActionsUseHumanLanguage')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'desktopNoHorizontalOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(100)
        await ck(await mobile.locator('.certloom-metric-grid-simple').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileMetricsStack')
        await ck(await mobile.locator('.certloom-simple-program-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileProgramCardsStack')
        await ck(await mobile.locator('.certloom-program-flow-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileProgramFlowStacks')
        await ck(await mobile.locator('.certloom-integration-source-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileIntegrationSourcesStack')
        await ck(await mobile.locator('.certloom-integration-destination-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileIntegrationDestinationsStack')
        await ck(await mobile.locator('.certloom-email-compose-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileEmailDeliveryStacks')
        await ck(await mobile.locator('.cvp-profile-facts').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobilePublicFactsStack')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileNoHorizontalOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-01 browser:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-CORE-01 browser: unified navigation, Dashboard, Program flow, Automations/Integrations and public profile form one responsive human workflow')
    print(f'\n{checks} UX-CORE-01 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
