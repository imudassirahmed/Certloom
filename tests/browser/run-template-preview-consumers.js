// Run with playwright-cli in an authenticated browser on the disposable Local site.
// Uses unsaved issuance/rule choices and restores the onboarding draft choices.
async (page, options = {}) => {
  if (!page.url().startsWith('http://ecapla.local/')) { throw new Error('Use the disposable Certloom Local site.'); }
  await page.bringToFront();
  const results=[];
  async function wait(name,predicate){
    try{await page.waitForFunction(predicate);}catch(error){
      const state=await page.evaluate(()=>({ready:document.readyState,visibility:document.visibilityState,focus:document.hasFocus(),mode:document.querySelector('input[name="presentation_mode"]:checked')?.value,draft:document.querySelector('[data-certloom-draft-status]')?.dataset.state}));
      throw new Error(name+': '+error.message+'; state='+JSON.stringify(state));
    }
  }
  async function ready(){
    await page.bringToFront();
    await wait('visible browser document',()=>document.visibilityState==='visible');
    await wait('shared preview scripts',()=>document.readyState==='complete'&&!!window.CertloomTextFit);
    await page.evaluate(async()=>{
      await document.fonts.ready;
      await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    });
    // The attached Chrome extension can drop native input immediately after
    // navigation, even with a complete/visible DOM. Event traces reproduced
    // that handoff. Give the browser a bounded settling interval; keep real
    // pointer/keyboard actions and fail if the resulting control does not open.
    await page.waitForTimeout(700);
    await wait('settled visible browser document',()=>document.visibilityState==='visible');
  }
  async function navigate(url){
    const previous=await page.evaluate(()=>performance.timeOrigin);
    const target=await page.evaluate(url=>new URL(url,location.href).href,url);
    await page.goto(target,{waitUntil:'load'});
    // A complete old document must never satisfy readiness for a new navigation.
    await page.waitForFunction(({target,previous})=>location.href===target&&performance.timeOrigin!==previous&&document.readyState==='complete',{target,previous});
    await ready();
  }
  async function capture(name,selector){
    await page.locator(selector+' [data-certloom-text-overflow]').first().waitFor({state:'visible'});
    const result=await page.locator(selector).first().evaluate((root,name)=>({name,
      boxes:root.querySelectorAll('[data-certloom-text-overflow]').length,
      fitted:[...root.querySelectorAll('[data-certloom-text-overflow]')].filter(e=>e.hasAttribute('data-certloom-text-base-px')).length,
      clipped:[...root.querySelectorAll('[data-certloom-text-overflow]')].filter(e=>e.clientWidth>0&&e.clientHeight>0&&(e.firstElementChild.scrollWidth>e.clientWidth+1||e.firstElementChild.scrollHeight>e.clientHeight+1)).map(e=>e.textContent),
      qrSvg:root.querySelectorAll('svg.certloom-template-preview-op-qr').length,
      svg:root.querySelectorAll('svg').length
    }),name);
    if (result.boxes!==result.fitted || result.clipped.length || result.qrSvg!==1) { throw new Error(JSON.stringify(result)); }
    results.push(result);
  }
  function draftSaved(response){return response.url().includes('admin-ajax.php')&&(response.request().postData()||'').includes('certloom_setup_draft_autosave');}
  await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-setup&step=appearance');
  const originalMode=await page.locator('input[name="presentation_mode"]:checked').inputValue();
  const originalTemplate=await page.locator('#certloom-wizard-template').inputValue();
  const design=await page.locator('#certloom-wizard-template').evaluate(e=>[...e.options].find(o=>Number(o.value)>0)?.value);
  if(!design){throw new Error('A published design is required to exercise previews.');}
  try {
    await page.bringToFront();
    await page.locator('label').filter({has:page.locator('input[name="presentation_mode"][value="design"]')}).click();
    await wait('setup Design pointer selection',()=>document.querySelector('input[name="presentation_mode"][value="design"]').checked);
    await page.bringToFront();
    const [saved]=await Promise.all([page.waitForResponse(draftSaved),page.locator('#certloom-wizard-template').selectOption(design)]);
    if(!(await saved.json()).success){throw new Error('The setup design draft was not saved.');}
    await wait('setup draft save',()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='saved');
    await wait('setup design preview',()=>document.querySelector('[data-certloom-wizard-template-preview]').getAttribute('aria-busy')==='false');
    await capture('setup-dynamic','[data-certloom-wizard-template-preview]');
    await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-setup&step=appearance');
    if(await page.locator('#certloom-wizard-template').inputValue()!==design){throw new Error('Reload must preserve the selected design.');}
    await capture('setup-reloaded','[data-certloom-wizard-template-preview]');
    if(results[0].svg!==results[1].svg){throw new Error('Reload must preserve the preview SVG primitives.');}
  } finally {
    await Promise.all([page.waitForResponse(draftSaved),page.evaluate(({originalTemplate,originalMode})=>{
      const select=document.querySelector('#certloom-wizard-template');
      select.value=originalTemplate;
      const radio=document.querySelector('input[name="presentation_mode"][value="'+originalMode+'"]');
      radio.checked=true;
      radio.dispatchEvent(new Event('change',{bubbles:true}));
      select.dispatchEvent(new Event('change',{bubbles:true}));
    },{originalTemplate,originalMode})]);
    await wait('setup draft restoration',()=>document.querySelector('[data-certloom-draft-status]').dataset.state==='saved');
  }
  await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-design-rules');
  await page.locator('[data-certloom-rule-choice="certificate"]').first().waitFor({state:'visible'});
  await page.locator('[data-certloom-rule-choice="certificate"]').first().selectOption('design:'+design);
  await wait('design rule preview',()=>!document.querySelector('[data-certloom-rule-preview="certificate"]').classList.contains('is-loading'));
  await capture('rules-dynamic','[data-certloom-rule-preview="certificate"]');
  await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-domain-programs');
  const program=page.locator('a[href*="program_id="]').first();
  if(!(await program.count())){throw new Error('An existing Program is required for its design dialog.');}
  await navigate(await program.getAttribute('href'));
  const opener=page.locator('[data-certloom-context-design-open]').first();
  if(options.contextInteraction==='keyboard') { await opener.focus(); await page.keyboard.press('Enter'); }
  else { await opener.click(); }
  await wait('Program design dialog '+(options.contextInteraction||'pointer')+' opening',()=>!!document.querySelector('dialog[open] [data-context-choice="certificate"]'));
  await page.locator('dialog[open] [data-context-choice="certificate"]').selectOption('design:'+design);
  await wait('Program design preview',()=>!document.querySelector('dialog[open] [data-context-preview="certificate"]').classList.contains('is-loading'));
  await capture('context-dynamic','dialog[open] [data-context-preview="certificate"]');
  await page.keyboard.press('Escape');
  await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-issuance');
  await page.locator('[data-issue-body]').waitFor({state:'visible'});
  await page.locator('[data-recipient-mode="new"]').click();
  await page.locator('[data-field="new_recipient.display_name"]').fill('Alexandria Noor Fatima Montgomery-Washington de la Cruz Al-Hassan');
  await page.locator('[data-issue-step-button="1"]').click();
  await page.locator('[data-credential-basis="independent"]').click();
  await page.locator('[data-issue-step-button="2"]').click();
  await page.locator('[data-field="credential_title"]').fill('Professional Development Recognition');
  const number=page.locator('[data-field="display_number"]');
  if(await number.isVisible()){await number.fill('PREVIEW-ONLY-NOT-ISSUED');}
  await page.locator('[data-issue-step-button="3"]').click();
  await page.locator('[data-presentation="design"]').click();
  await page.locator('[data-design-source="assigned"]').click();
  await page.locator('[data-picker="template"] [data-picker-search]').fill('Atelier');
  await page.locator('[data-picker="template"] [role="option"]').first().click();
  await capture('issuance-dynamic','[data-preview-stage]');
  await navigate('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=library');
  return {scenarios:results.length,contextInteraction:options.contextInteraction||'pointer',results};
}
