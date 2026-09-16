#!/usr/bin/env python3
"""Chromium acceptance for the current fitted three-pane Certificate Studio workspace."""
import asyncio, json, os, subprocess
from pathlib import Path
from playwright.async_api import async_playwright

ROOT=Path(__file__).resolve().parents[2]
BUILDER=ROOT/'tests'/'browser'/'build_ui_phase4_sticky_workspace_fixture.php'

def fixture_html():
    env=os.environ.copy(); env['CERTLOOM_DESIGNER_SCHEMA_VERSION']='3'
    p=subprocess.run(['php',str(BUILDER)],cwd=ROOT,env=env,text=True,capture_output=True)
    if p.returncode: raise RuntimeError(p.stderr or p.stdout)
    return p.stdout

async def main():
    failed=[]; checks=0; html=fixture_html()
    async def ck(v,name):
        nonlocal checks; checks+=1
        if not v: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':778}); page.set_default_timeout(5000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(220)
        metrics=await page.evaluate("""()=>{
          const q=s=>document.querySelector(s), r=e=>e.getBoundingClientRect(), cs=e=>getComputedStyle(e);
          const root=q('[data-certloom-designer]'), stage=q('.certloom-template-designer-stage'), left=q('.certloom-studio-left'), work=q('[data-certloom-designer-workspace]'), props=q('.certloom-template-designer-inspector'), doc=q('.certloom-studio-documentbar');
          return {root:r(root),stage:r(stage),left:r(left),work:r(work),props:r(props),doc:r(doc),stageCols:cs(stage).gridTemplateColumns,workOverflow:cs(work).overflowY,propsOverflow:cs(props).overflowY,rootOverflow:cs(root).overflowY,bodyOverflow:cs(document.body).overflowY,docH:document.documentElement.scrollHeight,clientH:document.documentElement.clientHeight,docW:document.documentElement.scrollWidth,clientW:document.documentElement.clientWidth};
        }""")
        await ck(abs(metrics['root']['height']-778)<=1,'desktopStudioFitsViewport')
        await ck(metrics['doc']['height']<=50,'documentBarStaysCompact')
        await ck(metrics['left']['x']<metrics['work']['x']<metrics['props']['x'],'desktopPaneOrderIsInsertCanvasInspector')
        await ck(len(metrics['stageCols'].split())==3,'desktopWorkbenchHasThreeColumns')
        await ck(metrics['work']['height']>=650 and metrics['work']['width']>=700,'canvasKeepsLargeWorkingArea')
        await ck(metrics['workOverflow'] in ('auto','scroll'),'canvasOwnsIndependentScroller')
        await ck(metrics['propsOverflow'] in ('auto','scroll'),'inspectorOwnsIndependentScroller')
        await ck(metrics['rootOverflow']=='hidden' and metrics['bodyOverflow']=='hidden','desktopPageDoesNotScrollAroundStudio')
        await ck(abs(metrics['docH']-metrics['clientH'])<=1,'desktopDocumentHeightEqualsViewport')
        await ck(metrics['docW']<=metrics['clientW']+1,'desktopHasNoRootHorizontalOverflow')

        # Selecting text makes the Inspector taller than its viewport; scrolling it does not move the canvas.
        layout=json.loads(await page.locator('[data-certloom-designer-json]').input_value()); tid=next(e['id'] for e in layout['elements'] if e['type']=='text')
        layer=page.locator(f'[data-certloom-designer-layer-select="{tid}"]'); await layer.focus(); await layer.press('Enter'); await page.wait_for_timeout(60)
        props=page.locator('.certloom-template-designer-inspector'); work=page.locator('[data-certloom-designer-workspace]')
        pscroll=await props.evaluate('e=>({sh:e.scrollHeight,ch:e.clientHeight})'); await ck(pscroll['sh']>pscroll['ch'],'selectedElementCreatesScrollableInspector')
        before_work=await work.evaluate('e=>e.scrollTop'); await props.evaluate('e=>e.scrollTop=120'); await page.wait_for_timeout(25)
        await ck(await props.evaluate('e=>e.scrollTop>0'),'inspectorScrollsIndependently')
        await ck(await work.evaluate('e=>e.scrollTop')==before_work,'inspectorScrollDoesNotMoveCanvas')

        # Zooming enlarges only the canvas scroller; hidden zoom state is driven through its canonical input event.
        zoom=page.locator('[data-certloom-designer-zoom]'); await zoom.evaluate("(e)=>{e.value='200';e.dispatchEvent(new Event('input',{bubbles:true}))}"); await page.wait_for_timeout(90)
        wscroll=await work.evaluate('e=>({sw:e.scrollWidth,cw:e.clientWidth,sh:e.scrollHeight,ch:e.clientHeight})')
        await ck(wscroll['sw']>wscroll['cw'] and wscroll['sh']>wscroll['ch'],'zoomCreatesLocalCanvasPanRange')
        await work.evaluate('e=>{e.scrollLeft=90;e.scrollTop=70}'); await page.wait_for_timeout(20)
        await ck(await work.evaluate('e=>e.scrollLeft>0&&e.scrollTop>0'),'canvasInternalScrollWorks')

        # Current Studio controls remain reachable in their intended surfaces.
        await ck(await page.locator('[data-certloom-designer-insert-focus]').is_visible(),'insertControlReachable')
        await ck(await page.locator('.certloom-studio-actions').get_by_text('Draft',exact=True).is_visible() and await page.locator('.certloom-studio-actions').get_by_text('Publish',exact=True).is_visible(),'draftAndPublishReachable')
        await ck(await page.locator('[data-certloom-designer-reset]').is_visible(),'restoreSavedStateControlReachable')
        view=page.locator('.certloom-studio-view-menu'); await ck(await view.locator('summary').is_visible(),'viewOptionsReachable'); await view.locator('summary').click(); await page.wait_for_timeout(25)
        await ck(await page.locator('[data-certloom-designer-sample-data]').is_visible() and await page.locator('[data-certloom-designer-safe-area]').is_visible(),'sampleAndSafeAreaLiveInViewOptions')
        await view.locator('summary').click()

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000); await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(180)
        mm=await mobile.evaluate("""()=>{const q=s=>document.querySelector(s),r=e=>e.getBoundingClientRect(),cs=e=>getComputedStyle(e);const s=q('.certloom-template-designer-stage'),l=q('.certloom-studio-left'),w=q('[data-certloom-designer-workspace]'),p=q('.certloom-template-designer-inspector');return {cols:cs(s).gridTemplateColumns,left:r(l),work:r(w),props:r(p),workPos:cs(w).position,propsPos:cs(p).position,docH:document.documentElement.scrollHeight,ch:document.documentElement.clientHeight,noX:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1};}""")
        await ck(len(mm['cols'].split())==1,'mobileWorkbenchStacksToOneColumn')
        await ck(mm['left']['y']<mm['work']['y']<mm['props']['y'],'mobileNaturalOrderIsInsertCanvasInspector')
        await ck(mm['workPos']!='sticky' and mm['propsPos']!='sticky','mobilePanesReturnToNaturalFlow')
        await ck(mm['docH']>mm['ch'],'mobileDocumentCanScrollNaturally')
        await ck(mm['noX'],'mobileHasNoHorizontalOverflow')
        await ck(mm['work']['height']>=459,'mobileCanvasKeepsUsableHeight')
        await mobile.close()

        forced=await browser.new_page(viewport={'width':1000,'height':800}); await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(60)
        await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsStillSupported')
        await forced.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4 current workspace Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4 current workspace Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
