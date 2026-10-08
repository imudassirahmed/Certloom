(function () {
	'use strict';
	var cfg = window.certloomIssueCredential || {}, popup = document.querySelector('[data-program-issuance]');
	if (!popup) { return; }
	var originalForm = popup.querySelector('form').cloneNode(true), app, contextKey = '', opener;
	var recoveryKey = 'certloom-issuance:' + String(cfg.actorId || 0), recovery;
	function text(key, fallback) { return cfg.i18n && cfg.i18n[key] || fallback; }
	function readRecovery() {
		try { var value = JSON.parse(sessionStorage.getItem(recoveryKey)); return value && /^[a-f0-9]{64}$/.test(value.fingerprint) && value.deep && value.payload && value.payload.use_program_settings === true && value.payload.issuance && value.review && value.review.recipient && value.review.program && value.review.template && value.review.signer ? value : null; } catch (_) { return null; }
	}
	function saveRecovery(value) { try { if (value) { sessionStorage.setItem(recoveryKey, JSON.stringify(value)); } else { sessionStorage.removeItem(recoveryKey); } } catch (_) { /* The in-memory review remains available when tab storage is disabled. */ } }
	function position() {
		var bar = document.getElementById('wpadminbar');
		popup.style.setProperty('--cl-pi-top', (bar ? Math.max(0, bar.getBoundingClientRect().bottom) : 0) + 'px');
	}
	function rest(url, options) {
		options = options || {};
		return fetch(url, {method:options.body ? 'POST' : 'GET', credentials:'same-origin', cache:'no-store', signal:options.signal, headers:{'X-WP-Nonce':cfg.restNonce, 'Content-Type':'application/json'}, body:options.body ? JSON.stringify(options.body) : undefined}).then(function (response) {
			return response.json().catch(function () { throw {message:text('loadFailed', 'The server response could not be read.'), uncertain:true}; }).then(function (data) {
				if (!response.ok) { throw {message:data.message || text('loadFailed', 'Try again.'), code:data.code, status:response.status, data:data.data || {}, uncertain:response.status >= 500}; }
				return data;
			});
		});
	}
	function createApp(deep, pending) {
		var form = popup.querySelector('form'), select = form.querySelector('[data-pi-select]'), reviewPanel = form.querySelector('[data-pi-review]'), receiptPanel = form.querySelector('[data-pi-receipt]');
		var next = form.querySelector('[data-pi-next]'), back = form.querySelector('[data-pi-back]'), status = form.querySelector('[data-pi-status]'), message = form.querySelector('[data-pi-message]'), help = form.querySelector('[data-pi-help]');
		var state = {deep:Object.assign({}, deep), settings:null, recipient:null, mode:'existing', stage:'select', busy:false, loading:false, failedLoad:false, fingerprint:'', payload:null, review:null, uncertain:false, complete:false, generation:0};
		var disposed = false, settingsController, searches = {}, timers = {}, enrollmentController;
		function node(selector) { return form.querySelector(selector); }
		function notify(copy, error) { message.textContent = copy || ''; message.hidden = !copy; message.classList.toggle('is-error', !!error); }
		function paint() {
			select.hidden = state.stage !== 'select'; reviewPanel.hidden = state.stage !== 'review'; receiptPanel.hidden = state.stage !== 'receipt';
			select.inert = state.busy || state.uncertain || state.loading;
			back.hidden = state.stage !== 'review'; back.disabled = state.busy || state.uncertain;
			next.hidden = state.complete; next.disabled = state.busy || state.loading;
			node('[data-pi-another]').hidden = !state.complete;
			next.textContent = state.uncertain ? text('retryIssue','Check issuance result') : state.failedLoad ? text('retryLoading','Retry loading') : state.stage === 'review' ? text('confirmIssue','Issue credential') : text('reviewCredential','Review credential');
			popup.querySelector('[data-pi-progress]').textContent = text(state.complete ? 'finishedStep' : state.stage === 'review' ? 'reviewStep' : 'recipientStep', state.complete ? 'Complete' : state.stage === 'review' ? '2 · Review' : '1 · Recipient');
			popup.setAttribute('aria-busy', String(state.busy || state.loading));
		}
		function clearEnrollment() { if (enrollmentController) { enrollmentController.abort(); } node('[data-pi-enrollment]').hidden = true; node('#cl-pi-enrollment').replaceChildren(new Option(text('enrollmentRequired','Choose enrollment…'),'')); }
		function renderRecipientMode(focus) {
			node('[data-pi-new]').hidden = state.mode !== 'new'; picker('recipient').hidden = state.mode !== 'existing';
			form.querySelectorAll('[data-pi-mode]').forEach(function (button) { button.setAttribute('aria-pressed',String(button.dataset.piMode === state.mode)); });
			if (focus) { (state.mode === 'new' ? form.elements.display_name : picker('recipient').querySelector('input')).focus(); }
		}
		function changed() { state.generation++; state.fingerprint = ''; state.payload = null; state.review = null; clearEnrollment(); help.hidden = true; notify(''); }
		function picker(kind) { return node('[data-pi-picker="' + kind + '"]'); }
		function hideResults(kind) { var box = picker(kind); box.querySelector('[role="listbox"]').hidden = true; box.querySelector('input').setAttribute('aria-expanded','false'); box.querySelector('input').removeAttribute('aria-activedescendant'); }
		function selected(kind, choice) {
			changed(); hideResults(kind);
			var box = picker(kind), input = box.querySelector('input'), clear = box.querySelector('[data-pi-clear]');
			input.value = choice.label || choice.name || ''; input.readOnly = true; if (clear) { clear.hidden = false; }
			box.querySelector('[data-pi-search-status]').textContent = kind === 'recipient' ? text('chosenRecipient','Selected recipient') : '';
			if (kind === 'recipient') { state.recipient = choice; state.deep.recipient_id = Number(choice.id); }
			else if (kind === 'program') { state.deep.program_id = Number(choice.id); state.deep.batch_id = 0; loadSettings(); }
			else { state.deep.batch_id = Number(choice.id); input.readOnly = false; box.hidden = true; loadSettings(); }
		}
		function clearChoice(kind) {
			changed(); var box = picker(kind), input = box.querySelector('input'); input.value = ''; input.readOnly = false; var clear = box.querySelector('[data-pi-clear]'); if (clear) { clear.hidden = true; }
			box.querySelector('[data-pi-search-status]').textContent = '';
			if (kind === 'recipient') { state.recipient = null; state.deep.recipient_id = 0; }
			else { state.deep.program_id = 0; state.deep.batch_id = 0; state.settings = null; renderSettings(); }
			input.focus(); search(kind, '', true);
		}
		function search(kind, query, immediate) {
			if (disposed || state.busy || state.uncertain) { return; }
			clearTimeout(timers[kind]); if (searches[kind]) { searches[kind].abort(); }
			var controller = new AbortController(); searches[kind] = controller;
			var box = picker(kind), input = box.querySelector('input'); hideResults(kind);
			box.querySelector('[data-pi-search-status]').textContent = text('searching','Searching…');
			timers[kind] = setTimeout(function () {
				var url = new URL(cfg.contextUrl); url.searchParams.set('kind',kind); url.searchParams.set('query',query); url.searchParams.set('program_id',String(state.deep.program_id || 0));
				rest(url.toString(), {signal:controller.signal}).then(function (data) {
					if (disposed || controller.signal.aborted || searches[kind] !== controller || input.readOnly) { return; }
					renderChoices(kind, data.choices || []);
				}).catch(function (error) { if (!disposed && !controller.signal.aborted && searches[kind] === controller) { box.querySelector('[data-pi-search-status]').textContent = error.message || text('loadFailed','Search failed. Try again.'); } });
			}, immediate ? 0 : 220);
		}
		function renderChoices(kind, rows) {
			var box = picker(kind), list = box.querySelector('[role="listbox"]'), input = box.querySelector('input'), page = 0, active = -1;
			box.querySelector('[data-pi-search-status]').textContent = rows.length ? String(rows.length) + ' ' + text('matches','matches') : text('noMatches','No matching choices.');
			function draw() {
				list.replaceChildren(); var first = page * 4;
				rows.slice(first,first + 4).forEach(function (choice, index) {
					var button = document.createElement('button'); button.type = 'button'; button.id = 'cl-pi-option-' + kind + '-' + (first + index); button.setAttribute('role','option'); button.setAttribute('aria-selected',String(first + index === active)); button.tabIndex = -1;
					button.textContent = choice.label + (kind === 'recipient' ? ' · #' + choice.id : ''); button.addEventListener('click',function () { selected(kind,choice); }); list.appendChild(button);
				});
				if (rows.length > 4) {
					var nav = document.createElement('div'); nav.className = 'cl-pi-result-nav'; nav.setAttribute('role','group');
					[[-1,'pagePrevious','←'],[1,'pageNext','→']].forEach(function (item) { var button = document.createElement('button'); button.type = 'button'; button.textContent = item[2]; button.setAttribute('aria-label',text(item[1],item[1])); button.disabled = item[0] < 0 ? page === 0 : (page + 1) * 4 >= rows.length; button.addEventListener('click',function () { page += item[0]; active = -1; input.removeAttribute('aria-activedescendant'); draw(); input.focus(); }); nav.appendChild(button); });
					var count = document.createElement('span'); count.textContent = (page + 1) + ' / ' + Math.ceil(rows.length / 4); nav.insertBefore(count,nav.lastChild); list.appendChild(nav);
				}
				list.hidden = !rows.length; input.setAttribute('aria-expanded',String(!!rows.length));
			}
			box._move = function (delta) { if (!rows.length) { return; } active = (active + delta + rows.length) % rows.length; page = Math.floor(active / 4); draw(); input.setAttribute('aria-activedescendant','cl-pi-option-' + kind + '-' + active); };
			box._choose = function () { if (active >= 0) { selected(kind,rows[active]); return true; } return false; }; draw();
		}
		function renderSettings() {
			var settings = state.settings, context = node('[data-pi-context]'); context.hidden = !settings;
			picker('program').hidden = !!settings;
			node('[data-pi-program-change]').hidden = !!deep.program_id;
			node('[data-pi-batch-line]').hidden = !settings || !!deep.batch_id || (!settings.has_batches && !settings.batch_required);
			node('[data-pi-expiry]').hidden = !settings || !settings.expiry_at_issuance;
			if (!settings) { picker('batch').hidden = true; return; }
			node('[data-pi-expiry-hint]').textContent = settings.expiry_required ? text('expiryRequired','This Program requires an expiry date for renewal.') : text('expiryOptional','This Program leaves expiry to each issuance. Blank means no expiry.');
			node('[data-pi-context-title]').textContent = settings.program.label;
			node('[data-pi-context-batch]').textContent = settings.batch ? settings.batch.label : '';
			node('[data-pi-manage]').href = settings.program_url;
			node('[data-pi-batch-label]').textContent = settings.batch ? settings.batch.label : text('programDefaults','Use Program settings');
			node('[data-pi-batch-clear]').hidden = !settings.batch;
			node('[data-pi-batch-open]').textContent = settings.batch ? text('changeBatch','Change Batch') : text('chooseBatch','Choose Batch');
		}
		function loadSettings() {
			if (settingsController) { settingsController.abort(); }
			settingsController = new AbortController(); var controller = settingsController;
			state.loading = true; state.failedLoad = false; status.textContent = text('currentSettings','Checking current settings…'); paint();
			var url = new URL(cfg.programBootstrapUrl); ['program_id','batch_id','recipient_id'].forEach(function (key) { if (state.deep[key]) { url.searchParams.set(key,String(state.deep[key])); } });
			rest(url.toString(), {signal:controller.signal}).then(function (data) {
				if (disposed || controller.signal.aborted || controller !== settingsController) { return; }
				state.settings = data.settings; state.recipient = data.recipient || state.recipient;
				if (data.recipient) { var box = picker('recipient'); box.querySelector('input').value = data.recipient.label; box.querySelector('input').readOnly = true; box.querySelector('[data-pi-clear]').hidden = false; }
				node('[data-pi-number]').hidden = data.automatic_number; renderSettings(); notify('');
			}).catch(function (error) { if (!disposed && !controller.signal.aborted && controller === settingsController) { state.failedLoad = true; notify(error.message || text('loadFailed','Could not load settings.'),true); } }).finally(function () { if (!disposed && !controller.signal.aborted && controller === settingsController) { state.loading = false; status.textContent = ''; paint(); } });
		}
		function renderReview(data) {
			var recipient = data.recipient || {};
			node('[data-pi-recipient-name]').textContent = recipient.name;
			node('[data-pi-recipient-email]').textContent = recipient.email || '';
			node('[data-pi-initials]').textContent = String(recipient.name || '').trim().split(/\s+/).slice(0,2).map(function (part) { return Array.from(part)[0] || ''; }).join('').toUpperCase();
			var facts = node('[data-pi-facts]'); facts.replaceChildren();
			var rows = [[text('program','Program'),data.credential_title || data.program.title], [text('certificate','Certificate'), data.presentation_mode === 'design' ? data.template.name : text('detailsOnly','Details only')], [text('validity','Valid from / expiry'), data.valid_from + ' / ' + (data.expires_at || text('noExpiry','No expiry'))], [text('signing','Signing'), data.signer.name || text('configuredSigning','Uses configured signing rules')]];
			if (data.batch && data.batch.id) { rows.splice(1,0,[text('batch','Batch'),data.batch.name]); }
			rows.forEach(function (row) { var wrapper = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd'); dt.textContent = row[0]; dd.textContent = row[1]; wrapper.append(dt,dd); facts.appendChild(wrapper); });
		}
		function showHelp() {
			help.hidden = false; var customer = node('[data-pi-customer]'); customer.hidden = !state.deep.recipient_id; customer.href = cfg.recipientsUrl + '&recipient_id=' + (state.deep.recipient_id || 0);
			node('[data-pi-program]').hidden = !state.settings; if (state.settings) { node('[data-pi-program]').href = state.settings.program_url; }
		}
		function loadEnrollments() {
			if (enrollmentController) { enrollmentController.abort(); } enrollmentController = new AbortController(); var controller = enrollmentController, generation = state.generation;
			var url = new URL(cfg.enrollmentsUrl); ['program_id','batch_id','recipient_id'].forEach(function (key) { url.searchParams.set(key,String(state.deep[key] || 0)); });
			state.loading = true; paint();
			return rest(url.toString(), {signal:controller.signal}).then(function (data) { if (disposed || controller.signal.aborted || generation !== state.generation) { return; } var select = node('#cl-pi-enrollment'); select.replaceChildren(new Option(text('enrollmentRequired','Choose enrollment…'),'')); (data.choices || []).forEach(function (row) { select.appendChild(new Option(row.label,String(row.id))); }); node('[data-pi-enrollment]').hidden = false; select.focus(); }).catch(function (error) { if (!disposed && !controller.signal.aborted && generation === state.generation) { notify(error.message,true); } }).finally(function () { if (!disposed && controller === enrollmentController) { state.loading = false; paint(); } });
		}
		function buildReview() {
			if (state.busy || state.loading || state.uncertain) { return; }
			if (!state.settings) { notify(text('selectProgram','Choose a Program to continue.'),true); picker('program').querySelector('input').focus(); return; }
			if (state.settings.batch_required && !state.deep.batch_id) { notify(text('batchRequired','Choose a Batch for this Program before reviewing.'),true); node('[data-pi-batch-open]').focus(); return; }
			if (state.settings.expiry_at_issuance && state.settings.expiry_required && !form.elements.expires_at.value) { notify(text('expiryRequired','This Program requires an expiry date for renewal.'),true); form.elements.expires_at.focus(); return; }
			if (state.mode === 'existing' && !state.deep.recipient_id) { notify(text('selectRecipient','Choose a recipient to continue.'),true); picker('recipient').querySelector('input').focus(); return; }
			if (state.mode === 'new' && !form.elements.display_name.value.trim()) { form.elements.display_name.setAttribute('aria-invalid','true'); form.elements.display_name.focus(); notify(text('newNameRequired','Enter the recipient name.'),true); return; }
			if (state.mode === 'new' && !form.elements.email.validity.valid) { form.elements.email.setAttribute('aria-invalid','true'); form.elements.email.focus(); notify(text('emailInvalid','Enter a valid email address.'),true); return; }
			var payload = {use_program_settings:true, issuance:{program_id:state.deep.program_id || 0, batch_id:state.deep.batch_id || 0, recipient_mode:state.mode, recipient_id:state.mode === 'existing' ? state.deep.recipient_id || 0 : 0, new_recipient:state.mode === 'new' ? {display_name:form.elements.display_name.value, email:form.elements.email.value} : {}, valid_from:state.settings.valid_from, display_number:form.elements.display_number.value, expires_at:form.elements.expires_at.value, registration_id:Number(node('#cl-pi-enrollment').value) || 0}};
			state.busy = true; notify(''); help.hidden = true; status.textContent = text('reviewing','Checking credential…'); var generation = state.generation; paint();
			rest(cfg.reviewUrl, {body:payload}).then(function (data) {
				if (disposed || generation !== state.generation) { return; }
				if (!/^[a-f0-9]{64}$/.test(data.review_fingerprint || '') || !data.review) { throw {message:text('loadFailed','Review could not be read.')}; }
				state.payload = payload; state.fingerprint = data.review_fingerprint; state.review = data.review; state.stage = 'review'; renderReview(data.review);
			}).catch(function (error) { if (disposed || generation !== state.generation) { return; } notify(error.message || text('loadFailed','Could not review credential.'),true); showHelp(); if (error.code === 'certloom_registration_selection_required') { loadEnrollments(); } }).finally(function () { if (!disposed) { state.busy = false; status.textContent = ''; paint(); if (state.stage === 'review') { next.focus(); } } });
		}
		function issueCredential() {
			if (state.busy || !state.fingerprint || !state.payload) { return; }
			var wasUncertain = state.uncertain;
			state.busy = true; notify(''); status.textContent = text('issuing','Issuing credential…'); paint();
			var record = {deep:state.deep, fingerprint:state.fingerprint, payload:state.payload, review:state.review}; saveRecovery(record);
			// Always retry the exact signed payload, including after a lost response.
			rest(cfg.issueUrl, {body:Object.assign({},state.payload,{review_fingerprint:state.fingerprint})}).then(function (data) {
				if (disposed) { return; }
				if (!data.receipt || !data.receipt.version_id || !data.receipt.verification_url || !data.continuations || !data.continuations.email_url) { throw {uncertain:true}; }
				state.uncertain = false; state.complete = true; state.stage = 'receipt'; saveRecovery(null); recovery = null;
				node('[data-pi-receipt-number]').textContent = data.receipt.display_number;
				node('[data-pi-view]').href = data.receipt.verification_url;
				node('[data-pi-email]').href = data.continuations.email_url;
			}).catch(function (error) {
				if (disposed) { return; }
				state.uncertain = (wasUncertain && [401,403,429].includes(error.status)) || (error.uncertain !== false && (!error.code || error.uncertain === true));
				if (state.uncertain) { recovery = record; notify(text('uncertainCommit','The response was interrupted. Retry the same issuance to check its result safely.'),true); }
				else { saveRecovery(null); state.fingerprint = ''; state.payload = null; state.review = null; state.stage = 'select'; notify(error.message || text('reviewChanged','Review again before issuing.'),true); showHelp(); if (!state.settings) { renderSettings(); loadSettings(); } }
			}).finally(function () { if (!disposed) { state.busy = false; status.textContent = ''; paint(); } });
		}
		['recipient','program','batch'].forEach(function (kind) {
			var box = picker(kind), input = box.querySelector('input'), clear = box.querySelector('[data-pi-clear]');
			input.addEventListener('focus',function () { if (!input.readOnly) { search(kind,input.value,true); } });
			input.addEventListener('input',function () { search(kind,input.value,false); });
			input.addEventListener('keydown',function (event) { var list = box.querySelector('[role="listbox"]'); if (event.key === 'Escape' && !list.hidden) { event.preventDefault(); event.stopPropagation(); hideResults(kind); } else if (!list.hidden && ['ArrowDown','ArrowUp'].includes(event.key)) { event.preventDefault(); box._move(event.key === 'ArrowDown' ? 1 : -1); } else if (event.key === 'Enter') { event.preventDefault(); if (!list.hidden && box._choose) { box._choose(); } } });
			if (clear) { clear.addEventListener('click',function () { clearChoice(kind); }); }
		});
		form.addEventListener('click',function (event) { ['recipient','program','batch'].forEach(function (kind) { if (!picker(kind).contains(event.target)) { hideResults(kind); } }); });
		form.querySelectorAll('[data-pi-mode]').forEach(function (button) { button.addEventListener('click',function () { changed(); state.mode = button.dataset.piMode; renderRecipientMode(true); }); });
		['display_name','email','display_number','expires_at'].forEach(function (name) { form.elements[name].addEventListener('input',function () { changed(); form.elements[name].removeAttribute('aria-invalid'); }); });
		node('#cl-pi-enrollment').addEventListener('change',function () { state.generation++; state.fingerprint = ''; notify(''); });
		node('[data-pi-batch-open]').addEventListener('click',function () { var input = picker('batch').querySelector('input'); picker('batch').hidden = false; input.focus(); search('batch',input.value,true); });
		node('[data-pi-batch-clear]').addEventListener('click',function () { changed(); state.deep.batch_id = 0; loadSettings(); });
		back.addEventListener('click',function () { if (!state.busy && !state.uncertain) { state.stage = 'select'; paint(); (state.mode === 'new' ? form.elements.display_name : picker('recipient').querySelector('input')).focus(); } });
		node('[data-pi-program-change]').addEventListener('click',function () { clearChoice('program'); picker('program').hidden = false; picker('program').querySelector('input').focus(); });
		form.addEventListener('submit',function (event) { event.preventDefault(); if (state.busy || state.loading) { return; } if (state.failedLoad) { loadSettings(); } else if (state.stage === 'review') { issueCredential(); } else { buildReview(); } });
		node('[data-pi-another]').addEventListener('click',function () { state.complete = false; state.stage = 'select'; state.deep.recipient_id = 0; state.recipient = null; state.mode = 'existing'; changed(); form.reset(); picker('recipient').querySelector('input').readOnly = false; picker('recipient').querySelector('[data-pi-clear]').hidden = true; renderRecipientMode(false); loadSettings(); });
		if (pending) {
			state.deep = pending.deep; state.payload = pending.payload; state.review = pending.review; state.fingerprint = pending.fingerprint; state.stage = 'review'; state.uncertain = true;
			state.mode = pending.payload.issuance.recipient_mode === 'new' ? 'new' : 'existing';
			var recipientDraft = pending.payload.issuance.new_recipient || {};
			form.elements.display_name.value = recipientDraft.display_name || ''; form.elements.email.value = recipientDraft.email || '';
			form.elements.display_number.value = pending.payload.issuance.display_number || ''; form.elements.expires_at.value = pending.payload.issuance.expires_at || '';
			renderRecipientMode(false); renderReview(state.review); notify(text('uncertainCommit','Retry this same issuance to check its result safely.'),true); paint();
		}
		else { renderSettings(); paint(); loadSettings(); }
		return {busy:function () { return state.busy; }, committing:function () { return state.busy && state.stage === 'review'; }, uncertain:function () { return state.uncertain; }, complete:function () { return state.complete; }, dispose:function () { disposed = true; if (settingsController) { settingsController.abort(); } if (enrollmentController) { enrollmentController.abort(); } Object.keys(searches).forEach(function (kind) { searches[kind].abort(); clearTimeout(timers[kind]); }); }};
	}
	function close(event) { if (event) { event.preventDefault(); } if (app && app.busy()) { return; } popup.close(); if (opener) { opener.focus({preventScroll:true}); } }
	popup.querySelector('[data-pi-close]').addEventListener('click',close); popup.addEventListener('cancel',close);
	window.addEventListener('resize',position);
	window.addEventListener('beforeunload',function (event) { if (app && (app.committing() || app.uncertain())) { event.preventDefault(); event.returnValue = ''; } });
	document.addEventListener('click',function (event) {
		var link = event.target.closest('a[href]'); if (!link || event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) { return; }
		var url = new URL(link.href); if (url.origin !== window.location.origin || url.searchParams.get('page') !== 'certloom-issuance') { return; }
		event.preventDefault(); opener = link; position();
		var deep = {}; ['program_id','batch_id','recipient_id'].forEach(function (key) { deep[key] = Number(url.searchParams.get(key)) || 0; }); var key = JSON.stringify(deep);
		if (app && (app.busy() || app.uncertain())) { if (!popup.open) { popup.showModal(); } return; }
		if (!app || key !== contextKey || app.complete()) { if (app) { app.dispose(); } popup.querySelector('form').replaceWith(originalForm.cloneNode(true)); recovery = readRecovery(); app = createApp(deep,recovery); contextKey = key; }
		if (!popup.open) { popup.showModal(); }
	});
})();
