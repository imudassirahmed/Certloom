<?php
$root = dirname( __DIR__, 2 );
$css = file_get_contents( $root . '/admin/assets/admin.css' );
$js  = file_get_contents( $root . '/admin/assets/admin.js' );
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;padding:24px;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 14px;border:1px solid #2271b1;border-radius:6px;background:#fff;color:#2271b1}.button-primary{background:#2271b1;color:#fff}.button-link{border:0;background:none;color:#2271b1;min-height:44px}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}<?php echo $css; ?></style>
<script>
window.ajaxurl='/wp-admin/admin-ajax.php';
window.__quickProgramCount=0;
window.fetch=function(url,opts){
  var body=opts&&opts.body, action='';
  if(body instanceof FormData){action=body.get('action')||'';} else if(typeof body==='string'){action=new URLSearchParams(body).get('action')||'';}
  var payload;
  if(action==='certloom_resolve_scoped_fields'){
    payload={success:true,data:{configured:true,fields:[
      {key:'email',label:'Email',type:'text',required:true,value_source:'recipient',origin:'program',public_exposure:'private',sensitivity:'personal'},
      {key:'grade',label:'Grade / result',type:'text',required:true,value_source:'credential',origin:'program',public_exposure:'private',sensitivity:'personal'},
      {key:'score',label:'Score',type:'number',required:true,value_source:'credential_custom',origin:'batch',public_exposure:'private',sensitivity:'personal'}
    ]}};
  } else if(action==='certloom_manual_quick_program'){
    window.__quickProgramCount++; var id=99+window.__quickProgramCount; payload={success:true,data:{id:id,label:'Quick Program '+id,message:'Program added and selected.'}};
  } else if(action==='certloom_manual_quick_batch'){
    payload={success:true,data:{id:77,program_id:Number(body.get('program_id')||0),label:'Spring 2027 — SPRING-2027',message:'Batch added and selected.'}};
  } else { payload={success:true,data:{items:[]}}; }
  return Promise.resolve({ok:true,json:function(){return Promise.resolve(payload);}});
};
</script></head><body>
<main class="wrap certloom-shell">
<section class="certloom-manual-card"><h1>Compose credential</h1>
<form class="certloom-manual-form" data-certloom-human-form data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="nonce">
<fieldset class="certloom-manual-fieldset certloom-recipient-compose" data-certloom-recipient-compose><legend>Recipient</legend>
<div class="certloom-manual-choice-row"><label><input type="radio" name="recipient_mode" value="existing" checked> Choose existing recipient</label><label><input type="radio" name="recipient_mode" value="new"> Create new recipient</label></div>
<div data-certloom-recipient-existing-panel><label><span>Recipient</span><select name="recipient_id" data-certloom-recipient-existing-select><option value="12">Amina Khan</option></select></label></div>
<div class="certloom-recipient-new-panel" data-certloom-recipient-new-panel hidden><div class="certloom-recipient-new-summary"><strong data-certloom-recipient-new-summary-name>New Recipient profile</strong><button type="button" class="button" data-certloom-dialog-open="certloom-new-recipient-dialog">Complete recipient profile</button></div></div>
<dialog id="certloom-new-recipient-dialog" class="certloom-premium-dialog" data-certloom-recipient-dialog><div class="certloom-dialog-head"><h2>New recipient</h2><button type="button" class="button-link" data-certloom-dialog-close>×</button></div><div class="certloom-dialog-body certloom-manual-grid certloom-manual-grid-2">
<label><span>Display name</span><input name="new_recipient[display_name]" required></label><label><span>Email</span><input type="email" name="new_recipient[email]"></label><label><span>First name</span><input name="new_recipient[first_name]"></label><label><span>Middle name</span><input name="new_recipient[middle_name]"></label><label><span>Last name</span><input name="new_recipient[last_name]"></label><label><span>External ID</span><input name="new_recipient[external_id]"></label><label><span>WordPress account</span><select name="new_recipient[wp_user_id]"><option value="0">No linked WordPress account</option></select></label>
</div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>Cancel</button><button type="button" class="button button-primary" data-certloom-recipient-profile-save>Use this recipient profile</button></div></dialog>
</fieldset>
<fieldset class="certloom-manual-fieldset"><legend>Credential context</legend><div class="certloom-manual-grid certloom-manual-grid-2">
<label><span>Program</span><select name="program_id" required><option value="">Choose Program…</option></select></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-program-dialog">Add new Program</button></div>
<label><span>Batch</span><select name="batch_id"><option value="0">No Batch</option></select></label><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-quick-batch-dialog">Add new Batch</button></div>
<div data-certloom-grade-field><label><span>Grade / result</span><input name="grade"></label></div></div></fieldset>
<fieldset><legend>Credential details</legend><div data-certloom-scoped-field-status></div><div data-certloom-scoped-field-inputs></div><div data-certloom-legacy-custom-fields></div><script type="application/json" data-certloom-scoped-existing-values>{}</script></fieldset>
<button type="submit" class="button button-primary" data-certloom-manual-review data-certloom-has-templates="1" disabled>Review credential</button>
</form></section>

<dialog id="certloom-quick-program-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="program" data-certloom-quick-target="select[name=program_id]" data-certloom-human-form><input type="hidden" name="action" value="certloom_manual_quick_program"><input type="hidden" name="nonce" value="n"><div class="certloom-dialog-body"><label>Program title <input name="program_title" required></label><input name="program_code"></div><div class="certloom-dialog-actions"><button type="button" data-certloom-dialog-close class="button">Cancel</button><button type="submit" class="button button-primary">Add Program</button></div></form></dialog>
<dialog id="certloom-quick-batch-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="batch" data-certloom-quick-target="select[name=batch_id]" data-certloom-quick-program-source="select[name=program_id]" data-certloom-human-form><input type="hidden" name="action" value="certloom_manual_quick_batch"><input type="hidden" name="nonce" value="n"><div class="certloom-dialog-body"><label>Program <select name="program_id" required><option value="">Choose Program…</option></select></label><label>Batch name <input name="batch_name" required></label><input name="batch_code"></div><div class="certloom-dialog-actions"><button type="button" data-certloom-dialog-close class="button">Cancel</button><button type="submit" class="button button-primary">Add Batch</button></div></form></dialog>

<section id="batch-editor"><h2>Add batch</h2><form data-certloom-human-form><label>Program <select id="certloom-batch-program-id" name="batch_program_id" required><option value="">Choose Program…</option></select></label><button type="button" class="button-link" data-certloom-dialog-open="certloom-batch-quick-program-dialog">Add new Program</button><label>Batch name <input name="batch_name_parent" value="Already typed"></label></form></section>
<dialog id="certloom-batch-quick-program-dialog" class="certloom-premium-dialog"><form data-certloom-quick-create="program" data-certloom-quick-target="#certloom-batch-program-id" data-certloom-human-form><input type="hidden" name="action" value="certloom_manual_quick_program"><input type="hidden" name="nonce" value="n"><div class="certloom-dialog-body"><label>Program title <input name="program_title" required></label><input name="program_code"></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>Cancel</button><button type="submit" class="button button-primary">Add Program</button></div></form></dialog>
</main>
<script><?php echo str_replace('</script>', '<\/script>', $js); ?></script>
</body></html>
