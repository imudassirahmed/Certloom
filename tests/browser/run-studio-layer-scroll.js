// Run with playwright-cli in the existing authenticated Local Studio browser.
// Navigation, selection and scrolling only; no layout edits, saving or publishing.
async(page)=>{
  if(!page.url().startsWith('http://ecapla.local/')){throw new Error('Use the disposable Local site.');}
  await page.bringToFront();
  await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=editor&template_id=42',{waitUntil:'commit'});
  await page.waitForFunction(()=>document.readyState==='complete'&&!!document.querySelector('[data-certloom-designer-layer-row]'));
  const originalViewport=await page.evaluate(()=>({width:innerWidth,height:innerHeight}));
  const originalLayout=await page.locator('[data-certloom-designer-json]').inputValue();
  const results=[];
  let phase='viewport setup';
  async function metrics(){return await page.locator('[data-certloom-designer-layers]').evaluate(list=>{
    const rect=list.getBoundingClientRect(),panel=list.parentElement.getBoundingClientRect();
    const last=list.lastElementChild.getBoundingClientRect();
    return {viewport:[innerWidth,innerHeight],rows:list.children.length,clientHeight:list.clientHeight,scrollHeight:list.scrollHeight,scrollTop:list.scrollTop,gutter:list.offsetWidth-list.clientWidth,
      bounded:rect.bottom<=panel.bottom+1&&rect.top>=panel.top-1,
      lastVisible:last.top>=rect.top-1&&last.bottom<=rect.bottom+1,
      focused:document.activeElement===list};
  });}
  try{
    for(const viewport of [{width:1536,height:1000},{width:1280,height:720},{width:1024,height:768},{width:1280,height:600},{width:900,height:700},{width:390,height:844}]){
      phase=viewport.width+'x'+viewport.height+' setup';
      await page.setViewportSize(viewport);await page.bringToFront();
      await page.evaluate(async()=>{await document.fonts.ready;await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});
      const list=page.locator('[data-certloom-designer-layers]');
      await list.scrollIntoViewIfNeeded();await list.focus();
      phase=viewport.width+'x'+viewport.height+' Home';
      await page.keyboard.press('Home');
      await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop<1);
      const start=await metrics();
      if(!start.bounded||start.clientHeight<1||start.gutter<1||!start.focused){throw new Error('Unreachable Layers pane: '+JSON.stringify(start));}
      phase=viewport.width+'x'+viewport.height+' End';
      await page.keyboard.press('End');
      await page.waitForFunction(()=>{const l=document.querySelector('[data-certloom-designer-layers]');return l.scrollHeight-l.clientHeight-l.scrollTop<2;});
      const end=await metrics();
      if(!end.lastVisible){throw new Error('End must reveal the last layer: '+JSON.stringify(end));}
      let wheel=false;
      if(start.scrollHeight>start.clientHeight+1){
        phase=viewport.width+'x'+viewport.height+' wheel';
        await page.keyboard.press('Home');
        await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop<1);
        const box=await list.boundingBox();
        await page.mouse.move(box.x+box.width/2,box.y+box.height/2);await page.mouse.wheel(0,650);
        await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop>0);
        wheel=true;
      }
      results.push({...end,keyboard:true,wheel});
    }
    await page.setViewportSize({width:1280,height:720});
    phase='last layer selection';
    const last=page.locator('[data-certloom-designer-layer-select]').last();
    const id=await last.getAttribute('data-certloom-designer-layer-select');
    await last.focus();await page.keyboard.press('Enter');
    await page.waitForFunction(id=>document.querySelector('[data-certloom-designer-layer-select="'+id+'"]').getAttribute('aria-pressed')==='true',id);
    const list=page.locator('[data-certloom-designer-layers]');await list.focus();
    phase='selected layer Home';
    await page.keyboard.press('Home');
    await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop<1);
    phase='selected layer ArrowDown';
    await page.keyboard.press('ArrowDown');
    await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop>0);
    await page.keyboard.press('PageDown');
    await page.waitForFunction(()=>document.querySelector('[data-certloom-designer-layers]').scrollTop>0);
    if(originalLayout!==await page.locator('[data-certloom-designer-json]').inputValue()){throw new Error('Layers navigation must not move or edit the selected artwork.');}
    return {viewports:results.length,results,selectedLayerKeyboardScroll:true,layoutUnchanged:true};
  }catch(error){
    const unchanged=originalLayout===await page.locator('[data-certloom-designer-json]').inputValue();
    throw new Error(phase+': '+error.message+'; completed='+results.length+'; metrics='+JSON.stringify(await metrics())+'; layoutUnchanged='+unchanged);
  }finally{await page.setViewportSize(originalViewport);}
}
