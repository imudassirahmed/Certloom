(() => {
  'use strict';
  const node = (tag, text, className) => {
    const el = document.createElement(tag);
    if (text !== undefined) el.textContent = text;
    if (className) el.className = className;
    return el;
  };
  class PendingRequests {
    constructor(root) {
      this.root = root; this.list = root.querySelector('[data-pending-list]');
      this.pagination = root.querySelector('[data-pending-pagination]'); this.feedback = root.querySelector('[data-pending-feedback]');
      this.health = root.querySelector('[data-pending-health]'); this.repairActions = root.querySelector('[data-pending-repair]');
      this.status = root.querySelector('[data-pending-status]'); this.messages = JSON.parse(root.dataset.messages);
      this.filters = Array.from(root.querySelectorAll('[data-pending-filter]'));
      this.program = root.querySelector('[data-pending-filter="program_id"]'); this.programMore = root.querySelector('[data-pending-program-more]');
      this.programCursor = 0; this.programsLoaded = false; this.loadedFilters = null; this.asOf = null;
      this.endpoint = new URL(root.dataset.endpoint, location.href);
      this.items = []; this.cursor = null; this.busy = false; this.mustRefresh = false; this.loadedStatus = null; this.generation = 0; this.controllers = new Set();
      if (this.endpoint.origin !== location.origin) { this.say(this.messages.loadError, true); this.list.setAttribute('aria-busy', 'false'); return; }
      root.querySelector('[data-pending-refresh]').addEventListener('click', () => this.load());
      this.status.addEventListener('change', () => this.load());
      this.filters.forEach(select => select.addEventListener('change', () => this.load()));
      this.load(false);
    }
    button(text, action, primary = false) {
      const button = node('button', text, primary ? 'certloom-account__primary' : '');
      button.type = 'button'; button.disabled = this.busy; button.addEventListener('click', action); return button;
    }
    say(text, error = false) { this.feedback.textContent = text; this.feedback.dataset.error = String(error); }
    setBusy(busy) {
      this.busy = busy; this.list.setAttribute('aria-busy', String(busy));
      this.root.querySelectorAll('button, select').forEach(el => { el.disabled = busy || (this.mustRefresh && el.hasAttribute('data-request-command')) || Boolean(el.closest('[data-request-history][aria-busy="true"]')); });
    }
    url(suffix = '') {
      const url = new URL(this.endpoint);
      if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + suffix);
      else url.pathname = url.pathname.replace(/\/$/, '') + suffix;
      return url;
    }
    async request(method, suffix = '', body = null, query = {}) {
      const url = this.url(suffix); Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, String(value)));
      const controller = new AbortController(); this.controllers.add(controller);
      const timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(url, { method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
          headers: { 'X-WP-Nonce': this.root.dataset.nonce, 'Content-Type': 'application/json', 'Accept': 'application/json' },
          ...(body ? { body: JSON.stringify(body) } : {}) });
        const model = await response.json();
        if (!response.ok) { const error = new Error(model.message || this.messages.loadError); error.status = response.status; throw error; }
        return model;
      } finally { clearTimeout(timer); this.controllers.delete(controller); }
    }
    async load(announce = true, more = false, preserveMessage = false) {
      if (this.busy) return;
      if (this.mustRefresh) more = false;
      const generation = ++this.generation;
      this.controllers.forEach(controller => controller.abort());
      this.setBusy(true); if (!preserveMessage) this.say('');
      // Independent reads start together; no Program -> queue -> health waterfall.
      const programPage = !this.programsLoaded ? this.loadProgramPage() : Promise.resolve();
      const healthRequest = !more ? this.request('GET', '/health').then(model => ({ model }), error => ({ error })) : null;
      try {
        const cursor = more ? this.cursor : null;
        const selected = Object.fromEntries(this.filters.map(select => [select.dataset.pendingFilter, select.value]));
        const model = await this.request('GET', '', null, { status: this.status.value, ...selected, ...(cursor ? { cursor_id: cursor.id, cursor_due: cursor.due || '', as_of: this.asOf } : {}) });
        if (!Array.isArray(model.items) || model.items.length > 50 || typeof model.has_more !== 'boolean' || !model.filters || typeof model.filters.as_of !== 'string' || !Number.isInteger(model.scanned) || model.scanned < 0 || model.scanned > 200 || (model.has_more && (!model.next_cursor || !Number.isSafeInteger(model.next_cursor.id) || model.next_cursor.id < 1))) throw new Error(this.messages.loadError);
        if (generation !== this.generation) return;
        this.items = more ? this.items.concat(model.items.filter(item => !this.items.some(old => old.id === item.id))) : model.items;
        this.cursor = model.has_more ? model.next_cursor : null; this.loadedStatus = this.status.value; this.loadedFilters = selected; this.asOf = model.filters.as_of; this.mustRefresh = false; this.render();
        if (more) {
          const first = model.items.length && Array.from(this.list.querySelectorAll('[data-request-id]')).find(card => Number(card.dataset.requestId) === model.items[0].id);
          const target = first ? first.querySelector('h2') : this.pagination.querySelector('button');
          if (target) { target.tabIndex = target.tagName === 'BUTTON' ? 0 : -1; target.focus({ preventScroll: true }); }
        }
        if (!more) {
          try { const health = await healthRequest; if (health.error) throw health.error; this.renderHealth(health.model); }
          catch (error) { this.health.replaceChildren(node('p', this.messages.healthError)); this.repairActions.replaceChildren(); }
        }
        if (announce && !preserveMessage) this.say(this.messages.loaded);
      } catch (error) { if (generation === this.generation) { if (this.loadedStatus) this.status.value = this.loadedStatus; if (this.loadedFilters) this.filters.forEach(select => { select.value = this.loadedFilters[select.dataset.pendingFilter]; }); this.say(error.status ? error.message : this.messages.loadError, true); if (!more) { this.health.replaceChildren(node('p', this.messages.healthError)); this.repairActions.replaceChildren(); } } }
      finally { await programPage; if (generation === this.generation) this.setBusy(false); }
    }
    async loadProgramPage() {
      try {
        const model = await this.request('GET', '/programs', null, { after_id: this.programCursor });
        if (!Array.isArray(model.items) || model.items.length > 50 || typeof model.has_more !== 'boolean' || !model.items.every(item => Number.isSafeInteger(item.id) && item.id > 0 && typeof item.label === 'string') || (model.has_more && (!model.items.length || model.next_id !== model.items[model.items.length - 1].id))) throw new Error(this.messages.programError);
        model.items.forEach(item => { if (!Array.from(this.program.options).some(option => option.value === String(item.id))) { const option = node('option', item.label); option.value = item.id; this.program.append(option); } });
        this.programsLoaded = true; this.programCursor = model.next_id; this.programMore.replaceChildren();
        if (model.has_more) this.programMore.append(this.button(this.messages.programMore, () => this.morePrograms()));
      } catch (error) { this.programMore.replaceChildren(node('p', this.messages.programError), this.button(this.messages.programMore, () => this.morePrograms())); }
    }
    async morePrograms() {
      if (this.busy) return;
      this.setBusy(true);
      try { await this.loadProgramPage(); } finally { this.setBusy(false); this.program.focus(); }
    }
    date(value) { const date = new Date(value.replace(' ', 'T') + 'Z'); return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(); }
    renderHealth(model) {
      const bounded = count => count && Number.isInteger(count.count) && count.count >= 0 && count.count <= 50 && typeof count.has_more === 'boolean';
      if (!bounded(model.due) || !model.jobs || !['queued', 'running', 'paused', 'failed'].every(key => bounded(model.jobs[key])) || !model.cron || typeof model.configuration_pending !== 'boolean' || typeof model.can_repair !== 'boolean') throw new Error(this.messages.healthError);
      this.health.replaceChildren(); this.repairActions.replaceChildren();
      const stats = node('dl', undefined, 'certloom-pending__health-stats');
      const add = (label, count) => { const pair = node('div'); pair.append(node('dt', label), node('dd', String(count.count) + (count.has_more ? '+' : ''))); stats.append(pair); };
      add(this.messages.dueChecks, model.due);
      ['queued', 'running', 'paused', 'failed'].forEach(key => add(this.messages[`${key}Checks`], model.jobs[key]));
      this.health.append(stats, node('p', model.configuration_pending ? this.messages.configurationQueued : this.messages.configurationClear));
      this.health.append(node('p', model.cron.traffic_enabled ? this.messages.cronTraffic : this.messages.cronExternal));
      if (model.cron.next_watchdog === null) this.health.append(node('p', this.messages.watchdogMissing));
      else if (model.cron.lag_seconds > 300) this.health.append(node('p', this.messages.watchdogLate));
      if (model.app_connections_need_review) this.health.append(node('p', this.messages.appReview));
      this.health.append(node('small', `${this.messages.checkedAt}: ${this.date(model.as_of)}`));
      if (model.can_repair) this.repairActions.append(this.button(this.messages.repair, () => this.repairSchedule()));
    }
    async repairSchedule() {
      if (this.busy) return;
      this.setBusy(true); this.say('');
      try {
        const receipt = await this.request('POST', '/repair-schedule', { confirm: true });
        if (receipt.outcome !== 'scheduled' || !Number.isInteger(receipt.checks_queued) || receipt.checks_queued < 0 || receipt.checks_queued > 2) throw new Error(this.messages.actionError);
        this.say(receipt.checks_queued ? this.messages.repaired : this.messages.repairEmpty);
      } catch (error) { this.say(error.status ? error.message : this.messages.actionError, true); }
      finally { this.setBusy(false); }
      await this.load(false, false, true);
      this.feedback.tabIndex = -1; this.feedback.focus({ preventScroll: true });
    }
    render() {
      this.list.replaceChildren(); this.pagination.replaceChildren();
      const filtered = this.loadedFilters && Object.entries(this.loadedFilters).some(([key, value]) => value !== (key === 'program_id' ? '0' : 'all'));
      if (!this.items.length) this.list.append(node('p', filtered ? (this.cursor ? this.messages.filteredEmpty : this.messages.filteredDone) : this.messages.empty, 'certloom-account__empty'));
      else { const grid = node('div', undefined, 'certloom-pending__list'); this.items.forEach(item => grid.append(this.card(item))); this.list.append(grid); }
      if (this.cursor) {
        if (filtered) this.pagination.append(node('p', this.messages.searchContinues));
        this.pagination.append(this.button(filtered ? this.messages.continueSearch : this.messages.more, () => this.load(true, true)));
      }
    }
    card(item) {
      const card = node('article', undefined, 'certloom-pending__card'); card.dataset.requestId = item.id;
      const top = node('div', undefined, 'certloom-pending__top'); const heading = node('h2', item.recipient);
      top.append(heading, node('span', item.status_label, 'certloom-pending__status'));
      card.append(top, node('p', item.program), node('p', item.notice));
      const meta = node('div', undefined, 'certloom-pending__meta');
      meta.append(node('span', `${this.messages.request} #${item.id}`), node('span', `${this.messages.created}: ${this.date(item.created_at)}`), node('span', `${this.messages.updated}: ${this.date(item.updated_at)}`)); card.append(meta);
      if (item.blockers.length) {
        const list = node('ul', undefined, 'certloom-pending__blockers');
        item.blockers.forEach(blocker => {
          const li = node('li'); li.append(node('span', `${this.messages[blocker.owner] || this.messages.organization} · `, 'certloom-pending__owner'));
          if (blocker.field) li.append(node('strong', `${blocker.field}: `));
          li.append(node('span', blocker.message)); list.append(li);
        }); card.append(list);
      }
      const actions = node('div', undefined, 'certloom-pending__actions');
      if (item.can_recheck) { const recheck = this.button(this.messages.recheck, () => this.command(item, 'recheck'), true); recheck.dataset.requestCommand = ''; actions.append(recheck); }
      if (item.can_cancel) {
        const cancel = this.button(this.messages.cancel, () => {
          if (this.busy || card.querySelector('[data-cancel-confirmation]')) return;
          const confirmation = node('div', undefined, 'certloom-pending__confirmation'); confirmation.dataset.cancelConfirmation = '';
          const question = node('p', this.messages.confirmCancel); question.id = `certloom-cancel-${item.id}`;
          confirmation.setAttribute('role', 'group'); confirmation.setAttribute('aria-labelledby', question.id);
          const keep = this.button(this.messages.keep, () => { confirmation.remove(); cancel.focus(); });
          const confirm = this.button(this.messages.confirm, () => this.command(item, 'cancel')); confirm.dataset.requestCommand = '';
          confirmation.append(question, keep, document.createTextNode(' '), confirm); actions.after(confirmation); keep.focus();
        }); cancel.dataset.requestCommand = ''; actions.append(cancel);
      }
      if (actions.childNodes.length) card.append(actions);
      const details = node('details'); details.append(node('summary', this.messages.history));
      const history = node('div'); history.dataset.requestHistory = ''; details.append(history); let loaded = false; let loading = false;
      details.addEventListener('toggle', async () => { if (details.open && !loaded && !loading) { loading = true; try { loaded = await this.history(item, history); } finally { loading = false; } } });
      card.append(details); return card;
    }
    async history(item, container, after = 0) {
      const generation = this.generation; container.setAttribute('aria-busy', 'true');
      if (!after) container.replaceChildren(node('p', this.messages.historyLoading));
      container.querySelectorAll('button').forEach(button => { button.disabled = true; });
      try {
        const model = await this.request('GET', `/${item.id}/history`, null, { after_id: after });
        if (generation !== this.generation || !container.isConnected) return false;
        if (!Array.isArray(model.items) || model.id !== item.id) throw new Error(this.messages.loadError);
        if (!after) container.replaceChildren();
        container.querySelectorAll('button').forEach(button => button.remove());
        const list = container.querySelector('ol') || node('ol', undefined, 'certloom-pending__history');
        model.items.forEach(event => {
          const li = node('li', event.label); li.append(node('small', [this.date(event.at), event.actor, event.from && event.to ? `${event.from} → ${event.to}` : ''].filter(Boolean).join(' · '))); list.append(li);
        });
        if (!list.isConnected) container.append(list);
        if (!list.childNodes.length) container.append(node('p', this.messages.historyEmpty));
        if (model.has_more) container.append(this.button(this.messages.historyMore, () => this.history(item, container, model.next_id)));
        return true;
      } catch (error) {
        if (generation !== this.generation || !container.isConnected) return false;
        this.say(error.status ? error.message : this.messages.loadError, true);
        if (!after) container.replaceChildren();
        container.append(this.button(this.messages.history, () => this.history(item, container, after))); return false;
      } finally { if (container.isConnected && generation === this.generation) { container.setAttribute('aria-busy', 'false'); if (!this.busy) container.querySelectorAll('button').forEach(button => { button.disabled = false; }); } }
    }
    async command(item, action) {
      if (this.busy || this.mustRefresh) return;
      this.setBusy(true); this.say('');
      let refresh = false;
      try {
        const receipt = await this.request('POST', `/${item.id}/${action}`, { revision: item.revision });
        if (receipt.id !== item.id || !['queued', 'cancelled', 'already_issued'].includes(receipt.outcome)) throw new Error(this.messages.actionError);
        this.say(this.messages[receipt.outcome]); refresh = true;
      } catch (error) { this.mustRefresh = true; this.say(error.status ? error.message : this.messages.actionError, true); }
      finally { this.setBusy(false); }
      // No automatic POST retry: a lost response requires a current GET first.
      if (refresh) {
        await this.load(false, false, true);
        const target = this.list.querySelector(`[data-request-id="${item.id}"] h2`) || this.feedback;
        target.tabIndex = -1; target.focus({ preventScroll: true });
      }
    }
  }
  document.querySelectorAll('[data-certloom-pending]').forEach(root => new PendingRequests(root));
})();
