/* Run on the saved, unpublished Lance QA draft in the authenticated Local tab.
 * Changes a font and exercises Undo; returns font and lock to their initial values.
 * Does not save or publish a new revision.
 */
async (page) => {
 if (!page.url().startsWith('http://ecapla.local/wp-admin/admin.php?page=certloom-templates')) throw new Error('Use the Local certificate editor.');
 const title = await page.getByRole('button', {name:'Rename certificate design', exact:true}).textContent();
 if (!title.includes('Lance workflow verified draft')) throw new Error('Use the designated unpublished QA draft.');
 await page.bringToFront();
 await page.setViewportSize({width:1536,height:864});
 const checks=[];
 const check=(pass,message)=>{if(!pass)throw new Error(message);checks.push(message);};
 await page.getByRole('button',{name:'Text',exact:true}).click();
 const properties=page.getByRole('complementary',{name:'Designer properties',exact:true});
 const appearance=properties.getByRole('button',{name:'Appearance',exact:true});
 if(await appearance.getAttribute('aria-expanded')!=='true')await appearance.click();
 const font=properties.locator('.certloom-font-picker__button:visible');
 check(/Arial/.test(await font.textContent()),'reopened saved Arial is reflected in the picker');
 await font.click();
 await page.getByRole('searchbox',{name:'Search fonts',exact:true}).fill('Georgia');
 await page.locator('.certloom-font-picker__panel:not([hidden]) [data-font-key=georgia]').click();
 check(/Georgia/.test(await font.textContent()),'new selection updates the picker');
 await page.getByRole('button',{name:'Undo',exact:true}).click();
 check(/Arial/.test(await font.textContent()),'Undo restores the displayed saved font');
 await page.getByRole('button',{name:'Lock Text',exact:true}).click();
 check(await font.isDisabled(),'locked layer disables its enhanced font control');
 await page.getByRole('button',{name:'Unlock Text',exact:true}).click();
 check(await font.isEnabled(),'unlocked layer enables its enhanced font control');
 for(const width of [1536,1024,390]){
  await page.setViewportSize({width,height:864});
  await font.scrollIntoViewIfNeeded();
  const size=properties.locator('[data-certloom-designer-style-field=font_size_pt]:visible');
  const a=await font.boundingBox(),b=await size.boundingBox();
  check(Math.abs(a.y-b.y)<2 && b.height<=48,width+': Font and Size align without a stretched input');
  const color=properties.locator('input[data-certloom-designer-style-field=color]:visible');
  check(await color.evaluate(e=>e.clientWidth>=75),width+': hexadecimal color has readable space');
  await font.click();
  check(await page.locator('.certloom-font-picker__panel:not([hidden])').evaluate(e=>{const r=e.getBoundingClientRect();return r.left>=0&&r.top>=0&&r.right<=innerWidth&&r.bottom<=innerHeight}),width+': picker remains inside viewport');
  await page.keyboard.press('Escape');
  await page.screenshot({path:'output/playwright/lance-studio-verified-'+width+'.png',timeout:20000});
 }
 return {checks:checks.length,results:checks};
}
