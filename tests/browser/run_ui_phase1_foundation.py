#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase1_foundation_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1180,'height':1000})
        await page.set_content(fixture(),wait_until='load')
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        panel=page.locator('#ledger-fixture .certloom-registry-panel')
        await ck(await panel.evaluate("e=>getComputedStyle(e).borderRadius")=='0px','ledgerPanelSquare')
        await ck(await panel.evaluate("e=>getComputedStyle(e).boxShadow")=='none','ledgerPanelNoShadow')
        primary=page.locator('#ledger-fixture .button-primary')
        primary_bg=await primary.evaluate("e=>getComputedStyle(e).backgroundColor")
        primary_fg=await primary.evaluate("e=>getComputedStyle(e).color")
        await ck(
            (primary_bg=='rgb(20, 80, 224)') or
            (primary_bg=='rgb(255, 255, 255)' and primary_fg in ('rgb(10, 52, 168)','rgb(1, 19, 61)')),
            'blueActionIdentityOrAccessibleBrandSurfaceInverse'
        )
        valid=page.locator('#ledger-fixture .ecapla-certverify-badge-valid')
        await ck(await valid.evaluate("e=>getComputedStyle(e).color")=='rgb(0, 115, 78)','ledgerValidUsesVerdictGreen')
        await ck(await valid.evaluate("e=>getComputedStyle(e,'::before').borderRadius")=='50%','ledgerValidHasCircleShape')
        revoked=page.locator('#ledger-fixture .ecapla-certverify-badge-withdrawn')
        await ck('polygon' in await revoked.evaluate("e=>getComputedStyle(e,'::before').clipPath"),'ledgerRevokedHasTriangleShape')
        expired=page.locator('#ledger-fixture .ecapla-certverify-badge-expired')
        await ck(await expired.evaluate("e=>getComputedStyle(e,'::before').borderRadius")=='0px','ledgerExpiredHasSquareShape')
        card=page.locator('#artifact-fixture .cvp-profile-card')
        await ck(await card.evaluate("e=>getComputedStyle(e).borderRadius")=='0px','artifactNotRoundedCard')
        await ck(await card.evaluate("e=>getComputedStyle(e).boxShadow")=='none','artifactNoCardShadow')
        hero=page.locator('#artifact-fixture .cvp-profile-hero')
        await ck('gradient' not in await hero.evaluate("e=>getComputedStyle(e).backgroundImage"),'artifactNoGradient')
        verdict=page.locator('#artifact-fixture .certloom-verdict')
        await ck('Source Serif 4' in await verdict.evaluate("e=>getComputedStyle(e).fontFamily"),'artifactVerdictSerifRole')
        await ck(await verdict.evaluate("e=>getComputedStyle(e).color")=='rgb(0, 115, 78)','artifactValidVerdictGreen')
        recipient=page.locator('#artifact-fixture .certloom-recipient-name')
        await ck('Source Serif 4' in await recipient.evaluate("e=>getComputedStyle(e).fontFamily"),'artifactRecipientSerifRole')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()
        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoPageOverflow')
        await mobile.close()
        reduced=await browser.new_page(viewport={'width':900,'height':700},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--cl-motion-base').trim()")=='0ms','reducedMotionTokenZero')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':700},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.locator('#ledger-fixture .ecapla-certverify-badge-valid').count()==1 and await forced.locator('#artifact-fixture .cvp-profile-card').count()==1,'forcedColorsSurfacesRender')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 1 foundation:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UI Phase 1 foundation: ledger/action/verdict semantics, flat artifact, responsive and accessibility baselines')
    print(f'\n{checks} UI Phase 1 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
