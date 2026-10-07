/* Existing Lance session, backed-up Local only. Run CLI fixture begin/cleanup around this test.
 * No design save/publish or outgoing mail. The two library entries are owned QA fixtures.
 */
async (page) => {
  const checks=[];const check=(ok,label)=>{if(!ok)throw new Error(label);checks.push(label);};
  const fresh=route=>route.continue();await page.route('**/wp-content/plugins/certloom/admin/assets/**',fresh);
  const delayUpload=async route=>{const body=route.request().postData()||'';if(body.includes('certloom_custom_font_upload')&&body.includes('Certloom QA font 20260929 UI')){const response=await route.fetch();await page.waitForTimeout(600);return route.fulfill({response});}return route.continue();};
  try{
  await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=editor&template_id=45',{waitUntil:'load'});
  await page.bringToFront();await page.waitForTimeout(700);
  check((await page.getByRole('button',{name:'Rename certificate design',exact:true}).textContent()).includes('Lance workflow verified draft'),'Exact existing unpublished QA design');
  await page.screenshot({path:'output/playwright/custom-font-before-20260929.png'});
  const endpoint=async (kind) => page.evaluate(async kind=>{
    const config=window.certloomFontPickerConfig;
    const fd=new FormData();fd.append('action','certloom_custom_font_upload');fd.append('nonce',kind==='nonce'?'forged':config.nonce);
    fd.append(kind==='array'?'family[]':'family','Certloom QA font 20260929');fd.append('weight',kind==='weight'?'450':'400');fd.append('style',kind==='style'?'sideways':'normal');
    const hash=kind==='replace'?'a4c5bc453ca281d90ea079e596da7ae0dfeb5777497c29ec254e76d97ff6f890':'57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e';
    let file;
    if(kind==='malformed'){const bytes=new Uint8Array(64);bytes.set([0,1,0,0]);bytes[5]=1;file=new Blob([bytes],{type:'font/ttf'});}
    else{const response=await fetch('/wp-content/plugins/certloom/resources/renderer/fonts/'+hash+'.ttf');if(!response.ok)throw new Error('Font fixture unavailable');file=await response.blob();}
    fd.append('font',file,'qa.ttf');const response=await fetch(config.ajaxUrl,{method:'POST',credentials:kind==='anonymous'?'omit':'same-origin',body:fd});const text=await response.text();let json=null;try{json=JSON.parse(text);}catch{}
    return{status:response.status,json,text:text.slice(0,600)};
  },kind);
  for(const kind of ['nonce','array','weight','style','malformed','anonymous']){
    const result=await endpoint(kind);check(result.status>=400&&!result.json?.success,kind+': real HTTP refusal');
    if(kind==='malformed')check(result.json?.data?.message.includes('font tables'),'Malformed SFNT rejected before storage');
  }
  const uploaded=await endpoint('valid');check(uploaded.status===200&&uploaded.json.success,'Actual multipart TTF upload persisted');
  check(uploaded.json.data.definition.faces[0].sha256==='57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e','Exact uploaded bytes in returned catalog');
  const duplicate=await endpoint('valid');check(duplicate.json?.success,'Identical reupload is idempotent');
  const replaced=await endpoint('replace');check(replaced.status===400&&!replaced.json.success&&replaced.json.data.message.includes('new family name'),'Replacing an existing face is refused');
  await page.getByRole('button',{name:'Text',exact:true}).click();
  const properties=page.getByRole('complementary',{name:'Designer properties',exact:true});
  const appearance=properties.getByRole('button',{name:'Appearance',exact:true});if(await appearance.getAttribute('aria-expanded')!=='true')await appearance.click();
  const picker=properties.locator('.certloom-font-picker__button:visible');await picker.click();
  const panel=page.locator('.certloom-font-picker__panel:not([hidden])');
  check(await panel.locator('.certloom-font-picker__help').first().isVisible(),'Download font limitation shown before choosing');
  await panel.getByRole('searchbox',{name:'Search fonts',exact:true}).fill('Arial');await page.waitForTimeout(100);
  await page.keyboard.press('ArrowDown');check(await page.evaluate(()=>document.activeElement.dataset.fontKey==='arial'),'Keyboard enters filtered option');
  check(await panel.locator('[role=option][tabindex="0"]').count()===1,'Virtual list has one option in Tab order');
  await page.keyboard.press('Escape');check(await picker.evaluate(e=>document.activeElement===e),'Escape returns focus to font field');
  await picker.click();await panel.getByRole('button',{name:'Upload custom font',exact:true}).click();
  await panel.getByRole('button',{name:'Upload font',exact:true}).click();
  check(await panel.locator('[data-cfp-family]').evaluate(e=>document.activeElement===e&&e.getAttribute('aria-invalid')==='true'),'Missing required family receives focus');
  check(await panel.locator('[data-cfp-upload-status]').getAttribute('role')==='status','Upload feedback is announced');
  await panel.locator('[data-cfp-family]').fill('Certloom QA font 20260929 UI');
  const fontBytes=await page.evaluate(async()=>Array.from(new Uint8Array(await(await fetch('/wp-content/plugins/certloom/resources/renderer/fonts/57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e.ttf')).arrayBuffer())));
  await panel.locator('[data-cfp-file]').setInputFiles({name:'qa.ttf',mimeType:'font/ttf',buffer:Buffer.from(fontBytes)},{timeout:10000});
  await page.route('**/wp-admin/admin-ajax.php',delayUpload);
  await panel.getByRole('button',{name:'Upload font',exact:true}).click();
  await page.waitForTimeout(100);check(await panel.isVisible()&&await panel.getAttribute('aria-busy')==='true','Delayed upload stays open while disabled controls lose focus');
  await page.waitForFunction(()=>!document.querySelector('.certloom-font-picker__panel:not([hidden])'),null,{polling:100});
  check((await picker.textContent()).includes('Certloom QA font 20260929 UI'),'Successful upload selects the actually loaded face');
  check(await picker.evaluate(e=>document.activeElement===e),'Success restores focus to font field');
  await page.getByRole('button',{name:'Undo',exact:true}).click();check((await picker.textContent()).includes('Arial'),'Undo restores previous design font');
  await page.screenshot({path:'output/playwright/custom-font-after-20260929.png'});
  return{checks:checks.length,results:checks};
  }finally{await page.unroute('**/wp-admin/admin-ajax.php',delayUpload);await page.unroute('**/wp-content/plugins/certloom/admin/assets/**',fresh);const undo=page.getByRole('button',{name:'Undo',exact:true});for(let i=0;i<5&&await undo.isEnabled();i++)await undo.click();}
}
