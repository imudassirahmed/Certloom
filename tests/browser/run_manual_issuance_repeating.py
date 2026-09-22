#!/usr/bin/env python3
"""Chromium acceptance for alpha.4.79 Issue Credential repeating data."""
import asyncio
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests/browser/build_manual_issuance_repeating_fixture.php'


def fixture():
    p = subprocess.run(['php', str(BUILDER)], cwd=ROOT, text=True, capture_output=True)
    if p.returncode:
        raise RuntimeError(p.stderr or p.stdout)
    return p.stdout


async def reach_data_step(page):
    await page.set_content(fixture(), wait_until='load')
    await page.wait_for_selector('[data-issue-body]:not([hidden])')
    recipient = page.locator('[data-picker="recipient"] [data-picker-search]')
    await recipient.focus()
    await page.wait_for_selector('[data-picker="recipient"] [role="option"]')
    await page.locator('[data-picker="recipient"] [role="option"]').first.click()
    await page.locator('[data-step-next]').click()
    program = page.locator('[data-picker="program"] [data-picker-search]')
    await program.focus()
    await page.wait_for_selector('[data-picker="program"] [role="option"]')
    await page.locator('[data-picker="program"] [role="option"]', has_text='Program Beta').click()
    batch = page.locator('[data-picker="batch"] [data-picker-search]')
    await batch.focus()
    await page.wait_for_selector('[data-picker="batch"] [role="option"]')
    await page.locator('[data-picker="batch"] [role="option"]').first.click()
    await page.locator('[data-step-next]').click()
    await page.wait_for_function("document.querySelector('[data-step-status]').textContent === 'Step 3 of 6'")


async def main():
    checks = 0
    failed = []

    async def ck(value, name):
        nonlocal checks
        checks += 1
        if not value:
            failed.append(name)

    html = fixture()
    await ck('admin/assets/admin.js' not in html, 'fixtureDoesNotRestoreDeletedGlobalIssuanceController')
    await ck('data-certloom-repeating-rowset' not in html, 'fixtureDoesNotUseDeletedRepeatingMarkup')

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            executable_path='/usr/bin/chromium',
            args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        )
        page = await browser.new_page(viewport={'width': 1440, 'height': 1000})
        page.set_default_timeout(7000)
        await reach_data_step(page)

        results = page.locator('.certloom-repeating-editor[data-rowset="results"]')
        subjects = page.locator('.certloom-repeating-editor[data-rowset="subjects"]')
        await ck(await results.count() == 1 and await subjects.count() == 1, 'profileRowsetsRenderedFromBootstrap')
        await ck(await results.locator('.certloom-repeating-row').count() == 0, 'resultsStartEmpty')
        await ck(await subjects.locator('.certloom-repeating-row').count() == 0, 'subjectsStartEmpty')

        add = results.locator('[data-add-row="results"]')
        await ck(not await add.is_disabled() and await add.get_attribute('aria-disabled') == 'false', 'addInitiallyAvailable')
        await add.click()
        await ck(await results.locator('.certloom-repeating-row').count() == 1, 'addCreatesResultRow')
        first = results.locator('.certloom-repeating-row').first
        await ck(await first.locator('[data-row-field="subject"]').count() == 1, 'resultRowHasSubjectField')
        await ck(await first.locator('[data-row-field="score"]').count() == 1, 'resultRowHasScoreField')
        await ck(await first.locator('[data-row-field="subject"]').evaluate('(e)=>document.activeElement===e'), 'addFocusesFirstNewField')
        await first.locator('[data-row-field="subject"]').fill('Mathematics')
        await first.locator('[data-row-field="score"]').fill('88')
        await first.locator('[data-row-field="grade"]').fill('A')

        subject_add = subjects.locator('[data-add-row="subjects"]')
        await subject_add.click()
        await ck(await subjects.locator('.certloom-repeating-row').count() == 1, 'subjectsCanBeAddedIndependently')
        await subjects.locator('[data-row-field="subject"]').fill('Physics')

        # Removing a row restores keyboard focus to the rowset's Add button.
        await subjects.locator('[data-remove-row]').click()
        await ck(await subjects.locator('.certloom-repeating-row').count() == 0, 'removeDeletesOnlyTargetRow')
        await ck(await subject_add.evaluate('(e)=>document.activeElement===e'), 'removeRestoresFocusToAdd')

        # Fill capacity. Re-rendering is intentional; Locator resolves the new Add button.
        for _ in range(49):
            await add.click()
        await ck(await results.locator('.certloom-repeating-row').count() == 50, 'rowsetStopsAt50Rows')
        await ck(await add.is_disabled() and await add.get_attribute('aria-disabled') == 'true', 'addDisabledAccessiblyAt50Rows')
        await ck('50 rows' in await results.locator('.certloom-repeating-limit').inner_text(), 'capacityExplainedInUI')

        last = results.locator('.certloom-repeating-row').last
        await last.locator('[data-remove-row]').click()
        await ck(await results.locator('.certloom-repeating-row').count() == 49, 'removalReopensCapacity')
        await ck(not await add.is_disabled() and await add.get_attribute('aria-disabled') == 'false', 'addReenabledBelowCapacity')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'desktopNoHorizontalOverflow')

        # Repeating data is carried in the REST Review payload, not hidden form inputs.
        await page.locator('[data-step-next]').click()  # Certificate & signing.
        await page.locator('[data-step-next]').click()  # Email.
        await page.locator('[data-step-next]').click()  # Review request.
        await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/admin/issue/review'))")
        review_body = await page.evaluate("window.__issueCalls.filter(c=>c.url.includes('/admin/issue/review')).slice(-1)[0].body")
        await ck(len(review_body.get('issuance', {}).get('repeating_data', {}).get('results', [])) == 49, 'reviewCarriesRepeatingRowsOverRest')
        await ck(review_body['issuance']['repeating_data']['results'][0].get('subject') == 'Mathematics', 'reviewPreservesResultValue')
        await ck(review_body['issuance']['repeating_data']['results'][0].get('score') == '88', 'reviewPreservesScoreValue')
        await page.close()

        mobile = await browser.new_page(viewport={'width': 390, 'height': 844})
        mobile.set_default_timeout(7000)
        await reach_data_step(mobile)
        mresults = mobile.locator('.certloom-repeating-editor[data-rowset="results"]')
        madd = mresults.locator('[data-add-row="results"]')
        await madd.click()
        mrow = mresults.locator('.certloom-repeating-row').first
        cols = await mrow.locator('.certloom-repeating-row-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')
        await ck(cols == 1, 'mobileFieldsSingleColumn')
        mremove = mrow.locator('[data-remove-row]')
        ah = await madd.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)')
        rh = await mremove.evaluate('(e)=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)')
        await ck(ah >= 43.5 and rh >= 43.5, 'mobileActionsMeet44px')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'), 'mobileNoHorizontalOverflow')
        await mobile.emulate_media(forced_colors='active')
        await mobile.wait_for_timeout(30)
        border = await mrow.evaluate('(e)=>getComputedStyle(e).borderStyle')
        await ck(border != 'none', 'forcedColorsPreservesRowBoundary')
        await mobile.close()
        await browser.close()

    if failed:
        print('FAIL  alpha.4.79 repeating data Chromium: ' + ', '.join(failed))
        print(f'\n{checks - len(failed)} passed, {len(failed)} failures')
        return 1
    print('PASS  alpha.4.79 repeating data Chromium: REST state, accessibility, 50-row cap and responsive behavior')
    print(f'\n{checks} checks, 0 failures')
    return 0


if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
