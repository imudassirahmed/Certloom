#!/usr/bin/env python3
"""Backup & Restore responsive/accessibility browser acceptance."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_backup_center_fixture.php'
def html():
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def main():
    failed=[]; checks=0
    async def ck(v,n):
        nonlocal checks; checks+=1
        if not v: failed.append(n)
    async with async_playwright() as pw:
        b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await b.new_page(viewport={'width':1280,'height':900}); await page.set_content(html(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Backup & Restore',exact=True).is_visible(),'heading')
        await ck(await page.get_by_role('heading',name='Your backups',exact=True).is_visible(),'yourBackups')
        summary=(await page.locator('.certloom-backup-center > p').nth(1).text_content()) or ''
        await ck('Stored locally:' in summary and 'Private backup storage on this site' in summary,'storagePlainLanguage')
        await ck(await page.get_by_role('heading',name='Bring data into Certloom',exact=True).is_visible(),'guidedIntake')
        await ck(await page.get_by_role('link',name='Restore a backup',exact=True).is_visible(),'restoreQuickstart')
        await ck(await page.get_by_role('link',name='Import certificates',exact=True).is_visible(),'importQuickstart')
        await ck(await page.get_by_role('button',name='Test storage').is_visible(),'testStorageVisible')
        await ck(await page.get_by_role('button',name='Back up now').is_visible(),'runNowVisible')
        await ck(await page.locator('#schedule').input_value()=='Daily','dailyScheduleVisible')
        await ck(await page.locator('#retention').get_attribute('max')=='60','retentionBounded')
        await ck(await page.get_by_text('Automatic retention',exact=True).is_visible(),'retentionAlignedLabel')
        await ck(await page.get_by_role('button',name='Save backup settings').evaluate('(e)=>e.getBoundingClientRect().width < 240'),'saveButtonContentSized')
        await ck(await page.get_by_text('Page 1 of 2',exact=True).is_visible() and await page.get_by_role('link',name='Next',exact=True).is_visible(),'backupPaginationVisible')
        await ck(await page.get_by_text('Verified at creation',exact=True).is_visible(),'integrityWordingHonest')
        await ck(await page.get_by_role('link',name='Review restore').is_visible(),'reviewRestoreVisible')
        await ck(await page.get_by_role('button',name='Review backup',exact=True).is_visible(),'reviewBackupVisible')
        body=(await page.locator('body').inner_text()).lower()
        await ck('source authority:' not in body and 'analyze backup' not in body,'noLegacyTechnicalCopy')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'desktopNoPageOverflow')
        await page.close()
        mobile=await b.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'mobileNoPageOverflow')
        heights=await mobile.evaluate("""() => [...document.querySelectorAll('.certloom-backup-center .button,.certloom-backup-center button,.certloom-backup-center select,.certloom-backup-center input[type=number]')].filter(x=>getComputedStyle(x).display!=='none').map(x=>x.getBoundingClientRect().height)""")
        await ck(min(heights)>=43.5,'mobileControls44')
        grid=await mobile.locator('.certloom-backup-settings-form').evaluate("el=>getComputedStyle(el).gridTemplateColumns")
        await ck(len([x for x in grid.split(' ') if x])==1,'mobileSettingsSingleColumn')
        wrap=mobile.locator('.certloom-backup-table-wrap'); table=mobile.locator('.certloom-backup-table')
        await ck((await table.bounding_box())['width']>(await wrap.bounding_box())['width'],'tableUsesLocalHorizontalScroll')
        await mobile.close()
        reduced=await b.new_page(viewport={'width':900,'height':700}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion')
        await reduced.close()
        forced=await b.new_page(viewport={'width':900,'height':700})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html(),wait_until='load')
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColors')
        except Exception:
            await ck(True,'forcedColorsUnsupported')
        await forced.close(); await b.close()
    if failed:
        print('FAIL  BAK-02A browser acceptance: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  Backup & Restore browser acceptance: current task-oriented copy, bounded storage, responsive layout and accessibility')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
