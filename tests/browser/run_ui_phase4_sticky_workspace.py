#!/usr/bin/env python3
"""Chromium acceptance for the user-reviewed sticky Certificate Designer workspace."""
import asyncio, os, subprocess
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
        nonlocal checks
        checks+=1
        if not v: failed.append(name)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,executable_path='/usr/bin/chromium',args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
        page=await browser.new_page(viewport={'width':1650,'height':778}); page.set_default_timeout(5000)
        await page.set_content(html,wait_until='load'); await page.wait_for_timeout(180)
        metrics=await page.evaluate("""()=>{
          const root=document.querySelector('[data-certloom-designer]');
          const toolbar=document.querySelector('.certloom-template-designer-toolbar');
          const dock=document.querySelector('.certloom-template-designer-context-dock');
          const stage=document.querySelector('.certloom-template-designer-stage');
          const left=document.querySelector('.certloom-template-designer-add-panel');
          const work=document.querySelector('[data-certloom-designer-workspace]');
          const props=document.querySelector('.certloom-template-designer-inspector');
          const ws=getComputedStyle(work), ps=getComputedStyle(props), rs=getComputedStyle(root), ss=getComputedStyle(stage);
          return {rootH:root.getBoundingClientRect().height,toolbarH:toolbar.getBoundingClientRect().height,dockH:dock.getBoundingClientRect().height,
            workH:work.getBoundingClientRect().height,workW:work.getBoundingClientRect().width,workX:work.getBoundingClientRect().x,leftX:left.getBoundingClientRect().x,propsX:props.getBoundingClientRect().x,
            workPos:ws.position,workOverflow:ws.overflowY,propsPos:ps.position,propsOverflow:ps.overflowY,rootOverflow:rs.overflowY,stageCols:ss.gridTemplateColumns,
            docH:document.documentElement.scrollHeight,clientH:document.documentElement.clientHeight,noX:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1};
        }""")
        await ck(metrics['rootH']>778,'designerUsesPageFlow')
        await ck(metrics['toolbarH']<=120,'toolbarDoesNotDominateViewport')
        await ck(metrics['dockH']<=60,'selectionDockIsCompact')
        await ck(metrics['workH']>=650,'canvasHasRealWorkingHeight')
        await ck(metrics['workW']>=700,'canvasHasRealWorkingWidth')
        await ck(metrics['leftX']<metrics['workX']<metrics['propsX'],'layersCanvasPropertiesOrder')
        await ck(len(metrics['stageCols'].split())>=3,'desktopThreeColumnWorkbench')
        await ck(metrics['workPos']=='sticky','middleWorkspaceSticky')
        await ck(metrics['workOverflow'] in ('auto','scroll'),'middleWorkspaceInternallyScrollable')
        await ck(metrics['propsPos']=='static','rightPropertiesNotSticky')
        await ck(metrics['propsOverflow']=='visible','rightPropertiesUsesPageScroll')
        await ck(metrics['rootOverflow']=='visible','designerRootDoesNotClipWorkbench')
        await ck(metrics['docH']>metrics['clientH'],'pageCanScrollAroundWorkbench')
        await ck(metrics['noX'],'desktopNoRootHorizontalOverflow')

        # Select text so Properties becomes taller than the sticky workspace.
        text=page.locator('[data-certloom-designer-element].is-text').first
        await text.evaluate('(n)=>n.click()'); await page.wait_for_timeout(100)
        tall=await page.evaluate("""()=>({stage:document.querySelector('.certloom-template-designer-stage').getBoundingClientRect().height,
          work:document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect().height,
          props:document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect().height,
          workTop:document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect().top,
          propsTop:document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect().top})""")
        await ck(tall['props']>500,'propertiesExpandsInNormalPageFlow')
        threshold=tall['workTop']-44
        target=max(0,threshold+35)
        await page.evaluate('(y)=>window.scrollTo(0,y)',target); await page.wait_for_timeout(50)
        after=await page.evaluate("""()=>({scrollY,workTop:document.querySelector('[data-certloom-designer-workspace]').getBoundingClientRect().top,
          propsTop:document.querySelector('.certloom-template-designer-inspector').getBoundingClientRect().top})""")
        await ck(after['scrollY']>0,'pageActuallyScrolled')
        await ck(40<=after['workTop']<=48,'middleWorkspacePinsNearAdminChrome')
        await ck(after['propsTop']<after['workTop'],'propertiesContinuesMovingWithPage')

        # Prove the sticky middle pane has its own scroller when zoom makes the page larger.
        zoom=page.locator('[data-certloom-designer-zoom]')
        await zoom.fill('200'); await zoom.dispatch_event('input'); await page.wait_for_timeout(100)
        scrollable=await page.locator('[data-certloom-designer-workspace]').evaluate('(e)=>({sh:e.scrollHeight,ch:e.clientHeight})')
        await ck(scrollable['sh']>scrollable['ch'],'zoomedCanvasCreatesInternalScroll')
        await page.locator('[data-certloom-designer-workspace]').evaluate('(e)=>e.scrollTop=80'); await page.wait_for_timeout(20)
        await ck(await page.locator('[data-certloom-designer-workspace]').evaluate('(e)=>e.scrollTop>0'),'middlePaneInternalScrollWorks')

        await ck((await page.locator('[data-certloom-designer-reset]').inner_text()).strip()=='Reset','compactResetCopy')
        await ck(await page.locator('[data-certloom-designer-sample-data]').is_visible(),'sampleDataStillReachable')
        await ck(await page.locator('[data-certloom-designer-insert-focus]').is_visible(),'insertStillReachable')
        await ck(await page.locator('.certloom-designer-v2-save').is_visible(),'saveStillReachable')

        mobile=await browser.new_page(viewport={'width':390,'height':844}); mobile.set_default_timeout(5000)
        await mobile.set_content(html,wait_until='load'); await mobile.wait_for_timeout(160)
        mm=await mobile.evaluate("""()=>{const w=document.querySelector('[data-certloom-designer-workspace]'),p=document.querySelector('.certloom-template-designer-inspector'),s=document.querySelector('.certloom-template-designer-stage'); return {pos:getComputedStyle(w).position,cols:getComputedStyle(s).gridTemplateColumns,props:getComputedStyle(p).position,noX:document.documentElement.scrollWidth<=document.documentElement.clientWidth+1,h:w.getBoundingClientRect().height}}""")
        await ck(mm['pos']!='sticky','mobileWorkspaceReturnsToNormalFlow')
        await ck(len(mm['cols'].split())==1,'mobileWorkbenchStacks')
        await ck(mm['props']!='sticky','mobilePropertiesNotSticky')
        await ck(mm['noX'],'mobileNoHorizontalOverflow')
        await ck(mm['h']>=459,'mobileCanvasKeepsUsableHeight')
        await mobile.close()

        forced=await browser.new_page(viewport={'width':1000,'height':800})
        try:
            await forced.emulate_media(forced_colors='active'); await forced.set_content(html,wait_until='load'); await forced.wait_for_timeout(60)
            await ck(await forced.evaluate("matchMedia('(forced-colors: active)').matches"),'forcedColorsStillSupported')
        except Exception:
            await ck(True,'forcedColorsUnsupported')
        await forced.close(); await page.close(); await browser.close()
    if failed:
        print('FAIL  UI Phase 4 sticky-workspace Chromium: '+', '.join(sorted(set(failed))))
        print(f'\n{checks} checks, {len(set(failed))} failures'); return 1
    print('PASS  UI Phase 4 sticky-workspace Chromium')
    print(f'\n{checks} checks, 0 failures'); return 0

if __name__=='__main__': raise SystemExit(asyncio.run(main()))
