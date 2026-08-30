<?php
/** Browser-only FLD-02 bounded Program/Batch lookup fixture using production admin JS/CSS. */
$css = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.js' );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:24px;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{max-width:980px;margin:0 auto}.fixture-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px}.fixture-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.fixture-grid label{display:grid;gap:6px}.fixture-grid select,.fixture-grid input{width:100%;max-width:none}.screen-reader-text{border:0;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute!important;width:1px;word-wrap:normal!important}@media(max-width:640px){body{padding:12px}.fixture-grid{grid-template-columns:1fr}}
<?php echo $css; ?>
</style><script>
window.ajaxurl='/wp-admin/admin-ajax.php';
window.__lookupCalls=[];
window.fetch=function(url, options){
  var data={}; if(options&&options.body&&options.body.forEach){options.body.forEach(function(v,k){data[k]=String(v);});}
  window.__lookupCalls.push(data);
  var query=(data.query||'').toLowerCase(), kind=data.kind||'', program=parseInt(data.program_id||'0',10)||0;
  var delay=query==='slow'?180:(query==='fast'?10:20), choices=[];
  if(kind==='program'){
    if(query==='slow'){choices=[{id:301,label:'Slow Legacy Program'}];}
    else if(query==='fast'){choices=[{id:302,label:'Fast Current Program'}];}
    else if(query.indexOf('scale')!==-1){choices=[{id:101,label:'Scale Program 101'},{id:102,label:'Scale Program 102'}];}
    else{choices=[{id:101,label:'Scale Program 101'},{id:102,label:'Scale Program 102'},{id:103,label:'Accessibility Program'}];}
  }else if(kind==='template'){
    if(query.indexOf('modern')!==-1){choices=[{id:801,label:'Modern Credential · r7'},{id:802,label:'Modern Academic · r3'}];}
    else{choices=[{id:801,label:'Modern Credential · r7'},{id:803,label:'Executive Minimal · r5'}];}
  }else if(kind==='batch'){
    if(program===101){choices=[{id:501,label:'Batch 101-A',program_id:101},{id:502,label:'Batch 101-B',program_id:101}];}
    else if(program===102){choices=[{id:601,label:'Batch 102-A',program_id:102}];}
    else if(program===302){choices=[{id:701,label:'Fast Batch',program_id:302}];}
  }else if(kind==='issuer'){
    choices=[{id:901,label:'Ecapla Academy · ecapla-academy'},{id:902,label:'Partner Institute · partner'}];
  }else if(kind==='signer'){
    choices=[{id:911,label:'Dr Amina Rahman · Registrar'},{id:912,label:'Prof Bilal Khan · Instructor'}];
  }else if(kind==='signer_scope'){
    choices=[{value:'global:0',label:'Global'},{value:'issuer:901',label:'Issuer · Ecapla Academy'},{value:'program:101',label:'Program · Scale Program 101'},{value:'batch:501',label:'Batch · Batch 101-A'}];
  }else if(kind==='tutor_course'){
    choices=[{id:921,label:'Tutor Leadership Essentials'},{id:922,label:'Tutor Data Skills'}];
  }else if(kind==='learndash_course'){
    choices=[{id:931,label:'LearnDash Compliance Basics'},{id:932,label:'LearnDash Advanced Safety'}];
  }
  return new Promise(function(resolve){setTimeout(function(){resolve({ok:true,json:function(){return Promise.resolve({success:true,data:{choices:choices}});}});},delay);});
};
</script></head><body><main class="wrap"><section class="fixture-card"><h1>Scoped credential context</h1><p>Search the complete active Program registry. Batch choices remain constrained to the selected Program.</p>
<form id="scoped-context-form"><div class="fixture-grid">
<label><span>Program</span><select name="program_id" data-certloom-context-lookup="program" data-certloom-context-nonce="nonce-program" data-certloom-context-search-placeholder="Search Programs…"><option value="">Choose Program…</option><option value="7">Previously selected Program</option></select></label>
<label><span>Batch <small>(optional)</small></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="nonce-batch" data-certloom-context-search-placeholder="Search Batches…"><option value="0">No Batch</option></select></label>
<label><span>Certificate design</span><select name="template_id" data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="nonce-template" data-certloom-context-search-placeholder="Search published Templates…"><option value="0">Use Program design automatically</option><option value="77" selected>Previously selected Template</option></select></label>
</div></form>
<form id="historical-filter"><div class="fixture-grid"><label><span>Historical Program filter</span><select name="filter_program_id" data-certloom-context-lookup="program" data-certloom-context-program-status="all" data-certloom-context-nonce="nonce-filter" data-certloom-context-search-placeholder="Search all Programs…"><option value="">All Programs</option></select></label></div></form>
<form id="scale-relations"><div class="fixture-grid">
<label><span>Issuer</span><select name="issuer_id" data-certloom-context-lookup="issuer" data-certloom-context-nonce="nonce-issuer" data-certloom-context-search-placeholder="Search issuers…"><option value="">No default issuer</option></select></label>
<label><span>Signer</span><select name="signer_id" data-certloom-context-lookup="signer" data-certloom-context-nonce="nonce-signer" data-certloom-context-search-placeholder="Search active Signers…"><option value="">None</option></select></label>
<label><span>Signer scope</span><select name="scope" data-certloom-context-lookup="signer_scope" data-certloom-context-empty-value="global:0" data-certloom-context-nonce="nonce-scope" data-certloom-context-search-placeholder="Search issuer, Program, or Batch…"><option value="global:0">Global</option></select></label>
<label><span>Tutor course</span><select name="tutor_course_id" data-certloom-context-lookup="tutor_course" data-certloom-context-nonce="nonce-tutor" data-certloom-context-search-placeholder="Search Tutor LMS courses…"><option value="">Choose a course</option></select></label>
<label><span>LearnDash course</span><select name="learndash_course_id" data-certloom-context-lookup="learndash_course" data-certloom-context-nonce="nonce-ld" data-certloom-context-search-placeholder="Search LearnDash courses…"><option value="">Choose a course</option></select></label>
</div></form>
<div id="program-profile-fixture"><select id="certloom-program-purpose"><option value="training" selected>Training</option></select><select id="certloom-program-subcategory"><option data-purpose="training" value="course" selected>Course</option></select>
<div data-certloom-locale-variant-list><div class="certloom-locale-variant-row"><label><span>Language</span><select name="profile_template_variant_locale[]"><option value="en_US">English</option></select></label><label><span>Published Template</span><select name="profile_template_variant_template_id[]" data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="nonce-locale-template" data-certloom-context-search-placeholder="Search published Templates…"><option value="0">Choose Template</option></select></label><button type="button" data-certloom-remove-locale-variant>Remove</button></div></div>
<button type="button" data-certloom-add-locale-variant>Add language variant</button><select data-certloom-locale-fallback data-selected="program_default"><option value="program_default">Program default design</option></select>
<template id="certloom-program-locale-variant-template"><div class="certloom-locale-variant-row"><label><span>Language</span><select name="profile_template_variant_locale[]"><option value="fr_FR">French</option></select></label><label><span>Published Template</span><select name="profile_template_variant_template_id[]" data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="nonce-locale-template-new" data-certloom-context-search-placeholder="Search published Templates…"><option value="0">Choose Template</option></select></label><button type="button" data-certloom-remove-locale-variant>Remove</button></div></template></div>
</section></main><script><?php echo $js; ?></script></body></html>
