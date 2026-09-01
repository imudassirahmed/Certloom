#!/usr/bin/env python3
"""UX-QA-01 advanced-admin/recovery journey acceptance."""
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def build(name,env=None):
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
        # 1. Advanced mode keeps the six-section shell while exposing diagnostics contextually.
        nav=await b.new_page(viewport={'width':1240,'height':800})
        await nav.set_content(build('build_admin_ux_fixture.php',{'CERTLOOM_ADMIN_UX_STATE':'advanced'}),wait_until='load')
        labels=await nav.locator('[data-primary-label]').all_inner_texts()
        await ck([x.strip() for x in labels]==['Home','Credentials','Programs','Design','Automations','Settings'],'advancedSixSectionShell')
        await ck(await nav.locator('.certloom-ui-mode-choice.is-current').inner_text()=='Advanced','advancedModeVisible')
        await ck(await nav.locator('.nav-tab').count()==8,'advancedContextDetailAvailable')
        await nav.close()

        # 2. Backup & Restore uses task language and bounded recovery controls.
        backup=await b.new_page(viewport={'width':1100,'height':900})
        await backup.set_content(build('build_backup_center_fixture.php'),wait_until='load')
        await ck(await backup.get_by_role('heading',name='Backup & Restore',exact=True).is_visible(),'backupRestoreHeading')
        await ck(await backup.get_by_role('heading',name='Your backups',exact=True).is_visible(),'backupInventory')
        await ck(await backup.get_by_role('link',name='Restore a backup',exact=True).is_visible(),'backupRestoreEntry')
        await ck(await backup.get_by_role('link',name='Import certificates',exact=True).is_visible(),'backupImportEntry')
        text=(await backup.locator('body').inner_text()).lower()
        await ck('source authority' not in text and 'analyze backup' not in text,'backupNoObsoleteJargon')
        await backup.close()

        # 3. A stalled restore never strands the administrator without a visible safe retry.
        restore=await b.new_page(viewport={'width':900,'height':700})
        await restore.set_content(build('build_restore_continuation_fixture.php'),wait_until='load')
        button=restore.locator('[data-certverify-continue-button]'); status=restore.locator('[data-certverify-auto-continue-status]')
        await ck(await button.is_visible(),'restoreRetryVisible')
        await restore.wait_for_timeout(900)
        await ck(await restore.evaluate('window.__submits')==1,'restoreAutoContinueOnce')
        await restore.wait_for_timeout(6500)
        await ck(not await button.is_disabled(),'restoreRetryReturnsAfterStall')
        await ck('retry safely' in ((await status.text_content()) or '').lower(),'restoreStallExplained')
        await button.click(); await restore.wait_for_timeout(30)
        await ck(await restore.evaluate('window.__submits')==2,'restoreManualRetryExactlyOnce')
        await restore.close()

        # 4. Integrations states availability honestly without roadmap/developer language or identity guessing.
        integ=await b.new_page(viewport={'width':1200,'height':820})
        await integ.set_content(build('build_integrations_fixture.php',{'CERTLOOM_INTEGRATIONS_MODE':'advanced'}),wait_until='load')
        await ck(await integ.get_by_role('heading',name='Integrations',exact=True).is_visible(),'integrationsCurrentContract')
        itext=await integ.locator('.certloom-integrations-workspace').inner_text()
        await ck('Every source uses the same Recipient → Program → credential workflow.' in itext,'integrationsSharedWorkflow')
        await ck('Explicit account or External ID — no name/email guessing' in itext,'integrationsNoIdentityGuessing')
        await ck(itext.count('Not detected')>=3 and 'Not connected' in itext and 'Create endpoint' in itext,'integrationsAvailabilityTruth')
        await ck(await integ.get_by_text('Technical boundary',exact=True).is_visible(),'integrationsAdvancedTechnicalBoundary')
        await ck('Publishing destinations' in itext and 'Destinations never decide who earned a credential' in itext,'integrationsSourceDestinationBoundary')
        await ck('What comes next' not in itext and 'roadmap' not in itext.lower(),'integrationsNoRoadmapHistory')
        await integ.close()

        # 5. Settings search stays usable in advanced administration and technical evidence remains a deliberate destination.
        settings=await b.new_page(viewport={'width':1100,'height':760})
        await settings.set_content(build('build_admin_ux_fixture.php',{'CERTLOOM_ADMIN_UX_STATE':'settings'}),wait_until='load')
        await settings.locator('#certloom-settings-search').fill('number'); await settings.wait_for_timeout(25)
        await ck('Certificate numbering' in await settings.locator('#certloom-settings-search-results').inner_text(),'settingsSearchWorks')
        await ck(await settings.get_by_role('link',name='Advanced',exact=True).is_visible(),'settingsAdvancedDestination')
        await settings.close()

        # 6. Accessibility media: advanced shell + recovery are usable under forced colors and RTL.
        forced=await b.new_page(viewport={'width':900,'height':720},forced_colors='active')
        await forced.set_content(build('build_backup_center_fixture.php'),wait_until='load')
        await ck(await forced.get_by_role('heading',name='Backup & Restore',exact=True).is_visible(),'advancedForcedColorsBackup')
        await forced.close()
        rtl=await b.new_page(viewport={'width':760,'height':820})
        html=build('build_integrations_fixture.php').replace('<html lang="en">','<html lang="ar" dir="rtl">')
        await rtl.set_content(html,wait_until='load')
        await ck(await rtl.evaluate("document.documentElement.dir==='rtl'"),'advancedRtlDirection')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'advancedRtlContained')
        await rtl.close(); await b.close()
    if failed:
        print('FAIL  UX-QA-01 advanced-admin journey: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  UX-QA-01 advanced-admin journey: six-section Advanced shell → Backup/Restore → stall recovery → Integrations → settings diagnostics')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
