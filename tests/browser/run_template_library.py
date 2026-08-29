#!/usr/bin/env python3
import asyncio, subprocess
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[2]

def fixture():
    r=subprocess.run(['php',str(ROOT/'tests/browser/build_template_library_fixture.php')],cwd=ROOT,capture_output=True,text=True)
    if r.returncode: raise RuntimeError(r.stderr or r.stdout)
    return r.stdout

async def main():
    checks=0; failed=[]
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        async def ck(value,label):
            nonlocal checks; checks+=1
            if not value: failed.append(label)
        page=await browser.new_page(viewport={'width':1440,'height':1000})
        await page.set_content(fixture(),wait_until='load')
        await ck(await page.get_by_role('heading',name='Choose a certificate design visually').count()==1,'libraryHeading')
        await ck(await page.locator('.certloom-template-library-card').count()==3,'threeVisualCards')
        await ck(await page.get_by_role('button',name='Use for Program').count()==2,'programPrimaryActions')
        await ck(await page.get_by_role('link',name='Customize').count()==3,'customizeActions')
        await ck(await page.get_by_role('link',name='Duplicate').count()==3,'duplicateActions')
        await ck(await page.get_by_text('Certification & Standards',exact=True).count()>=1,'programPurposeLabel')
        await ck(await page.get_by_role('heading',name='Assurance').count()==1,'curatedConceptVisible')
        await ck(await page.get_by_text('Curated factory draft. Publish before issuance.',exact=True).count()==1,'curatedDraftHonesty')
        await ck(await page.get_by_text('English base',exact=True).count()==1,'languageHonesty')
        await ck(await page.get_by_text('RTL layout-ready',exact=True).count()==1,'rtlFacet')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        # Large preview opens from first canonical thumbnail.
        first=page.locator('[data-certloom-library-preview]').first
        await first.click()
        dialog=page.locator('[data-certloom-library-dialog]')
        await ck(await dialog.evaluate('(d)=>d.open'),'previewDialogOpen')
        await ck(await page.locator('[data-certloom-library-dialog-body] .certloom-template-preview-scroll').count()==1,'previewCloned')
        await ck((await page.locator('[data-certloom-library-dialog-title]').inner_text())=='Classic Landscape','previewTitle')
        box=await page.locator('[data-certloom-library-dialog-stage]').bounding_box()
        await ck(bool(box and box['width']>500 and box['height']>300),'largePreviewScale')
        await page.get_by_role('button',name='Close').click()
        await ck(not await dialog.evaluate('(d)=>d.open'),'previewDialogClose')
        await ck(await first.evaluate('(e)=>document.activeElement===e'),'previewFocusRestored')
        # Background workflow uses local wp.media and remains disabled until valid selection.
        toggle=page.get_by_role('button',name='Upload certificate background')
        panel=page.locator('[data-certloom-library-background-panel]')
        await ck(await panel.get_attribute('hidden') is not None,'backgroundInitiallyCollapsed')
        await toggle.click()
        await ck(await panel.get_attribute('hidden') is None,'backgroundExpanded')
        await ck((await toggle.get_attribute('aria-expanded'))=='true','backgroundAriaExpanded')
        choose=page.get_by_role('button',name='Choose image')
        await choose.click(); await ck(await page.evaluate('window.__mediaOpened===true'),'mediaPickerOpened')
        await page.evaluate('window.__lastFrame.__select()')
        await ck((await page.locator('[data-certloom-library-background-id]').input_value())=='42','mediaAttachmentSelected')
        await ck((await page.locator('[data-certloom-library-background-name]').inner_text())=='certificate-background.png','mediaFilenameVisible')
        await ck(not await page.locator('[data-certloom-library-background-submit]').is_disabled(),'backgroundSubmitEnabled')
        await page.close()
        # Mobile containment and accessible action targets.
        mobile=await browser.new_page(viewport={'width':390,'height':844})
        await mobile.set_content(fixture(),wait_until='load')
        await ck(await mobile.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'mobileNoOverflow')
        action_heights=await mobile.locator('.certloom-template-library-actions .button').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect().height)')
        await ck(all(h>=44 for h in action_heights),'mobileActionsAtLeast44')
        await ck(await mobile.locator('.certloom-template-library-grid').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileSingleColumnCards')
        await mobile.close()
        # RTL does not overflow or reverse underlying authority semantics.
        rtl=await browser.new_page(viewport={'width':900,'height':780})
        await rtl.set_content(fixture(),wait_until='load'); await rtl.locator('html').evaluate("e=>e.setAttribute('dir','rtl')")
        await ck(await rtl.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'rtlNoOverflow')
        await ck(await rtl.get_by_role('button',name='Use for Program').first.is_visible(),'rtlPrimaryActionVisible')
        await rtl.close()
        reduced=await browser.new_page(viewport={'width':900,'height':780},reduced_motion='reduce')
        await reduced.set_content(fixture(),wait_until='load')
        await ck(await reduced.evaluate("matchMedia('(prefers-reduced-motion: reduce)').matches"),'reducedMotionActive')
        await ck(await reduced.locator('.certloom-template-library-card').first.is_visible(),'reducedMotionCardsVisible')
        await reduced.close()
        forced=await browser.new_page(viewport={'width':900,'height':780},forced_colors='active')
        await forced.set_content(fixture(),wait_until='load')
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsActive')
        await ck(await forced.locator('.certloom-template-library-card').first.is_visible(),'forcedColorsCardsVisible')
        await browser.close()
    if failed:
        print('FAIL  TPLUX-01 Template Library browser gate:', ', '.join(failed)); print(f'\n{checks-len(failed)} passed, {len(failed)} failures'); return 1
    print('PASS  TPLUX-02 Template Library browser gate: visual discovery, canonical preview dialog, Program action, background picker, responsive/RTL/accessibility media')
    print(f'\n{checks} TPLUX-02 Template Library browser checks, 0 failures'); return 0
raise SystemExit(asyncio.run(main()))
