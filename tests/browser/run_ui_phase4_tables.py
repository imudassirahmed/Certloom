#!/usr/bin/env python3
"""Chromium acceptance for Phase 4.8 Table/Data Table Designer authoring."""
import asyncio,json,os,subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]; BUILDER=ROOT/'tests/browser/build_ui_phase4_tables_fixture.php'
def fixture(schema):
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']=str(schema)
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,text=True,capture_output=True,env=env)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout
async def snap(page):
    await page.evaluate("""() => {const f=document.querySelector('[data-certloom-designer-form]'); f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}""")
    await page.wait_for_timeout(40)
    return json.loads(await page.locator('[data-certloom-designer-json]').input_value())
async def main():
    failed=[]; checks=0
    async def ck(v,n):
        nonlocal checks; checks+=1
        if not v: failed.append(n)
    html4=fixture(4); html3=fixture(3)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':900}); page.set_default_timeout(6000)
        await page.set_content(html4,wait_until='load'); await page.wait_for_timeout(240)
        await page.locator('[data-certloom-designer-insert-focus]').click(); await page.wait_for_timeout(30)
        table_add=page.locator('[data-certloom-designer-add="table"]'); data_add=page.locator('[data-certloom-designer-add="data_table"]')
        await ck(await table_add.count()==1 and not await table_add.is_disabled(),'schema4TableInsertAvailable')
        await ck(await data_add.count()==1 and not await data_add.is_disabled(),'schema4DataTableInsertAvailable')
        before=await snap(page); await table_add.click(); await page.wait_for_timeout(100); fixed=await snap(page)
        await ck(len(fixed['elements'])==len(before['elements'])+1,'fixedTableInsertAddsOneElement')
        table=fixed['elements'][-1]; tid=table['id']
        await ck(table.get('type')=='table' and len(table.get('columns',[]))==2 and len(table.get('rows',[]))==1,'fixedTableInsertMaterializesCanonicalDefaults')
        await ck(abs(sum(float(c['width_pct']) for c in table['columns'])-100)<.001,'fixedTableDefaultWidthsTotal100')
        await ck('table_preset' not in table and table.get('show_header') is True and isinstance(table.get('style'),dict),'registryPresetNeverLeaksIntoCanonicalTable')
        await ck(await page.locator('[data-certloom-designer-content="table"]').is_visible(),'fixedTableStructuredPropertiesVisible')
        await ck(await page.locator('[data-certloom-designer-style="table"]').count()==1 and await page.locator('[data-certloom-designer-property-section="appearance"] .certloom-studio-accordion-trigger').is_visible(),'fixedTableAppearanceAvailableInAccordion')
        await ck(await page.locator('[data-certloom-designer-table-column]').count()==2,'fixedTableRendersStructuredColumnRows')
        await ck(await page.locator('[data-certloom-designer-table-row]').count()==1,'fixedTableRendersStructuredCellRow')
        preview=page.locator(f'[data-certloom-designer-element="{tid}"] table')
        await ck(await preview.count()==1,'fixedTableCanvasUsesTablePreview')
        await ck(await preview.locator('th').count()==2 and await preview.locator('td').count()==2,'fixedTableCanvasPreviewShowsHeaderAndCells')
        # Add row and prove undo/redo.
        await page.locator('[data-certloom-designer-content="table"] [data-certloom-designer-table-add-row]').click(); await page.wait_for_timeout(80)
        two=await snap(page); table2=next(e for e in two['elements'] if e['id']==tid); await ck(len(table2['rows'])==2,'fixedTableAddRowUsesCanonicalMutation')
        await page.locator('[data-certloom-designer-undo]').click(); await page.wait_for_timeout(70); undone=await snap(page); await ck(len(next(e for e in undone['elements'] if e['id']==tid)['rows'])==1,'fixedTableRowAddUndoable')
        await page.locator('[data-certloom-designer-redo]').click(); await page.wait_for_timeout(70); redone=await snap(page); await ck(len(next(e for e in redone['elements'] if e['id']==tid)['rows'])==2,'fixedTableRowAddRedoable')
        # Add column, widths remain exact 100, rows get new key.
        await page.locator('[data-certloom-designer-content="table"] [data-certloom-designer-table-add-column]').click(); await page.wait_for_timeout(80); added=await snap(page); at=next(e for e in added['elements'] if e['id']==tid)
        await ck(len(at['columns'])==3 and abs(sum(float(c['width_pct']) for c in at['columns'])-100)<.001,'fixedTableAddColumnRebalancesWidthTo100')
        newkey=at['columns'][-1]['key']; await ck(all(newkey in r for r in at['rows']),'fixedTableAddColumnExpandsEveryLiteralRowSafely')
        # Edit first visible cell.
        first_cell=page.locator('[data-certloom-designer-content="table"] [data-certloom-designer-table-row="0"] input').first
        await first_cell.fill('Credential ID'); await first_cell.dispatch_event('change'); await page.wait_for_timeout(70); edited=await snap(page); et=next(e for e in edited['elements'] if e['id']==tid)
        await ck('Credential ID' in list(et['rows'][0].values()),'fixedTableCellEditPersistsLiteralText')
        # Appearance uses same style authority.
        appearance=page.locator('[data-certloom-designer-property-section="appearance"]')
        if 'is-open' not in ((await appearance.get_attribute('class')) or ''):
            await appearance.locator('.certloom-studio-accordion-trigger').click(); await page.wait_for_timeout(30)
        header_fill=page.locator('[data-certloom-designer-style="table"] [data-certloom-designer-style-field="header_fill"]'); await header_fill.fill('#EAF0FF'); await header_fill.dispatch_event('change'); await page.wait_for_timeout(70)
        styled=await snap(page); await ck(next(e for e in styled['elements'] if e['id']==tid)['style']['header_fill']=='#EAF0FF','tableAppearanceMutationPersistsCanonically')
        # Data Table. Insert drawer intentionally closes after an insertion.
        await page.locator('[data-certloom-designer-insert-focus]').click(); await page.wait_for_timeout(30)
        await data_add.click(); await page.wait_for_timeout(100); dynamic=await snap(page); dt=dynamic['elements'][-1]; did=dt['id']
        await ck(dt.get('type')=='data_table' and dt.get('source')=='credential.results' and dt.get('max_rows')==8,'dataTableInsertDefaultsToImmutableResults')
        await ck([c.get('field') for c in dt['columns']]==['subject','score','grade'],'dataTableDefaultColumnsUseClosedResultFields')
        await ck('rows' not in dt,'dataTableCanonicalBytesDoNotEmbedSyntheticPreviewRows')
        await ck(await page.locator('[data-certloom-designer-content="data_table"]').is_visible(),'dataTablePropertiesVisible')
        await ck(await page.locator('[data-certloom-designer-content="data_table"] [data-certloom-designer-table-source]').is_visible(),'dataTableSourceVisible')
        dpreview=page.locator(f'[data-certloom-designer-element="{did}"] table')
        await ck(await dpreview.count()==1 and await dpreview.locator('tbody tr').count()>=1,'dataTableCanvasUsesSyntheticProfileRows')
        canonical_before=json.dumps(dynamic,sort_keys=True,separators=(',',':'))
        await page.locator('.certloom-studio-view-menu summary').click(); await page.wait_for_timeout(30)
        await page.locator('[data-certloom-designer-sample-data]').select_option('school-result'); await page.wait_for_timeout(90); sample_after=await snap(page); await page.locator('.certloom-studio-view-menu summary').click(); await page.wait_for_timeout(20)
        await ck(json.dumps(sample_after,sort_keys=True,separators=(',',':'))==canonical_before,'sampleProfileDoesNotMutateDataTableCanonicalBytes')
        await ck(await dpreview.locator('tbody tr').count()==4,'schoolResultPreviewShowsFourSyntheticResultRows')
        # Source switch atomically resets field-compatible columns.
        source=page.locator('[data-certloom-designer-content="data_table"] [data-certloom-designer-table-source]'); await source.select_option('credential.subjects'); await page.wait_for_timeout(90); switched=await snap(page); sd=next(e for e in switched['elements'] if e['id']==did)
        await ck(sd['source']=='credential.subjects' and [c['field'] for c in sd['columns']]==['subject','code','credits'],'dataTableSourceSwitchResetsColumnsAtomically')
        # Max rows and header are canonical/undoable.
        maxrows=page.locator('[data-certloom-designer-content="data_table"] [data-certloom-designer-table-max-rows]'); await maxrows.fill('5'); await maxrows.dispatch_event('change'); await page.wait_for_timeout(70); limited=await snap(page); await ck(next(e for e in limited['elements'] if e['id']==did)['max_rows']==5,'dataTableMaxRowsCanonical')
        header=page.locator('[data-certloom-designer-content="data_table"] [data-certloom-designer-table-header]'); await header.uncheck(); await header.dispatch_event('change'); await page.wait_for_timeout(70); nohead=await snap(page); await ck(next(e for e in nohead['elements'] if e['id']==did)['show_header'] is False,'dataTableHeaderToggleCanonical')
        # Geometry guard rejects too-short height for 5 rows.
        geometry=page.locator('[data-certloom-designer-property-section="geometry"]')
        if 'is-open' not in ((await geometry.get_attribute('class')) or ''):
            await geometry.locator('.certloom-studio-accordion-trigger').click(); await page.wait_for_timeout(30)
        height=page.locator('[data-certloom-designer-field="height"]'); prior=float(await height.input_value()); await height.fill('12'); await height.dispatch_event('change'); await page.wait_for_timeout(60); guarded=await snap(page); gd=next(e for e in guarded['elements'] if e['id']==did)
        await ck(float(gd['height_mm'])==prior,'browserRejectsDataTableGeometryBelow4mmPerRow')
        # Lock protects structured content.
        lock=page.locator(f'[data-certloom-designer-layer-row="{did}"] [data-certloom-designer-layer-row-action="lock"]'); await lock.click(); await page.wait_for_timeout(60)
        await ck(await source.is_disabled() and await maxrows.is_disabled() and await header.is_disabled(),'lockedDataTableDisablesSourceRowsAndHeader')
        await ck(await page.locator('[data-certloom-designer-content="data_table"] [data-certloom-designer-table-add-column]').is_disabled(),'lockedDataTableDisablesColumnMutation')
        await lock.click(); await page.close()
        # Old schemas fail closed.
        old=await browser.new_page(viewport={'width':1280,'height':800}); old.set_default_timeout(5000); await old.set_content(html3,wait_until='load'); await old.wait_for_timeout(180)
        await ck(await old.locator('[data-certloom-designer-add="table"]').is_disabled(),'schema3TableInsertDisabled')
        await ck(await old.locator('[data-certloom-designer-add="data_table"]').is_disabled(),'schema3DataTableInsertDisabled'); await old.close()
        # Mobile/forced colors.
        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(html4,wait_until='load'); await mobile.wait_for_timeout(180); await mobile.locator('[data-certloom-designer-insert-focus]').click(); await mobile.wait_for_timeout(20); await mobile.locator('[data-certloom-designer-add="table"]').click(); await mobile.wait_for_timeout(80)
        addrow=mobile.locator('[data-certloom-designer-content="table"] [data-certloom-designer-table-add-row]'); addcol=mobile.locator('[data-certloom-designer-content="table"] [data-certloom-designer-table-add-column]')
        rh=await addrow.evaluate("e=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)"); ch=await addcol.evaluate("e=>Math.max(parseFloat(getComputedStyle(e).minHeight)||0,e.getBoundingClientRect().height)")
        await ck(rh>=43.5 and ch>=43.5,'mobileTableActionsMeet44pxContract')
        await mobile.emulate_media(forced_colors='active'); await mobile.wait_for_timeout(40); border=await mobile.locator('[data-certloom-designer-table-column]').first.evaluate("e=>getComputedStyle(e).borderStyle")
        await ck(border!='none','forcedColorsKeepsStructuredTableRowsPerceivable'); await mobile.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4.8 tables Chromium: '+', '.join(sorted(set(failed)))); print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4.8 tables Chromium'); print(f'\n{checks} checks, 0 failures'); return 0
if __name__=='__main__': raise SystemExit(asyncio.run(main()))
