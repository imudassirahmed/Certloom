/* Exercise actual admin draft recovery: fresh nonces and fixed Program context survive retry. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('admin/assets/admin.js','utf8');
const start = source.indexOf('\tfunction initDomainFormPersistence()');
const end = source.indexOf('\n\tfunction initDomainMediaAndColorControls()', start);
assert(start >= 0 && end > start);
const implementation = source.slice(start,end);

function fixture(embedded=true,section='') {
  const fields = {};
  const add = (name,value,type='hidden') => fields[name] = {name,value,type,dispatchEvent(){}};
  add('action','certloom_domain_create_batch');add('program_id','7');add('name','', 'text');
  add('_wpnonce','fresh-action');add('_wp_http_referer','fresh-referrer');
  add('program_revision','new-server-revision');add('program_workspace_version','1');
  if(embedded) {add('certloom_program_context','7');add('_certloom_program_nonce','fresh-context');add('batch_id','11');}
  if(section)add('program_workspace_section',section);
  const listeners = {};
  const form = {
    querySelector(selector) {
      if(selector==='input[name$="_id"]')return fields.program_id;
      const name = /name="([^"]+)"/.exec(selector)?.[1];return fields[name] || null;
    },
    querySelectorAll(selector) {
      const name = /^\[name="([^"]+)"\]$/.exec(selector)?.[1];return name && fields[name] ? [fields[name]] : [];
    },
    addEventListener(name,callback){listeners[name]=callback;}
  };
  const key = 'certloomDomainDraft:certloom_domain_create_batch:7'+(section?':'+section:'');
  const stored = new Map([[key,JSON.stringify([
    {name:'name',value:'Recovered cohort'}, {name:'program_id',value:'999'},
    {name:'certloom_program_context',value:'999'}, {name:'batch_id',value:'999'},
    {name:'_wpnonce',value:'expired-action'}, {name:'_certloom_program_nonce',value:'expired-context'},
    {name:'_wp_http_referer',value:'expired-referrer'}, {name:'program_workspace_section',value:'other-section'},
    {name:'program_revision',value:'original-edited-revision'}, {name:'program_workspace_version',value:'old-version'}
  ])]]);
  const storage = {getItem:k=>stored.get(k),setItem:(k,v)=>stored.set(k,v),removeItem:k=>stored.delete(k)};
  const window = {sessionStorage:storage,location:{search:'?certloom_retry=1'}};
  const context = {
    window, URLSearchParams, File:class {}, Event:class {}, escapeSelector:s=>s,
    document:{querySelectorAll:()=>[form],getElementById:()=>null},
    FormData:class {constructor(){} forEach(callback){Object.values(fields).forEach(f=>callback(f.value,f.name));}}
  };
  vm.runInNewContext(implementation+'\ninitDomainFormPersistence();',context);
  return {fields,listeners,stored,key};
}
const scoped = fixture();
assert.equal(scoped.fields.name.value,'Recovered cohort');
assert.equal(scoped.fields.program_id.value,'7');
assert.equal(scoped.fields.certloom_program_context.value,'7');
assert.equal(scoped.fields.batch_id.value,'11');
assert.equal(scoped.fields._wpnonce.value,'fresh-action');
assert.equal(scoped.fields._certloom_program_nonce.value,'fresh-context');
assert.equal(scoped.fields._wp_http_referer.value,'fresh-referrer');
assert.equal(scoped.fields.program_revision.value,'original-edited-revision','Recovery retains the originally edited revision so a conflict cannot silently become an overwrite');
assert.equal(scoped.fields.program_workspace_version.value,'1','Recovery keeps the current transport schema');
scoped.listeners.submit();
const persisted = JSON.parse(scoped.stored.get(scoped.key));
assert.equal(persisted.find(x=>x.name==='name').value,'Recovered cohort');
assert.equal(persisted.find(x=>x.name==='program_revision').value,'original-edited-revision');
assert(!persisted.some(x=>x.name==='program_workspace_version'));
for(const name of ['_wpnonce','_wp_http_referer','_certloom_program_nonce','certloom_program_context','program_id','batch_id'])assert(!persisted.some(x=>x.name===name));
const legacy = fixture(false);
assert.equal(legacy.fields.program_id.value,'999','Legacy editable Program selection still recovers its user choice');
assert.equal(legacy.fields._wpnonce.value,'fresh-action');
const details = fixture(false,'details'), fields = fixture(false,'fields');
assert.notEqual(details.key,fields.key,'Details and Fields cannot share an unsaved draft');
assert.equal(details.fields.program_workspace_section.value,'details');
assert.equal(fields.fields.program_workspace_section.value,'fields');
fields.listeners.submit();
assert(!JSON.parse(fields.stored.get(fields.key)).some(x=>x.name==='program_workspace_section'));
const window = {location:{search:''}};
Object.defineProperty(window,'sessionStorage',{get(){throw new Error('Storage denied');}});
vm.runInNewContext(implementation+'\ninitDomainFormPersistence();',{window});
console.log('25 checks, 0 failures');
