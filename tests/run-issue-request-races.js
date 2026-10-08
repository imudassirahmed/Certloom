/* Exercise production request handlers with deliberately reordered responses. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../admin/assets/issue-credential.js'), 'utf8');
function productionFunction(name) {
  const start = source.indexOf('\tfunction ' + name + '(');
  assert.ok(start >= 0, 'Production handler exists: ' + name);
  const end = source.indexOf('\n\tfunction ', start + 1);
  return source.slice(start, end < 0 ? source.indexOf('\n\tdocument.', start) : end);
}
function deferred() {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
}
async function flush() { for (let i = 0; i < 8; i++) { await Promise.resolve(); } }
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; console.log('PASS  ' + label); }
async function main() {
  let currentSignature = 'original';
  const calls = [], messages = [], renders = [], errors = [];
  const state = {busy: false, issuance: {program_id: 7}, email: {}, reviewFingerprint: '', reviewSignature: '', searchControllers: {}, searchTimers: {}};
  const status = {textContent: ''};
  const scheduled = new Map(); let timerId = 0;
  const context = vm.createContext({
    state, cfg: {reviewUrl: '/review', contextUrl: '/context'}, AbortController, URL,
    root: {querySelector: () => ({hidden: false})}, window: {location: {href: 'https://example.test/'}},
    validateAllBeforeReview: () => true, setBusy: busy => { state.busy = busy; },
    signature: () => currentSignature, text: (key, fallback) => fallback,
    showMessage: copy => messages.push(copy), clearMessage: () => messages.push(''),
    invalidateReview: () => { state.reviewFingerprint = ''; state.reviewSignature = ''; },
    renderReview: () => renders.push('review'), showRequestError: error => errors.push(error),
    renderDesignRequired: () => {}, picker: () => ({querySelector: () => status}),
    renderPickerResults: (kind, rows) => renders.push(rows), programTerm: 'Program', batchTerm: 'Batch',
    rest: (url, options) => { const response = deferred(); calls.push({url, options, response}); return response.promise; },
    setTimeout: callback => { scheduled.set(++timerId, callback); return timerId; },
    clearTimeout: id => scheduled.delete(id)
  });
  context.prevButton = {disabled:false}; context.renderReceipt = () => renders.push('receipt');
  vm.runInContext(productionFunction('buildReview') + '\n' + productionFunction('searchPicker') + '\n' + productionFunction('issueCredential'), context);
  context.buildReview(); currentSignature = 'changed while waiting';
  calls[0].response.resolve({review_fingerprint: 'old-fingerprint', review: {recipient: {name: 'Old name'}}});
  await flush();
  check(!state.reviewFingerprint && !renders.length && !state.busy, 'changed input cannot receive an old review or be marked ready to issue');
  check(messages.some(copy => copy.includes('Review again')), 'stale review gives an actionable retry message');
  context.buildReview(); context.buildReview();
  check(calls.length === 2, 'an in-flight review cannot be started twice');
  calls[1].response.resolve({review_fingerprint: 'current-fingerprint', review: {}}); await flush();
  check(state.reviewFingerprint === 'current-fingerprint' && state.reviewSignature === currentSignature, 'unchanged input receives its matching review');
  context.buildReview(); const staleFailure = calls.at(-1);
  currentSignature = 'edited before an earlier failure';
  staleFailure.response.reject({message: 'Old validation error', code: 'certloom_manual_issue_design_data_missing', data: {fields: ['old_field']}});
  await flush();
  check(!state.reviewFingerprint && !errors.length && !state.busy, 'an old review failure cannot apply validation errors to edited input');
  renders.length = 0; context.searchPicker('recipient', 'old', true);
  const old = calls.at(-1); context.searchPicker('recipient', 'new', false);
  check(old.options.signal.aborted, 'typing cancels the prior search before the debounce delay');
  old.response.resolve({choices: [{label: 'Old recipient'}]}); await flush();
  check(renders.length === 0, 'a late cancelled response cannot restore outdated recipient choices');
  for (const callback of scheduled.values()) { callback(); } scheduled.clear();
  calls.at(-1).response.resolve({choices: [{label: 'New recipient'}]}); await flush();
  check(renders.length === 1 && renders[0][0].label === 'New recipient', 'the latest search still displays its results');
  context.searchPicker('recipient', 'before failure', true); const staleLookupFailure = calls.at(-1);
  context.searchPicker('recipient', 'after failure', false); status.textContent = 'Latest search pending';
  staleLookupFailure.response.reject(new Error('Old lookup error')); await flush();
  check(status.textContent === 'Latest search pending', 'a cancelled search failure cannot replace current picker feedback');
  for (const callback of scheduled.values()) { callback(); } scheduled.clear();
  calls.at(-1).response.reject(new Error('Current lookup failed')); await flush();
  check(status.textContent === 'Current lookup failed', 'the latest search failure still gives useful feedback');
  context.searchPicker('batch', 'batch', true); const batch = calls.at(-1);
  state.issuance.program_id = 0; context.searchPicker('batch', '', true);
  batch.response.resolve({choices: [{label: 'Wrong Program batch'}]}); await flush();
  check(batch.options.signal.aborted && renders.at(-1).length === 0, 'removing Program context cancels an outstanding Batch search');
  state.reviewFingerprint = 'retry-fingerprint'; state.reviewSignature = currentSignature;
  context.issueCredential(); const issue = calls.at(-1); issue.response.reject(new TypeError('Connection lost')); await flush();
  check(state.commitUncertain && state.reviewFingerprint === 'retry-fingerprint' && context.prevButton.disabled, 'a dropped commit keeps its reviewed identity and prevents editing');
  const count = calls.length; context.buildReview();
  check(calls.length === count && state.reviewFingerprint === 'retry-fingerprint', 'refreshing review cannot replace an uncertain commit');
  context.issueCredential(); const retry = calls.at(-1);
  check(retry.options.body.review_fingerprint === issue.options.body.review_fingerprint, 'retry commits the same reviewed request');
  retry.response.resolve({receipt:{series_id:51,version_id:52}}); await flush();
  check(!state.commitUncertain && renders.at(-1) === 'receipt', 'a reconciled receipt clears uncertainty');
  context.issueCredential(); calls.at(-1).response.resolve({}); await flush();
  check(state.commitUncertain && renders.at(-1) === 'receipt', 'an incomplete success response cannot create a false issuance receipt');
  console.log('\n' + checks + ' checks, 0 failures');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
