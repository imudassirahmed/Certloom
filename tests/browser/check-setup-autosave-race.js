// Real Local draft endpoint. Hold an older successful response while the next
// save fails; an older success must never hide the unsaved latest choices.
// Only this administrator's unfinished appearance draft is changed/restored.
async (page) => {
 if (!page.url().startsWith('http://ecapla.local/')) throw new Error('Use the disposable Local site.');
 await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-setup&step=appearance',{waitUntil:'load'});
 await page.bringToFront();
 await page.waitForFunction(()=>document.visibilityState==='visible');
 const original=await page.evaluate(()=>({mode:document.querySelector('input[name="presentation_mode"]:checked').value,template:document.querySelector('#certloom-wizard-template').value}));
 const design=await page.locator('#certloom-wizard-template').evaluate(e=>[...e.options].find(o=>Number(o.value)>0)?.value);
 if(!design) throw new Error('A published design is required.');
 await page.locator('input[name="presentation_mode"][value="details_only"]').check();
 await page.waitForFunction(()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='saved');
 let releaseFirst, capturedFirst, failedLatest;
 const firstRelease=new Promise(resolve=>releaseFirst=resolve);
 const firstCaptured=new Promise(resolve=>capturedFirst=resolve);
 const latestFailure=new Promise(resolve=>failedLatest=resolve);
 let requests=0, failureSeen=false, firstSucceeded=false;
 const routePattern='**/wp-admin/admin-ajax.php';
 const routeHandler=async(route)=>{
  if(!(route.request().postData()||'').includes('certloom_setup_draft_autosave')) { await route.continue(); return; }
  requests++;
  if(requests===1){
   const response=await route.fetch();
   firstSucceeded=(await response.json()).success===true;
   capturedFirst(); await firstRelease; await route.fulfill({response});
  }else{
   failureSeen=true;
   await route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({success:false,data:{message:'Controlled Local autosave failure'}})});
   failedLatest();
  }
 };
 let result;
 await page.route(routePattern,routeHandler);
 try{
  await page.locator('input[name="presentation_mode"][value="design"]').check();
  await firstCaptured;
  await page.locator('#certloom-wizard-template').selectOption(design);
  // Allows both the original parallel requests and the fixed serialized queue.
  await Promise.race([latestFailure,page.waitForTimeout(800)]);
  const olderWasHeldDuringNewFailure=failureSeen;
  releaseFirst();
  await latestFailure;
  await page.waitForFunction(()=>document.querySelector('[data-certloom-draft-status]').dataset.state!=='saving');
  await page.waitForTimeout(250);
  const state=await page.evaluate(()=>({status:document.querySelector('[data-certloom-draft-status]').dataset.state,dirty:document.querySelector('[data-certloom-setup-v2]').getAttribute('data-certloom-dirty')}));
  result={firstSucceeded,requests,olderWasHeldDuringNewFailure,state,safeFailure:firstSucceeded&&state.status==='error'&&state.dirty==='1'};
  if(result.safeFailure){
   const url=page.url();
   await page.getByRole('link',{name:'Exit setup',exact:true}).click();
   await page.waitForFunction(()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='error');
   result.failedSaveBlocksNavigation=page.url()===url;
   await page.getByRole('button',{name:'Continue',exact:true}).click();
   await page.waitForFunction(()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='error');
   result.failedSaveBlocksSubmission=page.url()===url;
   result.namedDesignPicker=await page.getByRole('combobox',{name:'Choose a design',exact:true}).count()===1;
  }
  await page.screenshot({path:'output/playwright/setup-autosave-race.png'});
 }finally{
  releaseFirst();
  await page.unroute(routePattern,routeHandler);
  await page.evaluate(({mode,template})=>{
   document.querySelector('#certloom-wizard-template').value=template;
   const radio=document.querySelector('input[name="presentation_mode"][value="'+mode+'"]');
   radio.checked=true; radio.dispatchEvent(new Event('change',{bubbles:true}));
   document.querySelector('#certloom-wizard-template').dispatchEvent(new Event('change',{bubbles:true}));
  },original);
  await page.waitForFunction(()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='saved');
  await page.reload({waitUntil:'load'}); await page.bringToFront();
  const restored=await page.evaluate(()=>({mode:document.querySelector('input[name="presentation_mode"]:checked').value,template:document.querySelector('#certloom-wizard-template').value}));
  if(JSON.stringify(original)!==JSON.stringify(restored)) throw new Error('The original draft must survive a real reload after recovery.');
 }
 return {...result,originalDraftRestored:true};
}
