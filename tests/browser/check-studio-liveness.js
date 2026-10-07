// Diagnose the current Local Studio tab without navigation or editing a template.
async(page)=>{
  if(!page.url().startsWith('http://ecapla.local/')){throw new Error('Use the disposable Local site.');}
  await page.bringToFront();
  await page.locator('[data-certloom-designer-layers]').waitFor({state:'visible'});
  const state=await page.evaluate(()=>({url:location.href,ready:document.readyState,visibility:document.visibilityState,focus:document.hasFocus(),viewport:[innerWidth,innerHeight],transition:document.documentElement.style.viewTransitionName,layers:document.querySelector('[data-certloom-designer-layers]')?.getBoundingClientRect().toJSON(),fonts:document.fonts.status}));
  await page.screenshot({path:'output/playwright/01-layers-repair-recheck.png',animations:'disabled',timeout:30000});
  return state;
}
