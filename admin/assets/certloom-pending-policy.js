/* Organization policy has its own optimistic save, independent of queue pagination. */
(() => {
  'use strict';
  const initialize = () => document.querySelectorAll('[data-certloom-pending]:not([data-attention-deferred])').forEach(root => {
    if (root.dataset.policyReady) return; root.dataset.policyReady = '1';
    const panel = root.querySelector('[data-pending-policy]');
    if (!panel) return;
    const fields = [...panel.querySelectorAll('[data-policy-key]')];
    const save = panel.querySelector('[data-policy-save]');
    const refresh = panel.querySelector('[data-policy-refresh]');
    const feedback = panel.querySelector('[data-policy-feedback]');
    let hash = null, editable = false, busy = false, conflict = false;
    const state = on => {
      busy = on; panel.querySelector('[data-policy-fields]').setAttribute('aria-busy', String(on));
      fields.forEach(field => { field.disabled = on || !editable; });
      save.disabled = on || !editable || !hash || conflict; refresh.disabled = on;
    };
    const request = async (method, body) => {
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(`${root.dataset.endpoint}/policy`, { method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal, headers: { 'X-WP-Nonce': root.dataset.nonce, 'Content-Type': 'application/json' }, ...(body ? { body: JSON.stringify(body) } : {}) });
        const result = await response.json();
        if (!response.ok) { const error = new Error(result.message || feedback.dataset.error); error.status = response.status; throw error; }
        if (!result.policy || result.policy.version !== 1 || !/^[a-f0-9]{64}$/.test(result.sha256) || typeof result.can_edit !== 'boolean') throw new Error(feedback.dataset.error);
        return result;
      } finally { clearTimeout(timer); }
    };
    const load = async () => {
      if (busy) return; state(true); feedback.textContent = feedback.dataset.loading;
      try {
        const model = await request('GET'); hash = model.sha256; editable = model.can_edit; conflict = false;
        fields.forEach(field => { if (field.type === 'checkbox') field.checked = model.policy[field.dataset.policyKey]; else field.value = model.policy[field.dataset.policyKey]; });
        feedback.textContent = '';
      } catch (error) { feedback.textContent = error.message || feedback.dataset.error; }
      finally { state(false); }
    };
    save.addEventListener('click', async () => {
      if (busy || !editable || conflict) return;
      const invalid = fields.find(field => !field.checkValidity());
      if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
      const policy = Object.fromEntries(fields.map(field => [field.dataset.policyKey, field.type === 'checkbox' ? field.checked : Number(field.value)])); policy.version = 1;
      state(true);
      try { const model = await request('POST', { policy, sha256: hash }); hash = model.sha256; feedback.textContent = feedback.dataset.saved; }
      catch (error) { conflict = error.status === 409; feedback.textContent = error.message || feedback.dataset.error; }
      finally { state(false); (conflict ? refresh : save).focus({ preventScroll: true }); }
    });
    refresh.addEventListener('click', load);
    panel.addEventListener('toggle', () => { if (panel.open && !hash) load(); });
  });
  initialize(); document.addEventListener('certloom:attention-ready', initialize);
})();
