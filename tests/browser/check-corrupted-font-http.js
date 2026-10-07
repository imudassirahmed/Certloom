async (page) => {
  if(!page.url().startsWith('http://ecapla.local/wp-admin/'))throw new Error('Exact Local admin required');
  const result=await page.evaluate(async()=>{
    const config=window.certloomFontPickerConfig,hash='57f73e11f51999432bf7ab22ce55b6f945d5eca1bf824404cfa9ec2e3718c84e';
    const file=await(await fetch('/wp-content/plugins/certloom/resources/renderer/fonts/'+hash+'.ttf')).blob();
    const fd=new FormData();fd.append('action','certloom_custom_font_upload');fd.append('nonce',config.nonce);fd.append('family','Certloom QA font 20260929');fd.append('weight','400');fd.append('style','normal');fd.append('font',file,'qa.ttf');
    const response=await fetch(config.ajaxUrl,{method:'POST',body:fd});return{status:response.status,json:await response.json()};
  });
  if(result.status!==400||result.json.success||!result.json.data.message.includes('content identity'))throw new Error(JSON.stringify(result));
  return{checks:1,result:'Real HTTP reupload refuses corrupted content-addressed storage'};
}
