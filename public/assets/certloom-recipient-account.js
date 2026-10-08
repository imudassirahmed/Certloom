(() => {
  'use strict';
  let sequence = 0;
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  class AccountDetails {
    constructor(root) {
      this.root = root;
      this.workspace = root.querySelector('[data-account-workspace]');
      this.feedback = root.querySelector('[data-account-feedback]');
      this.messages = JSON.parse(root.dataset.messages);
      this.endpoint = new URL(root.dataset.endpoint, location.href);
      this.id = `certloom-detail-${++sequence}`;
      this.model = null; this.mode = 'personal'; this.dirty = false; this.busy = false; this.stale = false;
      this.programs = []; this.nextProgram = null; this.inputs = [];
      this.requests = []; this.nextRequest = null; this.requestView = 'active';
      if (this.endpoint.origin !== location.origin) { this.say(this.messages.loadError, true); this.workspace.setAttribute('aria-busy', 'false'); return; }
      this.load();
    }
    button(text, action, primary = false) {
      const button = node('button', text, primary ? 'certloom-account__primary' : '');
      button.type = 'button'; button.addEventListener('click', action);
      return button;
    }
    requestsEndpoint() {
      const url = new URL(this.endpoint);
      if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/me\/details\/?$/, '/me/certificate-requests'));
      else url.pathname = url.pathname.replace(/\/me\/details\/?$/, '/me/certificate-requests');
      return url;
    }
    async loadRequests(after = 0, view = this.requestView) {
      if (this.busy || (!after && !this.mayLeave())) return;
      const previous = this.model; this.setBusy(true); this.say('');
      try {
        const model = await this.request('GET', null, { after_id: after, view }, this.requestsEndpoint());
        if (!model || typeof model.linked !== 'boolean' || !Array.isArray(model.items)) throw new Error(this.messages.loadError);
        this.requests = after ? this.requests.concat(model.items) : model.items;
        this.nextRequest = model.has_more ? model.next_id : null;
        this.requestView = view;
        this.model = { linked: model.linked, fields: [] }; this.mode = 'requests'; this.dirty = false; this.stale = false;
        this.render(); const heading = this.workspace.querySelector('h3');
        if (heading) { heading.tabIndex = -1; heading.focus({ preventScroll: true }); }
      } catch (error) { this.model = previous; this.say(error.status ? error.message : this.messages.loadError, true); }
      finally { this.setBusy(false); }
    }
    say(text, error = false) { this.feedback.textContent = text; this.feedback.dataset.error = String(error); }
    async request(method, body, query = {}, route = this.endpoint) {
      const url = new URL(route);
      Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, String(value)));
      const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), 20000);
      try {
      const response = await fetch(url, {
        method, credentials: 'same-origin', cache: 'no-store',
        signal: controller.signal,
        headers: { 'X-WP-Nonce': this.root.dataset.nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
        ...(body ? { body: JSON.stringify(body) } : {})
      });
      const result = await response.json();
      if (!response.ok) {
        const error = new Error(result.message || this.messages.loadError);
        error.status = response.status; throw error;
      }
      return result;
      } finally { clearTimeout(timer); }
    }
    setBusy(busy) {
      this.busy = busy; this.workspace.setAttribute('aria-busy', String(busy));
      this.workspace.querySelectorAll('button, input, textarea, select').forEach(el => { el.disabled = busy || el.dataset.disabled === 'true'; });
      if (!busy) this.updateSave();
    }
    mayLeave() { return !this.dirty || window.confirm(this.messages.discard); }
    async load(registration = 0, appendPrograms = false, nextMode = this.mode, requestId = 0) {
      if (this.busy || (!appendPrograms && !this.mayLeave())) return;
      const previous = this.model;
      const previousProgramCount = this.programs.length;
      this.setBusy(true); this.say('');
      try {
        const initial = !previous && !registration && !appendPrograms;
        const reads = await Promise.allSettled([
          this.request('GET', null, { registration_id: registration, request_id: requestId, ...(appendPrograms ? { after_registration_id: this.nextProgram } : {}) }),
          initial ? this.request('GET', null, {}, this.requestsEndpoint()) : Promise.resolve(null)
        ]);
        if (reads[0].status !== 'fulfilled') throw reads[0].reason;
        const model = reads[0].value;
        if (!model || typeof model.linked !== 'boolean' || !Array.isArray(model.fields)) throw new Error(this.messages.loadError);
        this.model = model; this.mode = nextMode;
        const pending = reads[1].status === 'fulfilled' ? reads[1].value : null;
        if (initial && pending?.linked && Array.isArray(pending.items) && pending.items.length) {
          this.requests = pending.items; this.nextRequest = pending.has_more ? pending.next_id : null; this.mode = 'requests';
        }
        if (!registration && !requestId) {
          this.programs = appendPrograms ? this.programs.concat(model.registrations || []) : model.registrations || [];
          this.nextProgram = model.has_more_registrations ? model.next_registration_id : null;
        }
        this.dirty = false; this.stale = false; this.render();
        if (previous) {
          const target = appendPrograms ? this.workspace.querySelectorAll('.certloom-account__program h3')[previousProgramCount] : this.workspace.querySelector('h3');
          if (target) { target.tabIndex = -1; target.focus({ preventScroll: true }); }
        }
      } catch (error) {
        this.model = previous;
        this.say(error.status ? error.message : this.messages.loadError, true);
        if (!previous) {
          this.workspace.replaceChildren(node('p', this.messages.loadError, 'certloom-account__empty'), this.button(this.messages.retry, () => this.load()));
        }
      } finally { this.setBusy(false); }
    }
    render() {
      this.inputs = []; this.saveButton = null;
      this.workspace.replaceChildren();
      if (!this.model.linked) { this.workspace.append(node('p', this.messages.unlinked, 'certloom-account__empty')); return; }
      const nav = node('nav', undefined, 'certloom-account__nav'); nav.setAttribute('aria-label', this.messages.personal + ' / ' + this.messages.programs + ' / ' + this.messages.requests);
      const personal = this.button(this.messages.personal, () => this.load(0, false, 'personal'));
      const programs = this.button(this.messages.programs, () => this.load(0, false, 'programs'));
      const requests = this.button(this.messages.requests, () => this.loadRequests());
      personal.setAttribute('aria-pressed', String(this.mode === 'personal'));
      programs.setAttribute('aria-pressed', String(this.mode === 'programs'));
      requests.setAttribute('aria-pressed', String(this.mode === 'requests'));
      nav.append(personal, programs, requests); this.workspace.append(nav);
      if (this.mode === 'requests') { this.renderRequests(); return; }
      if (this.mode === 'programs' && !this.model.registration_id) { this.renderPrograms(); return; }
      const panel = node('div', undefined, 'certloom-account__panel');
      const title = node('h3', this.model.registration_id || this.model.request_id ? this.model.title : this.messages.personal);
      panel.append(title, node('p', this.model.registration_id || this.model.request_id ? this.messages.registrationIntro : this.messages.personalIntro, 'certloom-account__intro'));
      if (this.model.certificate_name) {
        const name = this.model.certificate_name;
        const section = node('div', undefined, 'certloom-account__issuer');
        section.append(node('h4', this.messages.certificateName));
        if (name.value) section.append(node('p', name.value));
        section.append(node('p', name.source === 'approved_field' ? this.messages.nameNeedsApproval : name.organization_managed ? this.messages.nameFromOrganization : this.messages.nameFromAccount));
        section.append(node('p', this.messages.nameHistorical)); panel.append(section);
      }
      const fields = node('div', undefined, 'certloom-account__fields');
      this.model.fields.forEach((field, index) => fields.append(this.renderField(field, index)));
      panel.append(fields);
      if (!this.model.fields.length) panel.append(node('p', this.model.registration_id ? this.messages.emptyRegistration : this.messages.empty, 'certloom-account__empty'));
      const issuer = this.model.issuer_fields || [];
      if (issuer.length) {
        const section = node('div', undefined, 'certloom-account__issuer');
        section.append(node('h4', this.messages.issuer), node('p', this.messages.issuerIntro));
        const list = node('ul'); issuer.forEach(field => list.append(node('li', field.label))); section.append(list); panel.append(section);
      }
      const actions = node('div', undefined, 'certloom-account__actions');
      if (this.model.fields.length) { this.saveButton = this.button(this.messages.save, () => this.save(), true); actions.append(this.saveButton); }
      actions.append(this.button(this.messages.refresh, () => this.load(this.model.registration_id || 0, false, this.mode, this.model.request_id || 0)));
      panel.append(actions, node('p', this.messages.future, 'certloom-account__footnote')); this.workspace.append(panel);
      this.updateSave();
    }
    renderPrograms() {
      this.workspace.append(node('h3', this.messages.programs), node('p', this.messages.programIntro, 'certloom-account__intro'));
      const collection = node('div', undefined, 'certloom-account__programs');
      this.programs.forEach(program => {
        const row = node('div', undefined, 'certloom-account__program'); const heading = node('h3', program.title);
        const action = this.button(this.messages.view, () => this.load(program.id));
        action.setAttribute('aria-label', `${this.messages.view}: ${program.title}`); row.append(heading, action); collection.append(row);
      });
      if (!this.programs.length) collection.append(node('p', this.messages.noPrograms, 'certloom-account__empty'));
      this.workspace.append(collection);
      if (this.nextProgram) this.workspace.append(this.button(this.messages.more, () => this.load(0, true)));
    }
    renderRequests() {
      this.workspace.append(node('h3', this.messages.requests), node('p', this.messages.requestsIntro, 'certloom-account__intro'));
      const tabs = node('div', undefined, 'certloom-account__actions');
      [['active', this.messages.activeRequests], ['closed', this.messages.closedRequests]].forEach(([view, text]) => {
        const tab = this.button(text, () => this.loadRequests(0, view));
        tab.setAttribute('aria-pressed', String(this.requestView === view)); tabs.append(tab);
      });
      this.workspace.append(tabs);
      const list = node('div', undefined, 'certloom-account__requests');
      this.requests.forEach(item => {
        const card = node('article', undefined, 'certloom-account__request');
        card.append(node('h4', item.title), node('p', this.messages[item.notice] || this.messages.issuer_review));
        if (item.can_complete) {
          const action = this.button(this.messages.view, () => this.load(item.registration_id || 0, false, item.registration_id ? 'programs' : 'personal', item.id), true);
          action.setAttribute('aria-label', `${this.messages.view}: ${item.title}`); card.append(action);
        }
        list.append(card);
      });
      if (!this.requests.length) list.append(node('p', this.requestView === 'closed' ? this.messages.noClosedRequests : this.messages.noRequests, 'certloom-account__empty'));
      this.workspace.append(list);
      const actions = node('div', undefined, 'certloom-account__actions');
      actions.append(this.button(this.messages.refresh, () => this.loadRequests()));
      if (this.nextRequest) actions.append(this.button(this.messages.moreRequests, () => this.loadRequests(this.nextRequest)));
      this.workspace.append(actions);
    }
    renderField(field, index) {
      const wrapper = node('div', undefined, 'certloom-account__field'); const id = `${this.id}-${index}`;
      const label = node('label', field.label); label.htmlFor = id;
      label.append(document.createTextNode(' '), node('span', field.required ? this.messages.required : this.messages.optional, 'certloom-account__requirement'));
      const input = node(field.type === 'textarea' ? 'textarea' : 'input'); input.id = id;
      if (input.tagName === 'INPUT') { input.type = ({ date: 'date', number: 'number', email: 'email', url: 'url' })[field.type] || 'text'; if (input.type === 'number') input.step = 'any'; }
      input.value = field.value || ''; input.maxLength = 8192;
      input.setAttribute('aria-required', String(!!field.required));
      const scopeHelp = this.model.registration_id ? (field.lifetime === 'recipient' ? this.messages.reusableHelp : this.messages.enrollmentHelp) : '';
      const help = node('p', [field.help, scopeHelp, field.approval_required ? this.messages.approval : ''].filter(Boolean).join(' '), 'certloom-account__help'); help.id = `${id}-help`;
      const status = node('span', this.messages[field.status] || this.messages.missing, `certloom-account__status certloom-account__status--${['complete', 'missing', 'awaiting_approval', 'rejected'].includes(field.status) ? field.status : 'missing'}`); status.id = `${id}-status`;
      input.setAttribute('aria-describedby', [help.textContent ? help.id : '', status.id].filter(Boolean).join(' '));
      input.addEventListener('input', () => { this.dirty = this.patchSize() > 0; this.updateSave(); });
      input.addEventListener('keydown', event => {
        // Committing a composed character is not a request to save the form.
        if (event.isComposing || event.keyCode === 229) return;
        if (event.key === 'Enter' && input.tagName === 'INPUT') {
          event.preventDefault();
          if (this.saveButton && !this.saveButton.disabled) this.save();
        }
      });
      this.inputs.push({ input, field }); wrapper.append(label, input); if (help.textContent) wrapper.append(help); wrapper.append(status); return wrapper;
    }
    patch() {
      const values = Object.create(null);
      this.inputs.forEach(({ input, field }) => {
        if (input.value !== field.value || (field.status === 'rejected' && input.value.trim())) values[field.key] = input.value;
      });
      return values;
    }
    patchSize() { return Object.keys(this.patch()).length; }
    updateSave() { if (this.saveButton) this.saveButton.disabled = this.busy || this.stale || !this.patchSize(); }
    async save() {
      if (this.busy || this.stale || !this.patchSize()) return;
      for (const { input } of this.inputs) { if (!input.reportValidity()) return; }
      const values = this.patch(); this.setBusy(true); this.saveButton.textContent = this.messages.saving;
      this.say(this.messages.saving);
      try {
        const model = await this.request('POST', { registration_id: this.model.registration_id || 0, request_id: this.model.request_id || 0, revision: this.model.revision, values });
        this.model = model; this.dirty = false; this.stale = false; this.render(); this.say(this.messages.saved);
        this.feedback.tabIndex = -1; this.feedback.focus({ preventScroll: true });
      } catch (error) {
        this.stale = error.status === 409;
        this.say(this.stale ? this.messages.stale : (error.status ? error.message : this.messages.saveError), true);
      } finally { this.setBusy(false); if (this.saveButton) this.saveButton.textContent = this.messages.save; }
    }
  }

  class RecipientReviews extends AccountDetails {
    async load(after = 0) {
      if (this.busy) return;
      this.setBusy(true); this.say('');
      try {
        const model = await this.request('GET', null, { after_id: after });
        if (!model || !Array.isArray(model.items)) throw new Error(this.messages.loadError);
        this.after = after; this.model = model; this.renderReviews();
      } catch (error) {
        this.say(error.status ? error.message : this.messages.loadError, true);
        if (!this.model) this.workspace.replaceChildren(this.button(this.messages.refresh, () => this.load()));
      } finally { this.setBusy(false); }
    }
    renderReviews() {
      this.workspace.replaceChildren();
      const list = node('div', undefined, 'certloom-account__review-list');
      this.model.items.forEach(item => {
        const section = node('article', undefined, 'certloom-account__panel');
        section.append(node('h3', `${item.recipient_name} · ${item.label}`), node('p', item.scope === 'registration' ? item.program_title : this.messages.personal, 'certloom-account__help'));
        const value = node('div', item.value, 'certloom-account__candidate'); value.setAttribute('aria-label', this.messages.value); section.append(value);
        if (!item.can_approve) section.append(node('p', this.messages.changed, 'certloom-account__help'));
        const actions = node('div', undefined, 'certloom-account__actions');
        const approve = this.button(this.messages.approve, () => this.decision(item, true), true);
        approve.setAttribute('aria-label', `${this.messages.approve}: ${item.recipient_name}, ${item.label}`);
        approve.dataset.disabled = String(!item.can_approve); approve.disabled = !item.can_approve;
        const reject = this.button(this.messages.reject, () => this.decision(item, false)); reject.setAttribute('aria-label', `${this.messages.reject}: ${item.recipient_name}, ${item.label}`);
        actions.append(approve, reject); section.append(actions); list.append(section);
      });
      if (!this.model.items.length) list.append(node('p', this.messages.empty, 'certloom-account__empty'));
      this.workspace.append(list);
      const actions = node('div', undefined, 'certloom-account__actions');
      actions.append(this.button(this.messages.refresh, () => this.load()));
      if (this.model.has_more) actions.append(this.button(this.messages.more, () => this.load(this.model.next_id)));
      this.workspace.append(actions);
    }
    async decision(item, approve) {
      if (this.busy || (approve && !item.can_approve)) return;
      this.setBusy(true); this.say('');
      try {
        const url = new URL(this.endpoint);
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + `/${item.id}/decision`);
        else url.pathname = url.pathname.replace(/\/$/, '') + `/${item.id}/decision`;
        await this.request('POST', { revision: item.revision, approve }, {}, url);
        this.model.items = this.model.items.filter(row => row.id !== item.id); this.renderReviews(); this.say(this.messages.saved);
        this.feedback.tabIndex = -1; this.feedback.focus({ preventScroll: true });
      } catch (error) { this.say(error.status ? error.message : this.messages.loadError, true); }
      finally { this.setBusy(false); }
    }
  }
  const boot = () => document.querySelectorAll('[data-certloom-account]').forEach(root => {
    if (root.dataset.accountReady) return;
    root.dataset.accountReady = 'true';
    try { new AccountDetails(root); } catch (_) {
      const status = root.querySelector('[data-account-feedback]');
      if (status) status.textContent = 'Unable to load certificate details. Please refresh the page.';
      root.querySelector('[data-account-workspace]')?.setAttribute('aria-busy', 'false');
    }
  });
  const initialize = () => {
    boot();
    document.querySelectorAll('[data-certloom-reviews]:not([data-attention-deferred])').forEach(root => {
      if (root.dataset.accountReady) return; root.dataset.accountReady = 'true';
      try { new RecipientReviews(root); } catch (_) { root.querySelector('[data-account-workspace]')?.setAttribute('aria-busy', 'false'); }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
  document.addEventListener('certloom:attention-ready', initialize);
})();
