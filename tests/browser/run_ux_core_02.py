#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_ux_core_02_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1200,'height':950})
        await page.set_content(fixture(),wait_until='load')
        async def ck(cond,label):
            nonlocal checks; checks+=1
            if not cond: failed.append(label)
        existing=page.locator('[data-certloom-recipient-existing-panel]').first
        newpanel=page.locator('[data-certloom-recipient-new-panel]')
        await ck(not await existing.is_hidden(),'existingRecipientStartsVisible')
        await ck(await newpanel.is_hidden(),'newRecipientSummaryStartsHidden')
        await ck(not await page.locator('input[name="grade"]').is_disabled(),'credentialGradeExistsForExistingRecipient')

        # Quick Program creation must preserve the compose form and activate Program/field contract.
        await page.get_by_role('button',name='Add new Program').first.click()
        qprog=page.locator('#certloom-quick-program-dialog')
        await ck(await qprog.locator('select[name="program_template_id"]').count()==1,'quickProgramOffersDesignSelection')
        await ck(await qprog.locator('select[name^="program_field_requirement["]').count()>=1,'quickProgramOffersCredentialFieldRules')
        qprog_box=await qprog.bounding_box()
        await ck(qprog_box is not None and qprog_box['height'] <= 950 * 0.90,'quickProgramDialogBoundedToViewport')
        qprog_body=qprog.locator('.certloom-dialog-body')
        await ck(await qprog_body.evaluate('(el) => el.scrollHeight > el.clientHeight'),'quickProgramLongBodyScrollsInternally')
        qprog_actions=await qprog.locator('.certloom-dialog-actions').bounding_box()
        await ck(qprog_actions is not None and qprog_actions['y'] + qprog_actions['height'] <= 950,'quickProgramActionsRemainVisible')
        await qprog.locator('input[name="program_title"]').fill('Advanced Safety Certificate')
        await qprog.get_by_role('button',name='Add Program').click()
        await page.wait_for_timeout(500)
        program=page.locator('.certloom-manual-form select[name="program_id"]')
        await ck(await program.input_value()=='100','quickProgramSelectedInIssuance')
        await ck(not await page.locator('[data-certloom-manual-review]').is_disabled(),'quickProgramCanEnableReviewWhenDesignsExist')
        await page.wait_for_timeout(150)
        await ck(await page.locator('input[name="grade"]').get_attribute('required') is not None,'programContractMakesGradeRequired')
        await ck(await page.locator('[data-certloom-scoped-value-key="score"]').count()==1,'batchProgramCredentialCustomFieldRenders')

        # Existing Recipient still never exposes profile creation requirements, while grade remains required.
        email=page.locator('input[name="new_recipient[email]"]')
        await ck(await email.is_disabled(),'newRecipientFieldsDisabledInExistingMode')
        await ck(await page.locator('input[name="grade"]').get_attribute('required') is not None,'requiredGradeRemainsForExistingRecipient')

        # New Recipient switches to a popup with the full supported reusable profile.
        await page.locator('input[name="recipient_mode"][value="new"]').check()
        dialog=page.locator('#certloom-new-recipient-dialog')
        await ck(await dialog.get_attribute('open') is not None,'newRecipientOpensProfilePopup')
        for name in ['display_name','email','first_name','middle_name','last_name','external_id','wp_user_id']:
            await ck(await dialog.locator(f'[name="new_recipient[{name}]"]').count()==1,f'newRecipientField_{name}')
        await page.wait_for_timeout(100)
        await ck(await email.get_attribute('required') is not None,'programRequiredRecipientEmailMarkedRequired')
        await ck(await page.locator('input[name="grade"]').get_attribute('required') is not None,'gradeStillRequiredInNewRecipientMode')
        await dialog.locator('input[name="new_recipient[display_name]"]').fill('Amina Khan')
        await email.fill('amina@example.test')
        await dialog.get_by_role('button',name='Use this recipient profile').click()
        await ck('Amina Khan' in await page.locator('[data-certloom-recipient-new-summary-name]').inner_text(),'newRecipientSummaryUpdates')

        # Quick Batch inherits the selected Program and preserves the issuance draft.
        await page.get_by_role('button',name='Add new Batch').click()
        qbatch=page.locator('#certloom-quick-batch-dialog')
        await ck(await qbatch.locator('select[name="program_id"]').input_value()=='100','quickBatchInheritsProgram')
        await ck(await qbatch.locator('select[name="batch_template_id"]').count()==1,'quickBatchOffersDesignSelection')
        await ck(await qbatch.locator('select[name="batch_template_id"] option[value="0"]').inner_text()=='Inherit Program design','quickBatchMakesProgramInheritanceExplicit')
        await ck(await qbatch.locator('select[name^="batch_field_requirement["]').count()>=1,'quickBatchOffersFieldOverrides')
        await qbatch.locator('input[name="batch_name"]').fill('Spring 2027')
        await qbatch.get_by_role('button',name='Add Batch').click(); await page.wait_for_timeout(450)
        await ck(await page.locator('.certloom-manual-form select[name="batch_id"]').input_value()=='77','quickBatchSelectedInIssuance')
        await ck((await dialog.locator('input[name="new_recipient[display_name]"]').input_value())=='Amina Khan','quickBatchPreservesRecipientDraft')

        # Batch create form can quick-add Program without losing its own unsaved fields.
        await page.locator('#batch-editor').get_by_role('button',name='Add new Program').click()
        bprog=page.locator('#certloom-batch-quick-program-dialog')
        await ck(await bprog.locator('select[name="program_template_id"]').count()==1,'batchQuickProgramOffersDesignSelection')
        await ck(await bprog.locator('select[name^="program_field_requirement["]').count()>=1,'batchQuickProgramOffersCredentialFieldRules')
        await bprog.locator('input[name="program_title"]').fill('Batch Parent Program')
        await bprog.get_by_role('button',name='Add Program').click(); await page.wait_for_timeout(450)
        await ck(await page.locator('#certloom-batch-program-id').input_value()=='101','batchQuickProgramSelected')
        await ck(await page.locator('input[name="batch_name_parent"]').input_value()=='Already typed','batchQuickProgramPreservesUnsavedBatchDraft')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':780})
        await mobile.set_content(fixture(),wait_until='load')
        await mobile.locator('input[name="recipient_mode"][value="new"]').check()
        d=mobile.locator('#certloom-new-recipient-dialog')
        await ck(await d.get_attribute('open') is not None,'mobileRecipientDialogOpens')
        dbox=await d.bounding_box()
        await ck(dbox is not None and dbox['height'] <= 780 * 0.90,'mobileDialogBoundedToViewport')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoDocumentOverflow')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UX-CORE-02 browser gate:', ', '.join(failed)); return 1
    print('PASS  UX-CORE-02 browser gate: Recipient ownership, required credential fields, quick Program/Batch and draft preservation')
    print(f'\n{checks} UX-CORE-02 browser checks, 0 failures')
    return 0
raise SystemExit(asyncio.run(run()))
