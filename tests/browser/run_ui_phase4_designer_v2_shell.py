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

        # Template identity can be edited in-place; recommendation profiles are guidance metadata,
        # not canvas authority and must not create design dirty/undo state.
        await ck((await root.get_attribute('data-studio-metadata-nonce')) == 'fixture-studio-metadata-nonce', 'studioMetadataNonceBoundToDesigner')
        title_display = page.locator('[data-certloom-studio-name-display]')
        title_input = page.locator('[data-certloom-studio-name-input]')
        await ck(await title_display.is_visible() and await title_input.is_hidden(), 'templateNameStartsAsInlineDisplay')
        await title_display.click()
        await ck(await title_input.is_visible() and await title_display.is_hidden(), 'clickTemplateNameStartsInlineRename')
        await title_input.fill('Academic — Renamed Studio')
        await title_input.press('ArrowLeft')
        await ck(await title_input.evaluate("el => document.activeElement === el"), 'renameInputOwnsTextEditingKeys')
        await title_input.press('Enter')
        await page.wait_for_timeout(80)
        await ck((await page.locator('[data-certloom-studio-name-text]').inner_text()).strip() == 'Academic — Renamed Studio', 'enterCommitsInlineTemplateRename')
        metadata_requests = await page.evaluate('() => window.__certloomMetadataRequests.slice()')
        await ck(any(r.get('metadata_key') == 'name' and r.get('name') == 'Academic — Renamed Studio' for r in metadata_requests), 'inlineRenameUsesStudioMetadataEndpoint')
        await ck('has-unsaved-changes' not in (await root.get_attribute('class') or ''), 'inlineRenameDoesNotDirtyCanvasRevision')

        profile_picker = page.locator('[data-certloom-studio-profile-picker]')
        await ck(await profile_picker.is_visible(), 'recommendedProfilesPickerVisibleInStudio')
        await ck(await page.locator('[data-certloom-studio-profile]').count() == 9, 'recommendedProfilesUsesNineSetupProfiles')
        await profile_picker.locator('summary').click()
        await page.locator('[data-certloom-studio-profile][value="training_lms"]').check()
        await page.locator('[data-certloom-studio-profile][value="iso_management"]').check()
        await ck((await page.locator('[data-certloom-studio-profile-summary]').inner_text()).strip() == 'Training / LMS +1', 'profileSummaryShowsFirstProfileAndAdditionalCount')
        await page.wait_for_timeout(420)
        metadata_requests = await page.evaluate('() => window.__certloomMetadataRequests.slice()')
        profile_requests = [r for r in metadata_requests if r.get('metadata_key') == 'recommended_profiles']
        await ck(bool(profile_requests), 'recommendedProfilesPersistThroughStudioMetadataEndpoint')
        if profile_requests:
            await ck(profile_requests[-1].get('recommended_profiles') == ['training_lms', 'iso_management'], 'recommendedProfilesPersistNormalizedSelection')
        else:
            await ck(False, 'recommendedProfilesPersistNormalizedSelection')
        await ck((await page.locator('[data-certloom-studio-profile-status]').inner_text()).strip() == 'Saved', 'profilePickerReportsSavedState')
        await ck('has-unsaved-changes' not in (await root.get_attribute('class') or ''), 'profileGuidanceDoesNotDirtyCanvasRevision')
        await profile_picker.locator('summary').click()
        await ck(not await profile_picker.get_attribute('open'), 'profilePickerClosesWithoutBlockingStudioToolbar')

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
          const layersPrimary=document.querySelector('.certloom-template-designer-layers-primary').getBoundingClientRect();
          const layersList=document.querySelector('.certloom-studio-layer-list').getBoundingClientRect();
          const layersListStyle=getComputedStyle(document.querySelector('.certloom-studio-layer-list'));
          return {
            docH:document.documentElement.scrollHeight, clientH:document.documentElement.clientHeight,
            docW:document.documentElement.scrollWidth, clientW:document.documentElement.clientWidth,
            designerH:designer.height, viewport:innerHeight,
            leftX:left.x, workspaceX:workspace.x, propsX:props.x,
            leftH:left.height, workspaceH:workspace.height, propsH:props.height,
            pageX:page.x, pageY:page.y, pageW:page.width, pageH:page.height,
            workX:workspace.x, workY:workspace.y, workW:workspace.width, workH:workspace.height,
            stageCols:stageStyle.gridTemplateColumns,
            leftOverflow:leftStyle.overflowY, leftScrollbarGutter:leftStyle.scrollbarGutter,
            workspaceOverflow:workspaceStyle.overflowY, propsOverflow:propsStyle.overflowY,
            layersPrimaryRight:layersPrimary.right, layersPrimaryBottom:layersPrimary.bottom,
            layersListRight:layersList.right, layersListBottom:layersList.bottom,
            layersListMaxHeight:layersListStyle.maxHeight,
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
        await ck(layout['leftScrollbarGutter'] == 'auto', 'leftRailDoesNotReserveHiddenScrollbarGutter')
        await ck(abs(layout['layersListRight'] - layout['layersPrimaryRight']) <= 1.5, 'layersScrollerUsesFullRailContentWidth')
        await ck(layout['layersListMaxHeight'] == 'none' and abs(layout['layersListBottom'] - layout['layersPrimaryBottom']) <= 1.5, 'layersScrollerFillsAvailableRailHeight')
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
        await ck(await first_row.locator('[data-certloom-designer-layer-row-action]').count() == 3, 'layerRowHasThreeCompactIconActions')
        layer_actions = await first_row.locator('[data-certloom-designer-layer-row-action]').evaluate_all("els => els.map(el => el.getAttribute('data-certloom-designer-layer-row-action'))")
        await ck(layer_actions == ['visibility', 'lock', 'delete'], 'layerActionsVisibilityLockDelete')
        await ck(await first_row.locator('.certloom-studio-layer-drag').count() == 0, 'layerRowHasNoDedicatedDragGlyph')
        await ck(await page.locator('.certloom-studio-layer-order-hint').count() == 1, 'frontBackHintPresent')
        await ck(await page.locator('.certloom-studio-layer-order-tooltip').count() == 1, 'frontBackHoverHelpPresent')
        first_children = await first_row.evaluate("el => [...el.children].map(c => c.getAttribute('data-certloom-designer-layer-row-action') || c.className)")
        await ck(first_children and first_children[0] == 'visibility', 'visibilityOccupiesFormerDragSlot')
        await ck('duplicate' not in layer_actions, 'duplicateRemovedFromPermanentLayerRow')
        layer_hit_targets = await first_row.evaluate("""el => {
          const eye=el.querySelector('[data-certloom-designer-layer-row-action=\"visibility\"]');
          const lock=el.querySelector('[data-certloom-designer-layer-row-action=\"lock\"]');
          const del=el.querySelector('[data-certloom-designer-layer-row-action=\"delete\"]');
          return [eye,lock,del].map(node => node ? node.getBoundingClientRect().width : 0);
        }""")
        await ck(all(width >= 24 for width in layer_hit_targets), 'compactLayerIconsKeepPracticalHitTargets')
        long_names = await page.locator('.certloom-studio-layer-name').all_inner_texts()
        await ck(any(name.endswith('....') for name in long_names), 'longLayerNamesUseFourDotCharacterLimit')
        layer_overflow = await page.locator('[data-certloom-designer-layers]').evaluate("el => el.scrollWidth <= el.clientWidth + 1")
        await ck(layer_overflow, 'layersHaveNoHorizontalScroll')

        # Hidden Inspector controls must never poison authoritative Draft/Publish submission.
        # QR legitimately uses quiet-zone 4 while Barcode requires >=10; the inactive Barcode
        # control therefore has to be disabled instead of inheriting QR's value and blocking
        # the browser with "invalid form control is not focusable".
        qr_row = layer_rows.filter(has_text='QR').first
        if await qr_row.count():
            await qr_row.locator('[data-certloom-designer-layer-select]').click()
            await page.wait_for_timeout(40)
            qr_quiet = page.locator('[data-certloom-designer-content="qr"] [data-certloom-designer-content-field="quiet_zone_modules"]')
            barcode_quiet = page.locator('[data-certloom-designer-content="barcode"] [data-certloom-designer-content-field="quiet_zone_modules"]')
            await ck(await qr_quiet.is_enabled(), 'activeQrQuietZoneRemainsEditable')
            await ck(await barcode_quiet.is_disabled(), 'inactiveBarcodeQuietZoneDisabledFromNativeValidation')
            await ck(await page.locator('[data-certloom-designer-form]').evaluate('el => el.noValidate === true'), 'studioFormUsesCanonicalJsonValidationNotHiddenInspectorNativeValidation')
            await ck(await page.locator('[data-certloom-designer-form]').evaluate('el => el.checkValidity()'), 'hiddenInspectorControlsCannotBlockStudioSave')

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
        selected_geometry = await page.evaluate("""() => {
          const shell=document.querySelector('.certloom-template-admin.is-designer-workspace').getBoundingClientRect();
          const editor=document.querySelector('.certloom-template-editor-focused').getBoundingClientRect();
          const designer=document.querySelector('.certloom-template-designer-v2').getBoundingClientRect();
          const form=document.querySelector('[data-certloom-designer-form]').getBoundingClientRect();
          const stage=document.querySelector('.certloom-template-designer-stage').getBoundingClientRect();
          const inspector=document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect();
          const fields=document.querySelector('[data-certloom-designer-fields]').getBoundingClientRect();
          const visibleSections=[...document.querySelectorAll('[data-certloom-designer-property-section]')].filter(el => getComputedStyle(el).display !== 'none').map(el => el.getBoundingClientRect());
          return {
            shellBottom:shell.bottom, stageTop:stage.top, stageBottom:stage.bottom, stageHeight:stage.height,
            inspectorLeft:inspector.left, inspectorRight:inspector.right, inspectorBottom:inspector.bottom,
            fieldsLeft:fields.left, fieldsRight:fields.right,
            sectionsInside:visibleSections.every(r => r.left >= inspector.left - 1 && r.right <= inspector.right + 1),
            docH:document.documentElement.scrollHeight, viewport:window.innerHeight
          };
        }""")
        await ck(selected_geometry['stageHeight'] > 400 and abs(selected_geometry['stageBottom'] - selected_geometry['shellBottom']) <= 1.5, 'selectedInspectorCannotCollapseThreePaneStage')
        await ck(selected_geometry['fieldsLeft'] >= selected_geometry['inspectorLeft'] - 1 and selected_geometry['fieldsRight'] <= selected_geometry['inspectorRight'] + 1 and selected_geometry['sectionsInside'], 'selectedInspectorSectionsRemainContainedInRightRail')
        await ck(abs(selected_geometry['inspectorBottom'] - selected_geometry['shellBottom']) <= 1.5, 'selectedInspectorRailReachesStudioBottom')
        await ck(selected_geometry['docH'] <= selected_geometry['viewport'] + 1, 'selectedInspectorCannotCreatePageLevelOverflow')
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
        await ck('is-open' not in (await content_section.get_attribute('class') or ''), 'openingAccordionClosesSibling')
        await ck('is-open' in (await appearance_section.get_attribute('class') or ''), 'inspectorKeepsOnlyOneAccordionOpen')
        await appearance_section.locator('.certloom-studio-accordion-trigger').click()
        await page.wait_for_timeout(20)
        await ck('is-open' in (await appearance_section.get_attribute('class') or ''), 'activeAccordionCannotCollapseSelectionInspectorToZero')
        await ck(await page.locator('[data-certloom-designer-field="rotation"]').count() == 1, 'rotationPrecisionControlRetained')
        await ck(await page.locator('[data-certloom-designer-style="text"] [data-certloom-designer-style-field="font_family"]').count() == 1, 'fontControlRetained')
        await ck(await page.locator('[data-certloom-designer-style="text"] [data-certloom-designer-style-field="font_size_pt"]').count() == 1, 'fontSizeControlRetained')
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

        # Real click-to-keyboard ownership: clicking an element must return focus to the canvas
        # without tests manually focusing it first, and arrows must still edit rather than scroll.
        clicked_canvas = page.locator(f'[data-certloom-designer-element="{selected_id}"]')
        await clicked_canvas.click(position={"x": 8, "y": 8}, force=True)
        await page.wait_for_timeout(50)
        active_selected = await page.evaluate("id => document.activeElement && document.activeElement.getAttribute('data-certloom-designer-element') === id", selected_id)
        await ck(active_selected, 'canvasClickReturnsKeyboardFocusToSelectedElement')
        click_before = await page.locator(f'[data-certloom-designer-element="{selected_id}"]').bounding_box()
        click_scroll_before = await page.evaluate('() => window.scrollY')
        await page.keyboard.press('ArrowLeft')
        await page.wait_for_timeout(70)
        click_after = await page.locator(f'[data-certloom-designer-element="{selected_id}"]').bounding_box()
        click_scroll_after = await page.evaluate('() => window.scrollY')
        await ck(click_before and click_after and click_after['x'] < click_before['x'] - 0.5, 'arrowWorksImmediatelyAfterCanvasClick')
        await ck(click_scroll_after == click_scroll_before, 'canvasArrowNeverFallsThroughToPageScroll')

        # Core editing shortcuts work from the canvas selection, not only through toolbar buttons.
        count_before_shortcuts = await page.locator('[data-certloom-designer-element]').count()
        await page.keyboard.press('Control+d')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts + 1, 'ctrlDDuplicatesCanvasSelection')
        await page.keyboard.press('Control+z')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts, 'ctrlZUndoesKeyboardDuplicate')
        await page.keyboard.press('Control+c')
        await page.keyboard.press('Control+v')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts + 1, 'ctrlCopyPasteDuplicatesCanvasSelection')
        await page.keyboard.press('Control+z')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts, 'ctrlZUndoesKeyboardPaste')
        await page.keyboard.press('Delete')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts - 1, 'deleteKeyRemovesCanvasSelection')
        await page.keyboard.press('Control+z')
        await page.wait_for_timeout(60)
        await ck(await page.locator('[data-certloom-designer-element]').count() == count_before_shortcuts, 'ctrlZRestoresKeyboardDelete')

        # Layers are a compact, horizontally bounded editor list and own their own vertical scroll.
        left_rail = page.locator('.certloom-studio-left')
        layer_list = page.locator('[data-certloom-designer-layers]')
        rail_metrics = await left_rail.evaluate('el => ({cw:el.clientWidth, sw:el.scrollWidth, ch:el.clientHeight, sh:el.scrollHeight})')
        list_metrics = await layer_list.evaluate('el => ({cw:el.clientWidth, sw:el.scrollWidth, ch:el.clientHeight, sh:el.scrollHeight})')
        await ck(rail_metrics['sw'] <= rail_metrics['cw'] + 1, 'leftRailHasNoHorizontalScroll')
        await ck(rail_metrics['sh'] <= rail_metrics['ch'] + 1, 'leftRailDoesNotBecomeSecondVerticalScrollbar')
        await ck(list_metrics['sw'] <= list_metrics['cw'] + 1, 'layersListHasNoHorizontalScroll')
        first_layer_box = await page.locator('[data-certloom-designer-layer-row]').first.bounding_box()
        await ck(bool(first_layer_box and first_layer_box['height'] <= 30.5), 'layerRowsStayCompactSingleLine')

        # Right-click on a Layer exposes its direct editing actions.
        selected_row = page.locator(f'[data-certloom-designer-layer-row="{selected_id}"]')
        await selected_row.click(button='right', position={"x": 24, "y": 12})
        await page.wait_for_timeout(30)
        layer_menu = page.locator('[data-certloom-designer-context-menu][data-surface="layer"]')
        await ck(await layer_menu.is_visible(), 'layerRightClickOpensContextMenu')
        for action, label in [('visibility','layerContextVisibility'),('lock','layerContextLock'),('duplicate','layerContextDuplicate'),('delete','layerContextDelete')]:
            item = layer_menu.locator(f'[data-certloom-designer-context-action="{action}"]')
            await ck(await item.count() == 1 and await item.is_visible(), label)
        await page.keyboard.press('Escape')
        await page.wait_for_timeout(20)
        await ck(not await layer_menu.is_visible(), 'escapeClosesLayerContextMenu')

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

        # The focused shell must consume the exact remaining viewport even when WordPress/admin chrome
        # places Studio below y=0. This protects staging from a dead strip below the three-pane workspace.
        viewport_fit = await page.evaluate("""() => {
          const shell=document.querySelector('.certloom-template-admin.is-designer-workspace');
          shell.style.setProperty('--certloom-studio-frame-top','43px');
          shell.style.setProperty('--certloom-studio-frame-height','111px'); // stale legacy value must be ignored.
          return true;
        }""")
        await page.wait_for_timeout(40)
        viewport_geometry = await page.evaluate("""() => {
          const q=s=>document.querySelector(s).getBoundingClientRect();
          const shell=q('.certloom-template-admin.is-designer-workspace');
          const editor=q('.certloom-template-editor-focused');
          const designer=q('.certloom-template-designer-v2');
          const form=q('[data-certloom-designer-form]');
          const stage=q('.certloom-template-designer-stage');
          return {shellTop:shell.top,shellBottom:shell.bottom,editorBottom:editor.bottom,designerBottom:designer.bottom,formBottom:form.bottom,stageBottom:stage.bottom,viewport:window.innerHeight};
        }""")
        await ck(viewport_fit and viewport_geometry['shellTop'] >= 42, 'studioViewportOffsetFixtureApplied')
        await ck(abs(viewport_geometry['shellBottom']-viewport_geometry['viewport']) <= 1.5, 'studioFillsRemainingViewportFromLiveOffset')
        await ck(all(abs(viewport_geometry[k]-viewport_geometry['viewport']) <= 1.5 for k in ['editorBottom','designerBottom','formBottom','stageBottom']), 'studioFullHeightChainReachesViewportBottom')
        await page.evaluate("() => { const shell=document.querySelector('.certloom-template-admin.is-designer-workspace'); shell.style.removeProperty('--certloom-studio-frame-top'); shell.style.removeProperty('--certloom-studio-frame-height'); window.dispatchEvent(new Event('resize')); }")
        await page.wait_for_timeout(80)

        # Canonical save remains a form submit and Publish survives external-submit-button serialization.
        # A hidden fallback intent is synchronized before submit so a missing submitter field can never
        # silently degrade Publish into Draft on WordPress staging.
        await page.evaluate("document.body.removeAttribute('data-submitted');document.body.removeAttribute('data-submit-intent');document.body.removeAttribute('data-submit-button-intent')")
        await page.locator('.certloom-studio-primary').click()
        await page.wait_for_timeout(40)
        await ck(await page.locator('body').get_attribute('data-submitted') == '1', 'publishUsesCanonicalFormSubmission')
        await ck(await page.locator('body').get_attribute('data-submit-button-intent') == 'publish', 'publishButtonCarriesExplicitIntent')
        await ck(await page.locator('body').get_attribute('data-submit-intent') == 'publish', 'publishIntentFallbackSerializedBeforeSubmit')

        await page.screenshot(path=str(SCREENSHOT), full_page=True)

        # Reproduce the real WordPress desktop frame instead of testing only a direct-body fixture:
        # wpbody-content starts below the admin bar and to the right of the admin menu. Selecting
        # an element must not collapse the stage, and a late admin-chrome top shift must be
        # remeasured without relying on page-level document height.
        wp_html = html.replace(
            '<body class="certloom-designer-workspace-page" style="margin:0;padding:0"><div class="certloom-template-admin is-designer-workspace">',
            '<body class="certloom-designer-workspace-page" style="margin:0;padding:0"><div id="wpcontent" style="position:absolute;top:32px;left:160px;right:0;height:760px;transform:translateZ(0);contain:paint"><div id="wpbody"><div id="wpbody-content" style="padding-bottom:65px"><div class="certloom-template-admin is-designer-workspace">',
            1,
        ).replace(
            '<script>document.addEventListener("submit"',
            '</div></div></div><script>document.addEventListener("submit"',
            1,
        )
        wp_page = await browser.new_page(viewport={'width': 1702, 'height': 892})
        await wp_page.set_content(wp_html, wait_until='load')
        await wp_page.wait_for_timeout(520)
        await wp_page.locator('[data-certloom-designer-layer-select]').first.click()
        await wp_page.wait_for_timeout(80)
        wp_geometry = await wp_page.evaluate("""() => {
          const shell=document.querySelector('.certloom-template-admin.is-designer-workspace').getBoundingClientRect();
          const editor=document.querySelector('.certloom-template-editor-focused').getBoundingClientRect();
          const designer=document.querySelector('.certloom-template-designer-v2').getBoundingClientRect();
          const form=document.querySelector('[data-certloom-designer-form]').getBoundingClientRect();
          const stage=document.querySelector('.certloom-template-designer-stage').getBoundingClientRect();
          const inspector=document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect();
          const fields=document.querySelector('[data-certloom-designer-fields]').getBoundingClientRect();
          const anchor=document.getElementById('wpbody-content').getBoundingClientRect();
          return {
            anchorTop:anchor.top, anchorLeft:anchor.left, shellTop:shell.top, shellLeft:shell.left,
            shellBottom:shell.bottom, editorBottom:editor.bottom, designerBottom:designer.bottom, formBottom:form.bottom, stageHeight:stage.height, stageBottom:stage.bottom,
            inspectorLeft:inspector.left, inspectorRight:inspector.right, inspectorBottom:inspector.bottom,
            fieldsLeft:fields.left, fieldsRight:fields.right, viewport:window.innerHeight, docH:document.documentElement.scrollHeight,
            wpPaddingBottom:getComputedStyle(document.getElementById('wpbody-content')).paddingBottom,
            frameHeight:getComputedStyle(document.querySelector('.certloom-template-admin.is-designer-workspace')).getPropertyValue('--certloom-studio-frame-height').trim(),
            shellParent:document.querySelector('.certloom-template-admin.is-designer-workspace').parentElement === document.body,
            viewportHost:document.querySelector('.certloom-template-admin.is-designer-workspace').getAttribute('data-certloom-studio-viewport-host')
          };
        }""")
        await ck(abs(wp_geometry['shellTop'] - wp_geometry['anchorTop']) <= 1.5 and abs(wp_geometry['shellLeft'] - wp_geometry['anchorLeft']) <= 1.5, 'studioFrameTracksWordPressContentBox')
        await ck(abs(wp_geometry['shellBottom'] - wp_geometry['viewport']) <= 1.5 and all(abs(wp_geometry[k]-wp_geometry['viewport']) <= 1.5 for k in ['editorBottom','designerBottom','formBottom','stageBottom']), 'wordPressFullStudioChainClosesViewportBottom')
        await ck(wp_geometry['wpPaddingBottom'] == '0px' and wp_geometry['frameHeight'] in ('', '0px'), 'wordPressFooterReservationCannotShortenStudioFrame')
        await ck(wp_geometry['shellParent'] and wp_geometry['viewportHost'] == 'body', 'studioEscapesTransformedWordPressContainingBlock')
        await ck(wp_geometry['stageHeight'] > 500 and wp_geometry['fieldsLeft'] >= wp_geometry['inspectorLeft'] - 1 and wp_geometry['fieldsRight'] <= wp_geometry['inspectorRight'] + 1, 'selectedInspectorStaysContainedInRealisticWordPressFrame')
        await ck(abs(wp_geometry['inspectorBottom'] - wp_geometry['viewport']) <= 1.5 and wp_geometry['docH'] <= wp_geometry['viewport'] + 1, 'selectedInspectorCannotRecreateBottomDeadStripOrPageScroll')
        await wp_page.evaluate("document.getElementById('wpcontent').style.top='0px'")
        await wp_page.wait_for_timeout(520)
        wp_shift = await wp_page.evaluate("""() => {
          const shell=document.querySelector('.certloom-template-admin.is-designer-workspace').getBoundingClientRect();
          const stage=document.querySelector('.certloom-template-designer-stage').getBoundingClientRect();
          return {shellTop:shell.top,shellBottom:shell.bottom,stageBottom:stage.bottom,viewport:window.innerHeight};
        }""")
        await ck(abs(wp_shift['shellTop']) <= 1.5, 'studioRemeasuresAfterLateWordPressTopShift')
        await ck(abs(wp_shift['shellBottom']-wp_shift['viewport']) <= 1.5 and abs(wp_shift['stageBottom']-wp_shift['viewport']) <= 1.5, 'lateWordPressShiftKeepsStageFlushToViewportBottom')
        await wp_page.close()

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
