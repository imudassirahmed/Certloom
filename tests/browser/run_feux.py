#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_feux_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        page=await browser.new_page(viewport={'width':1100,'height':780})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.locator('.certloom-credential-profile').evaluate('(e)=>e.getBoundingClientRect().width')<=742,'profileCompactWidth')
        await ck(await page.locator('.cvp-result-card').evaluate('(e)=>parseFloat(getComputedStyle(e).borderRadius)')<=12.1,'restrainedCardRadius')
        await ck(await page.locator('.cvp-result-row dt').first.evaluate('(e)=>getComputedStyle(e).textTransform')=='none','metadataNoUppercaseTransform')
        dtsize=float((await page.locator('.cvp-result-row dt').first.evaluate('(e)=>getComputedStyle(e).fontSize')).replace('px',''))
        ddsize=float((await page.locator('.cvp-result-row dd').first.evaluate('(e)=>getComputedStyle(e).fontSize')).replace('px',''))
        await ck(10.5<=dtsize<=12.5,'metadataLabelCompact')
        await ck(15<=ddsize<=17,'metadataValueHierarchy')
        await ck(await page.locator('.cvp-result-row dd').first.evaluate('(e)=>getComputedStyle(e).textTransform')=='none','recipientNameCasingPreserved')
        labels=await page.locator('.certloom-result-action').evaluate_all('(els)=>els.map(e=>e.textContent.trim().replace(/[▾▴]/g,""))')
        await ck('Copy link' in labels and 'Print' in labels and 'Show QR' not in labels,'compactActionLabelsNoQrButton')
        heights=await page.locator('.certloom-result-action').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in heights),'resultActionsAtLeast44')
        await ck(await page.locator('.certloom-result-qr').count()==1,'qrVisibleWithoutButton')
        qrwidth=await page.locator('.certloom-verification-qr-code').evaluate('(e)=>e.getBoundingClientRect().width')
        await ck(131<=qrwidth<=133,'qrCompact132')
        await ck(await page.locator('.certloom-verification-qr-code[role="img"][aria-label]').count()==1,'qrAccessibleName')
        await ck(await page.locator('.certloom-qr-svg').evaluate('(e)=>{let r=e.querySelector("rect"),p=e.querySelector("path");return r.getAttribute("fill")==="#fff"&&p.getAttribute("fill")==="#111"}') ,'qrFixedScanColors')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        shadow=await page.locator('.cvp-result-card').evaluate('(e)=>getComputedStyle(e).boxShadow')
        # FEUX-01 intentionally uses the shared soft elevation, not the older canvas shadow.
        shadow_parts=[float(part.replace('px','')) for part in shadow.replace(',', ' ').split() if part.endswith('px') and part.replace('px','').replace('.','',1).replace('-','',1).isdigit()]
        await ck('rgba' in shadow and shadow_parts and max(abs(v) for v in shadow_parts)<=3.1,'restrainedShadow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        action_widths=await mobile.locator('.certloom-result-action').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().width)')
        parent=await mobile.locator('.cvp-result-actions').evaluate('(e)=>e.getBoundingClientRect().width')
        await ck(any(w < parent-20 for w in action_widths),'mobileActionsVisuallyCompact')
        share_width=await mobile.locator('.certloom-share').evaluate('(e)=>e.getBoundingClientRect().width')
        share_button=await mobile.locator('.certloom-share > summary').evaluate('(e)=>e.getBoundingClientRect().width')
        await ck(share_width>=parent-2 and share_button<share_width-10,'mobileShareCompactButtonSafeContainer')
        await mobile.get_by_text('Share',exact=True).click()
        await ck(await mobile.locator('.certloom-share-menu').evaluate('(e)=>getComputedStyle(e).position')=='static','mobileShareMenuInFlow')
        await ck(await mobile.locator('.certloom-share-menu').evaluate('(e)=>e.getBoundingClientRect().width')>=share_width-2,'mobileShareMenuFullWidth')
        qrbox=await mobile.locator('.certloom-result-qr').evaluate('(e)=>e.getBoundingClientRect()')
        await ck(qrbox['right']<=390.5 and qrbox['left']>=-0.5,'mobileQrContained')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':800,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        duration=await reduced.locator('.certloom-result-action').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(duration in ('0s','0.00001s','0.001s') or duration.startswith('0.00'),'reducedMotionNoTransitions')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':800,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('.certloom-result-qr').count()==1,'forcedColorsQrRenders')
        await ck(await forced.locator('.certloom-result-action').count()>=3,'forcedColorsActionsRemain')
        await forced.close()

        printed=await browser.new_page(viewport={'width':800,'height':700})
        await printed.set_content(fixture(),wait_until='load')
        await printed.emulate_media(media='print')
        await ck(await printed.locator('.certloom-result-qr').evaluate('(e)=>getComputedStyle(e).display')!='none','printKeepsVerificationQrProof')
        await printed.close(); await browser.close()

    if failed:
        print('FAIL  FEUX browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  FEUX browser gate: compact hierarchy, directly visible QR, accessible actions, safe mobile Share/QR, reduced motion, forced colors and print behavior')
    print(f'\n{checks} FEUX browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
