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
            await ck(await page.get_by_text('Verified credential',exact=True).is_visible(),'verifiedEyebrowVisible')
            await ck(await page.get_by_text('Admin preview',exact=True).count()==0,'noAdminPreviewBanner')
            await ck(await page.get_by_text('Amina Khan',exact=True).is_visible(),'credentialContentVisible')
            await ck(await page.get_by_text('Authentic credential record',exact=True).is_visible(),'verifiedProofVisible')
            await ck(await page.get_by_text('Verified from the issuer’s Certloom registry',exact=False).is_visible(),'verifiedClaimVisible')
            await ck(await page.get_by_text('Copy link',exact=True).is_visible(),'publicShareActionVisible')
            await ck(await page.get_by_role('button',name='Print',exact=True).is_visible(),'printActionVisible')
            await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'noDocumentOverflow')
            await page.close()
        forced=await browser.new_page(viewport={'width':500,'height':800})
        await forced.emulate_media(forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.get_by_text('Authentic credential record',exact=True).is_visible(),'forcedColorsProofVisible')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  Alpha 4.42 public verification:', ', '.join(failed)); return 1
    print('PASS  Alpha 4.42 public verification: healthy normalized credentials render as normal public verification without an admin-preview barrier')
    print(f'\n{checks} public-verification browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
