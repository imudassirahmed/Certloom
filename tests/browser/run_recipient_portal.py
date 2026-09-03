#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture(state):
    env=os.environ.copy(); env['CERTLOOM_RECIPIENT_PORTAL_STATE']=state
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_recipient_portal_fixture.php')],cwd=ROOT,env=env,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)

        cards=await browser.new_page(viewport={'width':1280,'height':900})
        await cards.set_content(fixture('cards'),wait_until='load')
        await ck(await cards.get_by_role('heading',name='My Credentials').count()==1,'cardsHeading')
        await ck(await cards.locator('.certloom-my-credential-card').count()==2,'cardsTwoCredentials')
        await ck(await cards.locator('.certloom-my-credential-card__status--valid').count()==1,'cardsValidStatus')
        await ck(await cards.locator('.certloom-my-credential-card__status--revoked').count()==1,'cardsRevokedStatus')
        await ck(await cards.locator('.certloom-my-credential-card__expiring').count()==1,'cardsExpiringSoonCue')
        await ck(await cards.locator('.certloom-my-credential-card__credential-fields').count()==2,'cardsScopedCredentialDetails')
        await ck(await cards.locator('.certloom-my-credential-card__credential-fields').first.get_by_text('GPA',exact=True).count()==1,'cardsScopedGpaLabel')
        await ck(await cards.locator('.certloom-my-credential-card__credential-fields').first.get_by_text('Campus',exact=True).count()==1,'cardsScopedCampusLabel')
        await ck(await cards.locator('.certloom-my-credential-card').first.get_by_text('Grade',exact=True).count()==1,'cardsGradeNotDuplicated')
        await ck(await cards.locator('select[name="certloom_my_status"]').count()==1,'cardsStatusFilter')
        await ck(await cards.locator('select[name="certloom_my_sort"]').count()==1,'cardsSort')
        await ck(await cards.get_by_role('button',name='Apply').count()==1,'cardsApply')
        await ck(await cards.get_by_role('link',name='Verify').count()==0,'cardsVerifyFailClosed')
        await ck(await cards.get_by_text('Downloads gated',exact=True).count()==2,'cardsDownloadsHonest')
        await ck('normalized verification authority' in (await cards.locator('.certloom-my-credentials__authority-note').inner_text()).lower(),'cardsAuthorityHonest')
        await ck('@' not in await cards.locator('body').inner_text(),'cardsNoRecipientEmailLeak')
        await ck(await cards.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'cardsDesktopNoOverflow')
        await cards.close()

        grid=await browser.new_page(viewport={'width':1200,'height':900})
        await grid.set_content(fixture('grid'),wait_until='load')
        cols=await grid.locator('.certloom-my-credentials__collection').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols==2,'gridDesktopTwoColumns')
        await ck(await grid.locator('.certloom-my-credential-card').count()==2,'gridTwoCards')
        await grid.close()

        mobile_grid=await browser.new_page(viewport={'width':390,'height':820})
        await mobile_grid.set_content(fixture('grid'),wait_until='load')
        mcols=await mobile_grid.locator('.certloom-my-credentials__collection').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(mcols==1,'gridMobileOneColumn')
        await ck(await mobile_grid.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'gridMobileNoDocumentOverflow')
        await ck(await mobile_grid.locator('.certloom-my-credentials__header').evaluate('(e)=>getComputedStyle(e).flexDirection')=='column','gridMobileHeaderStacks')
        detail_cols=await mobile_grid.locator('.certloom-my-credential-card__credential-fields dl').first.evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(detail_cols==1,'gridMobileScopedDetailsOneColumn')
        await mobile_grid.close()

        table=await browser.new_page(viewport={'width':390,'height':820})
        await table.set_content(fixture('table'),wait_until='load')
        wrap=table.locator('.certloom-my-credentials__table-wrap')
        await ck(await wrap.get_attribute('role')=='region','tableNamedRegionRole')
        await ck(await wrap.get_attribute('aria-label')=='My credentials table','tableNamedRegionLabel')
        await ck(await wrap.get_attribute('tabindex')=='0','tableKeyboardFocusable')
        await ck(await wrap.evaluate('(e)=>e.scrollWidth > e.clientWidth'),'tableOwnsHorizontalOverflow')
        await ck(await table.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'tableNoDocumentOverflow')
        await ck(await table.locator('th[scope="col"]').count()==5,'tableColumnHeaders')
        await ck(await table.locator('th[scope="row"]').count()==2,'tableRowHeaders')
        await table.close()

        logged=await browser.new_page(viewport={'width':760,'height':600})
        await logged.set_content(fixture('logged_out'),wait_until='load')
        await ck(await logged.locator('[role="status"]').count()==1,'loggedOutStatusNotice')
        await ck('sign in to view your credentials' in (await logged.locator('[role="status"]').inner_text()).lower(),'loggedOutNoLeakCopy')
        await ck(await logged.get_by_role('link',name='Sign in').count()==1,'loggedOutSignInAction')
        await ck(await logged.locator('.certloom-my-credential-card').count()==0,'loggedOutNoCredentialCards')
        await logged.close()

        unlinked=await browser.new_page(viewport={'width':760,'height':600})
        await unlinked.set_content(fixture('unlinked'),wait_until='load')
        await ck('not linked' in (await unlinked.locator('[role="status"]').inner_text()).lower(),'unlinkedHonestNotice')
        await ck(await unlinked.locator('.certloom-my-credential-card').count()==0,'unlinkedNoCredentialCards')
        await ck('@' not in await unlinked.locator('body').inner_text(),'unlinkedNoEmailLeak')
        await unlinked.close()

        ready=await browser.new_page(viewport={'width':960,'height':800})
        await ready.set_content(fixture('authority_ready'),wait_until='load')
        await ck(await ready.get_by_role('link',name='Verify').count()==2,'authorityReadyVerifyAction')
        await ck((await ready.get_by_role('link',name='Verify').first.get_attribute('rel'))=='nofollow noopener','authorityReadyVerifyRel')
        await ck('immutable version' in (await ready.locator('.certloom-my-credentials__authority-note').inner_text()).lower(),'authorityReadyLiveTruthCopy')
        # Each credential owns its own copy feedback. A copy from the second card must not
        # announce success inside the first card.
        await ready.evaluate("Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:()=>Promise.resolve()}})")
        shares=ready.locator('.certloom-my-credentials__share')
        await shares.nth(1).locator('summary').click()
        await shares.nth(1).get_by_role('button',name='Copy URL').click()
        await ready.wait_for_timeout(40)
        await ck((await shares.nth(0).locator('.certloom-action-feedback').inner_text())=='','shareFeedbackDoesNotLeakToFirstCard')
        await ck((await shares.nth(1).locator('.certloom-action-feedback').inner_text())=='Credential link copied.','shareFeedbackStaysWithClickedCard')
        await ready.close()

        empty=await browser.new_page(viewport={'width':760,'height':600})
        await empty.set_content(fixture('empty'),wait_until='load')
        await ck(await empty.locator('.certloom-my-credentials__empty[role="status"]').count()==1,'emptyStatusRegion')
        await ck('no credentials match this view' in (await empty.locator('.certloom-my-credentials__empty').inner_text()).lower(),'emptyHelpfulCopy')
        await empty.close()

        reduced=await browser.new_page(viewport={'width':390,'height':820},reduced_motion='reduce')
        await reduced.set_content(fixture('cards'),wait_until='load')
        transition=await reduced.locator('.certloom-my-credentials__button').first.evaluate('(e)=>getComputedStyle(e).transitionDuration')
        await ck(transition in ('0s','0s, 0s') or transition.startswith('0s'),'reducedMotionDisablesTransition')
        await ck(await reduced.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'reducedMotionNoOverflow')
        await reduced.close()

        forced=await browser.new_page(viewport={'width':760,'height':600},forced_colors='active')
        await forced.set_content(fixture('cards'),wait_until='load')
        await ck(await forced.locator('.certloom-my-credentials').count()==1,'forcedColorsPortalRenders')
        await ck(await forced.locator('.certloom-my-credentials__button').count()>=1,'forcedColorsControlsRemain')
        await forced.close(); await browser.close()
    if failed:
        print('FAIL  Recipient Portal browser gate:', ', '.join(failed)); return 1
    print('PASS  Recipient Portal browser gate: authenticated cards/grid/table, fail-closed logged-out/unlinked states, authority-aware actions, responsive overflow, reduced motion and forced colors')
    print(f'\n{checks} Recipient Portal browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
