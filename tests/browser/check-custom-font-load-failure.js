/* Existing Local QA design, owned live-font fixtures; no artwork save or mail. */
async page=>{
 const checks=[];const check=(ok,label)=>{if(!ok)throw new Error(label);checks.push(label);};
 const asset=/\/(?:certloom-fonts|design-fonts)\/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e\.ttf(?:\?|$)/;
 const refuse=route=>route.fulfill({status:404,contentType:'text/plain',body:'Controlled missing QA font'});
 const fresh=route=>route.continue();await page.route('**/wp-content/plugins/certloom/admin/assets/**',fresh);
 try{
  await page.route(asset,refuse);
  await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=editor&template_id=45',{waitUntil:'load'});await page.bringToFront();await page.waitForTimeout(700);
  check((await page.getByRole('button',{name:'Rename certificate design',exact:true}).textContent()).includes('Lance workflow verified draft'),'Exact unpublished QA design');
  await page.getByRole('button',{name:'Text',exact:true}).click();
  const properties=page.getByRole('complementary',{name:'Designer properties',exact:true});const appearance=properties.getByRole('button',{name:'Appearance',exact:true});if(await appearance.getAttribute('aria-expanded')!=='true')await appearance.click();
  const picker=properties.locator('.certloom-font-picker__button:visible');const original=await picker.textContent();check(original.includes('Arial'),'Original design font');
  await picker.click();const panel=page.locator('.certloom-font-picker__panel:not([hidden])');const search=panel.getByRole('searchbox',{name:'Search fonts',exact:true});
  await search.fill('Certloom QA font 20260929 UI');const row=panel.getByRole('option',{name:'Certloom QA font 20260929 UI Aa Bb 123',exact:true});await row.click();
  const status=panel.locator('.certloom-font-picker__results');await status.filter({hasText:'Your previous selection is unchanged'}).waitFor({state:'visible'});
  check(await picker.textContent()===original,'A failed custom face never replaces the original selection');
  check(await status.evaluate(e=>document.activeElement===e),'Failed face status receives focus');
  check(await status.getAttribute('role')==='status','Failure is announced');
  await page.screenshot({path:'output/playwright/custom-font-load-failure-20260929.png'});
  await page.unroute(asset,refuse);await search.fill('Arial');await search.fill('Certloom QA font 20260929 UI');await row.click();
  await page.waitForFunction(()=>!document.querySelector('.certloom-font-picker__panel:not([hidden])'),null,{polling:100});
  check((await picker.textContent()).includes('Certloom QA font 20260929 UI'),'Retry loads the real stored font successfully');
  check(await picker.evaluate(e=>document.activeElement===e),'Retry returns focus to the field');
  await page.getByRole('button',{name:'Undo',exact:true}).click();check(await picker.textContent()===original,'Undo restores the original font');
  return{checks:checks.length,results:checks};
 }finally{await page.unroute(asset,refuse);await page.unroute('**/wp-content/plugins/certloom/admin/assets/**',fresh);const undo=page.getByRole('button',{name:'Undo',exact:true});for(let i=0;i<5&&await undo.isEnabled();i++)await undo.click();}
}
