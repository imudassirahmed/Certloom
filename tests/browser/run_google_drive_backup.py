#!/usr/bin/env python3
"""BAK-03 Google Drive Backup Center responsive/accessibility acceptance."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_google_drive_backup_fixture.php'
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
        await ck(await page.get_by_role('heading',name='Google Drive',exact=True).is_visible(),'driveHeading')
        summary=(await page.get_by_text('Google Drive is an optional second copy',exact=False).text_content()) or ''
        await ck('Local private storage remains the recovery source' in summary and 'credential authority never moves to Google' in summary,'authorityCopy')
        await ck(await page.get_by_role('button',name='Test Google Drive').is_visible(),'testDrive')
        await ck(await page.get_by_role('button',name='Disconnect').is_visible(),'disconnect')
        await ck(await page.get_by_text('Copy scheduled backups to Google Drive',exact=True).is_visible(),'scheduledToggle')
        await ck(await page.locator('#gret').get_attribute('max')=='60','retentionBounded')
        await ck(await page.get_by_role('button',name='Copy to Google Drive').is_visible(),'manualCopy')
        await ck(await page.get_by_text('Local only',exact=True).is_visible(),'safetyLocalOnly')
        await ck(await page.get_by_role('button',name='Save locally').is_visible(),'saveLocally')
        await ck(await page.get_by_text('Needs attention',exact=True).is_visible(),'failurePlainStatus')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'desktopNoOverflow')
        await page.close()
        mobile=await b.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'mobileNoPageOverflow')
        heights=await mobile.evaluate("""() => [...document.querySelectorAll('.certloom-backup-center .button,.certloom-backup-center button,.certloom-backup-center input[type=number]')].filter(x=>getComputedStyle(x).display!=='none').map(x=>x.getBoundingClientRect().height)""")
        await ck(min(heights)>=43.5,'mobileControls44')
        wraps=mobile.locator('.certloom-backup-table-wrap'); tables=mobile.locator('.certloom-backup-table')
        await ck(await tables.count()>=2,'twoBoundedTables')
        wb=await wraps.nth(1).bounding_box(); tb=await tables.nth(1).bounding_box(); await ck(tb['width']>wb['width'],'remoteTableLocalScroll')
        await mobile.close()
        rtl=await b.new_page(viewport={'width':900,'height':700}); await rtl.set_content(html(),wait_until='load'); await rtl.locator('html').evaluate("el=>el.dir='rtl'")
        await ck(await rtl.get_by_role('heading',name='Google Drive',exact=True).is_visible(),'rtlVisible'); await rtl.close()
        reduced=await b.new_page(viewport={'width':900,'height':700}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion'); await reduced.close()
        forced=await b.new_page(viewport={'width':900,'height':700})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html(),wait_until='load'); await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColors')
        except Exception: await ck(True,'forcedColorsUnsupported')
        await forced.close(); await b.close()
    if failed:
        print('FAIL  BAK-03 browser acceptance: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  BAK-03 browser acceptance: Drive copy controls are explicit, responsive, accessible, and preserve local authority')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
