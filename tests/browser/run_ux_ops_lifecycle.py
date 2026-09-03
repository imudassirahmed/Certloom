#!/usr/bin/env python3
import asyncio,subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
 r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_ops_lifecycle_fixture.php')],cwd=ROOT,text=True,capture_output=True)
 if r.returncode: raise RuntimeError(r.stderr or r.stdout)
 return r.stdout
async def main():
 failed=[]; checks=0
 async def ck(v,n):
  nonlocal checks; checks+=1
  if not v: failed.append(n)
 async with async_playwright() as pw:
  b=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
  page=await b.new_page(viewport={'width':1100,'height':760}); await page.set_content(fixture(),wait_until='load')
  await page.locator('tr[data-plugin="certloom/certloom.php"] .deactivate a').click()
  dialog=page.locator('#certloom-plugin-lifecycle-dialog')
  await ck(await dialog.evaluate('(e)=>e.open'),'dialogOpensOnlyForCertloom')
  await ck(await page.get_by_role('heading',name='Before deactivating Certloom',exact=True).is_visible(),'heading')
  await ck(await page.get_by_text('Public verification pages, credential QR destinations and the recipient portal stop working',exact=False).is_visible(),'verificationWarning')
  keep=page.locator('input[name="delete_data_on_uninstall"][value="0"]'); delete=page.locator('input[name="delete_data_on_uninstall"][value="1"]')
  await ck(await keep.is_checked(),'keepDefault')
  await ck('Recommended' in (await keep.locator('xpath=..').inner_text()),'keepRecommended')
  await delete.check(); await ck(await delete.is_checked() and not await keep.is_checked(),'destructiveExplicitChoice')
  await ck('cannot be undone' in (await delete.locator('xpath=..').inner_text()).lower(),'destructiveWarning')
  redirect=await page.locator('[data-certloom-deactivate-redirect]').input_value(); await ck('plugin=certloom%2Fcertloom.php' in redirect,'exactDeactivateDestinationPreserved')
  await page.get_by_role('button',name='Cancel',exact=True).click(); await ck(not await dialog.evaluate('(e)=>e.open'),'cancelCloses')
  await page.locator('tr[data-plugin="other/other.php"] .deactivate a').click(); await ck(not await dialog.evaluate('(e)=>e.open'),'otherPluginNotIntercepted')
  await page.close()
  mobile=await b.new_page(viewport={'width':390,'height':844}); await mobile.set_content(fixture(),wait_until='load'); await mobile.locator('tr[data-plugin="certloom/certloom.php"] .deactivate a').click()
  await ck(await mobile.evaluate('document.documentElement.scrollWidth<=window.innerWidth+1'),'mobileContained')
  actions=mobile.locator('.certloom-lifecycle-dialog-actions .button'); await ck(min(await actions.evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)'))>=43.5,'mobileActions44')
  await mobile.keyboard.press('Escape'); await ck(not await mobile.locator('#certloom-plugin-lifecycle-dialog').evaluate('(e)=>e.open'),'escapeCloses')
  await mobile.close()
  forced=await b.new_page(viewport={'width':900,'height':700},forced_colors='active'); await forced.set_content(fixture(),wait_until='load'); await forced.locator('tr[data-plugin="certloom/certloom.php"] .deactivate a').click()
  await ck(await forced.locator('.certloom-lifecycle-options>label').count()==2,'forcedColorsChoicesPresent')
  await forced.close(); await b.close()
 if failed:
  print('FAIL  UX-OPS lifecycle browser gate: '+', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
 print('PASS  UX-OPS lifecycle browser gate: exact plugin interception, keep/delete safety, keyboard, mobile and forced colors')
 print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
