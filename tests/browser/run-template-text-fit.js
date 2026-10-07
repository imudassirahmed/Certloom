// Run with playwright-cli --session <authenticated Local session> run-code --filename ...
async (page) => {
  if (!page.url().startsWith('http://ecapla.local/')) { throw new Error('Use the disposable Certloom Local site.'); }
  await page.goto('http://ecapla.local/wp-admin/admin.php?page=certloom-templates&design_view=library', {waitUntil:'domcontentloaded'});
  await page.bringToFront();
  await page.waitForFunction(() => !!window.CertloomTextFit);
  return await page.evaluate(() => {
    const checks=[];
    const host=document.createElement('div');
    host.style.cssText='position:absolute;left:-10000px;top:0';
    document.body.appendChild(host);
    function check(ok,label){if(!ok){throw new Error(label);}checks.push(label);}
    function fixture(value,width,height,mode='shrink'){
      const box=document.createElement('div'),span=document.createElement('span');
      box.style.cssText='display:flex;flex-direction:column;justify-content:center;font:24px/1.2 Arial;overflow:hidden;width:'+width+'px;height:'+height+'px';
      box.dataset.certloomTextOverflow=mode;box.dataset.certloomTextMinPx='5';span.textContent=value;
      box.appendChild(span);host.appendChild(box);return {box,span};
    }
    function fit(f){window.CertloomTextFit.refresh(host);return f.span.scrollWidth<=f.box.clientWidth&&f.span.scrollHeight<=f.box.clientHeight;}
    try {
      const long=fixture('Alexandria Noor Fatima Montgomery-Washington de la Cruz Al-Hassan',300,30);
      check(fit(long),'long recipient fits its box');
      check(parseFloat(long.box.style.fontSize)<24,'shrink reduces only oversized text');
      const shrunk=long.box.style.fontSize;
      window.CertloomTextFit.refresh(host);
      check(long.box.style.fontSize===shrunk,'repeated refresh does not shrink again');
      long.span.textContent='Alex';fit(long);
      check(parseFloat(long.box.style.fontSize)===24,'shorter text restores original size');
      long.span.textContent='Alexandria Noor Fatima Montgomery-Washington de la Cruz Al-Hassan';long.box.style.width='600px';
      check(fit(long)&&parseFloat(long.box.style.fontSize)>parseFloat(shrunk),'wider box restores proportionate size');
      const wrap=fixture('ISO/IEC 27001 certification scope for development and managed support services',200,180,'wrap');
      check(fit(wrap)&&parseFloat(wrap.box.style.fontSize)===24&&wrap.span.scrollHeight>30,'wrap uses multiple lines at the requested size');
      const clip=fixture('This text intentionally exceeds its narrow clip box',25,30,'clip');
      fit(clip);check(parseFloat(clip.box.style.fontSize)===24&&clip.span.scrollWidth>clip.box.clientWidth,'clip keeps requested font size');
      const minimum=fixture('Unfit'.repeat(300),10,20);fit(minimum);
      check(parseFloat(minimum.box.style.fontSize)===5,'unfit text respects minimum font size');
      const rtl=fixture('محمد علی رحمان محمد علی رحمان محمد علی رحمان',180,30);rtl.box.dir='rtl';
      check(fit(rtl)&&getComputedStyle(rtl.box).direction==='rtl','RTL fits without changing direction');
      const multiline=fixture('First explicit line\nSecond explicit line',220,40);
      check(fit(multiline)&&multiline.span.scrollHeight>parseFloat(getComputedStyle(multiline.box).lineHeight),'shrink preserves explicit line breaks');
      const rich=fixture('',200,30),run=document.createElement('span');run.style.fontSize='36px';run.textContent='A longer rich-text heading';rich.span.appendChild(run);
      check(fit(rich)&&Math.abs(parseFloat(run.style.fontSize)/parseFloat(rich.box.style.fontSize)-1.5)<0.01,'rich text preserves relative run sizes');
      return {checks:checks.length,passed:checks};
    } finally { host.remove(); }
  });
}
