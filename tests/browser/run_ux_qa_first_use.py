#!/usr/bin/env python3
"""UX-QA-01 first-use/current-authority end-to-end acceptance."""
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def build(name, env=None):
    e=os.environ.copy(); e.update(env or {})
    r=subprocess.run(['php',str(ROOT/'tests'/'browser'/name)],cwd=ROOT,env=e,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def main():
    failed=[]; checks=0
    async def ck(v,n):
        nonlocal checks; checks+=1
        if not v: failed.append(n)
    async with async_playwright() as pw:
        b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        # 1. Fresh Dashboard: understandable starting point, no migration console language.
        page=await b.new_page(viewport={'width':1280,'height':900})
        fresh=build('build_admin_dashboard_fixture.php',{'CERTLOOM_DASHBOARD_STATE':'fresh','CERTLOOM_DASHBOARD_ANALYTICS':'absent'})
        await page.set_content(fresh,wait_until='load')
        await ck(await page.get_by_role('heading',name='Dashboard',exact=True).is_visible(),'freshDashboard')
        await ck(await page.get_by_role('link',name='Setup wizard',exact=True).is_visible(),'freshSetupWizard')
        await ck(await page.get_by_text('Setup: 0 of 6 checks complete',exact=True).is_visible(),'freshCompactReadiness')
        await ck(await page.get_by_role('link',name='Review setup',exact=False).is_visible(),'freshReviewSetup')
        await ck(await page.get_by_role('link',name='Issue credential',exact=True).count()>=1,'freshIssueCredentialAvailable')
        safety=page.locator('details.certloom-dashboard-data-safety')
        await ck(await safety.count()==1,'freshDataSafety')
        await ck((await safety.get_attribute('open')) is None,'freshDataSafetyCollapsed')
        body=(await page.locator('body').inner_text()).lower()
        for phrase,label in [('current 1.5 registry','noVersionedRegistryJargon'),('credential series migration','noMigrationJargon'),('data-store switch','noStorageSwitchJargon'),('acceptance gate','noAcceptanceGateJargon')]:
            await ck(phrase not in body,label)
        await ck(await page.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'freshDesktopNoOverflow')
        await page.close()

        # 2. Adaptive Wizard: unified essentials-first setup, editable recommendations, protected Program gate.
        wiz=await b.new_page(viewport={'width':1100,'height':820})
        await wiz.set_content(build('build_setup_wizard_fixture.php'),wait_until='load')
        await ck(await wiz.locator('.certloom-wizard-progress-step').count()==7,'wizardSevenSteps')
        await ck(await wiz.locator('input[name="workspace_mode"]').count()==0,'wizardNoWorkspaceModeChoice')
        await ck(await wiz.get_by_text('Start with the essentials',exact=True).is_visible(),'wizardEssentialsFirst')
        await wiz.locator('#certloom-wizard-purpose').select_option('training_courses'); await wiz.wait_for_timeout(30)
        await ck(await wiz.locator('[data-certloom-wizard-summary-terminology]').inner_text()=='Course','wizardLiveRecommendation')
        await ck(await wiz.get_by_text('Program creation remains protected',exact=True).is_visible(),'wizardHonestProgramGate')
        await ck(await wiz.get_by_text('Recommendations are editable starting points, not compliance claims.',exact=True).is_visible(),'wizardNoComplianceClaim')
        await wiz.close()

        # 3. Post-Wizard Dashboard: transactional Wizard completion is the setup completion boundary; optional review checks cannot contradict it.
        post=await b.new_page(viewport={'width':1280,'height':900})
        html=build('build_admin_dashboard_fixture.php',{'CERTLOOM_DASHBOARD_STATE':'wizard_complete','CERTLOOM_DASHBOARD_ANALYTICS':'absent'})
        await post.set_content(html,wait_until='load')
        await ck(await post.get_by_text('Setup: 4 of 6 checks complete',exact=True).count()==0,'postWizardNoContradictoryFourOfSix')
        await ck(await post.locator('.certloom-setup-reminder').count()==0,'postWizardNoIncompleteReminder')
        await ck(await post.get_by_role('link',name='Setup wizard',exact=True).is_visible(),'postWizardPermanentSetupEntry')
        await ck(await post.locator('.certloom-setup-checklist').count()==0,'postWizardNoHeavyChecklist')
        await post.close()

        # 4. Current-authority first certificate: focused six-section create flow.
        cred=await b.new_page(viewport={'width':1280,'height':960})
        await cred.set_content(build('build_first_credential_fixture.php'),wait_until='load')
        nav=cred.locator('.certloom-workspace-nav a')
        await ck(await nav.count()==6,'credentialSixRelevantSections')
        await ck([x.strip() for x in await nav.all_inner_texts()]==['Overview','Recipient','Status','Custom Fields','Verification','Actions'],'credentialCreateOrder')
        await ck(await cred.locator('#certloom-workspace-program').count()==0 and await cred.locator('#certloom-workspace-activity').count()==0,'credentialNoDeadSections')
        await ck(await cred.get_by_role('button',name='Add credential',exact=True).is_visible(),'credentialPrimaryAction')
        await ck(await cred.get_by_role('link',name='Configure verification page',exact=True).is_visible(),'credentialVerificationHandoff')
        await cred.close()

        # 5. Public verification result: mixed-direction identity remains intact and utilities stay accessible.
        verify=await b.new_page(viewport={'width':900,'height':760})
        vh=build('build_feux_fixture.php').replace('Alexandria Noor Rahman','Alexandria نور Rahman')
        await verify.set_content(vh,wait_until='load')
        holder=verify.locator('.cvp-result-row dd').first
        await ck((await holder.inner_text()).strip()=='Alexandria نور Rahman','mixedDirectionNamePreserved')
        await ck(await holder.get_attribute('dir')=='auto','mixedDirectionUsesAuto')
        await ck(await verify.get_by_text('Valid',exact=True).is_visible(),'verificationStatusClear')
        actions=verify.locator('.certloom-result-action')
        await ck(min(await actions.evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)'))>=43.5,'verificationActions44')
        await ck(await verify.locator('.certloom-result-qr .certloom-verification-qr-code').count()==1,'verificationQrVisible')
        await verify.close()

        # Whole journey mobile sanity: fresh dashboard and first credential stay contained/touchable.
        mobile=await b.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(fresh,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'journeyFreshMobileContained')
        await mobile.set_content(build('build_first_credential_fixture.php'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'journeyCredentialMobileContained')
        heights=await mobile.evaluate("""() => [...document.querySelectorAll('.certloom-certificate-editor a.button,.certloom-certificate-editor button,.certloom-certificate-editor input:not([type=hidden]):not([type=checkbox]):not([type=radio]),.certloom-certificate-editor select,.certloom-workspace-nav a')].filter(el=>{const r=el.getBoundingClientRect();return r.width>0&&r.height>0}).map(el=>el.getBoundingClientRect().height)""")
        await ck(bool(heights) and min(heights)>=43.5,'journeyCredentialMobile44')
        await mobile.close(); await b.close()
    if failed:
        print('FAIL  UX-QA-01 first-use journey: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  UX-QA-01 first-use journey: operational Dashboard setup → Wizard → consistent completion → first credential → public verification')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
