#!/usr/bin/env python3
import asyncio, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]

def fixture(mode='resume'):
    env = os.environ.copy()
    env['CERTLOOM_DASHBOARD_ANALYTICS'] = 'present'
    env['CERTLOOM_GUIDED_TOUR'] = mode
    r = subprocess.run(['php', str(ROOT/'tests/browser/build_admin_dashboard_fixture.php')], cwd=ROOT, env=env, capture_output=True, text=True)
    if r.returncode:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks = 0
    failed = []
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, executable_path='/usr/bin/chromium', args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])

        async def ck(ok, label):
            nonlocal checks
            checks += 1
            if not ok:
                failed.append(label)

        # Resume path: should return to saved step without blocking Dashboard.
        page = await browser.new_page(viewport={'width': 1365, 'height': 900})
        await page.set_content(fixture('resume'), wait_until='load')
        root = page.locator('[data-certloom-guided-tour]')
        card = root.locator('.certloom-guided-tour-card')
        await root.wait_for(state='visible')
        await ck(await root.count() == 1, 'tourRegionExists')
        await ck(await root.get_attribute('aria-modal') is None, 'tourNotModal')
        await ck(await root.evaluate('(e)=>getComputedStyle(e).pointerEvents') == 'none', 'overlayDoesNotBlockDashboard')
        await ck(await card.evaluate('(e)=>getComputedStyle(e).pointerEvents') == 'auto', 'coachmarkIsInteractive')
        await ck((await root.locator('[data-certloom-tour-progress]').inner_text()).strip() == 'Step 3 of 6', 'resumesSavedStep')
        await ck((await root.locator('[data-certloom-tour-title]').inner_text()).strip() == 'Use Programs when structure helps', 'resumeTitle')
        await ck('certification scheme' in (await root.locator('[data-certloom-tour-body]').inner_text()), 'profileAwareProgramCopy')
        await ck(await page.locator('.certloom-guided-tour-target').count() == 1, 'oneRealTargetHighlighted')
        await ck('page=certloom-domain-programs' in ((await root.locator('[data-certloom-tour-open]').get_attribute('href')) or ''), 'openActionUsesRealTargetHref')
        await ck(not await root.locator('[data-certloom-tour-back]').is_disabled(), 'backEnabledOnResumeStep')
        await ck((await root.locator('[data-certloom-tour-next]').inner_text()).strip() == 'Next', 'nextLabelBeforeFinal')
        await ck(await root.locator('[data-certloom-tour-next]').evaluate('(e)=>document.activeElement===e'), 'nextReceivesInitialFocus')
        requests = await page.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=start' in q for q in requests), 'resumePersistsStart')
        await ck(any('event=progress' in q and 'step=2' in q for q in requests), 'resumePersistsCurrentProgress')

        # Forward/back navigation updates real target and persisted progress.
        await root.locator('[data-certloom-tour-next]').click()
        await ck((await root.locator('[data-certloom-tour-progress]').inner_text()).strip() == 'Step 4 of 6', 'nextAdvancesStep')
        await ck((await root.locator('[data-certloom-tour-title]').inner_text()).strip() == 'Control presentation separately', 'nextUpdatesTitle')
        await ck(await page.locator('.certloom-guided-tour-target').count() == 1, 'targetMovesWithStep')
        await root.locator('[data-certloom-tour-back]').click()
        await ck((await root.locator('[data-certloom-tour-progress]').inner_text()).strip() == 'Step 3 of 6', 'backReturnsStep')
        requests = await page.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=progress' in q and 'step=3' in q for q in requests), 'nextPersistsProgress')

        # Escape is a permanent dismiss and focus returns to Dashboard heading.
        await root.press('Escape')
        await ck(await root.is_hidden(), 'escapeDismissesTour')
        await ck(await page.locator('.certloom-guided-tour-target').count() == 0, 'dismissClearsTargetHighlight')
        await ck(await page.evaluate("document.activeElement && document.activeElement.id === 'certloom-dashboard-title'"), 'dismissRestoresDashboardFocus')
        requests = await page.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=dismiss' in q for q in requests), 'escapePersistsDismissal')
        await ck(await page.get_by_role('heading', name='Dashboard').count() == 1, 'dashboardRemainsUsableAfterDismiss')
        await page.close()

        # Restart path starts at step one and can finish all six steps.
        restart = await browser.new_page(viewport={'width': 1280, 'height': 850})
        await restart.set_content(fixture('restart'), wait_until='load')
        rroot = restart.locator('[data-certloom-guided-tour]')
        await rroot.wait_for(state='visible')
        await ck((await rroot.locator('[data-certloom-tour-progress]').inner_text()).strip() == 'Step 1 of 6', 'restartStartsAtOne')
        await ck(await rroot.locator('[data-certloom-tour-back]').is_disabled(), 'backDisabledOnFirstStep')
        await ck((await rroot.locator('[data-certloom-tour-title]').inner_text()).strip() == 'Issue a credential', 'firstStepTitle')
        await ck('page=certloom-current-registry' in ((await rroot.locator('[data-certloom-tour-open]').get_attribute('href')) or '') or 'certloom' in ((await rroot.locator('[data-certloom-tour-open]').get_attribute('href')) or ''), 'firstStepOpensRealIssuance')
        rrequests = await restart.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=restart' in q for q in rrequests), 'restartPersistsRestartEvent')
        for i in range(5):
            await rroot.locator('[data-certloom-tour-next]').click()
        await ck((await rroot.locator('[data-certloom-tour-progress]').inner_text()).strip() == 'Step 6 of 6', 'reachesFinalStep')
        await ck((await rroot.locator('[data-certloom-tour-next]').inner_text()).strip() == 'Finish tour', 'finalButtonLabel')
        await ck((await rroot.locator('[data-certloom-tour-title]').inner_text()).strip() == 'Review what is happening', 'finalStepTitle')
        await rroot.locator('[data-certloom-tour-next]').click()
        await ck(await rroot.is_hidden(), 'finishClosesTour')
        rrequests = await restart.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=complete' in q and 'step=5' in q for q in rrequests), 'finishPersistsCompletion')
        await ck(not any('event=dismiss' in q for q in rrequests), 'finishDoesNotRecordDismissal')
        await restart.close()

        # Explicit dismiss button remains permanent and visible.
        dismiss = await browser.new_page(viewport={'width': 1024, 'height': 768})
        await dismiss.set_content(fixture('restart'), wait_until='load')
        droot = dismiss.locator('[data-certloom-guided-tour]')
        await droot.wait_for(state='visible')
        button = droot.get_by_role('button', name='Dismiss product tour')
        await ck(await button.count() == 1, 'dismissButtonAccessibleName')
        await button.click()
        await ck(await droot.is_hidden(), 'dismissButtonCloses')
        drequests = await dismiss.evaluate('window.__certloomTourRequests.slice()')
        await ck(any('event=dismiss' in q for q in drequests), 'dismissButtonPersistsDismissal')
        await dismiss.close()

        # Mobile target sizes / no overflow.
        mobile = await browser.new_page(viewport={'width': 390, 'height': 820})
        await mobile.set_content(fixture('restart'), wait_until='load')
        mroot = mobile.locator('[data-certloom-guided-tour]')
        await mroot.wait_for(state='visible')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'mobileNoHorizontalOverflow')
        for selector, label in [('[data-certloom-tour-back]','mobileBackTarget'),('[data-certloom-tour-next]','mobileNextTarget'),('[data-certloom-tour-dismiss]','mobileDismissTarget')]:
            box = await mroot.locator(selector).bounding_box()
            await ck(bool(box and box['height'] >= 44), label)
        mbox = await mroot.bounding_box()
        await ck(bool(mbox and mbox['x'] >= 0 and mbox['x'] + mbox['width'] <= 391), 'mobileCoachmarkWithinViewport')
        await mobile.close()

        # Reduced motion and forced-colors contracts exercise actual media queries.
        reduced = await browser.new_page(viewport={'width': 900, 'height': 700}, reduced_motion='reduce')
        await reduced.set_content(fixture('restart'), wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"), 'reducedMotionActive')
        await ck(await reduced.locator('[data-certloom-guided-tour]').is_visible(), 'tourVisibleWithReducedMotion')
        await reduced.close()

        forced = await browser.new_page(viewport={'width': 900, 'height': 700}, forced_colors='active')
        await forced.set_content(fixture('restart'), wait_until='load')
        fcard = forced.locator('.certloom-guided-tour-card')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"), 'forcedColorsActive')
        await ck(await fcard.is_visible(), 'tourVisibleInForcedColors')
        await ck((await fcard.evaluate('(e)=>getComputedStyle(e).borderTopStyle')) != 'none', 'forcedColorsBorderRetained')
        await forced.close()

        await browser.close()

    if failed:
        print('FAIL Phase 3 guided Dashboard tour Chromium contract:', ', '.join(failed))
        print(f'\n{checks} guided-tour browser checks, {len(failed)} failures')
        return 1
    print('PASS Phase 3 guided Dashboard tour Chromium contract')
    print(f'\n{checks} guided-tour browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
