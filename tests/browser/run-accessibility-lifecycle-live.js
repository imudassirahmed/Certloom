/* Existing Lance Local session. No form submits, saved artwork or provider requests. */
async (page) => {
 const checks=[];const observations=[];const errors=[];
 // Extension-attached Chrome disallows browser-level CDP. A pass-through asset
 // route disables HTTP cache while exercising the actual current source bytes.
 const freshAssets=route=>route.continue();await page.route('**/wp-content/plugins/certloom/admin/assets/**',freshAssets);
 const check=(ok,label)=>{if(!ok)throw new Error(label);checks.push(label);};
 const onError=error=>errors.push(error.message);page.on('pageerror',onError);
 const scenarios=[{name:'desktop',width:1366,height:900},{name:'mobile',width:390,height:844},{name:'narrow',width:320,height:640},{name:'zoom-equivalent',width:683,height:450},{name:'forced-colors',width:390,height:844,forced:true},{name:'rtl-layout',width:390,height:844,rtl:true}];
 async function navigate(url,scenario){
  await page.setViewportSize({width:scenario.width,height:scenario.height});await page.emulateMedia({forcedColors:scenario.forced?'active':'none',reducedMotion:'reduce'});
  await page.goto(url,{waitUntil:'load'});await page.bringToFront();await page.waitForTimeout(700);
  if(scenario.rtl)await page.evaluate(()=>document.documentElement.dir='rtl');
 }
 try{
  for(const scenario of scenarios){
   await navigate('http://ecapla.local/wp-admin/plugins.php',scenario);
   const trigger=page.locator('tr[data-plugin="certloom/certloom.php"] .deactivate a');await trigger.focus();await page.keyboard.press('Enter');
   const dialog=page.getByRole('dialog',{name:'Before deactivating Certloom',exact:true});await dialog.waitFor({state:'visible'});
   check(await dialog.locator('#certloom-plugin-lifecycle-description').isVisible(),scenario.name+': named dialog has its description');
   check(await dialog.getByRole('radiogroup',{name:'If the plugin is deleted',exact:true}).count()===1,scenario.name+': named retention choices');
   const keep=dialog.locator('input[type=radio][value="0"]');check(await keep.isChecked(),scenario.name+': retained-data default');
   await keep.focus();await page.keyboard.press('ArrowDown');check(await dialog.locator('input[type=radio][value="1"]').isChecked(),scenario.name+': native radio arrow navigation');
   await page.keyboard.press('ArrowUp');check(await keep.isChecked(),scenario.name+': non-destructive choice restored');
   for(let i=0;i<7;i++){
    await page.keyboard.press('Tab');
    const focus=await dialog.evaluate(root=>{const e=document.activeElement,r=e.getBoundingClientRect(),box=root.getBoundingClientRect();return{inside:root.contains(e),visible:r.width>0&&r.height>0&&r.bottom>Math.max(0,box.top)&&r.top<Math.min(innerHeight,box.bottom),tag:e.tagName,type:e.type||'',id:e.id,hasFocus:document.hasFocus()};});
    if(!focus.inside||!focus.visible)await page.screenshot({path:'output/playwright/a11y-lifecycle-focus-failure-20260929.png'});
    check(focus.inside&&focus.visible,scenario.name+': modal Tab '+(i+1)+' stays visible and inside: '+JSON.stringify(focus));
   }
   const close=dialog.getByRole('button',{name:'Close',exact:true});await close.focus();await page.keyboard.press('Shift+Tab');
   check(await dialog.locator('button[type=submit]').evaluate(e=>document.activeElement===e),scenario.name+': reverse Tab wraps to the final control');
   await page.keyboard.press('Tab');check(await close.evaluate(e=>document.activeElement===e),scenario.name+': forward Tab wraps back to Close');
   await page.screenshot({path:'output/playwright/a11y-lifecycle-'+scenario.name+'-20260929.png'});
   check(await dialog.evaluate(e=>e.scrollWidth<=e.clientWidth+1),scenario.name+': dialog text has no horizontal overflow');
   await page.keyboard.press('Escape');check(await trigger.evaluate(e=>document.activeElement===e),scenario.name+': Escape returns focus');
  }
  for(const scenario of scenarios){
   await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=editor&template_id=45',scenario);
   check((await page.getByRole('button',{name:'Rename certificate design',exact:true}).textContent()).includes('Lance workflow verified draft'),scenario.name+': original unpublished design');
   await page.getByRole('button',{name:'Text',exact:true}).click();
   const properties=page.getByRole('complementary',{name:'Designer properties',exact:true});const appearance=properties.getByRole('button',{name:'Appearance',exact:true});if(await appearance.getAttribute('aria-expanded')!=='true')await appearance.click();
   const picker=properties.locator('.certloom-font-picker__button:visible');await picker.scrollIntoViewIfNeeded();await picker.focus();await page.keyboard.press('Enter');
   const panel=page.locator('.certloom-font-picker__panel:not([hidden])');await panel.waitFor({state:'visible'});
   check(await panel.evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth+1&&r.bottom<=innerHeight+1;}),scenario.name+': font picker within viewport');
   const search=panel.getByRole('searchbox',{name:'Search fonts',exact:true});await search.fill('Arial');await page.waitForTimeout(100);await page.keyboard.press('ArrowDown');
   check(await panel.locator('[role=option][tabindex="0"]').count()===1,scenario.name+': single virtual-list Tab stop');
   check(await page.evaluate(()=>document.activeElement.dataset.fontKey==='arial'),scenario.name+': keyboard filtered option');
   const outline=await page.evaluate(()=>{const e=document.activeElement,s=getComputedStyle(e);return{style:s.outlineStyle,width:s.outlineWidth,focusVisible:e.matches(':focus-visible'),hasRule:Array.from(document.styleSheets).some(sheet=>{try{return Array.from(sheet.cssRules).some(rule=>(rule.selectorText||'').includes('certloom-font-picker__row:focus-visible'));}catch{return false;}})};});
   check(outline.style!=='none'&&parseFloat(outline.width)>=2,scenario.name+': visible option focus indicator: '+JSON.stringify(outline));
   await search.fill('zzzz-no-such-font');await page.waitForTimeout(100);
   const empty=await panel.locator('[role=status]').allTextContents();observations.push({scenario:scenario.name,emptySearchStatus:empty.filter(Boolean)});
   check(empty.some(text=>text.includes('No fonts found.')),scenario.name+': empty search is explained and announced');
   check(await panel.locator('[data-cfp-viewport]').isHidden(),scenario.name+': empty results do not leave a blank scrolling list');
   await panel.getByRole('button',{name:'Upload custom font',exact:true}).click();await panel.getByRole('button',{name:'Upload font',exact:true}).click();
   check(await panel.locator('[data-cfp-family]').evaluate(e=>document.activeElement===e&&e.getAttribute('aria-invalid')==='true'),scenario.name+': required-field error focus');
   check(await panel.locator('[data-cfp-family]').getAttribute('aria-describedby')===await panel.locator('[data-cfp-upload-status]').getAttribute('id'),scenario.name+': error associated with required field');
   check(await panel.evaluate(e=>e.scrollWidth<=e.clientWidth+1),scenario.name+': upload controls have no horizontal overflow');
   await page.screenshot({path:'output/playwright/a11y-font-'+scenario.name+'-20260929.png'});
   await page.keyboard.press('Escape');check(await picker.evaluate(e=>document.activeElement===e),scenario.name+': font Escape restores focus');
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),scenario.name+': editor page width contained');
   const undo=page.getByRole('button',{name:'Undo',exact:true});for(let i=0;i<5&&await undo.isEnabled();i++)await undo.click();
  }
  check(errors.length===0,'No JavaScript page errors in exercised states');
  return{checks:checks.length,results:checks,observations,errors,limits:'683x450 is a reduced CSS viewport representing 200% reflow, not native browser zoom; RTL is layout stress, not a translated WordPress locale; no screen-reader or WCAG conformance claim.'};
 }finally{page.off('pageerror',onError);await page.unroute('**/wp-content/plugins/certloom/admin/assets/**',freshAssets);await page.emulateMedia({forcedColors:'none',reducedMotion:'no-preference'});await page.setViewportSize({width:1366,height:900});await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=editor&template_id=45',{waitUntil:'load'});}
}
