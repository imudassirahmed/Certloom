#!/usr/bin/env python3
"""Real Chromium FLD-02 dynamic Manual/Bulk field-form acceptance."""
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]
def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_scoped_field_form_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout
async def run():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(ok,label):
            nonlocal checks; checks+=1
            if not ok: failed.append(label)
        page=await browser.new_page(viewport={'width':1100,'height':900})
        await page.set_content(fixture(),wait_until='load'); await page.wait_for_timeout(50)
        manual=page.locator('#manual')
        await ck(await manual.locator('[name="new_recipient[first_name]"]').get_attribute('required') is not None,'recipientRequiredFromContract')
        await ck(await manual.locator('[data-certloom-legacy-custom-fields]').is_hidden(),'configuredProgramHidesLegacyKeys')
        await ck(await manual.locator('[data-certloom-legacy-custom-fields] input').is_disabled(),'hiddenLegacyKeysDisabled')
        gpa=manual.locator('[name="custom_merge_fields[gpa]"]')
        await ck(await gpa.count()==1 and await gpa.get_attribute('type')=='number','gpaUsesNumberInput')
        await ck(await gpa.get_attribute('required') is not None and await gpa.input_value()=='3.50','gpaRequiredAndExistingValueRestored')
        statement=manual.locator('[name="custom_merge_fields[statement]"]')
        await ck(await statement.evaluate('(e)=>e.tagName')=='TEXTAREA','textareaTypeRenderedTruthfully')
        await ck(await manual.locator('[data-certloom-grade-field]').is_visible() and await manual.locator('[name="grade"]').get_attribute('required') is None,'optionalGradeVisible')
        await ck('Privacy: profile · personal' in await manual.locator('.certloom-scoped-field-row').filter(has_text='GPA').inner_text(),'privacyMetadataVisible')

        await gpa.fill('3.91')
        await manual.locator('select[name="batch_id"]').select_option('501'); await manual.locator('select[name="batch_id"]').dispatch_event('change'); await page.wait_for_timeout(50)
        await ck(await manual.locator('[name="custom_merge_fields[gpa]"]').input_value()=='3.91','valuePreservedAcrossBatchResolution')
        campus=manual.locator('[name="custom_merge_fields[campus]"]')
        await ck(await campus.count()==1 and await campus.get_attribute('required') is not None,'batchRequiredFieldAdded')
        await ck('Batch · Required' in await manual.locator('.certloom-scoped-field-row').filter(has_text='Campus').inner_text(),'batchOriginExplained')
        await ck(await manual.locator('[name="grade"]').get_attribute('required') is not None,'batchCanTightenGradeToRequired')
        await ck('Program + Batch' in await manual.locator('[data-certloom-scoped-field-status]').inner_text(),'resolvedContextAnnounced')

        await manual.locator('select[name="program_id"]').select_option('102'); await manual.locator('select[name="program_id"]').dispatch_event('change'); await page.wait_for_timeout(50)
        await ck(await manual.locator('[data-certloom-scoped-field-inputs] .certloom-scoped-field-row').count()==0,'configuredEmptyProgramHasNoDynamicFields')
        await ck(await manual.locator('[data-certloom-legacy-custom-fields]').is_hidden(),'configuredEmptyDoesNotReopenLegacyBypass')
        await ck(await manual.locator('[data-certloom-grade-field]').is_hidden() and await manual.locator('[name="grade"]').is_disabled(),'configuredEmptyHidesUnassignedGrade')

        await manual.locator('select[name="program_id"]').select_option('103'); await manual.locator('select[name="program_id"]').dispatch_event('change'); await page.wait_for_timeout(50)
        await ck(await manual.locator('[data-certloom-legacy-custom-fields]').is_visible(),'legacyProgramRetainsMigrationCompatibility')
        await ck(not await manual.locator('[data-certloom-legacy-custom-fields] input').is_disabled(),'legacyControlsReenabled')
        await ck(await manual.locator('[data-certloom-grade-field]').is_visible(),'legacyProgramRetainsGrade')
        await ck(await manual.locator('[name="new_recipient[first_name]"]').get_attribute('required') is None,'recipientContractRequirementResets')

        bulk=page.locator('#bulk'); schema=await bulk.locator('[data-certloom-bulk-field-schema-body]').inner_text()
        await ck('custom:gpa' in schema and '(required)' in schema,'bulkSchemaNamesRequiredScopedColumn')
        await ck('custom:campus' in schema and 'grade' in schema,'bulkSchemaUsesSameBatchContract')
        calls=await page.evaluate('window.__fieldCalls')
        await ck(any(c.get('action')=='certloom_resolve_scoped_fields' and c.get('program_id')=='101' and c.get('batch_id')=='501' for c in calls),'browserRequestsExactProgramBatchContract')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await page.close()

        mobile=await browser.new_page(viewport={'width':360,'height':760})
        await mobile.set_content(fixture(),wait_until='load'); await mobile.wait_for_timeout(50)
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        await ck(await mobile.locator('.fixture-recipient').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length')==1,'mobileRecipientSingleColumn')
        await ck(await mobile.locator('[name="custom_merge_fields[gpa]"]').is_visible(),'mobileDynamicFieldVisible')
        await mobile.close(); await browser.close()
    if failed:
        print('FAIL  FLD-02 dynamic field Chromium gate:', ', '.join(failed)); return 1
    print('PASS  FLD-02 dynamic field Chromium gate: typed inputs, recipient ownership, Batch tightening, value preservation, legacy migration boundary and Bulk schema alignment')
    print(f'\n{checks} scoped-field browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(run()))
