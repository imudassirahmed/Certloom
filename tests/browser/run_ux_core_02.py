#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_issue_credential_479_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        page=await browser.new_page(viewport={'width':1200,'height':950}); page.set_default_timeout(6000); await page.set_content(fixture(),wait_until='load'); await page.wait_for_selector('[data-issue-body]:not([hidden])')
        # Existing/new Recipient ownership is explicit; new profile is focused and reusable.
        await ck(await page.locator('[data-picker="recipient"]').is_visible(),'existingRecipientStartsVisible')
        await ck(await page.locator('[data-new-recipient]').is_hidden(),'newRecipientPanelStartsHidden')
        await page.locator('[data-recipient-mode="new"]').click(); panel=page.locator('[data-new-recipient]'); await ck(await panel.is_visible(),'newRecipientModeShowsFocusedProfilePanel')
        for field in ['display_name','email','first_name','middle_name','last_name','external_id']:
            await ck(await panel.locator(f'[data-field="new_recipient.{field}"]').count()==1,f'newRecipientField_{field}')
        await ck(await panel.locator('[data-picker="wp_user"]').count()==1,'newRecipientOffersAsyncWordPressAccountPicker')
        wp=panel.locator('[data-picker="wp_user"] [data-picker-search]'); await wp.focus(); await page.wait_for_selector('[data-picker="wp_user"] [role="option"]'); await page.locator('[data-picker="wp_user"] [role="option"]').click(); await ck('amina_wp' in await panel.locator('[data-picker="wp_user"] [data-picker-selected]').inner_text(),'wordPressAccountSelectedWithoutRawNumericId')
        await panel.locator('[data-field="new_recipient.display_name"]').fill('Amina New'); await panel.locator('[data-field="new_recipient.email"]').fill('new@example.test')
        await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 2 of 6','recipientDraftContinuesToContextStep')

        # Quick Program deliberately inherits designs; it does not expose presentation assignment.
        await page.locator('[data-quick-open="program"]').click(); qprog=page.locator('[data-quick-dialog="program"]'); await ck(await qprog.get_attribute('open') is not None,'quickProgramDialogOpensInPlace')
        await ck(await qprog.locator('[name="program_template_id"], [name^="program_field_requirement["]').count()==0,'quickProgramAvoidsDesignAndFieldRuleConfiguration')
        qbox=await qprog.bounding_box(); await ck(qbox is not None and qbox['height'] <= 950*0.90,'quickProgramDialogBoundedToViewport')
        await qprog.locator('[name="title"]').fill('Advanced Safety Certificate'); await qprog.locator('[name="code"]').fill('ASC'); await qprog.get_by_role('button',name='Add and use').click(); await page.wait_for_function("document.querySelector('[data-picker=\"program\"] [data-picker-value]').value === '3'")
        await ck(await page.locator('[data-picker="program"] [data-picker-value]').input_value()=='3','quickProgramCreatedAndSelected')
        await ck('Advanced Safety Certificate' in await page.locator('[data-summary-title]').inner_text(),'quickProgramSeedsCredentialTitle')
        # Program selection loads canonical field contract instead of embedding field rules in quick create.
        await page.wait_for_function("window.__issueCalls.some(c=>c.url.includes('/program-context')&&c.url.includes('program_id=3'))")

        # Quick Batch binds to the selected Program and does not disturb the Recipient draft.
        await page.locator('[data-quick-open="batch"]').click(); qbatch=page.locator('[data-quick-dialog="batch"]'); await ck(await qbatch.get_attribute('open') is not None,'quickBatchDialogOpensInPlace')
        await ck(await qbatch.locator('[name="batch_template_id"], [name^="batch_field_requirement["]').count()==0,'quickBatchInheritsPresentationWithoutAssignmentControls')
        await qbatch.locator('[name="name"]').fill('Spring 2027'); await qbatch.locator('[name="code"]').fill('S27'); await qbatch.get_by_role('button',name='Add and use').click(); await page.wait_for_function("document.querySelector('[data-picker=\"batch\"] [data-picker-value]').value === '33'")
        await ck(await page.locator('[data-picker="batch"] [data-picker-value]').input_value()=='33','quickBatchCreatedAndSelected')
        calls=await page.evaluate('window.__issueCalls'); quick_batch=[c for c in calls if '/quick-batch' in c['url']][-1]; await ck(quick_batch['body'].get('program_id')==3,'quickBatchRequestUsesCurrentProgram')
        await page.locator('[data-step-prev]').click(); await ck(await panel.locator('[data-field="new_recipient.display_name"]').input_value()=='Amina New','quickCreatesPreserveUnsavedRecipientDraft')
        await page.locator('[data-step-next]').click(); await page.locator('[data-step-next]').click(); await ck(await page.locator('[data-step-status]').inner_text()=='Step 3 of 6','credentialDataMergedIntoDetailsStep')
        await ck(await page.locator('[data-scoped-field="grade"]').count()==1,'canonicalProgramFieldContractRendersCredentialGrade')
        await ck(await page.locator('[data-scoped-field="address"]').count()==1,'canonicalProgramFieldContractRendersCustomCredentialField')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780}); await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_selector('[data-issue-body]:not([hidden])'); await mobile.locator('[data-recipient-mode="new"]').click(); await ck(await mobile.locator('[data-new-recipient]').is_visible(),'mobileNewRecipientPanelOpensInline'); await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoDocumentOverflow'); await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-02 browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  UX-CORE-02 browser gate: Recipient ownership, WordPress account picker, quick Program/Batch REST continuity and canonical credential-field contract')
    print(f'\n{checks} UX-CORE-02 browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
