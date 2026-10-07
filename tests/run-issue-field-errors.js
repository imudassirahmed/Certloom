/* Exercise production field-error behavior and preservation of accessibility hints. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../admin/assets/issue-credential.js'), 'utf8');
function productionFunction(name) {
  const start = source.indexOf('\tfunction ' + name + '(');
  assert.ok(start >= 0, 'Production function exists: ' + name);
  const end = source.indexOf('\n\tfunction ', start + 1);
  return source.slice(start, end);
}
class Element {
  constructor() { this.attributes = new Map(); this.textContent = ''; this.focusCount = 0; }
  get id() { return this.getAttribute('id') || ''; }
  set id(value) { this.setAttribute('id', value); }
  getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
  hasAttribute(name) { return this.attributes.has(name); }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  removeAttribute(name) { this.attributes.delete(name); }
  insertAdjacentElement(position, element) { this.inserted = element; }
  remove() { this.removed = true; }
  focus() { this.focusCount++; }
  closest(selector) { return selector === 'label' ? this.label || null : this.control || null; }
}
const messages = [];
const context = vm.createContext({fieldError: null, document: {createElement: () => new Element()}, showMessage: (copy, kind) => messages.push({copy, kind})});
vm.runInContext(['clearMessage', 'clearFieldError', 'showFieldError', 'validateAllBeforeReview'].map(productionFunction).join('\n'), context);
let checks = 0;
function check(ok, label) { assert.ok(ok, label); checks++; console.log('PASS  ' + label); }
const input = new Element(), caption = new Element();
input.label = {querySelector: () => caption};
input.setAttribute('aria-describedby', 'existing-help');
input.setAttribute('aria-invalid', 'false');
context.showFieldError('Enter a credential title.', input);
const note = input.inserted;
check(input.focusCount === 1 && input.getAttribute('aria-invalid') === 'true', 'a field error focuses and marks the affected input');
check(input.getAttribute('aria-describedby') === 'existing-help ' + note.id, 'the field error preserves an existing help association');
check(note.textContent === 'Enter a credential title.' && note.getAttribute('role') === 'alert', 'the visible error is text and has an announcement role');
check(input.getAttribute('aria-labelledby') === caption.id && !!caption.id, 'the label caption excludes the error from the field name');
context.clearMessage();
check(note.removed && input.getAttribute('aria-describedby') === 'existing-help' && input.getAttribute('aria-invalid') === 'false', 'clearing the error preserves original descriptions and invalid state');
check(!input.hasAttribute('aria-labelledby') && !caption.id, 'clearing removes only the temporary label association and ID');
input.setAttribute('aria-labelledby', 'existing-label');
context.showFieldError('First error', input);
const first = input.inserted;
context.showFieldError('Replacement error', input);
check(first.removed && input.getAttribute('aria-labelledby') === 'existing-label', 'replacement errors retire the old note without changing an explicit label');
context.clearFieldError();
const pickerInput = new Element(), control = new Element();
pickerInput.control = control;
context.showFieldError('Choose a recipient.', pickerInput);
check(control.inserted.textContent === 'Choose a recipient.' && !pickerInput.inserted, 'picker errors appear outside the search and clear-button control');
context.clearFieldError();
check(!pickerInput.hasAttribute('aria-invalid') && !pickerInput.hasAttribute('aria-describedby'), 'an input without prior accessibility attributes returns to its original state');
context.showFieldError('A field is unavailable.', null);
check(messages.at(-1).copy === 'A field is unavailable.' && messages.at(-1).kind === 'error', 'a missing input retains actionable global error feedback');
let visibleStep = -1, focusedStep = -1;
const hiddenInput = new Element();
hiddenInput.focus = () => { focusedStep = visibleStep; };
context.reviewStep = 5;
context.validateStep = index => { if (index === 2) { context.showFieldError('Complete the title.', hiddenInput); return false; } return true; };
context.goToStep = index => { visibleStep = index; };
check(context.validateAllBeforeReview() === false && focusedStep === 2, 'a failed cross-step review reveals the field before restoring focus');
console.log('\n' + checks + ' checks, 0 failures');
