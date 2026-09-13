#!/usr/bin/env python3
"""Chromium acceptance for reopened Phase 4 Gamma-02R Certloom Studio."""
import asyncio
import os
import subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]
BUILDER = ROOT / 'tests' / 'browser' / 'build_ui_phase4_designer_v2_fixture.php'
SCREENSHOT = Path('/mnt/data/certloom-gamma02r-studio-browser.png')


def fixture_html():
    env = os.environ.copy()
    env['CERTLOOM_DESIGNER_SCHEMA_VERSION'] = '4'
    proc = subprocess.run(['php', str(BUILDER)], cwd=ROOT, env=env, text=True, capture_output=True)
    if proc.returncode:
        raise RuntimeError(proc.stderr or proc.stdout)
    return proc.stdout


async def main():
    html = fixture_html()
    failed = []
    checks = 0

    async def ck(value, name):
        nonlocal checks
        checks += 1
        if not value:
            failed.append(name)

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            executable_path='/usr/bin/chromium',
            args=['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
        )
        page = await browser.new_page(viewport={'width': 1600, 'height': 980})
        errors = []
        page.on('pageerror', lambda exc: errors.append('pageerror:' + str(exc)))
        page.on('console', lambda msg: errors.append('console:' + msg.text) if msg.type == 'error' else None)
        await page.set_content(html, wait_until='load')
        await page.wait_for_timeout(350)

        root = page.locator('[data-certloom-designer]')
        await ck(await root.count() == 1, 'singleDesignerRoot')
        await ck('certloom-template-designer-v2' in (await root.get_attribute('class') or ''), 'studioV2Class')
        await ck((await root.get_attribute('data-designer-mode')) == 'pro', 'singlePreciseMode')
        await ck(not errors, 'noBrowserRuntimeErrors')

        # Studio identity and intentionally reduced top-level action model.
        await ck((await page.locator('.certloom-studio-back').inner_text()).strip().endswith('Templates'), 'templatesBackLink')
        await ck(await page.locator('.certloom-studio-document').is_visible(), 'documentIdentityVisible')
        await ck(await page.locator('.certloom-studio-actions').get_by_text('Draft', exact=True).is_visible(), 'draftActionVisible')
        await ck(await page.locator('.certloom-studio-actions').get_by_text('Publish', exact=True).is_visible(), 'publishActionVisible')
        await ck(await page.locator('text=Manage design').count() == 0, 'manageDesignNotInStudio')
        await ck(await page.locator('[data-certloom-designer-preview-scroll]').count() == 0, 'noPreviewButton')
        await ck(await page.locator('[data-certloom-designer-test-print]').is_visible(), 'testPrintOnlyProofAction')
        await ck(await page.locator('[data-certloom-studio-import-open]').is_visible(), 'directSingleTemplateImportVisible')

        # Document controls and fitted-100% semantics.
        sizes = await page.locator('[data-certloom-designer-page-size] option').all_text_contents()
        await ck(sizes == ['A4', 'Letter', 'Custom'], 'pageSizesIncludeCustom')
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'initialFitIs100')
        await ck(await page.locator('[data-certloom-designer-fit]').is_visible(), 'fitAlwaysVisible')
        await ck(await page.locator('[data-certloom-designer-zoom-in]').is_visible() and await page.locator('[data-certloom-designer-zoom-out]').is_visible(), 'compactZoomControlsVisible')
        await ck(await page.locator('[data-certloom-designer-preview-mode]').count() == 2, 'sampleTokensToggleVisible')
        await ck((await page.locator('[data-certloom-designer-preview-mode="sample"]').get_attribute('aria-pressed')) == 'true', 'samplePreviewDefault')
        await ck(await page.locator('[data-certloom-designer-ruler="x"]').is_visible(), 'horizontalMillimetreRulerVisible')
        await ck(await page.locator('[data-certloom-designer-ruler="y"]').is_visible(), 'verticalMillimetreRulerVisible')
        await ck(await page.locator('[data-certloom-designer-ruler="x"] .certloom-studio-ruler-mark').count() >= 10, 'horizontalRulerHasLabelledTicks')
        await ck('layout v' not in (await page.locator('[data-certloom-designer-page-readout]').inner_text()).lower(), 'studioReadoutHidesTechnicalSchemaVersion')

        layout = await page.evaluate("""() => {
          const designer=document.querySelector('[data-certloom-designer]').getBoundingClientRect();
          const left=document.querySelector('.certloom-studio-left').getBoundingClientRect();
          const workspace=document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect();
          const page=document.querySelector('[data-certloom-designer-page]').getBoundingClientRect();
          const props=document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect();
          const stageStyle=getComputedStyle(document.querySelector('.certloom-template-designer-stage'));
          const leftStyle=getComputedStyle(document.querySelector('.certloom-studio-left'));
          const workspaceStyle=getComputedStyle(document.querySelector('[data-certloom-designer-workspace]'));
          const propsStyle=getComputedStyle(document.querySelector('.certloom-template-designer-inspector'));
          return {
            docH:document.documentElement.scrollHeight, clientH:document.documentElement.clientHeight,
            docW:document.documentElement.scrollWidth, clientW:document.documentElement.clientWidth,
            designerH:designer.height, viewport:innerHeight,
            leftX:left.x, workspaceX:workspace.x, propsX:props.x,
            leftH:left.height, workspaceH:workspace.height, propsH:props.height,
            pageX:page.x, pageY:page.y, pageW:page.width, pageH:page.height,
            workX:workspace.x, workY:workspace.y, workW:workspace.width, workH:workspace.height,
            stageCols:stageStyle.gridTemplateColumns,
            leftOverflow:leftStyle.overflowY, workspaceOverflow:workspaceStyle.overflowY, propsOverflow:propsStyle.overflowY,
            display:workspaceStyle.display
          };
        }""")
        await ck(layout['docH'] <= layout['clientH'] + 1, 'desktopNoPageLevelVerticalScroll')
        await ck(layout['docW'] <= layout['clientW'] + 1, 'desktopNoPageLevelHorizontalScroll')
        await ck(layout['leftX'] < layout['workspaceX'] < layout['propsX'], 'threePaneOrder')
        await ck(len(layout['stageCols'].split()) >= 3, 'threePaneGrid')
        await ck(layout['workspaceOverflow'] in ('auto', 'scroll'), 'canvasOwnsOverflow')
        await ck(layout['propsOverflow'] in ('auto', 'scroll'), 'inspectorOwnsOverflow')
        await ck(layout['leftH'] > 500 and layout['workspaceH'] > 500 and layout['propsH'] > 500, 'fullHeightWorkingRails')
        await ck(layout['pageX'] >= layout['workX'] and layout['pageX'] + layout['pageW'] <= layout['workX'] + layout['workW'] + 1, 'fittedPageInsideCanvasWidth')
        await ck(layout['pageY'] >= layout['workY'] and layout['pageY'] + layout['pageH'] <= layout['workY'] + layout['workH'] + 1, 'fittedPageInsideCanvasHeight')
        await ck(layout['display'] == 'block', 'rulerDoesNotBecomeFlexSiblingOfCanvas')

        # Insert drawer: internal expandable submenu, layering, search, and close after insert.
        trigger = page.locator('[data-certloom-designer-insert-focus]')
        drawer = page.locator('[data-certloom-designer-insert-panel]')
        await ck(await drawer.is_hidden(), 'insertDrawerInitiallyClosed')
        await trigger.click()
        await page.wait_for_timeout(40)
        await ck(await drawer.is_visible(), 'insertDrawerExpandsInLeftRail')
        await ck((await trigger.get_attribute('aria-expanded')) == 'true', 'insertExpandedStateExposed')
        z = await drawer.evaluate("el => Number(getComputedStyle(el).zIndex)")
        await ck(z >= 2, 'insertDrawerHasBoundedLocalLayering')
        insert_layout = await page.evaluate("""() => {
          const d=document.querySelector('[data-certloom-designer-insert-panel]').getBoundingClientRect();
          const l=document.querySelector('.certloom-template-designer-layers-primary').getBoundingClientRect();
          const left=document.querySelector('.certloom-studio-left').getBoundingClientRect();
          return {drawerBottom:d.bottom,layersTop:l.top,drawerLeft:d.left,drawerRight:d.right,leftLeft:left.left,leftRight:left.right};
        }""")
        await ck(insert_layout['drawerBottom'] <= insert_layout['layersTop'] + 1, 'insertDrawerExpandsWithoutCoveringLayers')
        await ck(insert_layout['drawerLeft'] >= insert_layout['leftLeft'] - 1 and insert_layout['drawerRight'] <= insert_layout['leftRight'] + 1, 'insertDrawerStaysInsideLeftRail')
        await ck(await page.locator('[data-certloom-insert-category]').count() >= 4, 'insertElementsCategorized')
        required = ['qr', 'barcode', 'field', 'dynamic_field', 'heading', 'text', 'image', 'seal', 'logo', 'signature', 'ellipse', 'background', 'list']
        present = await page.locator('[data-certloom-designer-add]').evaluate_all("els => els.map(el => el.getAttribute('data-certloom-designer-add'))")
        await ck(all(kind in present for kind in required), 'requiredInsertKindsPresent')
        search = page.locator('[data-certloom-designer-palette-search]')
        await search.fill('seal')
        await ck(await page.locator('[data-certloom-designer-add="seal"]').is_visible(), 'insertSearchKeepsMatchingElement')
        await ck(await page.locator('[data-certloom-designer-add="heading"]').is_hidden(), 'insertSearchHidesNonmatch')
        await search.fill('')
        visible_layer = page.locator('[data-certloom-designer-layer-select]').first
        await visible_layer.click()
        await page.wait_for_timeout(30)
        await ck((await visible_layer.get_attribute('aria-pressed')) == 'true', 'layersRemainClickableBesideExpandedInsert')
        await ck(await drawer.is_visible(), 'layerInteractionDoesNotCollapseInlineInsertDrawer')
        before = await page.locator('[data-certloom-designer-element]').count()
        await page.locator('[data-certloom-designer-add="ellipse"]').click()
        await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-element]').count() == before + 1, 'insertAddsCanonicalElement')
        shape_appearance = page.locator('[data-certloom-designer-property-section="appearance"]')
        shape_content = page.locator('[data-certloom-designer-property-section="content"]')
        await ck('is-open' in (await shape_appearance.get_attribute('class') or ''), 'shapeOpensAppearanceInspectorFirst')
        await ck('is-open' not in (await shape_content.get_attribute('class') or ''), 'shapeDoesNotOpenContentInspectorFirst')
        await ck(await drawer.is_hidden(), 'insertDrawerClosesAfterInsert')
        await ck((await trigger.get_attribute('aria-expanded')) == 'false', 'insertStateClosesAfterInsert')

        # Compact layers actions and drag reordering.
        layer_rows = page.locator('[data-certloom-designer-layer-row]')
        await ck(await layer_rows.count() == before + 1, 'layersMirrorCanvasElements')
        first_row = layer_rows.first
        await ck((await first_row.get_attribute('draggable')) == 'true', 'layersAreDragReorderable')
        await ck(await first_row.locator('[data-certloom-designer-layer-row-action]').count() == 4, 'layerRowHasFourCompactIconActions')
        layer_actions = await first_row.locator('[data-certloom-designer-layer-row-action]').evaluate_all("els => els.map(el => el.getAttribute('data-certloom-designer-layer-row-action'))")
        await ck(layer_actions == ['visibility', 'lock', 'duplicate', 'delete'], 'layerActionsVisibilityLockDuplicateDelete')
        if await layer_rows.count() >= 2:
            first_id = await layer_rows.nth(0).get_attribute('data-certloom-designer-layer-row')
            second_id = await layer_rows.nth(1).get_attribute('data-certloom-designer-layer-row')
            await page.evaluate("""() => {
              const rows=[...document.querySelectorAll('[data-certloom-designer-layer-row]')];
              const source=rows[0], target=rows[1], data=new DataTransfer();
              source.dispatchEvent(new DragEvent('dragstart',{bubbles:true,cancelable:true,dataTransfer:data}));
              target.dispatchEvent(new DragEvent('dragover',{bubbles:true,cancelable:true,dataTransfer:data}));
              target.dispatchEvent(new DragEvent('drop',{bubbles:true,cancelable:true,dataTransfer:data}));
              source.dispatchEvent(new DragEvent('dragend',{bubbles:true,cancelable:true,dataTransfer:data}));
            }""")
            await page.wait_for_timeout(80)
            reordered = await page.locator('[data-certloom-designer-layer-row]').first.get_attribute('data-certloom-designer-layer-row')
            await ck(reordered in (first_id, second_id) and reordered != first_id, 'layerDragChangesFrontBackOrder')

        # Selecting a text layer exposes contextual Inspector + floating canvas toolbar.
        text_el = page.locator('[data-certloom-designer-element].is-text').first
        await text_el.click(position={'x': 3, 'y': 3})
        await page.wait_for_timeout(80)
        await ck(await page.locator('[data-certloom-designer-fields]').is_visible(), 'inspectorAppearsForSelection')
        await ck(await page.locator('[data-certloom-designer-empty]').is_hidden(), 'emptyInspectorHidesForSelection')
        await ck(await page.locator('[data-certloom-designer-selection-toolbar]').is_visible(), 'floatingSelectedElementToolbarVisible')
        await ck(await page.locator('[data-certloom-designer-selection-action]').count() == 4, 'floatingToolbarCompactActions')
        await ck(await page.locator('.certloom-studio-accordion').count() >= 6, 'inspectorUsesAccordionSections')
        content_section = page.locator('[data-certloom-designer-property-section="content"]')
        appearance_section = page.locator('[data-certloom-designer-property-section="appearance"]')
        await ck('is-open' in (await content_section.get_attribute('class') or ''), 'contextualContentAccordionOpensFirstForText')
        if 'is-open' not in (await appearance_section.get_attribute('class') or ''):
            await appearance_section.locator('.certloom-studio-accordion-trigger').click()
            await page.wait_for_timeout(20)
        await ck('is-open' in (await content_section.get_attribute('class') or ''), 'openingAccordionDoesNotCloseSibling')
        await ck('is-open' in (await appearance_section.get_attribute('class') or ''), 'multipleInspectorAccordionsStayOpen')
        await ck(await page.locator('[data-certloom-designer-field="rotation"]').count() == 1, 'rotationPrecisionControlRetained')
        await ck(await page.locator('[data-certloom-designer-style-field="font_family"]').count() == 1, 'fontControlRetained')
        await ck(await page.locator('[data-certloom-designer-style-field="font_size_pt"]').count() == 1, 'fontSizeControlRetained')
        await ck(await page.locator('[data-certloom-designer-style-field="font_weight"]').count() == 1, 'fontWeightControlRetained')
        await ck(await page.locator('[data-certloom-designer-style-field="overflow"]').count() == 1, 'textWrappingOverflowControlRetained')

        # Canvas selection owns keyboard arrows instead of scrolling the WordPress document.
        selected_id = await text_el.get_attribute('data-certloom-designer-element')
        selected_row = page.locator(f'[data-certloom-designer-layer-row="{selected_id}"]')
        await ck('is-selected' in (await selected_row.get_attribute('class') or ''), 'canvasSelectionHighlightsMatchingLayer')
        layer_visibility = await selected_row.evaluate("""el => { const list=el.closest('[data-certloom-designer-layers]'); const r=el.getBoundingClientRect(), l=list.getBoundingClientRect(); return r.top >= l.top-1 && r.bottom <= l.bottom+1; }""")
        await ck(layer_visibility, 'selectedLayerAutoScrollsIntoLayersViewport')
        selected_canvas = page.locator(f'[data-certloom-designer-element="{selected_id}"]')
        await selected_canvas.focus()
        await page.wait_for_timeout(20)
        before_box = await selected_canvas.bounding_box()
        before_scroll = await page.evaluate('() => window.scrollY')
        await page.keyboard.press('ArrowRight')
        await page.wait_for_timeout(70)
        after_box = await page.locator(f'[data-certloom-designer-element="{selected_id}"]').bounding_box()
        after_scroll = await page.evaluate('() => window.scrollY')
        await ck(after_box and before_box and after_box['x'] > before_box['x'] + 0.5, 'arrowKeyNudgesSelectedElement')
        await ck(after_scroll == before_scroll, 'arrowKeyDoesNotScrollOuterPage')
        before_y = after_box['y'] if after_box else 0
        await page.keyboard.press('Shift+ArrowDown')
        await page.wait_for_timeout(70)
        shifted_box = await page.locator(f'[data-certloom-designer-element="{selected_id}"]').bounding_box()
        await ck(shifted_box and shifted_box['y'] > before_y + 2, 'shiftArrowUsesLargerNudge')

        # Technical IDs are removed from the normal inspector header and retained under Advanced.
        await ck(not await page.locator('.certloom-template-designer-element-meta code').is_visible(), 'rawElementIdHiddenFromNormalInspectorHeader')
        advanced = page.locator('[data-certloom-designer-property-section="advanced"]')
        if 'is-open' not in (await advanced.get_attribute('class') or ''):
            await advanced.locator('.certloom-studio-accordion-trigger').click()
            await page.wait_for_timeout(20)
        await ck((await page.locator('[data-certloom-designer-advanced-id]').inner_text()).strip() == selected_id, 'advancedInspectorShowsSelectedElementId')

        toolbar_bounds = await page.locator('[data-certloom-designer-selection-toolbar]').bounding_box()
        page_bounds = await page.locator('[data-certloom-designer-page]').bounding_box()
        await ck(bool(toolbar_bounds and page_bounds and toolbar_bounds['y'] >= page_bounds['y'] - 1 and toolbar_bounds['y'] + toolbar_bounds['height'] <= page_bounds['y'] + page_bounds['height'] + 1), 'floatingToolbarStaysInsideCanvasViewport')

        # Undo / redo / restore-last-saved are the only left history controls.
        await ck(await page.locator('.certloom-studio-history > button').count() == 3, 'historyStripIsThreeCompactIcons')
        await ck(await page.locator('[data-certloom-designer-undo]').is_enabled(), 'undoEnabledAfterChange')
        await page.locator('[data-certloom-designer-undo]').click()
        await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-redo]').is_enabled(), 'redoEnabledAfterUndo')
        await page.locator('[data-certloom-designer-redo]').click()
        await page.wait_for_timeout(50)
        await ck(await root.evaluate("el => el.classList.contains('has-unsaved-changes')"), 'redoRestoresDirtyState')
        page.once('dialog', lambda dialog: asyncio.create_task(dialog.accept()))
        await page.locator('[data-certloom-designer-reset]').click()
        await page.wait_for_timeout(80)
        await ck(not await root.evaluate("el => el.classList.contains('has-unsaved-changes')"), 'restoreReturnsToLastSavedState')
        await ck(await page.locator('[data-certloom-designer-element]').count() == before, 'restoreRemovesUnsavedInsertedElement')

        # Sample/Tokens is a view-only live preview mode and never dirties the design.
        recipient_merge = page.locator('[data-certloom-designer-element]').filter(has_text='Alexandria Noor Rahman').first
        await ck(await recipient_merge.count() == 1, 'samplePreviewShowsRecipientSampleValue')
        await page.locator('[data-certloom-designer-preview-mode="tokens"]').click()
        await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element]').filter(has_text='{{recipient.name}}').count() >= 1, 'tokenPreviewShowsCanonicalRecipientToken')
        await ck((await page.locator('[data-certloom-designer-preview-mode="tokens"]').get_attribute('aria-pressed')) == 'true', 'tokenPreviewPressedState')
        await ck(not await root.evaluate("el => el.classList.contains('has-unsaved-changes')"), 'tokenPreviewDoesNotDirtyDesign')
        await ck(await page.locator('[data-certloom-designer-undo]').is_disabled(), 'tokenPreviewDoesNotCreateUndoHistory')
        await page.locator('[data-certloom-designer-preview-mode="sample"]').click()
        await page.wait_for_timeout(50)
        await ck(await page.locator('[data-certloom-designer-element]').filter(has_text='Alexandria Noor Rahman').count() >= 1, 'samplePreviewRestoresSampleValue')
        await ck(not await root.evaluate("el => el.classList.contains('has-unsaved-changes')"), 'samplePreviewReturnStillClean')

        # Relative zoom and Ctrl/Cmd+0 fitted baseline.
        await page.locator('[data-certloom-designer-zoom-in]').click()
        await page.wait_for_timeout(40)
        z1 = (await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip()
        await ck(z1 != '100%', 'zoomInMovesAwayFromFitBaseline')
        await page.locator('[data-certloom-designer-workspace]').focus()
        await page.keyboard.press('Control+0')
        await page.wait_for_timeout(60)
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'ctrl0ReturnsToFitted100')

        # Custom page size actually resizes the design and refits it.
        await page.locator('[data-certloom-designer-page-size]').select_option('Custom')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-custom-size]').is_visible(), 'customDimensionsAppear')
        width = page.locator('[data-certloom-designer-custom-width]')
        height = page.locator('[data-certloom-designer-custom-height]')
        await width.fill('250')
        await width.dispatch_event('change')
        await height.fill('180')
        await height.dispatch_event('change')
        await page.wait_for_timeout(100)
        readout = await page.locator('[data-certloom-designer-page-readout]').inner_text()
        await ck('Custom' in readout and '250' in readout and '180' in readout, 'customPageDimensionsApplyToCanonicalCanvas')
        await ck((await page.locator('[data-certloom-designer-zoom-output]').inner_text()).strip() == '100%', 'customPageRefitsTo100')

        # Proof and import affordances are direct, but no export clutter exists in Studio.
        await page.locator('[data-certloom-designer-test-print]').click()
        await page.wait_for_timeout(80)
        await ck((await page.evaluate('() => window.__certloomPrinted || 0')) == 1, 'testPrintInvokesPrintFlow')
        await page.locator('[data-certloom-studio-import-open]').click()
        await page.wait_for_timeout(30)
        await ck(await page.locator('[data-certloom-studio-import-dialog]').evaluate('el => el.open === true || el.hasAttribute("open")'), 'studioImportDialogOpens')
        await page.locator('[data-certloom-studio-import-close]').click()
        await page.wait_for_timeout(20)
        await ck(not await page.locator('[data-certloom-studio-import-dialog]').evaluate('el => el.open === true || el.hasAttribute("open")'), 'studioImportDialogCloses')
        await ck(await page.locator('text=Export JSON').count() == 0 and await page.locator('text=Export template').count() == 0, 'studioHasNoExportManagementClutter')

        # Canonical save remains a form submit despite the polished Studio shell.
        await page.evaluate("document.body.removeAttribute('data-submitted')")
        await page.locator('.certloom-studio-primary').click()
        await page.wait_for_timeout(40)
        await ck(await page.locator('body').get_attribute('data-submitted') == '1', 'publishUsesCanonicalFormSubmission')

        await page.screenshot(path=str(SCREENSHOT), full_page=True)

        # Mobile deliberately returns to natural document flow.
        mobile = await browser.new_page(viewport={'width': 390, 'height': 844})
        mobile_errors = []
        mobile.on('pageerror', lambda exc: mobile_errors.append(str(exc)))
        await mobile.set_content(html, wait_until='load')
        await mobile.wait_for_timeout(220)
        m = await mobile.evaluate("""() => {
          const stage=getComputedStyle(document.querySelector('.certloom-template-designer-stage'));
          const left=document.querySelector('.certloom-studio-left').getBoundingClientRect();
          const canvas=document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect();
          const props=document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect();
          return {
            noHorizontal:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,
            naturalScroll:document.documentElement.scrollHeight>document.documentElement.clientHeight,
            stage:stage.gridTemplateColumns,
            leftY:left.y, canvasY:canvas.y, propsY:props.y
          };
        }""")
        await ck(not mobile_errors, 'mobileNoRuntimeErrors')
        await ck(m['noHorizontal'], 'mobileNoHorizontalOverflow')
        await ck(m['naturalScroll'], 'mobileNaturalPageFlow')
        await ck(len(m['stage'].split()) == 1, 'mobileSingleColumnStudio')
        await ck(m['leftY'] < m['canvasY'] < m['propsY'], 'mobileStacksInsertCanvasInspector')
        await mobile.close()

        # Accessibility preference contracts remain live.
        reduced = await browser.new_page(viewport={'width': 1000, 'height': 800})
        await reduced.emulate_media(reduced_motion='reduce')
        await reduced.set_content(html, wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"), 'reducedMotionMedia')
        await reduced.close()

        forced = await browser.new_page(viewport={'width': 1000, 'height': 800})
        try:
            await forced.emulate_media(forced_colors='active')
            await forced.set_content(html, wait_until='load')
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"), 'forcedColorsMedia')
        except Exception:
            await ck(True, 'forcedColorsUnsupported')
        await forced.close()

        await page.close()
        await browser.close()

    if failed:
        unique = sorted(set(failed))
        print('FAIL  UI Phase 4 Gamma-02R Certloom Studio Chromium: ' + ', '.join(unique))
        print(f'\n{checks} checks, {len(unique)} failures')
        return 1
    print('PASS  UI Phase 4 Gamma-02R Certloom Studio Chromium')
    print(f'\n{checks} checks, 0 failures')
    print('Screenshot: ' + str(SCREENSHOT))
    return 0


if __name__ == '__main__':
    raise SystemExit(asyncio.run(main()))
