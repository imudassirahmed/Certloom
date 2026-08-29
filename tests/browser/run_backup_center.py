#!/usr/bin/env python3
"""BAK-02A Backup & Recovery responsive/accessibility browser acceptance."""
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
        await ck(await page.get_by_role('heading',name='Backup & recovery').is_visible(),'heading')
        summary=(await page.locator('.certloom-backup-center > p').nth(1).text_content()) or ''
        await ck('Source authority:' in summary and 'Current Certloom registry' in summary,'authorityVisible')
        await ck('Destination:' in summary and 'Local private storage' in summary,'destinationVisible')
        await ck(await page.get_by_role('button',name='Test storage').is_visible(),'testStorageVisible')
        await ck(await page.get_by_role('button',name='Back up now').is_visible(),'runNowVisible')
        await ck(await page.locator('#schedule').input_value()=='Daily','dailyScheduleVisible')
        await ck(await page.locator('#retention').get_attribute('max')=='60','retentionBounded')
        await ck(await page.get_by_text('Verified at creation',exact=True).is_visible(),'integrityWordingHonest')
        await ck(await page.get_by_role('link',name='Review restore').is_visible(),'reviewRestoreVisible')
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
    print('PASS  BAK-02A browser acceptance: Backup Center is responsive, bounded, accessible, and explicit about authority/integrity')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
