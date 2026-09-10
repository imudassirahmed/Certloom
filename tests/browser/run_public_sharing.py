#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from urllib.parse import urlparse, parse_qs, unquote
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_SOC_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_public_sharing_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        page=await browser.new_page(viewport={'width':1100,'height':760})
        await page.set_content(fixture('public'),wait_until='load')
        await ck(await page.get_by_text('Share',exact=True).count()==1,'publicShareDisclosure')
        await page.get_by_text('Share',exact=True).click()
        await ck(await page.locator('.certloom-share[open]').count()==1,'publicDisclosureOpens')
        await ck(await page.locator('.certloom-share-link[data-platform]').count()==5,'publicFivePlatforms')
        platforms=await page.locator('.certloom-share-link[data-platform]').evaluate_all('(els)=>els.map(e=>e.dataset.platform)')
        await ck(platforms==['linkedin','whatsapp','facebook','x','email'],'platformOrder')
        expected_hosts={'linkedin':'www.linkedin.com','whatsapp':'wa.me','facebook':'www.facebook.com','x':'x.com'}
        for platform,host in expected_hosts.items():
            link=page.locator(f'.certloom-share-link--{platform}')
            href=await link.get_attribute('href') or ''
            await ck(urlparse(href).scheme=='https','https_'+platform)
            await ck(urlparse(href).hostname==host,'host_'+platform)
            rel=await link.get_attribute('rel') or ''
            await ck(all(x in rel.split() for x in ['nofollow','noopener','noreferrer']),'rel_'+platform)
            await ck(await link.get_attribute('target')=='_blank','target_'+platform)
        email=await page.locator('.certloom-share-link--email').get_attribute('href') or ''
        await ck(email.startswith('mailto:?subject='),'emailMailto')
        await ck(await page.locator('.certloom-share-link--email').get_attribute('target') is None,'emailNoBlankTarget')
        body=' '.join(await page.locator('body').all_inner_texts())
        await ck('recipient@example.test' not in body,'noRecipientEmailLeak')
        await ck('CERT-168' not in ''.join([await page.locator(f'.certloom-share-link--{x}').inner_text() for x in platforms]),'platformLabelsNoCredentialData')
        # Provider query contains only generic text + canonical public URL, not recipient/profile field payloads.
        linkedin=await page.locator('.certloom-share-link--linkedin').get_attribute('href') or ''
        await ck('credentials.example.test%2Fcertloom%2Fcredential%2FCERT-168%2F' in linkedin,'linkedinCanonicalProfileUrl')
        await page.evaluate("navigator.clipboard={writeText:(v)=>{window.__copied=v;return Promise.resolve();}}")
        await page.get_by_role('button',name='Copy URL').click()
        await page.wait_for_timeout(40)
        await ck(await page.evaluate('window.__copied')=='https://credentials.example.test/certloom/credential/CERT-168/','copyUsesCanonicalProfileUrl')
        await ck('copied' in (await page.locator('.certloom-action-feedback').inner_text()).lower(),'copyFeedbackAnnounced')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        summary=await browser.new_page(viewport={'width':900,'height':700})
        await summary.set_content(fixture('summary'),wait_until='load')
        await summary.get_by_text('Share',exact=True).click()
        lin=await summary.locator('.certloom-share-link--linkedin').get_attribute('href') or ''
        await ck('credentials.example.test%2Fcertloom%2Fverify%2FCERT-168%2F' in lin,'summarySharesVerificationUrl')
        await ck('/credential/' not in unquote(lin),'summaryDoesNotInventProfileUrl')
        await summary.close()

        private=await browser.new_page(viewport={'width':800,'height':600})
        await private.set_content(fixture('private'),wait_until='load')
        await ck(await private.get_by_text('Share',exact=True).count()==0,'privateNoShare')
        await ck(await private.locator('.certloom-share-link').count()==0,'privateNoProviderLinks')
        await private.close()

        portal=await browser.new_page(viewport={'width':900,'height':700})
        await portal.set_content(fixture('portal_ready'),wait_until='load')
        await ck(await portal.get_by_text('Share',exact=True).count()==1,'portalGuestVisibleShare')
        await portal.get_by_text('Share',exact=True).click()
        await portal.evaluate("navigator.clipboard={writeText:(v)=>{window.__copied=v;return Promise.resolve();}}")
        await portal.get_by_role('button',name='Copy URL').click(); await portal.wait_for_timeout(40)
        await ck(await portal.evaluate('window.__copied')=='https://credentials.example.test/certloom/verify/CERT-168/','portalCopyUsesAuthorizedVerifyUrl')
        await ck('copied' in (await portal.locator('.certloom-action-feedback').inner_text()).lower(),'portalCopyFeedback')
        await portal.close()

        closed=await browser.new_page(viewport={'width':800,'height':600})
        await closed.set_content(fixture('portal_closed'),wait_until='load')
        await ck(await closed.get_by_text('Share',exact=True).count()==0,'portalPrivateNoShare')
        await ck(await closed.locator('.certloom-share-link').count()==0,'portalPrivateNoLinks')
        await closed.close()

        mobile=await browser.new_page(viewport={'width':390,'height':820})
        await mobile.set_content(fixture('public'),wait_until='load')
        await mobile.get_by_text('Share',exact=True).click()
        position=await mobile.locator('.certloom-share-menu').evaluate('(e)=>getComputedStyle(e).position')
        await ck(position=='static','mobileMenuInFlow')
        width=await mobile.locator('.certloom-share').evaluate('(e)=>Math.round(e.getBoundingClientRect().width)')
        parent=await mobile.locator('.cvp-result-actions').evaluate('(e)=>Math.round(e.getBoundingClientRect().width)')
        await ck(width>=parent-2,'mobileDisclosureFullWidth')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await mobile.close()

        reduced=await browser.new_page(viewport={'width':760,'height':600},reduced_motion='reduce')
        await reduced.set_content(fixture('public'),wait_until='load')
        await reduced.get_by_text('Share',exact=True).click()
        behavior=await reduced.locator('.certloom-share-menu').evaluate('(e)=>getComputedStyle(e).scrollBehavior')
        await ck(behavior in ('auto',''), 'reducedMotionNoSmoothScrolling')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':760,'height':600},forced_colors='active')
        await forced.set_content(fixture('public'),wait_until='load')
        await forced.get_by_text('Share',exact=True).click()
        await ck(await forced.locator('.certloom-share-menu').count()==1,'forcedColorsMenuRenders')
        await ck(await forced.locator('.certloom-share-link').count()==6,'forcedColorsControlsRemain')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  Public Sharing browser gate:', ', '.join(failed)); return 1
    print('PASS  Public Sharing browser gate: provider disclosure, privacy/authority gating, copy behavior, external-link hardening, mobile, reduced motion and forced colors')
    print(f'\n{checks} Public Sharing browser checks, 0 failures')
    return 0
raise SystemExit(asyncio.run(run()))
