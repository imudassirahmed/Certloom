/* One-page Program editing. REST reads only; Program settings and staged batches share one save. */
(function () {
	'use strict';
	const clone = value => JSON.parse(JSON.stringify(value));
	const keyOf = item => item.id ? String(item.id) : item.key;
	class BatchDraft {
		constructor(items = [], limit = 25) { this.limit = limit; this.changes = new Map(items.map(item => [keyOf(item), clone(item)])); }
		get(item) { return { ...item, patch: { ...item.patch, ...(this.changes.get(keyOf(item)) || {}).patch } }; }
		stage(item, patch) {
			const key = keyOf(item), previous = this.changes.get(key);
			if (!previous && this.changes.size >= this.limit) { throw new Error('limit'); }
			this.changes.set(key, { id: item.id, key: item.key || key, revision: previous ? previous.revision : item.revision, patch: { ...(previous ? previous.patch : {}), ...clone(patch) } });
		}
		bulk(items, patch) {
			const keys = new Set([...this.changes.keys(), ...items.map(keyOf)]);
			if (keys.size > this.limit) { throw new Error('limit'); }
			items.forEach(item => { const merged = { ...this.get(item).patch, ...patch }; if (!BatchDraft.validDates(merged)) { throw new Error('dates'); } });
			items.forEach(item => this.stage(item, patch));
		}
		undo(key) { this.changes.delete(key); }
		serialize() { return JSON.stringify([...this.changes.values()]); }
		static validDates(patch) { return !patch.start_date || !patch.end_date || patch.end_date >= patch.start_date; }
	}
	if (typeof module !== 'undefined' && module.exports) { module.exports = { BatchDraft }; }
	if (typeof document === 'undefined') { return; }

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.querySelector('[data-certloom-program-page]');
		if (!root) { return; }
		const config = JSON.parse(root.querySelector('[data-program-config]').textContent), text = config.text;
		const find = selector => root.querySelector(selector), all = selector => [...root.querySelectorAll(selector)];
		const form = find('[data-certloom-entity-form="program"]'), payload = form.elements.program_batch_changes;
		let restored = []; try { restored = JSON.parse(payload.value); } catch (_) {}
		const draft = new BatchDraft(Array.isArray(restored) ? restored : [], config.limit), bases = new Map(), selected = new Set();
		const batchDialog = find('[data-program-batch-dialog]'), batchForm = find('[data-program-batch-form]'), bulkDialog = find('[data-program-bulk-dialog]');
		const programId = Number(root.dataset.programId), list = find('[data-program-batch-list]');
		let page = 1, total = 0, rows = [], current = null, navigation = [], search = '', sequence = 0, detailSequence = 0, saving = false, batchBaseline = '', keyboard = true;
		const original = new URLSearchParams(new FormData(form)).toString();
		const dirty = () => draft.changes.size > 0 || new URLSearchParams(new FormData(form)).toString() !== original;
		document.addEventListener('keydown', () => { keyboard = true; }, true);
		document.addEventListener('pointerdown', () => { keyboard = false; }, true);
		const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
		function open(dialog) { dialog.classList.toggle('has-pointer-motion', !keyboard && !reduced.matches); dialog.showModal(); }
		function close(dialog) { dialog.close(); dialog.classList.remove('has-pointer-motion'); }
		function feedback(message) { find('[data-program-feedback]').textContent = message; }
		function sync() { payload.value = draft.serialize(); payload.dispatchEvent(new Event('change', { bubbles: true })); feedback(draft.changes.size ? text.prepared : ''); render(); }
		async function read(params = {}) {
			const url = new URL(root.dataset.batchesUrl); Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, String(v)));
			const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } });
			const body = await response.json(); if (!response.ok) { throw new Error(body.message || text.failed); } return body;
		}
		function node(tag, className, label) { const element = document.createElement(tag); element.className = className || ''; if (label !== undefined) { element.textContent = label; } return element; }
		function visibleRows() {
			const added = [...draft.changes.values()].filter(item => !item.id).map(item => ({ ...item.patch, id: 0, key: item.key, recipient_count: 0 }));
			const matches = item => !search || `${item.name} ${item.code || ''}`.toLowerCase().includes(search.toLowerCase());
			return [...added.filter(matches), ...rows.map(item => ({ ...item, ...(draft.changes.get(String(item.id)) || {}).patch }))];
		}
		function render() {
			if (!list) { return; }
			list.replaceChildren();
			const items = visibleRows();
			items.forEach(item => {
				const key = keyOf(item), row = node('div', 'certloom-program-batch-row');
				const label = node('label', 'certloom-batch-select'), check = node('input'); check.type = 'checkbox'; check.checked = selected.has(key); check.setAttribute('aria-label', item.name);
				check.addEventListener('change', () => { check.checked ? selected.add(key) : selected.delete(key); selection(); }); label.append(check);
				const edit = node('button', 'certloom-batch-open'); edit.type = 'button'; edit.append(node('strong', '', item.name));
				const subtitle = [item.code, text.batchSummary.replace('%s', String(item.recipient_count || 0)), [true, 1, '1'].includes(item.active) ? '' : text.inactive].filter(Boolean).join(' · ');
				edit.append(node('span', '', subtitle)); edit.addEventListener('click', () => showBatch(key)); row.append(label, edit);
				if (draft.changes.has(key)) { const badge = node('span', 'certloom-batch-pending', text.pending); row.append(badge); const undo = node('button', 'button-link', item.id ? text.undo : text.remove); undo.type = 'button'; undo.addEventListener('click', () => { draft.undo(key); selected.delete(key); sync(); }); row.append(undo); }
				list.append(row);
			});
			if (!items.length) { list.append(node('p', 'certloom-batch-empty', search ? text.noResults : text.empty)); }
			find('[data-program-list-page]').textContent = `${page} / ${Math.max(1, Math.ceil(total / 25))}`;
			find('[data-program-list-previous]').disabled = page <= 1;
			find('[data-program-list-next]').disabled = page * 25 >= total;
			selection();
		}
		function selection() {
			if (!list) { return; }
			const keys = visibleRows().map(keyOf), count = keys.filter(key => selected.has(key)).length;
			const select = find('[data-program-select-page]'); select.checked = keys.length > 0 && count === keys.length; select.indeterminate = count > 0 && count < keys.length;
			find('[data-program-bulk-open]').disabled = selected.size === 0;
		}
		async function loadPage(nextPage = 1) {
			if (!list) { return; }
			const ticket = ++sequence; list.setAttribute('aria-busy', 'true'); feedback(text.loading);
			try { const result = programId ? await read({ page: nextPage, search }) : { items: [], total: 0, page: 1 }; if (ticket !== sequence) { return false; } rows = result.items; total = result.total; page = result.page; render(); feedback(draft.changes.size ? text.prepared : ''); return true; }
			catch (error) { if (ticket === sequence) { feedback(error.message); list.replaceChildren(); const retry = node('button', 'button', text.failed); retry.type = 'button'; retry.addEventListener('click', () => loadPage(nextPage)); list.append(retry); } return false; }
			finally { if (ticket === sequence) { list.setAttribute('aria-busy', 'false'); } }
		}
		async function baseFor(key) {
			if (bases.has(key)) { return bases.get(key); }
			if (draft.changes.has(key) && !draft.changes.get(key).id) { return draft.changes.get(key); }
			const item = await read({ batch_id: key }); bases.set(key, item); return item;
		}
		function selectValue(select, value, fallback) {
			value = value == null ? '' : String(value);
			if (![...select.options].some(option => option.value === value)) { const option = node('option', '', fallback); option.value = value; select.append(option); }
			select.value = value; select.dispatchEvent(new Event('change', { bubbles: true }));
		}
		function batchPatch() {
			const data = new FormData(batchForm), patch = {};
			['name', 'code', 'start_date', 'end_date', 'instructor_signer_id', 'batch_template_id'].forEach(name => { patch[name] = String(data.get(name) || '').trim(); });
			patch.active = batchForm.elements.active.checked;
			patch.batch_field_requirement = {};
			all('[data-program-batch-field]').forEach(select => { if (select.value) { patch.batch_field_requirement[select.dataset.programBatchField] = select.value; } });
			return patch;
		}
		function validBatch() {
			const end = batchForm.elements.end_date; end.setCustomValidity(BatchDraft.validDates(batchPatch()) ? '' : text.dates);
			return batchForm.reportValidity();
		}
		function applyCurrent() {
			if (!current || !validBatch()) { return false; }
			const patch = batchPatch();
			if (JSON.stringify(patch) === batchBaseline && current.id) { return true; }
			try { draft.stage(current, patch); batchBaseline = JSON.stringify(patch); sync(); return true; }
			catch (error) { find('[data-program-batch-error]').textContent = text[error.message] || error.message; return false; }
		}
		function protectBatchClose(event) {
			if (event) { event.preventDefault(); }
			if (current && JSON.stringify(batchPatch()) !== batchBaseline && !window.confirm(text.discard)) { return; }
			++detailSequence; close(batchDialog);
		}
		async function showBatch(key, direction = 0, keepNavigation = false) {
			const ticket = ++detailSequence;
			if (!keepNavigation) { navigation = visibleRows().map(keyOf); }
			if (!batchDialog.open) { open(batchDialog); }
			current = null; batchForm.querySelector('button[type="submit"]').disabled = true;
			find('[data-program-batch-error]').textContent = text.loading; find('[data-program-batch-body]').setAttribute('aria-busy', 'true'); find('[data-program-batch-body]').inert = true;
			all('[data-program-batch-previous], [data-program-batch-next]').forEach(button => { button.disabled = true; });
			try {
				const item = key ? await baseFor(key) : { id: 0, key: `new-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`, patch: { name: '', code: '', start_date: '', end_date: '', active: true, instructor_signer_id: '', batch_template_id: 0, batch_field_requirement: {} } };
				if (ticket !== detailSequence || !batchDialog.open) { return; }
				current = item; const effective = draft.get(item).patch;
				batchForm.reset();
				['name', 'code', 'start_date', 'end_date'].forEach(name => { batchForm.elements[name].value = effective[name] || ''; }); batchForm.elements.end_date.setCustomValidity('');
				selectValue(batchForm.elements.instructor_signer_id, effective.instructor_signer_id, text.unavailable);
				selectValue(batchForm.elements.batch_template_id, effective.batch_template_id || 0, text.unavailable); batchForm.elements.active.checked = [true, 1, '1'].includes(effective.active);
				all('[data-program-batch-field]').forEach(select => {
					select.value = (effective.batch_field_requirement || {})[select.dataset.programBatchField] || '';
					const programSelect = [...form.querySelectorAll('[name^="program_field_requirement["]')].find(field => field.name === `program_field_requirement[${select.dataset.programBatchField}]`);
					[...select.options].forEach(option => { option.disabled = !!programSelect && programSelect.value === 'required' && ['optional', 'exclude'].includes(option.value); });
				});
				find('[data-program-batch-members-body]').replaceChildren(); find('[data-program-batch-members]').hidden = !item.id;
				find('[data-program-batch-error]').textContent = ''; find('#certloom-batch-dialog-title').textContent = item.id || key ? effective.name || text.edit : text.add;
				const position = navigation.indexOf(keyOf(item)); find('[data-program-batch-position]').textContent = position >= 0 ? `${position + 1} / ${navigation.length}` : (item.id ? text.edit : text.new);
				find('[data-program-batch-previous]').disabled = position < 0 || position === 0 && page <= 1; find('[data-program-batch-next]').disabled = position < 0 || position >= navigation.length - 1 && page * 25 >= total;
				batchBaseline = JSON.stringify(batchPatch()); batchForm.querySelector('button[type="submit"]').disabled = false;
				if (direction && !keyboard && !reduced.matches) { find('[data-program-batch-body]').animate([{ opacity: 0, transform: `translateX(${direction * 12}px)` }, { opacity: 1, transform: 'translateX(0)' }], { duration: 160, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' }); }
				find('[data-program-batch-body]').inert = false;
				if (direction) { batchForm.elements.name.focus({ preventScroll: true }); }
			} catch (error) { if (ticket === detailSequence) { find('[data-program-batch-error]').textContent = error.message; } }
			finally { if (ticket === detailSequence) { find('[data-program-batch-body]').setAttribute('aria-busy', 'false'); find('[data-program-batch-body]').inert = false; } }
		}
		if (batchDialog) {
			batchForm.addEventListener('input', () => { batchForm.elements.end_date.setCustomValidity(''); });
			find('[data-program-add-batch]').addEventListener('click', () => showBatch(null));
			find('[data-program-batch-close]').addEventListener('click', protectBatchClose); batchDialog.addEventListener('cancel', protectBatchClose);
			batchForm.addEventListener('submit', event => { event.preventDefault(); if (applyCurrent()) { close(batchDialog); find('[data-program-add-batch]').focus({ preventScroll: true }); } });
			[-1, 1].forEach(direction => { find(direction < 0 ? '[data-program-batch-previous]' : '[data-program-batch-next]').addEventListener('click', async () => {
				if (!applyCurrent()) { return; }
				const position = navigation.indexOf(keyOf(current)) + direction;
				if (navigation[position]) { showBatch(navigation[position], direction, true); return; }
				if (direction < 0 && page <= 1 || direction > 0 && page * 25 >= total) { return; }
				all('[data-program-batch-previous], [data-program-batch-next]').forEach(button => { button.disabled = true; });
				if (!await loadPage(page + direction)) { find('[data-program-batch-error]').textContent = text.failed; all('[data-program-batch-previous], [data-program-batch-next]').forEach(button => { button.disabled = false; }); return; }
				navigation = rows.map(keyOf); const next = direction > 0 ? navigation[0] : navigation[navigation.length - 1];
				if (next && batchDialog.open) { showBatch(next, direction, true); }
			}); });
			find('[data-program-batch-members]').addEventListener('click', async () => {
				if (!current || !current.id) { return; }
				if (dirty() || JSON.stringify(batchPatch()) !== batchBaseline) { find('[data-program-batch-error]').textContent = text.saveMembers; return; }
				const ticket = detailSequence, batchId = current.id;
				try { const result = await read({ batch_id: batchId, members: true }); if (ticket === detailSequence && batchDialog.open) { const container = find('[data-program-batch-members-body]'); const fragment = document.createElement('template'); fragment.innerHTML = result.html; container.replaceChildren(fragment.content); } }
				catch (error) { find('[data-program-batch-error]').textContent = error.message; }
			});
			const bulkForm = find('[data-program-bulk-form]');
			all('[data-program-bulk-enable]').forEach(toggle => { const update = () => { find(`[data-program-bulk-value="${toggle.dataset.programBulkEnable}"]`).querySelectorAll('input,select').forEach(input => { input.disabled = !toggle.checked; }); }; toggle.addEventListener('change', update); update(); });
			find('[data-program-bulk-open]').addEventListener('click', () => { find('[data-program-bulk-count]').textContent = text.selected.replace('%s', String(selected.size)); find('[data-program-bulk-error]').textContent = ''; open(bulkDialog); });
			find('[data-program-bulk-close]').addEventListener('click', () => close(bulkDialog));
			bulkForm.addEventListener('submit', async event => {
				event.preventDefault(); const patch = {}, button = bulkForm.querySelector('[type="submit"]');
				if (selected.size > config.limit) { find('[data-program-bulk-error]').textContent = text.limit; return; }
				all('[data-program-bulk-enable]:checked').forEach(toggle => { const name = toggle.dataset.programBulkEnable; patch[name] = bulkForm.elements[name].value; });
				if (!Object.keys(patch).length) { find('[data-program-bulk-error]').textContent = text.fields; return; }
				button.disabled = true; find('[data-program-bulk-error]').textContent = text.loading;
				try { const items = await Promise.all([...selected].map(baseFor)); if (!bulkDialog.open) { return; } draft.bulk(items, patch); sync(); close(bulkDialog); }
				catch (error) { find('[data-program-bulk-error]').textContent = text[error.message] || error.message; }
				finally { button.disabled = false; }
			});
			find('[data-program-select-page]').addEventListener('change', event => { visibleRows().forEach(item => event.target.checked ? selected.add(keyOf(item)) : selected.delete(keyOf(item))); render(); });
			find('[data-program-list-previous]').addEventListener('click', () => loadPage(page - 1)); find('[data-program-list-next]').addEventListener('click', () => loadPage(page + 1));
			let timer; find('[data-program-batch-search]').addEventListener('input', event => { clearTimeout(timer); search = event.target.value.trim(); timer = setTimeout(() => { selected.clear(); loadPage(1); }, 220); });
			loadPage().then(() => { if (config.initialBatch) { showBatch(String(config.initialBatch)); } else if (config.initialNew) { showBatch(null); } });
		}

		all('[data-program-settings-open]').forEach(button => { button.addEventListener('click', () => open(find(`[data-program-settings="${button.dataset.programSettingsOpen}"]`))); });
		all('[data-program-settings]').forEach(dialog => {
			function done(event) { if (event) { event.preventDefault(); } const invalid = [...dialog.querySelectorAll('input,select,textarea')].find(input => !input.disabled && !input.checkValidity()); if (invalid) { invalid.reportValidity(); return; } close(dialog); }
			dialog.querySelectorAll('[data-program-settings-done]').forEach(button => button.addEventListener('click', done)); dialog.addEventListener('cancel', done);
		});
		form.addEventListener('invalid', event => { const dialog = event.target.closest('[data-program-settings]'); if (dialog && !dialog.open) { open(dialog); } }, true);
		form.addEventListener('submit', event => { if (!event.defaultPrevented && form.checkValidity()) { saving = true; } });
		window.addEventListener('beforeunload', event => { if (!saving && (dirty() || current && batchDialog.open && JSON.stringify(batchPatch()) !== batchBaseline)) { event.preventDefault(); event.returnValue = ''; } });
		let previewSequence = 0;
		const previewContainer = find('[data-program-design-preview-body]');
		function fitPreview() {
			const canvas = previewContainer.querySelector('.certloom-template-preview-scroll');
			if (!canvas || !previewContainer.clientWidth) { return; }
			if (window.CertloomTextFit) { window.CertloomTextFit.refresh(canvas); }
			const width = Number(canvas.dataset.certloomPreviewWidth), height = Number(canvas.dataset.certloomPreviewHeight);
			if (!width || !height) { return; }
			const scale = Math.min((previewContainer.clientWidth - 24) / width, (previewContainer.clientHeight - 24) / height, 1);
			canvas.style.width = `${width}px`; canvas.style.height = `${height}px`;
			canvas.style.transform = `translate(-50%, -50%) scale(${Math.max(0.01, scale)})`;
		}
		if (window.ResizeObserver) { new ResizeObserver(fitPreview).observe(previewContainer); }
		else { window.addEventListener('resize', fitPreview); }
		find('[data-program-design-preview]').addEventListener('click', async () => {
			const ticket = ++previewSequence, container = previewContainer, url = new URL(config.previewUrl), id = Number(form.elements.program_template_id.value);
			url.searchParams.set('mode', id ? 'assigned' : 'inherited'); url.searchParams.set('template_id', String(id));
			if (!id) { url.searchParams.set('issuer_id', form.elements.issuer_id ? form.elements.issuer_id.value : '0'); }
			container.textContent = text.loading;
			try {
				const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } }), result = await response.json();
				if (!response.ok) { throw new Error(result.message || text.failed); } if (ticket !== previewSequence) { return; }
				// This authenticated endpoint returns the canonical escaped RenderPlanHtmlPreview fragment.
				const fragment = document.createElement('template'); fragment.innerHTML = String(result.html || '');
				container.replaceChildren(fragment.content); fitPreview();
			}
			catch (error) { if (ticket === previewSequence) { container.textContent = error.message; } }
		});
	});
})();
