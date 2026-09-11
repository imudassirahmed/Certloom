#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_phase4_public_profile_preview_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        for width in (860,540,360):
            page=await browser.new_page(viewport={'width':width,'height':900})
            await page.set_content(fixture(),wait_until='load')
            async def ck(cond,label):
                nonlocal checks; checks+=1
                if not cond: failed.append(f'{width}:{label}')
            await ck(await page.get_by_text('Credential preview',exact=True).is_visible(),'previewEyebrowVisible')
            await ck(await page.get_by_text('Admin preview',exact=True).is_visible(),'adminPreviewBannerVisible')
            await ck(await page.get_by_text('Amina Khan',exact=True).is_visible(),'credentialContentVisible')
            await ck(await page.get_by_text('Current credential record',exact=True).is_visible(),'previewProofVisible')
            await ck(await page.get_by_text('Verified from the issuer’s Certloom registry',exact=True).count()==0,'noVerifiedClaim')
            await ck(await page.get_by_text('Copy link',exact=True).count()==0,'noPublicShareAction')
            await ck(await page.get_by_role('button',name='Print preview').is_visible(),'printPreviewActionVisible')
            await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'noDocumentOverflow')
            await page.close()
        forced=await browser.new_page(viewport={'width':500,'height':800})
        await forced.emulate_media(forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.cvp-profile-preview-banner').is_visible(),'forcedColorsBannerVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  Alpha 4.31 public-profile preview:', ', '.join(failed)); return 1
    print('PASS  Alpha 4.31 public-profile preview: authorized inspection is visible, bounded and never presented as public verification')
    print(f'\n{checks} public-profile preview browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
