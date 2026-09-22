<?php
/** Browser-only FLD-02 dynamic issuance-field fixture using production admin JS/CSS. */
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/certloom-tokens.css' ) . "\n" . file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.css' );
$js  = file_get_contents( dirname( __DIR__, 2 ) . '/admin/assets/admin.js' );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:24px;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{max-width:1080px;margin:auto}.fixture-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px;margin-bottom:18px}.fixture-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.fixture-grid label{display:grid;gap:6px}.fixture-card input,.fixture-card textarea,.fixture-card select{width:100%;max-width:none}.fixture-recipient{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.description{color:#50575e}@media(max-width:640px){body{padding:12px}.fixture-grid,.fixture-recipient{grid-template-columns:1fr}}
<?php echo $css; ?>
</style><script>
window.ajaxurl='/wp-admin/admin-ajax.php';
window.certloomAdminL10n={usageIssuance:{grade_default:true}};
window.__fieldCalls=[];
window.fetch=function(url,options){var d={};if(options&&options.body&&options.body.forEach){options.body.forEach(function(v,k){d[k]=String(v);});}window.__fieldCalls.push(d);var action=d.action||'';
 if(action==='certloom_resolve_scoped_fields'){
  var program=parseInt(d.program_id||'0',10)||0,batch=parseInt(d.batch_id||'0',10)||0,payload={configured:false,batch_configured:false,fields:[]};
  if(program===101){payload={configured:true,batch_configured:!!batch,fields:[
   {key:'first_name',label:'First name',type:'text',required:true,requirement:'required',origin:'program',value_source:'recipient',sensitivity:'personal',public_exposure:'private'},
   {key:'grade',label:'Grade',type:'text',required:!!batch,requirement:batch?'required':'optional',origin:batch?'batch':'program',value_source:'credential',sensitivity:'public_safe',public_exposure:'both'},
   {key:'gpa',label:'GPA',type:'number',required:true,requirement:'required',origin:'program',value_source:'credential_custom',sensitivity:'personal',public_exposure:'profile'},
   {key:'statement',label:'Statement',type:'textarea',required:false,requirement:'optional',origin:'program',value_source:'credential_custom',sensitivity:'personal',public_exposure:'private'}
  ]}; if(batch===501){payload.fields.push({key:'campus',label:'Campus',type:'text',required:true,requirement:'required',origin:'batch',value_source:'credential_custom',sensitivity:'public_safe',public_exposure:'verification'});}}
  else if(program===102){payload={configured:true,batch_configured:false,fields:[]};}
  return Promise.resolve({ok:true,json:function(){return Promise.resolve({success:true,data:payload});}});
 }
 if(action==='certloom_lookup_scoped_context'){var kind=d.kind||'',program=parseInt(d.program_id||'0',10)||0,choices=kind==='program'?[{id:101,label:'Configured Program'},{id:102,label:'Configured Empty Program'},{id:103,label:'Legacy Program'}]:(program===101?[{id:501,label:'Spring Batch',program_id:101}]:[]);return Promise.resolve({ok:true,json:function(){return Promise.resolve({success:true,data:{choices:choices}});}});}
 return Promise.resolve({ok:false,status:400,json:function(){return Promise.resolve({success:false,data:{message:'Unexpected request'}});}});
};
</script></head><body><main class="wrap">
<section class="fixture-card"><h1>Manual issuance field alignment</h1><form id="manual" data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="fields-nonce"><div class="fixture-grid">
<label>Program<select name="program_id"><option value="101" selected>Configured Program</option><option value="102">Configured Empty Program</option><option value="103">Legacy Program</option></select></label>
<label>Batch<select name="batch_id"><option value="0" selected>No Batch</option><option value="501" data-program-id="101">Spring Batch</option></select></label></div>
<p data-certloom-scoped-field-status role="status" aria-live="polite"></p>
<div class="fixture-recipient"><label><input type="radio" name="recipient_mode" value="new" checked> New recipient</label><label>First name<input name="new_recipient[first_name]"></label><label>Email<input name="new_recipient[email]" type="email"></label></div>
<label data-certloom-grade-field>Grade / result<input name="grade"></label>
<div data-certloom-scoped-field-inputs></div>
<fieldset data-certloom-legacy-custom-fields><legend>Legacy custom values</legend><input name="legacy_key" value="legacy"></fieldset>
<script type="application/json" data-certloom-scoped-existing-values>{"gpa":"3.50"}</script>
</form></section>
<section class="fixture-card"><h2>Bulk schema</h2><form id="bulk" data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="fields-nonce"><select name="program_id"><option value="101" selected>Configured Program</option></select><select name="batch_id"><option value="501" selected data-program-id="101">Spring Batch</option></select><p data-certloom-scoped-field-status role="status" aria-live="polite"></p><p data-certloom-bulk-field-schema-body></p></form></section>
</main><script><?php echo $js; ?></script></body></html>
