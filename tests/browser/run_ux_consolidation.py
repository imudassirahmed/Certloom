#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state='desktop'):
    env=os.environ.copy(); env['CERTLOOM_UX_CONS_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_consolidation_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        page=await browser.new_page(viewport={'width':1280,'height':900})
        await page.set_content(fixture(),wait_until='load')
        primary=await page.locator('[data-primary-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck(primary==['Dashboard','Credentials','People & Programs','Design','Insights & Integrations','Settings'],'sixPrimarySections')
        await ck(len(primary)==6,'primaryBoundedSix')
        await ck(not any(x in primary for x in ['Data Tools','Recipients','Manual Issuance']),'specialistRoutesNotPrimary')
        sub=await page.locator('[data-subnav-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck(sub==['Settings','Backup & Restore','Setup','Fields'],'settingsSubnav')
        await ck(await page.locator('[data-subnav-label][aria-current="page"]').inner_text()=='Backup & Restore','backupSubnavCurrent')
        await ck(await page.locator('h1').inner_text()=='Backup & Restore','backupTitle')
        await ck(await page.locator('.certloom-recovery-quickstart .button').count()==3,'threeRecoveryTasks')
        await ck(await page.locator('[data-certloom-recovery-intake]').count()==1,'shortImportRestoreGuide')
        await page.get_by_role('button',name='Import certificates from CSV').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('[data-certloom-recovery-step="2"]').evaluate('(e)=>e.classList.contains("is-current")'),'guideAdvancesToReviewStep')
        await ck(await page.locator('#ecapla-certloom-import-file').evaluate('(e)=>document.activeElement===e'),'csvChoiceFocusesCsvFile')
        await page.get_by_role('button',name='Restore a Certloom backup').click(); await page.wait_for_timeout(40)
        await ck(await page.locator('#ecapla-certverify-backup-file').evaluate('(e)=>document.activeElement===e'),'backupChoiceFocusesBackupFile')
        await ck(await page.locator('#certloom-backups').count()==1,'backupLeadSurface')
        order=await page.locator('.certloom-recovery-grid > section').evaluate_all('(els)=>els.map(e=>({id:e.id,order:getComputedStyle(e).order}))')
        backup=next(x for x in order if x['id']=='certloom-backups')
        await ck(backup['order']=='1','backupVisualFirst')
        gridcol=await page.locator('#certloom-backups').evaluate('(e)=>getComputedStyle(e).gridColumn')
        await ck(gridcol in ('1 / -1','1 / -1'),'backupSpansDesktop')
        await ck(await page.locator('.certloom-product-notice-rail[aria-live="polite"]').count()==1,'topNoticeRail')
        rail=page.locator('.certloom-product-notice-rail[aria-live="polite"]')
        await ck(await rail.locator('.notice').count()==2,'globalNoticesCollected')
        await ck(await page.locator('#certloom-backups .certloom-dashboard-notice').count()==0,'dashboardNoticeLeavesContentCard')
        await ck(await rail.evaluate('(e)=>e.previousElementSibling&&e.previousElementSibling.tagName==="H1"'),'noticeRailImmediatelyAfterTitle')
        heights=await page.locator('.certloom-product-subnav a,.certloom-recovery-quickstart .button,#restore-action').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'targetsAtLeast44')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        dialogs=[]
        page.on('dialog', lambda d: (dialogs.append(d.message), asyncio.create_task(d.dismiss())))
        await page.locator('#restore-action').click()
        await page.wait_for_timeout(30)
        await ck(len(dialogs)==1 and 'safety backup' in dialogs[0].lower(),'restoreConfirmationDialog')
        await page.close()

        advanced=await browser.new_page(viewport={'width':1100,'height':780})
        await advanced.set_content(fixture('advanced'),wait_until='load')
        adv=await advanced.locator('[data-primary-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck(len(adv)==6 and 'Developer tools' not in adv,'advancedStillSixPrimary')
        advsub=await advanced.locator('[data-subnav-label]').evaluate_all('(els)=>els.map(e=>e.textContent.trim())')
        await ck('Developer tools' in advsub,'advancedDeveloperToolsSubtab')
        await advanced.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture('mobile'),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('.certloom-product-subnav').evaluate('(e)=>e.scrollWidth>=e.clientWidth'),'mobileSubnavContained')
        await ck(await mobile.locator('.certloom-recovery-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length==1'),'mobileOneColumnRecovery')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        duration=await reduced.locator('.certloom-product-subnav a').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(duration in ('0s','0.00001s','0.001s') or duration.startswith('0.00'),'reducedMotion')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.certloom-product-subnav').count()==1,'forcedColorsSubnav')
        await ck(await forced.locator('#restore-action').count()==1,'forcedColorsRestoreAction')
        await forced.close(); await browser.close()

    if failed:
        print('FAIL  UX-CONS-01 browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-CONS-01 browser gate: six-section shell, Backup & Restore, notice rail, confirmation, mobile, reduced motion and forced colors')
    print(f'\n{checks} UX-CONS-01 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
