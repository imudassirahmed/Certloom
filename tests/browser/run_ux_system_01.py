#!/usr/bin/env python3
import asyncio
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
CSS=(ROOT/'public/assets/certloom-public.css').read_text()
ADMIN_CSS=(ROOT/'admin/assets/admin.css').read_text()
HTML='''<!doctype html><html><head><style>%s</style><style>%s</style></head><body>
<div class="ecapla-certverify-widget cv-widget"><form class="cv-search-form">
<div class="certloom-verification-field"><label class="certloom-verification-label" for="n">Certificate number</label><input id="n" class="cv-search-input"></div>
<details class="certloom-verification-code-disclosure"><summary>I have a verification code</summary><label class="certloom-verification-label" for="c">Verification code</label><input id="c" class="cv-search-input certloom-secondary-code-input"></details><button class="cv-search-btn">Verify</button></form></div>
<div class="cv-result-card cv-status-valid"><span class="cv-status-mark">V</span></div>
<div class="cv-result-card cv-status-revoked"><span class="cv-status-mark">R</span></div>
<div class="cv-result-card cv-status-voided"><span class="cv-status-mark">X</span></div>
<div class="cv-result-card cv-status-pending_review"><span class="cv-status-mark">P</span></div>
<div class="cv-result-card cv-status-unknown"><span class="cv-status-mark">?</span></div>
<div class="ecapla-certverify-admin-wrap certloom-admin-shell" id="admin-preview"><div class="certloom-embed-design-grid">
<article class="certloom-embed-design-card certloom-embed-design-professional"><div class="certloom-embed-design-preview"><span class="certloom-embed-preview-title">Verify a credential</span><span class="certloom-embed-preview-field"></span><span class="certloom-embed-preview-button"></span></div></article>
<article class="certloom-embed-design-card certloom-embed-design-halo"><div class="certloom-embed-design-preview"><span class="certloom-embed-preview-title">Verify a credential</span></div></article>
<article class="certloom-embed-design-card certloom-embed-design-forge"><div class="certloom-embed-design-preview"><span class="certloom-embed-preview-title">Verify a credential</span></div></article>
</div></div>
</body></html>''' % (CSS, ADMIN_CSS)
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await b.new_page(viewport={'width':900,'height':700}); await page.set_content(HTML)
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        await ck(await page.locator('label[for="n"]').is_visible(),'certificateNumberLabelVisible')
        await ck(await page.locator('details').is_visible(),'optionalCodeDisclosureVisible')
        await ck(not await page.locator('#c').is_visible(),'optionalCodeCollapsedInitially')
        await page.locator('details summary').click()
        await ck(await page.locator('label[for="c"]').is_visible(),'verificationCodeLabelVisibleWhenOpened')
        await ck(await page.locator('#c').is_visible(),'verificationCodeInputVisibleWhenOpened')
        async def colors(sel): return await page.locator(sel).evaluate("e=>[getComputedStyle(e).backgroundColor,getComputedStyle(e).color]")
        valid=await colors('.cv-status-valid .cv-status-mark'); revoked=await colors('.cv-status-revoked .cv-status-mark'); voided=await colors('.cv-status-voided .cv-status-mark'); pending=await colors('.cv-status-pending_review .cv-status-mark'); unknown=await colors('.cv-status-unknown .cv-status-mark')
        await ck(valid!=revoked,'revokedNeverSharesValidColors')
        await ck(valid!=voided,'voidedNeverSharesValidColors')
        await ck(valid!=pending,'pendingNeverSharesValidColors')
        await ck(valid!=unknown,'unknownNeverSharesValidColors')
        await ck(revoked==voided,'invalidAdministrativeStatesShareDangerTreatment')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.set_viewport_size({'width':360,'height':700}); await page.wait_for_timeout(20)
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck((await page.locator('.cv-search-btn').bounding_box())['height']>=44,'mobileSubmitTargetAtLeast44')
        await ck((await page.locator('details summary').bounding_box())['height']>0,'mobileDisclosureOperable')
        await ck('Certificate number'==await page.locator('label[for="n"]').inner_text(),'visibleLabelTextStable')
        await page.set_viewport_size({'width':900,'height':700}); await page.wait_for_timeout(20)
        await ck(await page.locator('.certloom-embed-design-grid').is_visible(),'appearanceDesignGridVisible')
        await ck(await page.locator('.certloom-embed-design-card').count()>=3,'appearanceDesignCardsRendered')
        halo_bg=await page.locator('.certloom-embed-design-halo .certloom-embed-design-preview').evaluate("e=>getComputedStyle(e).backgroundColor")
        forge_bg=await page.locator('.certloom-embed-design-forge .certloom-embed-design-preview').evaluate("e=>getComputedStyle(e).backgroundColor")
        await ck(halo_bg!=forge_bg,'appearanceDesignsHaveDistinctVisualTreatments')
        await b.close()
    if failed:
        print('FAIL  UX-SYSTEM-01 browser gate:', ', '.join(failed)); return 1
    print('PASS  UX-SYSTEM-01 browser gate: fail-safe statuses, verification disclosure and visual design merchandising')
    print(f'\n{checks} UX-SYSTEM-01 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
