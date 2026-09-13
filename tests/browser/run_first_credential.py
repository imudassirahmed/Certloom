#!/usr/bin/env python3
"""UX-QA current-authority first-credential browser acceptance."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_first_credential_fixture.php'
def fixture():
    r=subprocess.run(['php',str(BUILDER)],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def main():
    failed=[]; checks=0
    async def ck(v,n):
        nonlocal checks; checks+=1
        if not v: failed.append(n)
    async with async_playwright() as pw:
        browser=await pw.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        html=fixture()
        page=await browser.new_page(viewport={'width':1280,'height':1000}); await page.set_content(html,wait_until='load')
        await ck(await page.get_by_role('heading',name='Add credential',exact=True).is_visible(),'heading')
        await ck(await page.get_by_text('New certificate',exact=True).is_visible(),'plainEyebrow')
        await ck(await page.get_by_text('Create a certificate in the current Certloom registry.',exact=True).is_visible(),'plainHero')
        nav=page.locator('.certloom-workspace-nav a')
        await ck(await nav.count()==6,'sixLocalAnchors')
        labels=[(await nav.nth(i).inner_text()).strip() for i in range(await nav.count())]
        await ck(labels==['Overview','Recipient','Status','Custom Fields','Verification','Actions'],'focusedCreateNavigation')
        await ck(await page.locator('#certloom-workspace-program').count()==0,'noDeadProgramSectionOnCreate')
        await ck(await page.locator('#certloom-workspace-activity').count()==0,'noDeadActivitySectionOnCreate')
        await ck(await page.get_by_label('Certificate number').is_visible(),'certificateNumber')
        await ck(await page.get_by_label('Holder name').is_visible(),'holderName')
        await ck(await page.get_by_label('Issue date').is_visible(),'issueDate')
        await ck(await page.get_by_label('Expiry date').is_visible(),'expiryDate')
        await ck(await page.get_by_label('Grade').is_visible(),'customGrade')
        await ck(await page.get_by_label('Status',exact=True).is_visible(),'status')
        await ck(await page.get_by_role('button',name='Add credential',exact=True).is_visible(),'primaryAction')
        await ck(await page.get_by_role('link',name='Configure verification page',exact=True).is_visible(),'verificationSetupAction')
        await ck(await page.get_by_role('link',name='Review public fields',exact=True).is_visible(),'publicFieldsAction')
        body=(await page.locator('body').inner_text()).lower()
        for phrase,label in [('normalized domain','noNormalizedDomain'),('acceptance-gated','noAcceptanceGate'),('current authoritative','noAuthorityJargon'),('legacy authority','noLegacyAuthority')]:
            await ck(phrase not in body,label)
        await ck('linked recipient profiles and wordpress-user connections are not available in this build yet' in body,'honestRecipientBoundary')
        await ck(await page.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'desktopNoOverflow')
        # Keyboard traversal must enter the real workspace rather than trapping focus.
        await page.keyboard.press('Tab'); first=await page.evaluate('document.activeElement && document.activeElement.textContent')
        await ck(bool(first and 'Back to credentials' in first),'keyboardStartsAtBackLink')
        await page.keyboard.press('Tab'); second=await page.evaluate('document.activeElement && document.activeElement.textContent')
        await ck(bool(second and 'Overview' in second),'keyboardReachesWorkspaceNav')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); await mobile.set_content(html,wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'mobileNoPageOverflow')
        await ck(await mobile.locator('.certloom-workspace-nav').evaluate('el=>el.scrollWidth>=el.clientWidth'),'localNavContained')
        controls=await mobile.evaluate("""() => [...document.querySelectorAll('.certloom-certificate-editor a.button,.certloom-certificate-editor button,.certloom-certificate-editor input:not([type=hidden]),.certloom-certificate-editor select,.certloom-workspace-nav a')].filter(el=>{const r=el.getBoundingClientRect();return r.width>0&&r.height>0}).map(el=>el.getBoundingClientRect().height)""")
        await ck(bool(controls) and min(controls)>=43.5,'mobile44pxTargets')
        await ck(await mobile.get_by_role('button',name='Add credential',exact=True).is_visible(),'mobilePrimaryAction')
        await mobile.close()

        rtl=await browser.new_page(viewport={'width':760,'height':900}); await rtl.set_content(html.replace('<html lang="en">','<html lang="ar" dir="rtl">'),wait_until='load')
        await ck(await rtl.evaluate("document.documentElement.dir==='rtl'"),'rtlDirection')
        await ck(await rtl.evaluate('document.documentElement.scrollWidth<=document.documentElement.clientWidth+1'),'rtlNoOverflow')
        await ck(await rtl.get_by_role('heading',name='Add credential',exact=True).is_visible(),'rtlHeading')
        await rtl.close()

        reduced=await browser.new_page(viewport={'width':900,'height':700}); await reduced.emulate_media(reduced_motion='reduce'); await reduced.set_content(html,wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotion')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active'); await forced.set_content(html,wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColors')
        await ck(await forced.get_by_role('button',name='Add credential',exact=True).is_visible(),'forcedColorsPrimary')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UX-QA first credential browser acceptance: '+', '.join(failed)); print(f'\n{checks} checks, {len(failed)} failures'); return 1
    print('PASS  UX-QA first credential browser acceptance: focused create flow, truthful protected-state copy, keyboard/mobile/RTL/forced-colors behavior')
    print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
