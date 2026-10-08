/* Exercise the production short-flow handlers with reordered and interrupted REST responses. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../admin/assets/program-issuance.js'), 'utf8');
function handler(name, endMarker) {
  const start = source.indexOf('\t\tfunction ' + name + '(');
  const end = source.indexOf(endMarker || '\n\t\tfunction ', start + 1);
  assert.ok(start >= 0 && end > start, 'Production handler exists: ' + name);
  return source.slice(start, end);
}
function deferred() { let resolve, reject; const promise = new Promise((yes,no) => { resolve = yes; reject = no; }); return {promise, resolve, reject}; }
async function flush() { for (let i = 0; i < 12; i++) { await Promise.resolve(); } }
let checks = 0;
function check(ok, message) { assert.ok(ok, message); checks++; console.log('PASS  ' + message); }
async function main() {
  const calls = [], saved = [], messages = [], renders = [];
  const state = {settings:{valid_from:'2026-10-08'}, deep:{program_id:7,batch_id:8,recipient_id:9}, mode:'existing', busy:false, loading:false, uncertain:false, generation:0, stage:'select'};
  const elements = {display_name:{value:'New customer'}, email:{value:'',validity:{valid:true}}, display_number:{value:''}, expires_at:{value:''}};
  Object.values(elements).forEach(element=>{element.focus=()=>{};});
  const nodes = new Map();
  const context = vm.createContext({state, cfg:{reviewUrl:'/review',issueUrl:'/issue'}, disposed:false, recovery:null,
    form:{elements}, next:{focus(){}}, help:{hidden:true}, status:{textContent:''},
    node: selector => { if (!nodes.has(selector)) { nodes.set(selector,{value:'',href:'',textContent:'',focus(){}}); } return nodes.get(selector); },
    text:(key,fallback)=>fallback, paint(){}, notify:copy=>messages.push(copy), showHelp(){}, loadEnrollments:()=>renders.push('enrollments'), renderReview:row=>renders.push(row),
    renderSettings:()=>renders.push('settings'), loadSettings:()=>renders.push('settings-loaded'),
    saveRecovery:row=>saved.push(row), rest:(url,options)=>{ const call = deferred(); calls.push({url,options,call}); return call.promise; }
  });
  vm.runInContext(handler('buildReview') + '\n' + handler('issueCredential', "\n\t\t['recipient'"),context);
  context.buildReview(); context.buildReview();
  check(calls.length === 1, 'Two review clicks start only one request.');
  const payload = calls[0].options.body;
  check(payload.use_program_settings === true && !('template_id' in payload.issuance) && !('custom_merge_fields' in payload.issuance) && !('signer_id' in payload.issuance) && !('expiry_mode' in payload.issuance), 'The popup sends recipient/context choices without configurable overrides.');
  state.generation++;
  calls[0].call.resolve({review_fingerprint:'a'.repeat(64),review:{recipient:{name:'Old'}}}); await flush();
  check(!state.fingerprint && state.stage === 'select' && !renders.length, 'An old review cannot attach to changed input.');
  context.buildReview(); calls.at(-1).call.resolve({review_fingerprint:'b'.repeat(64),review:{recipient:{name:'Current'}}}); await flush();
  check(state.fingerprint === 'b'.repeat(64) && state.stage === 'review' && state.payload, 'The current signed review is retained for confirmation.');
  const frozen = JSON.stringify(state.payload);
  context.issueCredential(); context.issueCredential();
  check(calls.filter(row=>row.url === '/issue').length === 1 && saved.at(-1).fingerprint === state.fingerprint, 'Confirmation persists its signed identity and prevents duplicate clicks.');
  calls.at(-1).call.reject(new TypeError('Connection lost')); await flush();
  check(state.uncertain && state.stage === 'review' && state.fingerprint === 'b'.repeat(64) && JSON.stringify(state.payload) === frozen, 'A lost response preserves the exact signed issuance for recovery.');
  context.buildReview(); check(calls.filter(row=>row.url === '/review').length === 2, 'An uncertain commit cannot be replaced by a new review.');
  context.issueCredential();
  const retry = calls.at(-1).options.body;
  check(retry.review_fingerprint === 'b'.repeat(64) && JSON.stringify(retry.issuance) === JSON.stringify(payload.issuance), 'Recovery resubmits the same fingerprint and original payload.');
  calls.at(-1).call.resolve({receipt:{version_id:42,display_number:'CERT-42',verification_url:'/verify'},continuations:{email_url:'/email'}}); await flush();
  check(state.complete && !state.uncertain && state.stage === 'receipt' && saved.at(-1) === null, 'A reconciled result shows the receipt and clears recovery data.');
  state.complete = false; state.stage = 'review'; context.issueCredential(); calls.at(-1).call.resolve({receipt:{}}); await flush();
  check(state.uncertain && state.fingerprint === 'b'.repeat(64), 'An incomplete success response remains uncertain instead of allowing another issuance.');
  context.issueCredential(); calls.at(-1).call.reject({code:'server_failure',message:'Server failed',uncertain:true}); await flush();
  check(state.uncertain && state.fingerprint === 'b'.repeat(64), 'A server failure retains recovery identity.');
  context.issueCredential(); calls.at(-1).call.resolve({receipt:{version_id:42,verification_url:'/verify'}}); await flush();
  check(state.uncertain && !state.complete && saved.at(-1), 'A partial receipt cannot clear recovery before its continuation links are available.');
  context.issueCredential(); calls.at(-1).call.reject({code:'rest_cookie_invalid_nonce',status:403,message:'Session expired',uncertain:false}); await flush();
  check(state.uncertain && state.fingerprint === 'b'.repeat(64) && saved.at(-1), 'Expired authentication cannot discard an uncertain issuance result.');
  context.issueCredential(); calls.at(-1).call.reject({code:'review_stale',message:'Review again',uncertain:false}); await flush();
  check(!state.uncertain && !state.fingerprint && !state.payload && state.stage === 'select' && saved.at(-1) === null, 'A definitive stale review returns to selection without retaining a commit identity.');
  context.buildReview(); calls.at(-1).call.reject({code:'certloom_registration_selection_required',message:'Choose enrollment'}); await flush();
  check(renders.includes('enrollments'), 'Ambiguous enrollment requests exact context instead of choosing an arbitrary attempt.');
  context.buildReview(); const stale = calls.at(-1); state.generation++; const before = messages.length; stale.call.reject({message:'Old validation'}); await flush();
  check(messages.length === before && !state.busy, 'Stale validation errors cannot overwrite a newer selection.');
  state.stage='review';state.fingerprint='c'.repeat(64);state.payload=payload;state.settings=null;state.uncertain=true;
  context.issueCredential(); calls.at(-1).call.reject({code:'review_stale',message:'Review again',uncertain:false});await flush();
  check(state.stage==='select'&&renders.includes('settings-loaded'), 'A definitively rejected recovered request reloads its editable Program context.');
  state.settings={batch_required:true};state.deep.batch_id=0;const beforeRequired=calls.length;context.buildReview();
  check(calls.length===beforeRequired&&messages.at(-1).includes('Choose a Batch'), 'A Program requiring a Batch blocks review until its exact Batch is chosen.');
  state.settings={expiry_at_issuance:true,expiry_required:true};context.buildReview();
  check(calls.length===beforeRequired&&messages.at(-1).includes('requires an expiry'), 'A Program requiring renewal collects an expiry before review.');
  console.log(checks + ' compact issuance race/recovery checks passed.');
}
main().catch(error=>{ console.error(error); process.exitCode = 1; });
