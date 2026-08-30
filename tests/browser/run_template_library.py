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
        await ck(await page.locator('.certloom-template-library-card').count()==4,'fourGeometryCards')
        await ck(await page.get_by_role('button',name='Use for Program').count()==3,'programPrimaryActions')
        await ck(await page.get_by_role('link',name='Edit in Designer').count()==4,'editDesignerActions')
        await ck(await page.get_by_role('link',name='Duplicate').count()==4,'duplicateActions')
        await ck(await page.get_by_text('Certification & Standards',exact=True).count()>=1,'programPurposeLabel')
        await ck(await page.get_by_role('heading',name='Assurance Letter Landscape').count()==1,'curatedConceptVisible')
        await ck(await page.get_by_text('Curated factory draft. Publish before issuance.',exact=True).count()==1,'curatedDraftHonesty')
        await ck(await page.get_by_text('English base',exact=True).count()==1,'languageHonesty')
        await ck(await page.get_by_text('RTL layout-ready',exact=True).count()>=1,'rtlFacet')
        await ck(await page.evaluate('document.documentElement.scrollWidth <= window.innerWidth + 1'),'desktopNoOverflow')
        await ck(await page.locator('.certloom-template-library-program-filter').count()==1,'dedicatedProgramFilterStrip')
        program_boxes=await page.locator('.certloom-library-filter-program .certloom-context-lookup__search, .certloom-library-filter-program select').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect()).map(r=>({x:r.x,y:r.y,w:r.width,h:r.height}))')
        await ck(len(program_boxes)==2 and abs(program_boxes[0]['y']-program_boxes[1]['y'])<2 and abs(program_boxes[0]['h']-program_boxes[1]['h'])<2,'programSearchSelectAlignedDesktop')
        primary_boxes=await page.locator('.certloom-template-library-filter-primary input, .certloom-template-library-filter-primary select').evaluate_all('(els)=>els.map(e=>e.getBoundingClientRect()).map(r=>({y:r.y,h:r.height}))')
        await ck(len(primary_boxes)==4 and max(abs(b['y']-primary_boxes[0]['y']) for b in primary_boxes)<2,'primaryFilterControlsShareBaseline')
        # C09 fit-to-card: landscape and portrait pages are fully contained, centered and never internally scrollable.
        for index,label in enumerate(('a4Landscape','a4Portrait','letterLandscape','letterPortrait')):
            stage=page.locator('.certloom-template-library-thumbnail-stage').nth(index)
            wrapper=stage.locator('.certloom-template-preview-scroll')
            page_box=wrapper.locator('.certloom-template-preview-page')
            contained=await stage.evaluate("""(stage)=>{const page=stage.querySelector('.certloom-template-preview-page');if(!page)return false;const s=stage.getBoundingClientRect(),p=page.getBoundingClientRect();return p.left>=s.left-1&&p.top>=s.top-1&&p.right<=s.right+1&&p.bottom<=s.bottom+1;}""")
            await ck(contained,label+'ThumbnailFullyContained')
            await ck(await wrapper.evaluate('(e)=>getComputedStyle(e).overflow==="hidden"'),'noInternalScrollbars'+label.title())
            centered=await stage.evaluate("""(stage)=>{const page=stage.querySelector('.certloom-template-preview-page');const s=stage.getBoundingClientRect(),p=page.getBoundingClientRect();return Math.abs((p.left+p.width/2)-(s.left+s.width/2))<=1.5&&Math.abs((p.top+p.height/2)-(s.top+s.height/2))<=1.5;}""")
            await ck(centered,label+'ThumbnailCentered')
        await ck(await page.locator('[data-fixture-page="a4-landscape"]').count()==1 and await page.locator('[data-fixture-page="a4-portrait"]').count()==1,'a4BothOrientations')
        await ck(await page.locator('[data-fixture-page="letter-landscape"]').count()==1 and await page.locator('[data-fixture-page="letter-portrait"]').count()==1,'letterBothOrientations')
        await ck(await page.locator('.fixture-long-name').count()==4,'longRecipientNamesCovered')
        await ck(await page.locator('.fixture-background').count()>=2,'backgroundContentCovered')
        await ck(await page.locator('.fixture-logo').count()>=2,'logoContentCovered')
        await ck(await page.locator('.certloom-template-preview-page[dir="rtl"]').count()==1,'rtlCertificateContentCovered')
        # Large preview opens from first canonical thumbnail.
        first=page.locator('[data-certloom-library-preview]').first
        await first.click()
        dialog=page.locator('[data-certloom-library-dialog]')
        await ck(await dialog.evaluate('(d)=>d.open'),'previewDialogOpen')
        await ck(await page.locator('[data-certloom-library-dialog-body] .certloom-template-preview-scroll').count()==1,'previewCloned')
        await ck((await page.locator('[data-certloom-library-dialog-title]').inner_text())=='Classic A4 Landscape','previewTitle')
        box=await page.locator('[data-certloom-library-dialog-stage]').bounding_box()
        await ck(bool(box and box['width']>500 and box['height']>300),'largePreviewScale')
        modal_wrapper=page.locator('[data-certloom-library-dialog-stage] .certloom-template-preview-scroll')
        await ck(await modal_wrapper.evaluate('(e)=>getComputedStyle(e).overflow==="hidden"'),'dialogPreviewNoInternalScrollbar')
        await ck(await page.locator('[data-certloom-library-dialog-stage] .certloom-template-preview-page').evaluate('(e)=>e.scrollWidth<=e.clientWidth+1&&e.scrollHeight<=e.clientHeight+1'),'dialogPageContained')
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
        await ck(await mobile.locator('.certloom-library-filter-program .certloom-context-lookup').evaluate('(e)=>getComputedStyle(e).gridTemplateColumns.split(" ").length===1'),'mobileProgramLookupStacks')
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
