#!/usr/bin/env python3
"""Chromium contract for UI Phase 3 — Setup Wizard v2."""
import asyncio
import os
import subprocess
from pathlib import Path

from playwright.async_api import async_playwright

ROOT = Path(__file__).resolve().parents[2]


def fixture(state="welcome"):
    env = os.environ.copy()
    env["CERTLOOM_WIZ_STATE"] = state
    result = subprocess.run(
        ["php", str(ROOT / "tests/browser/build_setup_wizard_fixture.php")],
        cwd=ROOT,
        env=env,
        capture_output=True,
        text=True,
    )
    if result.returncode:
        raise RuntimeError(result.stderr or result.stdout)
    return result.stdout


async def run():
    checks = 0
    failed = []

    async with async_playwright() as p:
        browser = await p.chromium.launch(
            headless=True,
            executable_path="/usr/bin/chromium",
            args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-gpu"],
        )

        async def ck(ok, label):
            nonlocal checks
            checks += 1
            if not ok:
                failed.append(label)

        # Welcome — focused, full-screen and free from legacy hierarchy setup.
        welcome = await browser.new_page(viewport={"width": 1280, "height": 900})
        await welcome.set_content(fixture("welcome"), wait_until="load")
        root = welcome.locator("[data-certloom-setup-v2]")
        await ck(await root.count() == 1, "setupV2Root")
        await ck(await root.get_attribute("data-certloom-step") == "welcome", "welcomeState")
        await ck(
            await root.evaluate("e => getComputedStyle(e).position") == "fixed",
            "focusedFixedShell",
        )
        await ck(
            await root.evaluate("e => {const s=getComputedStyle(e); return s.inset==='0px' || (s.top==='0px'&&s.right==='0px'&&s.bottom==='0px'&&s.left==='0px');}"),
            "focusedShellFillsViewport",
        )
        await ck(
            await welcome.locator("body").evaluate("e => getComputedStyle(e).overflow") == "hidden",
            "setupBodyDoesNotScroll",
        )
        await ck(
            await welcome.get_by_text("Set up your first credential.", exact=True).is_visible(),
            "welcomePromise",
        )
        await ck(
            await welcome.get_by_text("Nothing is auto-created behind your back.", exact=False).is_visible(),
            "welcomeNoHiddenRecords",
        )
        await ck(await welcome.get_by_role("button", name="Start setup").count() == 1, "startSetupAction")
        await ck(await welcome.locator(".certloom-setup-v2-brand img").count() == 2, "exactBrandAssetPair")
        await ck(await welcome.locator(".certloom-setup-v2-brand").get_by_text("Certloom", exact=True).count() == 0, "noFallbackBrandFont")
        await ck(await welcome.locator(".certloom-setup-v2-brand-wordmark").evaluate("e => e.complete && e.naturalWidth > 0"), "brandWordmarkLoads")
        await ck(await welcome.locator("input[name=program_id],input[name=batch_id],input[name=signer_id]").count() == 0, "welcomeNoHierarchyInputs")
        await ck(await welcome.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "welcomeNoOverflow")
        await welcome.close()

        # Profile — nine UX presets, live contextual hierarchy help, no subcategory detour.
        profile = await browser.new_page(viewport={"width": 1280, "height": 900})
        await profile.set_content(fixture("profile"), wait_until="load")
        await ck(await profile.locator(".certloom-setup-v2-progress-step").count() == 7, "sevenDecisionSteps")
        await ck(
            "Profile" in await profile.locator('.certloom-setup-v2-progress-step[aria-current="step"]').inner_text(),
            "profileCurrent",
        )
        await ck(await profile.locator('input[name="usage_profile"]').count() == 9, "nineUsageProfiles")
        await ck(await profile.locator("#certloom-wizard-subcategory").count() == 0, "noSubcategoryStep")
        await ck(await profile.locator('input[name="workspace_mode"]').count() == 0, "noSimpleAdvancedMode")
        await ck(await profile.locator(".certloom-setup-v2-model-help").count() == 1, "profileModelDisclosure")
        await ck(await profile.locator('button[value="skip_step"][formnovalidate]').count() == 1, "profileSkipLater")
        iso = profile.locator('input[name="usage_profile"][value="iso_management"]')
        await iso.check()
        await profile.wait_for_timeout(20)
        await ck(
            "certification scheme" in (await profile.locator('[data-certloom-hierarchy="program"]').inner_text()).lower(),
            "isoProgramMeaningLive",
        )
        await ck(
            "audit cycle" in (await profile.locator('[data-certloom-hierarchy="batch"]').inner_text()).lower(),
            "isoBatchMeaningLive",
        )
        government = profile.locator('input[name="usage_profile"][value="government_licensing"]')
        await government.check()
        await profile.wait_for_timeout(20)
        await ck(
            "license" in (await profile.locator('[data-certloom-hierarchy="program"]').inner_text()).lower(),
            "governmentProgramMeaningLive",
        )
        profile_root = profile.locator("[data-certloom-setup-v2]")
        await ck(await profile_root.get_attribute("data-certloom-dirty") == "1", "profileDirtyAfterChange")
        targets = await profile.locator(
            ".certloom-setup-v2-progress-step,.certloom-setup-v2-actions .button,.certloom-setup-v2-save-exit"
        ).evaluate_all("els => els.map(e => e.getBoundingClientRect().height)")
        await ck(all(h >= 40 for h in targets), "desktopTargetsUsable")
        await ck(await profile.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "profileNoOverflow")
        await profile.close()

        # Organization — default identity, WP media control, optional web/contact.
        organization = await browser.new_page(viewport={"width": 1100, "height": 820})
        await organization.set_content(fixture("organization"), wait_until="load")
        await ck(
            "Organization" in await organization.locator('.certloom-setup-v2-progress-step[aria-current="step"]').inner_text(),
            "organizationCurrent",
        )
        current_number = organization.locator('.certloom-setup-v2-progress-step[aria-current="step"] .certloom-setup-v2-progress-number')
        await ck(await current_number.evaluate("e => getComputedStyle(e).backgroundColor") == "rgb(1, 193, 116)", "organizationCurrentGreen")
        progress_fill = await organization.locator('.certloom-setup-v2-progress-step[aria-current="step"]').evaluate("e => getComputedStyle(e,'::before').backgroundImage")
        await ck("linear-gradient" in progress_fill and "193" in progress_fill, "organizationProgressBrandGradient")
        await ck(await organization.locator('button[value="skip_step"][formnovalidate]').count() == 1, "organizationSkipLater")
        await ck(await organization.locator('input[name="organization_name"][required]').count() == 1, "organizationNameRequired")
        await ck(await organization.locator('[data-certloom-media-control] input[name="organization_logo_id"]').count() == 1, "organizationMediaControl")
        await ck(await organization.locator('input[name="organization_website"]').count() == 1, "organizationWebsiteOptional")
        await ck(await organization.locator('input[name="organization_contact"]').count() == 1, "organizationContactOptional")
        await ck("Issuer" in await organization.locator(".certloom-setup-v2-heading").inner_text(), "organizationExplainsIssuerLater")
        org_name = organization.locator('input[name="organization_name"]')
        await org_name.fill("")
        await organization.locator("#certloom-wizard-step-form").evaluate("f => f.reportValidity()")
        await organization.wait_for_timeout(20)
        await ck(await org_name.get_attribute("aria-invalid") == "true", "organizationInvalidMarked")
        await ck(await organization.evaluate("document.activeElement && document.activeElement.name === 'organization_name'"), "organizationInvalidFocused")
        await org_name.fill("Example Org")
        await ck(await organization.locator("[data-certloom-setup-v2]").get_attribute("data-certloom-dirty") == "1", "organizationDirtyTracked")
        await organization.evaluate("""() => {
            window.__certloomMediaHandlers = {};
            window.wp = {media: () => ({
                on: (name, cb) => { window.__certloomMediaHandlers[name] = cb; },
                state: () => ({get: () => ({first: () => ({toJSON: () => ({id:77,mime:'image/png',filename:'logo.png',url:'https://example.test/logo.png',sizes:{thumbnail:{url:'https://example.test/logo-thumb.png'}}})})})}),
                open: () => { if (window.__certloomMediaHandlers.select) window.__certloomMediaHandlers.select(); if (window.__certloomMediaHandlers.close) window.__certloomMediaHandlers.close(); }
            })};
        }""")
        choose_logo = organization.locator('[data-certloom-media-select]')
        await ck(await choose_logo.get_attribute("aria-haspopup") == "dialog", "organizationMediaDialogSemantic")
        await choose_logo.click()
        await organization.wait_for_timeout(30)
        await ck(await organization.locator('input[name="organization_logo_id"]').input_value() == "77", "organizationMediaSelected")
        logo_preview_height = await organization.locator('[data-certloom-media-preview]').evaluate("e => e.getBoundingClientRect().height")
        await ck(logo_preview_height <= 56, "organizationLogoPreviewCompact")
        action_gap = await organization.locator('.certloom-setup-v2-media-actions').evaluate("e => parseFloat(getComputedStyle(e).columnGap || getComputedStyle(e).gap || '0')")
        await ck(action_gap >= 10, "organizationLogoActionsSpaced")
        action_centers = await organization.locator('.certloom-setup-v2-media-actions > *').evaluate_all("els => els.map(e => {const r=e.getBoundingClientRect(); return r.top+r.height/2})")
        await ck(max(action_centers)-min(action_centers) <= 2, "organizationLogoActionsAligned")
        await ck(await organization.locator('[data-certloom-media-preview]').get_attribute("role") == "status", "organizationMediaLiveStatus")
        await ck(await organization.evaluate("document.activeElement === document.querySelector('[data-certloom-media-select]')"), "organizationMediaFocusReturned")
        await organization.locator('[data-certloom-media-clear]').click()
        await ck(await organization.locator('input[name="organization_logo_id"]').input_value() == "", "organizationMediaCleared")
        await ck(await organization.evaluate("document.activeElement === document.querySelector('[data-certloom-media-select]')"), "organizationMediaClearFocusReturned")
        await ck(await organization.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "organizationNoOverflow")
        footer_alignment = await organization.evaluate("""() => {const card=document.querySelector('.certloom-setup-v2-step').getBoundingClientRect(); const foot=document.querySelector('.certloom-setup-v2-footer').getBoundingClientRect(); return Math.abs(card.left-foot.left)<=2 && Math.abs(card.right-foot.right)<=2;}""")
        await ck(footer_alignment, "wizardFooterAlignsWithCard")
        await organization.close()

        # Fields — recommendations remain editable and custom fields work without a separate settings trip.
        fields = await browser.new_page(viewport={"width": 1100, "height": 820})
        await fields.set_content(fixture("fields"), wait_until="load")
        await ck(await fields.locator('input[name="selected_fields[]"]').count() == 4, "recommendedFieldsVisible")
        await ck(await fields.locator('input[name="selected_fields[]"]:checked').count() == 2, "recommendedFieldsEditable")
        await ck(await fields.locator('button[value="skip_step"][formnovalidate]').count() == 1, "fieldsSkipLater")
        fresh = fields.locator("[data-certloom-custom-field-new]")
        await fresh.fill("Accreditation reference")
        await fields.locator("[data-certloom-custom-field-add]").click()
        await fields.wait_for_timeout(20)
        custom = fields.locator('input[name="custom_fields[]"]')
        await ck(await custom.count() == 1, "customFieldAdded")
        await ck(await custom.input_value() == "Accreditation reference", "customFieldValuePreserved")
        await fields.locator("[data-certloom-custom-field-remove]").click()
        await ck(await custom.count() == 0, "customFieldRemoved")
        await fresh.fill("Jurisdiction")
        await fresh.press("Enter")
        await ck(await fields.locator('input[name="custom_fields[]"]').count() == 1, "customFieldEnterShortcut")
        await fields.close()

        # Appearance — three mutually exclusive modes and competing artifact state is cleared.
        appearance = await browser.new_page(viewport={"width": 1100, "height": 840})
        await appearance.set_content(fixture("appearance"), wait_until="load")
        await ck(await appearance.locator('input[name="presentation_mode"]').count() == 3, "threePresentationModes")
        image_panel = appearance.locator('[data-certloom-presentation-panel="image"]')
        design_panel = appearance.locator('[data-certloom-presentation-panel="design"]')
        image_id = appearance.locator('input[name="certificate_image_id"]')
        design_select = appearance.locator('select[name="design_template_id"]')
        await ck(not await image_panel.evaluate("e => e.hidden"), "imagePanelInitiallyVisible")
        await ck(await design_panel.evaluate("e => e.hidden"), "designPanelInitiallyHidden")
        await ck(await image_id.input_value() == "91", "imageArtifactInitiallySelected")
        await ck(await appearance.locator('select[name="design_template_id"] option:not([value="0"])').count() == 10, "tenStarterDesignsAvailable")
        await ck(await appearance.get_by_text("Open Design Center", exact=True).count() == 1, "designCenterAvailable")
        await ck(await appearance.get_by_text("10 starter designs", exact=False).count() == 1, "starterDesignGuidance")
        await ck(await appearance.get_by_text("create your own certificate design", exact=False).count() == 1, "customDesignerGuidance")
        await ck(await appearance.locator('button[value="skip_step"][formnovalidate]').count() == 1, "appearanceSkipLater")
        await appearance.locator('input[name="presentation_mode"][value="design"]').check()
        await appearance.wait_for_timeout(20)
        await ck(await image_panel.evaluate("e => e.hidden"), "imagePanelHiddenForDesign")
        await ck(not await design_panel.evaluate("e => e.hidden"), "designPanelVisible")
        await ck(await image_id.input_value() == "", "designClearsImageArtifact")
        await appearance.wait_for_timeout(50)
        preview_scroll = appearance.locator('.certloom-wizard-template-preview-canvas .certloom-template-preview-scroll')
        await ck(await preview_scroll.get_attribute('data-certloom-preview-fitted') == '1', "designPreviewFitted")
        await ck(await preview_scroll.evaluate("e => e.scrollWidth <= e.clientWidth + 1 && e.scrollHeight <= e.clientHeight + 1"), "designPreviewNoScrollbars")
        await ck(await appearance.locator('.certloom-wizard-template-preview-stage').count() == 1, "designPreviewScaledStage")
        await design_select.select_option("44")
        await ck(await design_select.input_value() == "44", "publishedDesignSelectable")
        await appearance.locator('input[name="presentation_mode"][value="details_only"]').check()
        await appearance.wait_for_timeout(20)
        await ck(await image_panel.evaluate("e => e.hidden") and await design_panel.evaluate("e => e.hidden"), "detailsOnlyHidesArtifactPanels")
        await ck(await design_select.input_value() == "0" and await image_id.input_value() == "", "detailsOnlyClearsCompetingArtifacts")
        await appearance.locator('input[name="presentation_mode"][value="image"]').check()
        await appearance.wait_for_timeout(20)
        await ck(not await image_panel.evaluate("e => e.hidden"), "imageModeRestoresImagePanel")
        await ck(await design_select.input_value() == "0", "imageModeKeepsDesignCleared")
        await appearance.close()

        # Context & data — design requirements and active profile terminology drive onboarding.
        context = await browser.new_page(viewport={"width": 1050, "height": 900})
        await context.set_content(fixture("context"), wait_until="load")
        await ck(
            "Context & data" in await context.locator('.certloom-setup-v2-progress-step[aria-current="step"]').inner_text(),
            "contextCurrent",
        )
        await ck(await context.get_by_text("Active profile · ISO / Management Systems", exact=True).count() == 1, "contextProfileVisible")
        await ck(await context.get_by_text("Certification body", exact=True).count() >= 1, "isoIssuerVocabulary")
        await ck(await context.get_by_text("Scheme / standard / service line", exact=True).count() >= 1, "isoProgramVocabulary")
        await ck(await context.get_by_text("Audit / issuance cycle", exact=True).count() >= 1, "isoBatchVocabulary")
        await ck(await context.get_by_text("Authorized certification authority", exact=True).count() >= 1, "isoSignerVocabulary")
        await ck(await context.locator('input[name="setup_credential_values[address]"][required]').count() == 1, "designAddressOnboardingField")
        await ck(await context.get_by_text("Required by design", exact=True).count() >= 3, "designRequiredBadges")
        await ck(await context.get_by_text("Collected with the recipient on the next step", exact=True).count() == 1, "recipientRequirementsDeferredCorrectly")
        program = context.locator('[data-certloom-setup-program]')
        batch = context.locator('[data-certloom-setup-batch]')
        await program.select_option("11")
        await context.wait_for_timeout(20)
        await ck(await context.locator('select[name="setup_issuer_id"]').input_value() == "5", "programAutoSelectsIssuer")
        await ck(not await batch.locator('option[value="21"]').is_disabled(), "matchingBatchAvailable")
        await ck(await batch.locator('option[value="22"]').is_disabled(), "otherProgramBatchDisabled")
        await ck(await context.locator('button[value="skip_step"][formnovalidate]').count() == 1, "contextSkipLater")
        await context.close()

        # First credential — recipient facts and effective date complete the real issuance.
        issue = await browser.new_page(viewport={"width": 1050, "height": 800})
        await issue.set_content(fixture("issue"), wait_until="load")
        await ck(
            "First credential" in await issue.locator('.certloom-setup-v2-progress-step[aria-current="step"]').inner_text(),
            "firstCredentialCurrent",
        )
        await ck(await issue.locator('input[name="first_recipient_name"][required]').count() == 1, "recipientRequired")
        await ck(await issue.locator('input[name="credential_title"][required]').count() == 1, "independentCredentialTitleRequired")
        await ck(await issue.locator('input[name="issue_date"][required]').count() == 1, "effectiveDateRequired")
        await ck(await issue.get_by_text("Effective date", exact=True).count() == 1, "effectiveDateTerminology")
        await ck(await issue.locator('input[name="first_recipient_email"]').count() == 1, "recipientEmailAvailable")
        await ck(await issue.locator('input[name="first_recipient_external_id"][required]').count() == 1, "designRecipientRequirementCollected")
        await ck(await issue.locator('button[value="skip_step"][formnovalidate]').count() == 1, "issueDoLater")
        prohibited = await issue.locator('[name="numbering_mode"],[name="backup_mode"]').count()
        await ck(prohibited == 0, "noLegacyPolicyFields")
        await ck(await issue.get_by_text("Ready for a real credential", exact=True).count() == 1, "canonicalIssuanceVisible")
        await ck(await issue.get_by_text("Independent credential", exact=True).count() == 1, "independentBasisExplicit")
        await ck(await issue.get_by_text("Issue first credential", exact=True).count() == 1, "realIssueActionVisible")
        await ck(await issue.get_by_text("Review context & data", exact=True).count() == 1, "contextReviewLinkVisible")
        await ck(await issue.get_by_text("Active profile · ISO / Management Systems", exact=True).count() == 1, "issueProfileVisible")
        await ck(await issue.get_by_text("Standalone issuance connection is still being implemented", exact=False).count() == 0, "noObsoleteBackendGap")
        await issue.close()

        # Finish — explicit do-later is a complete setup state, not a fake requirement.
        finish = await browser.new_page(viewport={"width": 1050, "height": 800})
        await finish.set_content(fixture("finish"), wait_until="load")
        await ck(await finish.get_by_text("Setup complete", exact=True).count() == 1, "finishStatesSetupComplete")
        await ck(await finish.get_by_text("Certloom is ready. Issue your first credential when you are ready.", exact=True).count() == 1, "finishIssuanceDeferredHonestly")
        await ck(await finish.get_by_text("no placeholder Program, Batch or credential was created", exact=False).count() == 1, "finishNoPlaceholderRecords")
        await ck(await finish.get_by_text("One required step remains", exact=False).count() == 0, "finishNoObsoleteRequirement")
        await ck(await finish.locator(".certloom-setup-v2-next li").count() == 4, "profileChecklistVisible")
        await ck(await finish.locator(".certloom-setup-v2-footer").count() == 0, "finishNoDraftFooter")
        await finish.close()

        # Mobile — contained progress, single-column forms and 44px core targets.
        mobile = await browser.new_page(viewport={"width": 390, "height": 820})
        await mobile.set_content(fixture("profile"), wait_until="load")
        await ck(await mobile.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "mobileNoRootOverflow")
        await ck(await mobile.locator(".certloom-setup-v2-progress").evaluate("e => e.scrollWidth > e.clientWidth"), "mobileProgressContainedScrollable")
        mobile_heights = await mobile.locator(
            ".certloom-setup-v2-progress-step,.certloom-setup-v2-actions .button,.certloom-setup-v2-exit"
        ).evaluate_all("els => els.map(e => e.getBoundingClientRect().height)")
        await ck(all(h >= 44 for h in mobile_heights), "mobileTargets44")
        await ck(
            await mobile.locator(".certloom-setup-v2-profile-grid").evaluate(
                "e => getComputedStyle(e).gridTemplateColumns.trim().split(/\\s+/).length === 1"
            ),
            "mobileProfileOneColumn",
        )
        await mobile.close()

        # RTL — same flow, no horizontal root overflow.
        rtl = await browser.new_page(viewport={"width": 900, "height": 760})
        await rtl.set_content(fixture("rtl"), wait_until="load")
        await ck(await rtl.locator("html").get_attribute("dir") == "rtl", "rtlDirection")
        await ck(await rtl.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"), "rtlNoOverflow")
        await ck(await rtl.locator(".certloom-setup-v2-progress").get_attribute("aria-label") == "Setup progress", "rtlSemanticProgress")
        await rtl.close()

        # Reduced motion and forced colors remain usable.
        reduced = await browser.new_page(viewport={"width": 900, "height": 700}, reduced_motion="reduce")
        await reduced.set_content(fixture("profile"), wait_until="load")
        duration = await reduced.locator(".certloom-setup-v2-profile-card").first.evaluate("e => getComputedStyle(e).transitionDuration")
        await ck(duration in ("0s", "0.00001s", "0.001s") or duration.startswith("0.00"), "reducedMotion")
        await reduced.close()

        forced = await browser.new_page(viewport={"width": 900, "height": 700}, forced_colors="active")
        await forced.set_content(fixture("profile"), wait_until="load")
        await ck(await forced.locator(".certloom-setup-v2-progress").count() == 1, "forcedColorsProgress")
        await forced.locator('input[name="usage_profile"][value="iso_management"]').check()
        selected_border = await forced.locator('input[name="usage_profile"][value="iso_management"]').locator("xpath=..").evaluate("e => getComputedStyle(e).borderTopColor")
        await ck(bool(selected_border), "forcedColorsSelectedProfileVisible")
        await ck(await forced.locator(".certloom-setup-v2-model-help").count() == 1, "forcedColorsHierarchyHelp")
        await forced.close()

        await browser.close()

    if failed:
        print("FAIL  UI Phase 3 Setup Wizard v2 browser gate:", ", ".join(failed))
        print(f"\n{checks-len(failed)} passed, {len(failed)} failures")
        return 1

    print("PASS  UI Phase 3 Setup Wizard v2 browser gate: focused shell, profiles, organization, field recommendations, mutually exclusive appearance, design-driven context/data, real optional first-credential issuance, honest deferred finish, mobile/RTL/accessibility")
    print(f"\n{checks} UI Phase 3 browser checks, 0 failures")
    return 0


raise SystemExit(asyncio.run(run()))
