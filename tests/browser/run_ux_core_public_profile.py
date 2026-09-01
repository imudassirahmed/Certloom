#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_public_profile_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        for width in (900,537,360):
            page=await browser.new_page(viewport={'width':width,'height':950})
            await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(120)
            async def ck(cond,label):
                nonlocal checks; checks+=1
                if not cond: failed.append(f'{width}:{label}')
            await ck(await page.get_by_text('Issued certificate',exact=True).is_visible(),'certificateHeadingVisible')
            await ck(await page.get_by_text('Certificate of Achievement',exact=True).is_visible(),'certificateContentVisible')
            await ck(await page.get_by_text('Amina Khan',exact=True).count()>=2,'recipientAlsoVisibleOnCertificate')
            scroll=page.locator('.cvp-certificate-preview .certloom-template-preview-scroll')
            await ck(await scroll.get_attribute('data-certloom-preview-fitted')=='1','certificateFitInitialized')
            scale=await page.locator('.cvp-certificate-preview .certloom-template-preview-page').evaluate("e=>getComputedStyle(e).transform")
            await ck(scale!='none' or width>=900,'certificateUsesResponsiveScale')
            await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'noDocumentOverflow')
            cap=page.locator('.cvp-profile-qr figcaption')
            box=await cap.bounding_box(); text=await cap.inner_text()
            await ck(bool(box and box['width']>=90 and box['height']<90 and 'Scan to open' in text),'qrCaptionReadableNotVertical')
            await page.close()
        await browser.close()
    if failed:
        print('FAIL  UX-CORE public Credential Profile:', ', '.join(failed)); return 1
    print('PASS  UX-CORE public Credential Profile: issued certificate visible, responsive and QR proof readable')
    print(f'\n{checks} public-profile browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
