#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ui_phase4_dashboard_review_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode:
        raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        desktop=await browser.new_page(viewport={'width':1365,'height':900})
        await desktop.set_content(fixture(),wait_until='load')
        async def ck(ok,label):
            nonlocal checks
            checks+=1
            if not ok: failed.append(label)
        shell=desktop.locator('#dashboard-review-surface')
        helpbox=desktop.locator('#screen-meta-links')
        hero=desktop.locator('.certloom-dashboard-hero')
        sr=await shell.bounding_box(); hr=await helpbox.bounding_box(); heror=await hero.bounding_box()
        await ck(sr is not None and hr is not None and sr['y'] >= hr['y'] + hr['height'] - 1,'shellClearsNativeHelpFloat')
        await ck(heror is not None and sr is not None and abs((heror['x']+heror['width'])-(sr['x']+sr['width'])) < 2,'heroUsesFullShellWidth')
        buttons=desktop.locator('.certloom-setup-reminder-dismiss .button-link')
        await ck(await buttons.count()==2,'reminderKeepsBothActions')
        for i,label in enumerate(['snooze','dismiss']):
            b=await buttons.nth(i).bounding_box()
            await ck(b is not None and b['width'] > 90 and b['height'] >= 30,label+'ActionReadableNotSquare')
        reminder=desktop.locator('.certloom-setup-reminder')
        rr=await reminder.bounding_box()
        await ck(rr is not None and rr['height'] < 90,'desktopReminderRemainsCompact')
        table=desktop.locator('.certloom-dashboard-recent-table')
        tr=await table.bounding_box(); panel=await desktop.locator('.certloom-panel').first.bounding_box()
        await ck(tr is not None and panel is not None and tr['width'] <= panel['width'] + 1,'desktopRecentTableFitsPanel')
        await ck(await table.evaluate("e=>getComputedStyle(e).minWidth")=='0px','desktopRecentTableCancelsRegistryMinWidth')
        status_cell=await desktop.locator('.certloom-dashboard-recent-table tbody td:nth-child(5)').first.bounding_box()
        await ck(status_cell is not None and panel is not None and status_cell['x'] + status_cell['width'] <= panel['x'] + panel['width'] + 1,'desktopStatusColumnVisibleInsidePanel')
        await ck(await desktop.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopHasNoHorizontalOverflow')
        await ck(await desktop.locator('.certloom-dashboard-recent-table thead th').count()==5,'dashboardTableUsesFiveCompactColumns')
        headers=[x.strip() for x in await desktop.locator('.certloom-dashboard-recent-table thead th').all_inner_texts()]
        await ck(headers==['Credential','Recipient','Program / batch','Dates','Status'],'dashboardCompactHeaders')
        recipient_width=float((await desktop.locator('.certloom-dashboard-recent-table thead th').nth(1).evaluate("e=>getComputedStyle(e).width")).replace('px',''))
        program_width=float((await desktop.locator('.certloom-dashboard-recent-table thead th').nth(2).evaluate("e=>getComputedStyle(e).width")).replace('px',''))
        dates_width=float((await desktop.locator('.certloom-dashboard-recent-table thead th').nth(3).evaluate("e=>getComputedStyle(e).width")).replace('px',''))
        status_width=float((await desktop.locator('.certloom-dashboard-recent-table thead th').nth(4).evaluate("e=>getComputedStyle(e).width")).replace('px',''))
        await ck(recipient_width > dates_width and recipient_width < tr['width']*.33,'recipientColumnReducedButStillPrimary')
        await ck(dates_width > program_width and status_width > program_width,'datesAndStatusReceiveMoreRoomThanProgram')
        await ck(float((await desktop.locator('.certloom-dashboard-recent-table thead th').nth(3).evaluate("e=>getComputedStyle(e).paddingLeft")).replace('px','')) >= 16,'datesColumnHasBreathingRoomAfterProgram')
        await ck(await desktop.locator('.certloom-dashboard-date-line').count()==4,'dashboardDatesStackIssuedAndExpiry')
        await ck(await desktop.get_by_role('link',name='View').count()==2,'dashboardCurrentCredentialsExposeView')
        await ck(await desktop.get_by_role('link',name='Edit').count()==2,'dashboardCurrentCredentialsExposeEdit')
        await ck('certloom-brand-mark.png' in await hero.evaluate("e=>getComputedStyle(e,'::after').backgroundImage"),'watermarkStillPresent')
        await ck(abs(float(await hero.evaluate("e=>getComputedStyle(e,'::after').opacity"))-.115)<.002,'watermarkOpacityUnchanged')
        good_dot=desktop.locator('[data-health="good"] .certloom-health-dot')
        warning_dot=desktop.locator('[data-health="warning"] .certloom-health-dot')
        bad_dot=desktop.locator('[data-health="bad"] .certloom-health-dot')
        await ck(await good_dot.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(1, 193, 116)','readyHealthDotIsGreen')
        await ck(await warning_dot.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(138, 90, 0)','warningHealthDotIsAmber')
        await ck(await bad_dot.evaluate("e=>getComputedStyle(e).backgroundColor")=='rgb(192, 39, 31)','badHealthDotIsRed')
        await ck(len({await good_dot.evaluate("e=>getComputedStyle(e).backgroundColor"), await warning_dot.evaluate("e=>getComputedStyle(e).backgroundColor"), await bad_dot.evaluate("e=>getComputedStyle(e).backgroundColor")})==3,'healthStatesUseDistinctSemanticColours')
        await desktop.close()

        mobile=await browser.new_page(viewport={'width':390,'height':900})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileHasNoHorizontalOverflow')
        await ck(await mobile.locator('.certloom-dashboard-recent-table thead').evaluate("e=>getComputedStyle(e).position")=='absolute','mobileTableHeaderVisuallyHidden')
        first_td=mobile.locator('.certloom-dashboard-recent-table tbody td').first
        await ck(await first_td.evaluate("e=>getComputedStyle(e).display")=='grid','mobileRecentRowsUseLabeledGrid')
        await ck(await first_td.evaluate("e=>getComputedStyle(e,'::before').content") not in ('none','normal',''),'mobileRecentRowsExposeColumnLabels')
        await ck(await mobile.locator('.certloom-setup-reminder-actions').evaluate("e=>getComputedStyle(e).flexDirection")=='column','mobileReminderActionsStackCleanly')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  Phase 4 dashboard visual-review browser gate:', ', '.join(failed))
        print(f'\n{checks-len(failed)} passed, {len(failed)} failures')
        return 1
    print('PASS  Phase 4 dashboard visual-review browser gate: native Help float clearance, readable setup actions, compact responsive recent activity, approved watermark preserved')
    print(f'\n{checks} browser checks, 0 failures')
    return 0

raise SystemExit(asyncio.run(run()))
