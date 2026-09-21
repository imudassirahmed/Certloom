(function () {
	'use strict';

	var root = document.querySelector('[data-certloom-issue-app]');
	if (!root) { return; }

	var cfg = window.certloomIssueCredential || {};
	var body = root.querySelector('[data-issue-body]');
	var loading = root.querySelector('[data-issue-loading]');
	var form = root.querySelector('[data-issue-form]');
	var message = root.querySelector('[data-issue-message]');
	var stepPanels = Array.prototype.slice.call(root.querySelectorAll('[data-issue-step]'));
	var stepButtons = Array.prototype.slice.call(root.querySelectorAll('[data-issue-step-button]'));
	var prevButton = root.querySelector('[data-step-prev]');
	var nextButton = root.querySelector('[data-step-next]');
	var stepStatus = root.querySelector('[data-step-status]');
	var stepCount = stepPanels.length;
	var reviewStep = Math.max(0, stepCount - 1);
	var summaryProgressBar = root.querySelector('[data-summary-progress-bar]');
	var summaryProgressText = root.querySelector('[data-summary-progress-text]');
	var programTerm = root.getAttribute('data-program-term') || 'Program';
	var batchTerm = root.getAttribute('data-batch-term') || 'Batch';
	var issuerTerm = root.getAttribute('data-issuer-term') || 'Issuer';
	var signerTerm = root.getAttribute('data-signer-term') || 'Signer';

	var state = {
		step: 0,
		bootstrap: null,
		recipientMode: 'existing',
		credentialBasis: 'program',
		selected: { recipient: null, program: null, batch: null, issuer: null, signer: null, template: null },
		programContext: null,
		issuance: {
			recipient_mode: 'existing', recipient_id: 0, new_recipient: {},
			credential_basis: 'program', title_source: 'program', credential_title: '',
			presentation_mode: 'details_only', certificate_image_id: 0,
			program_id: 0, batch_id: 0, issuer_id: 0, template_id: 0, design_source: 'inherited', signer_id: 0,
			valid_from: '', expiry_mode: 'program', expires_at: '', grade: '', display_number: '',
			custom_merge_fields: {}, design_field_aliases: {}, repeating_data: {}, verification_field_keys: null
		},
		email: { mode: 'review', template_id: 'automatic' },
		fieldContract: { fields: [] },
		preview: null,
		reviewFingerprint: '',
		reviewPayload: null,
		reviewSignature: '',
		previewTimer: null,
		previewController: null,
		searchControllers: {},
		searchTimers: {},
		programContextController: null,
		verificationFieldContext: '',
		busy: false,
		oneOffCounter: 0
	};

	function text(key, fallback) {
		return cfg.i18n && cfg.i18n[key] ? String(cfg.i18n[key]) : fallback;
	}

	function limit(key, fallback) {
		var value = state.bootstrap && state.bootstrap.limits ? parseInt(state.bootstrap.limits[key], 10) : 0;
		return value > 0 ? value : fallback;
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
			return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];
		});
	}

	function rest(url, options) {
		options = options || {};
		var headers = Object.assign({'X-WP-Nonce': cfg.restNonce || ''}, options.headers || {});
		if (options.body && !(options.body instanceof FormData) && 'string' !== typeof options.body) {
			headers['Content-Type'] = 'application/json';
			options.body = JSON.stringify(options.body);
		}
		options.headers = headers;
		options.credentials = 'same-origin';
		return fetch(url, options).then(function (response) {
			return response.json().catch(function () { return {}; }).then(function (data) {
				if (!response.ok) {
					var error = new Error(data && data.message ? data.message : ('Request failed (' + response.status + ')'));
					error.code = data && data.code ? data.code : 'rest_error';
					error.data = data && data.data ? data.data : {};
					throw error;
				}
				return data;
			});
		});
	}

	function showMessage(copy, kind) {
		if (!message) { return; }
		message.hidden = !copy;
		message.className = 'certloom-issue-global-message' + (kind ? ' is-' + kind : '');
		message.textContent = copy || '';
		if (copy) { message.scrollIntoView({behavior:'smooth', block:'nearest'}); }
	}

	function clearMessage() { showMessage('', ''); }

	function showRequestError(error) {
		if (error && 'certloom_manual_issue_duplicate_series' === error.code && error.data && error.data.series_id && cfg.credentialsUrl && message) {
			var url = new URL(cfg.credentialsUrl, window.location.href);
			url.searchParams.set('series_id', String(error.data.series_id));
			message.hidden = false;
			message.className = 'certloom-issue-global-message is-error';
			message.innerHTML = '<span>' + escapeHtml(error.message || 'This credential already exists.') + '</span> <a class="button button-small" target="_blank" rel="noopener noreferrer" href="' + escapeHtml(url.toString()) + '">View existing credential</a>';
			message.scrollIntoView({behavior:'smooth', block:'nearest'});
			return;
		}
		showMessage(error && error.message ? error.message : 'The request could not be completed.', 'error');
	}

	function setBusy(busy) {
		state.busy = !!busy;
		if (prevButton) { prevButton.disabled = busy || state.step === 0; }
		if (nextButton) { nextButton.disabled = busy || state.step === reviewStep; }
		var reviewButton = root.querySelector('[data-build-review]');
		var issueButton = root.querySelector('[data-issue-confirm]');
		if (reviewButton) { reviewButton.disabled = !!busy; }
		if (issueButton) { issueButton.disabled = !!busy; }
		root.classList.toggle('is-busy', busy);
	}

	function invalidateReview() {
		state.reviewFingerprint = '';
		state.reviewPayload = null;
		state.reviewSignature = '';
		var reviewContent = root.querySelector('[data-review-content]');
		var technical = root.querySelector('[data-review-technical]');
		var actions = root.querySelector('[data-review-actions]');
		if (reviewContent) { reviewContent.hidden = true; reviewContent.innerHTML = ''; }
		if (technical) { technical.hidden = true; }
		if (actions) { actions.hidden = true; }
	}

	function signature() {
		return JSON.stringify({issuance: state.issuance, email: state.email});
	}

	function onDataChanged(options) {
		options = options || {};
		invalidateReview();
		updateSummary();
		if (!options.skipPreview) { schedulePreview(); }
	}

	function picker(kind) { return root.querySelector('[data-picker="' + kind + '"]'); }

	function setPickerSelected(kind, choice, options) {
		options = options || {};
		var box = picker(kind);
		if (!box) { return; }
		var hidden = box.querySelector('[data-picker-value]');
		var search = box.querySelector('[data-picker-search]');
		var selected = box.querySelector('[data-picker-selected]');
		var clear = box.querySelector('[data-picker-clear]');
		var results = box.querySelector('[data-picker-results]');
		var status = box.querySelector('[data-picker-status]');
		state.selected[kind] = choice || null;
		if (hidden) { hidden.value = choice && choice.id ? String(choice.id) : ''; }
		if (search) {
			search.value = choice ? String(choice.label || choice.name || choice.title || '') : '';
			search.setAttribute('aria-expanded', 'false');
		}
		if (selected) {
			// Keep the selected text in the DOM for assistive/test consumers, but the
			// search input itself is the visible selected-value surface.
			selected.hidden = true;
			selected.innerHTML = choice ? '<strong>' + escapeHtml(choice.label || choice.name || choice.title || '') + '</strong>' : '';
		}
		if (clear) { clear.hidden = !choice; }
		if (results) { results.hidden = true; results.innerHTML = ''; }
		if (status) { status.textContent = ''; }
		if (!options.silent) { handlePickerChange(kind, choice); }
	}

	function clearPicker(kind, options) { setPickerSelected(kind, null, options || {}); }

	function handlePickerChange(kind, choice) {
		var id = choice && choice.id ? parseInt(choice.id, 10) || 0 : 0;
		if ('recipient' === kind) {
			state.issuance.recipient_id = id;
		}
		if ('wp_user' === kind) {
			state.issuance.new_recipient.wp_user_id = id;
		}
		if ('program' === kind) {
			selectProgram(choice);
			return;
		}
		if ('batch' === kind) {
			state.issuance.batch_id = id;
			loadProgramContext(false);
			return;
		}
		if ('issuer' === kind) { state.issuance.issuer_id = id; }
		if ('signer' === kind) { state.issuance.signer_id = id; }
		if ('template' === kind) { state.issuance.template_id = id; }
		onDataChanged();
	}

	function normalizeChoice(kind, raw) {
		raw = raw || {};
		var choice = Object.assign({}, raw);
		choice.id = parseInt(raw.id || 0, 10) || 0;
		choice.label = String(raw.label || raw.name || raw.title || ('#' + choice.id));
		if ('program' === kind) {
			choice.title = String(raw.title || raw.label || '');
			choice.issuer_id = parseInt(raw.issuer_id || 0, 10) || 0;
			choice.issuer_name = String(raw.issuer_name || '');
			choice.validity_summary = String(raw.validity_summary || '');
			choice.program_validity_available = !!(raw.program_validity_available || raw.program_default_available);
		}
		if ('batch' === kind) { choice.program_id = parseInt(raw.program_id || 0, 10) || 0; }
		return choice;
	}

	function renderPickerResults(kind, rows) {
		var box = picker(kind);
		if (!box) { return; }
		var results = box.querySelector('[data-picker-results]');
		var search = box.querySelector('[data-picker-search]');
		var status = box.querySelector('[data-picker-status]');
		rows = Array.isArray(rows) ? rows : [];
		if (status) { status.textContent = rows.length ? rows.length + (rows.length === 1 ? ' match.' : ' matches.') : text('noMatches', 'No matching choices.'); }
		if (!results) { return; }
		results.innerHTML = '';
		if (!rows.length) { results.hidden = true; if (search) { search.setAttribute('aria-expanded', 'false'); } return; }
		rows.forEach(function (raw) {
			var choice = normalizeChoice(kind, raw);
			var button = document.createElement('button');
			button.type = 'button';
			button.setAttribute('role', 'option');
			button.textContent = choice.label;
			button.addEventListener('mousedown', function (event) { event.preventDefault(); });
			button.addEventListener('click', function () { setPickerSelected(kind, choice); });
			results.appendChild(button);
		});
		results.hidden = false;
		if (search) { search.setAttribute('aria-expanded', 'true'); }
	}

	function searchPicker(kind, query, immediate) {
		var box = picker(kind);
		if (!box) { return; }
		if ('batch' === kind && !state.issuance.program_id) {
			renderPickerResults(kind, []);
			var batchStatus = box.querySelector('[data-picker-status]');
			if (batchStatus) { batchStatus.textContent = text('chooseProgramFirst', 'Choose a ' + programTerm + ' before selecting a ' + batchTerm + '.'); }
			return;
		}
		if (state.searchTimers[kind]) { clearTimeout(state.searchTimers[kind]); }
		var run = function () {
			if (state.searchControllers[kind]) { state.searchControllers[kind].abort(); }
			var controller = new AbortController();
			state.searchControllers[kind] = controller;
			var status = box.querySelector('[data-picker-status]');
			if (status) { status.textContent = text('searching', 'Searching…'); }
			var url = new URL(cfg.contextUrl, window.location.href);
			url.searchParams.set('kind', kind);
			url.searchParams.set('query', query || '');
			if ('batch' === kind) { url.searchParams.set('program_id', String(state.issuance.program_id || 0)); }
			rest(url.toString(), {signal: controller.signal}).then(function (data) {
				renderPickerResults(kind, data && Array.isArray(data.choices) ? data.choices : []);
			}).catch(function (error) {
				if ('AbortError' === error.name) { return; }
				if (status) { status.textContent = error.message || text('loadFailed', 'Could not load choices.'); }
			});
		};
		if (immediate) { run(); } else { state.searchTimers[kind] = setTimeout(run, 220); }
	}

	function initPickers() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-picker]'), function (box) {
			var kind = box.getAttribute('data-picker');
			var search = box.querySelector('[data-picker-search]');
			var clear = box.querySelector('[data-picker-clear]');
			if (search) {
				search.addEventListener('focus', function () {
					var selectedChoice = state.selected[kind];
					var selectedLabel = selectedChoice ? String(selectedChoice.label || selectedChoice.name || selectedChoice.title || '') : '';
					if (selectedChoice && search.value === selectedLabel) {
						var results = box.querySelector('[data-picker-results]');
						var status = box.querySelector('[data-picker-status]');
						if (results) { results.hidden = true; results.innerHTML = ''; }
						if (status) { status.textContent = ''; }
						search.setAttribute('aria-expanded', 'false');
						return;
					}
					searchPicker(kind, search.value, true);
				});
				search.addEventListener('input', function () {
					if (state.selected[kind] && search.value !== state.selected[kind].label) { clearPicker(kind); }
					searchPicker(kind, search.value, false);
				});
				search.addEventListener('keydown', function (event) { if ('Escape' === event.key) { var results = box.querySelector('[data-picker-results]'); if (results) { results.hidden = true; } search.setAttribute('aria-expanded', 'false'); } });
			}
			if (clear) { clear.addEventListener('click', function () { clearPicker(kind); if (search) { search.focus(); searchPicker(kind, '', true); } }); }
		});
	}

	function selectProgram(choice) {
		var previous = state.issuance.program_id;
		var next = choice && choice.id ? parseInt(choice.id, 10) || 0 : 0;
		state.issuance.program_id = next;
		if (previous !== next) {
			if (state.programContextController) { state.programContextController.abort(); state.programContextController = null; }
			clearPicker('batch', {silent:true});
			state.issuance.batch_id = 0;
			state.fieldContract = {fields:[]};
			state.programContext = null;
			state.verificationFieldContext = '';
			state.issuance.verification_field_keys = null;
			if (previous && next) { showMessage(text('programChangedBatch', batchTerm + ' cleared because the ' + programTerm + ' changed. Loading current ' + batchTerm + ' choices…'), 'info'); }
		}
		if (!next) {
			state.issuance.issuer_id = 0;
			if ('program' === state.credentialBasis) {
				state.issuance.credential_title = '';
				state.issuance.title_source = 'program';
				setTitleInput('');
			}
			renderProgramContext();
			renderScopedFields();
			onDataChanged();
			return;
		}
		if ('program' === state.credentialBasis) {
			state.issuance.credential_title = String(choice.title || choice.label || '');
			state.issuance.title_source = 'program';
			setTitleInput(state.issuance.credential_title);
		}
		state.issuance.issuer_id = parseInt(choice.issuer_id || 0, 10) || 0;
		state.issuance.expiry_mode = choice.program_validity_available ? 'program' : 'permanent';
		var expiry = root.querySelector('[data-field="expiry_mode"]');
		if (expiry) { expiry.value = state.issuance.expiry_mode; }
		loadProgramContext(true);
		searchPicker('batch', '', true);
		onDataChanged();
	}

	function loadProgramContext(showErrors) {
		if (!state.issuance.program_id) { renderProgramContext(); return Promise.resolve(); }
		if (state.programContextController) { state.programContextController.abort(); }
		var controller = new AbortController();
		state.programContextController = controller;
		var requestedProgramId = state.issuance.program_id;
		var requestedBatchId = state.issuance.batch_id;
		var requestedBasis = state.credentialBasis;
		var url = new URL(cfg.programContextUrl, window.location.href);
		url.searchParams.set('program_id', String(requestedProgramId));
		if (requestedBatchId) { url.searchParams.set('batch_id', String(requestedBatchId)); }
		return rest(url.toString(), {signal: controller.signal}).then(function (data) {
			if ('program' !== state.credentialBasis || 'program' !== requestedBasis || state.issuance.program_id !== requestedProgramId || state.issuance.batch_id !== requestedBatchId) { return; }
			state.programContext = data || null;
			state.fieldContract = data && data.field_contract ? data.field_contract : {fields:[]};
			syncVerificationFieldSelection(state.fieldContract.fields || []);
			if (data && data.issuer) {
				state.issuance.issuer_id = normalizeChoice('issuer', data.issuer).id;
			}
			renderProgramContext();
			renderScopedFields();
			updateSummary();
			schedulePreview();
		}).catch(function (error) {
			if ('AbortError' === error.name) { return; }
			if (showErrors) { showMessage(error.message || text('loadFailed', 'Could not load ' + programTerm + ' details.'), 'error'); }
		});
	}

	function renderProgramContext() {
		var note = root.querySelector('[data-program-context-note]');
		if (note) {
			var parts = [];
			if (state.selected.program) { parts.push('<strong>' + escapeHtml(state.selected.program.label) + '</strong>'); }
			if (state.programContext && state.programContext.validity && state.programContext.validity.summary) { parts.push('<span>Validity: ' + escapeHtml(state.programContext.validity.summary) + '</span>'); }
			if (state.programContext && state.programContext.issuer && state.programContext.issuer.name) { parts.push('<span>' + escapeHtml(issuerTerm) + ': ' + escapeHtml(state.programContext.issuer.name) + '</span>'); }
			note.hidden = !parts.length;
			note.innerHTML = parts.join('');
		}
		var inherited = root.querySelector('[data-inherited-issuer]');
		var inheritedName = root.querySelector('[data-inherited-issuer-name]');
		var independent = root.querySelector('[data-independent-issuer]');
		var hasInherited = 'program' === state.credentialBasis && !!(state.programContext && state.programContext.issuer);
		if (inherited) { inherited.hidden = !hasInherited; }
		if (inheritedName) { inheritedName.textContent = hasInherited ? String(state.programContext.issuer.name || state.programContext.issuer.label || '') : ''; }
		if (independent) { independent.hidden = 'independent' !== state.credentialBasis; }
		var manageProgram = root.querySelector('[data-manage-program]');
		if (manageProgram && cfg.programsUrl) {
			var manageUrl = new URL(cfg.programsUrl, window.location.href);
			if (state.issuance.program_id) { manageUrl.searchParams.set('program_id', String(state.issuance.program_id)); }
			manageProgram.href = manageUrl.toString();
		}
		var validity = root.querySelector('[data-validity-summary]');
		if (validity) {
			validity.textContent = state.programContext && state.programContext.validity && state.programContext.validity.summary ? state.programContext.validity.summary : ('program' === state.credentialBasis ? 'No ' + programTerm + ' validity default is configured.' : 'Independent credentials use No expiry or a specific date.');
		}
	}

	function setTitleInput(value) {
		var input = root.querySelector('[data-field="credential_title"]');
		if (input) { input.value = value || ''; }
		updateTitleHint();
	}

	function updateTitleHint() {
		var hint = root.querySelector('[data-title-hint]');
		if (!hint) { return; }
		if ('program' === state.issuance.title_source && state.selected.program) { hint.textContent = text('programTitleHint', 'Prefilled from the selected ' + programTerm + '. Edit it freely for this credential only.'); }
		else if (state.selected.program) { hint.innerHTML = escapeHtml(text('customTitleHint', 'Custom for this credential. The ' + programTerm + ' title is unchanged.')) + ' <button type="button" class="button-link" data-use-program-title>Use ' + escapeHtml(programTerm) + ' title</button>'; }
		else { hint.textContent = 'Enter the title that should appear on this credential.'; }
		var reset = hint.querySelector('[data-use-program-title]');
		if (reset) { reset.addEventListener('click', function () {
			state.issuance.credential_title = String(state.selected.program.title || state.selected.program.label || '');
			state.issuance.title_source = 'program';
			setTitleInput(state.issuance.credential_title);
			onDataChanged();
		}); }
	}

	function bindSimpleFields() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-field]'), function (input) {
			var path = input.getAttribute('data-field');
			input.addEventListener('input', function () { simpleFieldChanged(path, input.value); });
			// Credential title updates its hint while typing. Re-rendering that hint on
			// blur would replace the “Use Program title” button between mousedown and
			// click, making the reset action appear dead in some browsers.
			if ('credential_title' !== path) {
				input.addEventListener('change', function () { simpleFieldChanged(path, input.value); });
			}
		});
	}

	function simpleFieldChanged(path, value) {
		if (0 === path.indexOf('new_recipient.')) {
			state.issuance.new_recipient[path.substring(14)] = value;
		} else {
			state.issuance[path] = value;
			if ('credential_title' === path) {
				var programTitle = state.selected.program ? String(state.selected.program.title || state.selected.program.label || '') : '';
				state.issuance.title_source = state.issuance.program_id && value === programTitle ? 'program' : 'custom';
				updateTitleHint();
			}
			if ('expiry_mode' === path) { renderExpiryMode(); }
		}
		onDataChanged();
	}

	function renderExpiryMode() {
		var field = root.querySelector('[data-expiry-date-field]');
		if (field) { field.hidden = 'date' !== state.issuance.expiry_mode; }
		var select = root.querySelector('[data-field="expiry_mode"]');
		if (select) {
			var programOption = select.querySelector('option[value="program"]');
			if (programOption) { programOption.disabled = !state.issuance.program_id; }
			if (!state.issuance.program_id && 'program' === state.issuance.expiry_mode) { state.issuance.expiry_mode = 'permanent'; select.value = 'permanent'; }
		}
	}

	function initRecipientModes() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-recipient-mode]'), function (button) {
			button.addEventListener('click', function () {
				state.recipientMode = button.getAttribute('data-recipient-mode');
				state.issuance.recipient_mode = state.recipientMode;
				root.querySelectorAll('[data-recipient-mode]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
				var existing = picker('recipient');
				var fresh = root.querySelector('[data-new-recipient]');
				if (existing) { existing.hidden = 'existing' !== state.recipientMode; }
				if (fresh) { fresh.hidden = 'new' !== state.recipientMode; }
				onDataChanged();
			});
		});
	}

	function initBasis() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-credential-basis]'), function (button) {
			button.addEventListener('click', function () {
				var basis = button.getAttribute('data-credential-basis');
				if (state.credentialBasis === basis) { return; }
				state.credentialBasis = basis;
				state.issuance.credential_basis = basis;
				root.querySelectorAll('[data-credential-basis]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
				var context = root.querySelector('[data-program-context]');
				var independent = root.querySelector('[data-independent-note]');
				if (context) { context.hidden = 'program' !== basis; }
				if (independent) { independent.hidden = 'independent' !== basis; }
				if ('independent' === basis) {
					if (state.programContextController) { state.programContextController.abort(); state.programContextController = null; }
					clearPicker('program', {silent:true}); clearPicker('batch', {silent:true});
					state.issuance.program_id = 0; state.issuance.batch_id = 0; state.issuance.issuer_id = state.selected.issuer ? state.selected.issuer.id : 0;
					state.programContext = null; state.fieldContract = {fields:[]}; state.verificationFieldContext = ''; state.issuance.verification_field_keys = null; state.issuance.title_source = 'custom'; state.issuance.expiry_mode = 'permanent';
					var expiry = root.querySelector('[data-field="expiry_mode"]'); if (expiry) { expiry.value = 'permanent'; }
				} else {
					state.issuance.issuer_id = 0; state.issuance.expiry_mode = state.selected.program && state.selected.program.program_validity_available ? 'program' : 'permanent';
				}
				renderExpiryMode(); renderProgramContext(); renderScopedFields(); updateTitleHint(); onDataChanged();
			});
		});
	}

	function verificationFieldAllowed(field) {
		field = field || {};
		var exposure = String(field.public_exposure || 'private').toLowerCase();
		var sensitivity = String(field.sensitivity || 'personal').toLowerCase();
		return 'internal' !== sensitivity && ('verification' === exposure || 'both' === exposure);
	}

	function syncVerificationFieldSelection(fields) {
		fields = Array.isArray(fields) ? fields : [];
		var eligible = fields.filter(verificationFieldAllowed).map(function (field) { return String(field.key || ''); }).filter(Boolean).sort();
		var signature = [state.credentialBasis, state.issuance.program_id || 0, state.issuance.batch_id || 0, eligible.join(',')].join('|');
		if (state.verificationFieldContext === signature) { return; }
		state.verificationFieldContext = signature;
		state.issuance.verification_field_keys = eligible;
	}

	function verificationToggleHtml(field, key) {
		var allowed = verificationFieldAllowed(field);
		var selectedKeys = Array.isArray(state.issuance.verification_field_keys) ? state.issuance.verification_field_keys : [];
		var selected = allowed && selectedKeys.indexOf(key) !== -1;
		var title = allowed ? text('showOnVerification', 'Show on verification') : text('verificationPrivate', 'Not available on verification');
		var hint = allowed ? text('verificationIncludeHint', 'Include this value on the public verification screen for this credential.') : text('verificationPrivateHint', 'Field privacy settings do not allow this value on public verification.');
		return '<label class="certloom-issue-verification-toggle' + (allowed ? '' : ' is-disabled') + '"><input type="checkbox" data-verification-field="' + escapeHtml(key) + '"' + (selected ? ' checked' : '') + (allowed ? '' : ' disabled') + '><span><strong>' + escapeHtml(title) + '</strong><small>' + escapeHtml(hint) + '</small></span></label>';
	}

	function renderScopedFields() {
		var box = root.querySelector('[data-scoped-fields]');
		if (!box) { return; }
		var fields = state.fieldContract && Array.isArray(state.fieldContract.fields) ? state.fieldContract.fields : [];
		syncVerificationFieldSelection(fields);
		var html = '<div class="certloom-issue-subhead"><div><h3>Credential fields</h3><p>' + escapeHtml('program' === state.credentialBasis ? (programTerm + ' / ' + batchTerm + ' field rules') : 'Independent credential facts') + '</p></div></div>';
		var rendered = 0;
		fields.forEach(function (field) {
			var key = String(field.key || '');
			if (!key) { return; }
			var source = String(field.value_source || 'credential_custom');
			var required = !!field.required;
			var origin = String(field.origin || programTerm);
			if ('recipient' === source) {
				html += '<div class="certloom-issue-scoped-entry" data-scoped-field="' + escapeHtml(key) + '"><div class="certloom-issue-field-row is-readonly"><div><strong>' + escapeHtml(field.label || key) + (required ? ' <em>Required</em>' : '') + '</strong><small>From Recipient profile · ' + escapeHtml(origin) + '</small></div><span>Frozen at issue</span></div>' + verificationToggleHtml(field, key) + '</div>';
				rendered++; return;
			}
			var value = 'grade' === key ? state.issuance.grade : (state.issuance.custom_merge_fields[key] || '');
			html += '<div class="certloom-issue-scoped-entry" data-scoped-field="' + escapeHtml(key) + '"><label class="certloom-issue-field"><span>' + escapeHtml(field.label || key) + (required ? ' <em>*</em>' : '') + '</span><input type="text" data-scoped-input="' + escapeHtml(key) + '" value="' + escapeHtml(value) + '"' + (required ? ' required' : '') + '><small>' + escapeHtml(origin ? ('Configured by ' + origin) : '') + '</small></label>' + verificationToggleHtml(field, key) + '</div>';
			rendered++;
		});
		if (!rendered && state.bootstrap && state.bootstrap.profile && state.bootstrap.profile.issuance && state.bootstrap.profile.issuance.grade_default) {
			html += '<label class="certloom-issue-field" data-scoped-field="grade"><span>Grade / result</span><input type="text" data-scoped-input="grade" value="' + escapeHtml(state.issuance.grade || '') + '"></label>';
			rendered++;
		}
		if (!rendered) { html += '<div class="certloom-issue-empty is-compact"><p>' + escapeHtml(text('noReusableFields', 'No reusable credential fields are configured for this context. Add one-off details only when needed.')) + '</p></div>'; }
		box.innerHTML = html;
		Array.prototype.forEach.call(box.querySelectorAll('[data-scoped-input]'), function (input) {
			input.addEventListener('input', function () {
				var key = input.getAttribute('data-scoped-input');
				if ('grade' === key) { state.issuance.grade = input.value; }
				else { state.issuance.custom_merge_fields[key] = input.value; }
				onDataChanged();
			});
		});
		Array.prototype.forEach.call(box.querySelectorAll('[data-verification-field]'), function (input) {
			input.addEventListener('change', function () {
				var key = String(input.getAttribute('data-verification-field') || '');
				var keys = (Array.isArray(state.issuance.verification_field_keys) ? state.issuance.verification_field_keys : []).filter(function (existing) { return existing !== key; });
				if (input.checked) { keys.push(key); }
				keys.sort();
				state.issuance.verification_field_keys = keys;
				onDataChanged();
			});
		});
		renderRepeating();
	}

	function renderRepeating() {
		var section = root.querySelector('[data-repeating-section]');
		var container = root.querySelector('[data-repeating-rowsets]');
		if (!section || !container) { return; }
		var rowsets = state.bootstrap && state.bootstrap.profile && state.bootstrap.profile.issuance && Array.isArray(state.bootstrap.profile.issuance.repeating_rowsets) ? state.bootstrap.profile.issuance.repeating_rowsets : [];
		section.hidden = !rowsets.length;
		if (!rowsets.length) { container.innerHTML = ''; state.issuance.repeating_data = {}; return; }
		var html = '';
		rowsets.forEach(function (kind) {
			var rows = Array.isArray(state.issuance.repeating_data[kind]) ? state.issuance.repeating_data[kind] : [];
			var atCapacity = rows.length >= limit('repeating_rows', 50);
			html += '<section class="certloom-repeating-editor" data-rowset="' + escapeHtml(kind) + '"><header><strong>' + escapeHtml('results' === kind ? 'Results' : 'Subjects') + '</strong><button type="button" class="button button-small" data-add-row="' + escapeHtml(kind) + '" aria-disabled="' + (atCapacity ? 'true' : 'false') + '"' + (atCapacity ? ' disabled' : '') + '>Add row</button></header><div data-rowset-rows>';
			rows.forEach(function (row, index) { html += repeatingRowHtml(kind, row, index); });
			html += '</div><small class="certloom-repeating-limit">' + escapeHtml(text('repeatingLimit', 'Repeating credential data is limited to 50 rows.')) + '</small></section>';
		});
		container.innerHTML = html;
		bindRepeating(container);
	}

	function repeatingFields(kind) { return 'results' === kind ? ['subject','code','score','max_score','percentage','grade','credits','status'] : ['subject','code','credits']; }
	function repeatingRowHtml(kind, row, index) {
		var html = '<div class="certloom-repeating-row" data-row-index="' + index + '"><div class="certloom-repeating-row-grid">';
		repeatingFields(kind).forEach(function (field) { html += '<label><span>' + escapeHtml(field.replace(/_/g, ' ')) + '</span><input type="text" data-row-field="' + escapeHtml(field) + '" value="' + escapeHtml(row[field] || '') + '"></label>'; });
		html += '</div><button type="button" class="button-link-delete" data-remove-row>Remove row</button></div>';
		return html;
	}
	function bindRepeating(container) {
		Array.prototype.forEach.call(container.querySelectorAll('[data-add-row]'), function (button) {
			button.addEventListener('click', function () {
				var kind = button.getAttribute('data-add-row'); if (!Array.isArray(state.issuance.repeating_data[kind])) { state.issuance.repeating_data[kind] = []; }
				if (state.issuance.repeating_data[kind].length >= limit('repeating_rows', 50)) { showMessage(text('repeatingLimit', 'Repeating credential data is limited to 50 rows.'), 'error'); return; }
				state.issuance.repeating_data[kind].push({}); renderRepeating(); onDataChanged();
				var editor = root.querySelector('.certloom-repeating-editor[data-rowset="' + kind + '"]');
				var rows = editor ? editor.querySelectorAll('.certloom-repeating-row') : [];
				var firstField = rows.length ? rows[rows.length - 1].querySelector('[data-row-field]') : null;
				if (firstField) { firstField.focus(); }
			});
		});
		Array.prototype.forEach.call(container.querySelectorAll('[data-rowset]'), function (editor) {
			var kind = editor.getAttribute('data-rowset');
			Array.prototype.forEach.call(editor.querySelectorAll('.certloom-repeating-row'), function (rowEl, index) {
				Array.prototype.forEach.call(rowEl.querySelectorAll('[data-row-field]'), function (input) {
					input.addEventListener('input', function () { if (!state.issuance.repeating_data[kind][index]) { state.issuance.repeating_data[kind][index] = {}; } state.issuance.repeating_data[kind][index][input.getAttribute('data-row-field')] = input.value; onDataChanged(); });
				});
				var remove = rowEl.querySelector('[data-remove-row]'); if (remove) { remove.addEventListener('click', function () {
					state.issuance.repeating_data[kind].splice(index, 1); renderRepeating(); onDataChanged();
					var add = root.querySelector('[data-add-row="' + kind + '"]'); if (add) { add.focus(); }
				}); }
			});
		});
	}

	function addOneOff(key, label, value) {
		key = String(key || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
		if (!key) { key = 'field_' + (++state.oneOffCounter); }
		if (!Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, key) && Object.keys(state.issuance.custom_merge_fields).length >= limit('custom_fields', 30)) {
			showMessage('This credential already uses the maximum number of custom credential fields.', 'error');
			return;
		}
		if (!Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, key)) { state.issuance.custom_merge_fields[key] = value || ''; }
		renderOneOff();
		var input = root.querySelector('[data-oneoff-value="' + key.replace(/"/g, '') + '"]'); if (input) { input.focus(); }
		onDataChanged();
	}
	function renderOneOff() {
		var list = root.querySelector('[data-oneoff-list]'); if (!list) { return; }
		var keys = Object.keys(state.issuance.custom_merge_fields).filter(function (key) {
			var contract = state.fieldContract && Array.isArray(state.fieldContract.fields) ? state.fieldContract.fields : [];
			return !contract.some(function (field) { return String(field.key || '') === key; });
		});
		list.innerHTML = keys.map(function (key) { return '<div class="certloom-oneoff-row"><label><span>Field key</span><input type="text" data-oneoff-key value="' + escapeHtml(key) + '" maxlength="64"></label><label><span>Value</span><input type="text" data-oneoff-value="' + escapeHtml(key) + '" value="' + escapeHtml(state.issuance.custom_merge_fields[key] || '') + '"></label><button type="button" class="button-link-delete" data-oneoff-remove="' + escapeHtml(key) + '">Remove</button></div>'; }).join('');
		Array.prototype.forEach.call(list.querySelectorAll('[data-oneoff-value]'), function (input) { input.addEventListener('input', function () { state.issuance.custom_merge_fields[input.getAttribute('data-oneoff-value')] = input.value; onDataChanged(); }); });
		Array.prototype.forEach.call(list.querySelectorAll('[data-oneoff-key]'), function (input) { input.addEventListener('change', function () { var row = input.closest('.certloom-oneoff-row'); var valueInput = row ? row.querySelector('[data-oneoff-value]') : null; var oldKey = valueInput ? valueInput.getAttribute('data-oneoff-value') : ''; var newKey = input.value.toLowerCase().replace(/[^a-z0-9_-]/g, ''); if (!newKey || newKey === oldKey) { input.value = oldKey; return; } if (Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, newKey)) { showMessage('That credential field key is already in use.', 'error'); input.value = oldKey; return; } state.issuance.custom_merge_fields[newKey] = state.issuance.custom_merge_fields[oldKey] || ''; delete state.issuance.custom_merge_fields[oldKey]; Object.keys(state.issuance.design_field_aliases).forEach(function (target) { if (state.issuance.design_field_aliases[target] === oldKey) { state.issuance.design_field_aliases[target] = newKey; } }); renderOneOff(); onDataChanged(); }); });
		Array.prototype.forEach.call(list.querySelectorAll('[data-oneoff-remove]'), function (button) { button.addEventListener('click', function () { var key = button.getAttribute('data-oneoff-remove'); delete state.issuance.custom_merge_fields[key]; Object.keys(state.issuance.design_field_aliases).forEach(function (target) { if (state.issuance.design_field_aliases[target] === key) { delete state.issuance.design_field_aliases[target]; } }); renderOneOff(); onDataChanged(); }); });
	}
	function initOneOff() { var add = root.querySelector('[data-add-oneoff]'); if (add) { add.addEventListener('click', function () { addOneOff('', '', ''); }); } }

	function initPresentation() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-presentation]'), function (button) {
			button.addEventListener('click', function () {
				state.issuance.presentation_mode = button.getAttribute('data-presentation');
				root.querySelectorAll('[data-presentation]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
				renderPresentation(); onDataChanged();
			});
		});
		Array.prototype.forEach.call(root.querySelectorAll('[data-design-source]'), function (button) {
			button.addEventListener('click', function () {
				state.issuance.design_source = button.getAttribute('data-design-source');
				if ('inherited' === state.issuance.design_source) { state.issuance.template_id = 0; clearPicker('template', {silent:true}); }
				root.querySelectorAll('[data-design-source]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
				renderPresentation(); onDataChanged();
			});
		});
		var choose = root.querySelector('[data-choose-image]'); if (choose) { choose.addEventListener('click', chooseCertificateImage); }
		var clear = root.querySelector('[data-clear-image]'); if (clear) { clear.addEventListener('click', function () { state.issuance.certificate_image_id = 0; renderImageSelection(null); onDataChanged(); }); }
	}
	function renderPresentation() {
		var image = root.querySelector('[data-image-controls]'); var design = root.querySelector('[data-design-controls]'); var assigned = root.querySelector('[data-assigned-design]');
		if (image) { image.hidden = 'image' !== state.issuance.presentation_mode; }
		if (design) { design.hidden = 'design' !== state.issuance.presentation_mode; }
		if (assigned) { assigned.hidden = !('design' === state.issuance.presentation_mode && 'assigned' === state.issuance.design_source); }
		if ('design' !== state.issuance.presentation_mode) {
			if (state.previewTimer) { clearTimeout(state.previewTimer); state.previewTimer = null; }
			if (state.previewController) { state.previewController.abort(); state.previewController = null; }
			state.preview = null; renderPreview(); renderDesignRequired([]);
		}
	}
	function chooseCertificateImage() {
		if (!window.wp || !wp.media) { showMessage('WordPress Media Library is unavailable.', 'error'); return; }
		var frame = wp.media({title:'Choose certificate image', button:{text:'Use this image'}, multiple:false, library:{type:'image'}});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			var mime = String(attachment.mime || '');
			if (['image/png','image/jpeg'].indexOf(mime) === -1) { showMessage(text('imageType', 'Choose a PNG or JPEG image.'), 'error'); return; }
			state.issuance.certificate_image_id = parseInt(attachment.id || 0, 10) || 0;
			renderImageSelection(attachment); onDataChanged({skipPreview:true});
		});
		frame.open();
	}
	function renderImageSelection(attachment) {
		var box = root.querySelector('[data-image-selection]'); var clear = root.querySelector('[data-clear-image]'); if (clear) { clear.hidden = !state.issuance.certificate_image_id; }
		if (!box) { return; }
		if (!state.issuance.certificate_image_id) { box.innerHTML = ''; return; }
		box.innerHTML = attachment && attachment.url ? '<img src="' + escapeHtml(attachment.url) + '" alt=""><span>' + escapeHtml(attachment.filename || 'Selected image') + '</span>' : '<span>Selected media #' + escapeHtml(state.issuance.certificate_image_id) + '</span>';
	}

	function schedulePreview() {
		if (state.previewTimer) { clearTimeout(state.previewTimer); }
		if (state.previewController) { state.previewController.abort(); state.previewController = null; }
		if ('design' !== state.issuance.presentation_mode) { renderPreview(); return; }
		state.previewTimer = setTimeout(loadPreview, 320);
	}
	function canPreview() {
		if ('existing' === state.issuance.recipient_mode && !state.issuance.recipient_id) { return false; }
		if ('new' === state.issuance.recipient_mode && !String(state.issuance.new_recipient.display_name || '').trim()) { return false; }
		if ('program' === state.issuance.credential_basis && !state.issuance.program_id) { return false; }
		if (!String(state.issuance.credential_title || '').trim()) { return false; }
		if (!state.issuance.valid_from) { return false; }
		if ('assigned' === state.issuance.design_source && !state.issuance.template_id) { return false; }
		return true;
	}
	function loadPreview() {
		if ('design' !== state.issuance.presentation_mode) { return; }
		state.previewTimer = null;
		var status = root.querySelector('[data-preview-status]');
		if (!canPreview()) { state.preview = null; if (status) { status.textContent = text('previewWaiting', 'Complete Recipient and Credential details to load the live preview.'); } renderPreview(); return; }
		if (state.previewController) { state.previewController.abort(); }
		var controller = new AbortController(); state.previewController = controller;
		if (status) { status.textContent = text('previewUpdating', 'Updating preview…'); }
		rest(cfg.previewUrl, {method:'POST', body:state.issuance, signal:controller.signal}).then(function (data) {
			state.preview = data || null; renderPreview(); renderDesignRequired(data && data.data_readiness && Array.isArray(data.data_readiness.fields) ? data.data_readiness.fields : []);
			var sourceSummary = root.querySelector('[data-design-source-summary]'); if (sourceSummary) { sourceSummary.textContent = data && data.source_label ? data.source_label : ''; }
		}).catch(function (error) {
			if ('AbortError' === error.name) { return; }
			state.preview = {error:error.message}; renderPreview();
		});
	}
	function renderPreview() {
		var stage = root.querySelector('[data-preview-stage]'); var status = root.querySelector('[data-preview-status]'); if (!stage) { return; }
		var card = stage.closest('.certloom-issue-preview-card');
		if (card) { card.classList.toggle('is-compact', 'design' !== state.issuance.presentation_mode); }
		if ('design' !== state.issuance.presentation_mode) { stage.innerHTML = '<div class="certloom-issue-preview-empty"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>Live certificate preview applies when Certloom design is selected.</p></div>'; if (status) { status.textContent = 'No design preview is needed for this appearance.'; } return; }
		if (!state.preview) { stage.innerHTML = '<div class="certloom-issue-preview-empty"><span class="dashicons dashicons-awards" aria-hidden="true"></span><p>Live preview will appear here.</p></div>'; return; }
		if (state.preview.error) { stage.innerHTML = '<div class="certloom-issue-preview-empty is-error"><span class="dashicons dashicons-warning" aria-hidden="true"></span><p>' + escapeHtml(state.preview.error) + '</p></div>'; if (status) { status.textContent = 'Preview needs attention.'; } return; }
		stage.innerHTML = '<div class="certloom-issue-preview-document" data-preview-document>' + String(state.preview.html || '') + '</div>';
		if (status) { status.textContent = (state.preview.name ? state.preview.name + ' · ' : '') + (state.preview.source_label || 'Live unsaved preview'); }
		fitPreview();
	}
	function fitPreview() {
		var stage = root.querySelector('[data-preview-stage]'); var doc = root.querySelector('[data-preview-document]'); if (!stage || !doc) { return; }
		requestAnimationFrame(function () {
			var width = doc.scrollWidth || doc.getBoundingClientRect().width; var height = doc.scrollHeight || doc.getBoundingClientRect().height;
			if (!width || !height) { return; }
			var scale = Math.min((stage.clientWidth - 24) / width, (stage.clientHeight - 24) / height, 1);
			doc.style.transform = 'scale(' + Math.max(0.1, scale) + ')';
		});
	}

	function renderDesignRequired(fields) {
		var section = root.querySelector('[data-design-required-fields]'); var list = root.querySelector('[data-design-required-list]'); if (!section || !list) { return; }
		var missing = (Array.isArray(fields) ? fields : []).filter(function (field) { return field && 'missing' === String(field.status || ''); });
		section.hidden = !missing.length;
		list.innerHTML = '';
		missing.forEach(function (field) {
			var row = document.createElement('div'); row.className = 'certloom-design-required-row';
			var copy = document.createElement('div'); copy.innerHTML = '<strong>' + escapeHtml(field.label || field.token || 'Required data') + '</strong><small>' + escapeHtml(field.source || '') + '</small>'; row.appendChild(copy);
			var action = document.createElement('button'); action.type = 'button'; action.className = 'button button-small';
			var type = String(field.action || 'context');
			if ('recipient' === type) { action.textContent = 'Edit Recipient'; action.addEventListener('click', function () { goToStep(0); }); }
			else if ('program' === type || 'context' === type) { action.textContent = 'Review context'; action.addEventListener('click', function () { goToStep(1); }); }
			else if ('repeating' === type) { action.textContent = 'Add rows'; action.addEventListener('click', function () { goToStep(2); var details = root.querySelector('[data-repeating-section]'); if (details) { details.scrollIntoView({behavior:'smooth', block:'center'}); } }); }
			else {
				var key = String(field.field_key || '').toLowerCase().replace(/[^a-z0-9_-]/g, '');
				action.textContent = key && Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, key) ? 'Edit value' : 'Add value';
				action.addEventListener('click', function () { goToStep(2); if ('grade' === type || 'grade' === key) { var gradeInput = root.querySelector('[data-scoped-input="grade"]'); if (gradeInput) { gradeInput.focus(); } return; } if (key) { focusOrAddCredentialDetail(key, field.label || key); } });
			}
			row.appendChild(action);
			if (field.field_key && ('credential_detail' === type || 'grade' === type)) {
				var alias = document.createElement('button'); alias.type = 'button'; alias.className = 'button-link'; alias.textContent = 'Map existing detail'; alias.addEventListener('click', function () { mapDesignField(field); }); row.appendChild(alias);
			}
			list.appendChild(row);
		});
	}
	function focusOrAddCredentialDetail(key, label) {
		var scoped = root.querySelector('[data-scoped-input="' + key + '"]'); if (scoped) { scoped.focus(); scoped.scrollIntoView({behavior:'smooth', block:'center'}); return; }
		if (Object.prototype.hasOwnProperty.call(state.issuance.custom_merge_fields, key)) { renderOneOff(); var existing = root.querySelector('[data-oneoff-value="' + key + '"]'); if (existing) { existing.focus(); existing.scrollIntoView({behavior:'smooth', block:'center'}); } return; }
		addOneOff(key, label, '');
	}
	function mapDesignField(field) {
		var target = String(field.field_key || '').toLowerCase().replace(/[^a-z0-9_-]/g, ''); if (!target) { return; }
		var candidates = [];
		var contract = state.fieldContract && Array.isArray(state.fieldContract.fields) ? state.fieldContract.fields : [];
		contract.forEach(function (row) { if ('credential_custom' === String(row.value_source || '') && row.key) { candidates.push({key:String(row.key), label:String(row.label || row.key)}); } });
		Object.keys(state.issuance.custom_merge_fields).forEach(function (key) { if (!candidates.some(function (item) { return item.key === key; })) { candidates.push({key:key,label:key.replace(/_/g,' ')}); } });
		if (!candidates.length) { focusOrAddCredentialDetail(target, field.label || target); return; }
		var source = window.prompt('Map “' + (field.label || target) + '” to an existing credential detail key:\n' + candidates.map(function (item) { return item.key + ' — ' + item.label; }).join('\n'), candidates[0].key);
		if (!source) { return; }
		source = source.toLowerCase().replace(/[^a-z0-9_-]/g, '');
		if (!candidates.some(function (item) { return item.key === source; })) { showMessage('Choose one of the existing credential detail keys.', 'error'); return; }
		state.issuance.design_field_aliases[target] = source; onDataChanged(); showMessage('Design field mapped to existing credential detail “' + source + '”.', 'success');
	}

	function initEmail() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-email-mode]'), function (button) {
			button.addEventListener('click', function () {
				state.email.mode = button.getAttribute('data-email-mode');
				root.querySelectorAll('[data-email-mode]').forEach(function (b) { b.classList.toggle('is-active', b === button); });
				var design = root.querySelector('[data-email-design-field]'); if (design) { design.hidden = 'review' !== state.email.mode; }
				onDataChanged({skipPreview:true});
			});
		});
		var select = root.querySelector('[data-email-template]'); if (select) { select.addEventListener('change', function () { state.email.template_id = select.value || 'automatic'; onDataChanged({skipPreview:true}); }); }
	}

	function populateEmailDesigns() {
		var select = root.querySelector('[data-email-template]'); if (!select || !state.bootstrap) { return; }
		(state.bootstrap.email_designs || []).forEach(function (design) { if (!design || !design.id) { return; } var option = document.createElement('option'); option.value = design.id; option.textContent = design.name || design.id; select.appendChild(option); });
	}

	function validateStep(index) {
		clearMessage();
		if (0 === index) {
			if ('existing' === state.issuance.recipient_mode && !state.issuance.recipient_id) { showMessage('Choose an existing Recipient before continuing.', 'error'); focusPicker('recipient'); return false; }
			if ('new' === state.issuance.recipient_mode && !String(state.issuance.new_recipient.display_name || '').trim()) { showMessage('Enter the new Recipient display name before continuing.', 'error'); focusField('new_recipient.display_name'); return false; }
			if ('new' === state.issuance.recipient_mode) {
				var emailInput = root.querySelector('[data-field="new_recipient.email"]');
				if (emailInput && emailInput.value && !emailInput.validity.valid) { showMessage('Enter a valid recipient email address or leave it blank.', 'error'); emailInput.focus(); return false; }
			}
		}
		if (1 === index && 'program' === state.issuance.credential_basis && !state.issuance.program_id) { showMessage('Choose a ' + programTerm + ', or switch to Independent credential.', 'error'); focusPicker('program'); return false; }
		if (2 === index) {
			if (!String(state.issuance.credential_title || '').trim()) { showMessage('Enter the credential title.', 'error'); focusField('credential_title'); return false; }
			if (!state.issuance.valid_from) { showMessage('Choose the credential effective date.', 'error'); focusField('valid_from'); return false; }
			if ('date' === state.issuance.expiry_mode && !state.issuance.expires_at) { showMessage('Choose the credential expiry date.', 'error'); focusField('expires_at'); return false; }
			if ('date' === state.issuance.expiry_mode && state.issuance.expires_at < state.issuance.valid_from) { showMessage('The expiry date cannot be earlier than the credential effective date.', 'error'); focusField('expires_at'); return false; }
			if (state.bootstrap && state.bootstrap.numbering && !state.bootstrap.numbering.automatic && !String(state.issuance.display_number || '').trim()) { showMessage('Enter the certificate number required by the current numbering policy.', 'error'); focusField('display_number'); return false; }
			var missingRequired = root.querySelector('[data-scoped-input][required]:invalid'); if (missingRequired) { showMessage('Complete the required credential data before continuing.', 'error'); missingRequired.focus(); return false; }
		}
		if (3 === index) {
			if ('image' === state.issuance.presentation_mode && !state.issuance.certificate_image_id) { showMessage('Choose a PNG or JPEG certificate image.', 'error'); return false; }
			if ('design' === state.issuance.presentation_mode && 'assigned' === state.issuance.design_source && !state.issuance.template_id) { showMessage('Choose a published certificate design.', 'error'); focusPicker('template'); return false; }
			if ('design' === state.issuance.presentation_mode && state.preview && state.preview.data_readiness && state.preview.data_readiness.missing_count) { showMessage('The selected certificate design still needs credential data. Complete the highlighted requirements in Credential details.', 'error'); goToStep(2); return false; }
		}
		return true;
	}
	function focusPicker(kind) { var box = picker(kind); var search = box ? box.querySelector('[data-picker-search]') : null; if (search) { search.focus(); } }
	function focusField(path) { var input = root.querySelector('[data-field="' + path + '"]'); if (input) { input.focus(); } }

	function goToStep(index) {
		index = Math.max(0, Math.min(reviewStep, parseInt(index, 10) || 0));
		state.step = index;
		stepPanels.forEach(function (panel, i) { panel.hidden = i !== index; panel.classList.toggle('is-current', i === index); });
		stepButtons.forEach(function (button, i) { button.classList.toggle('is-current', i === index); if (i === index) { button.setAttribute('aria-current','step'); } else { button.removeAttribute('aria-current'); } button.classList.toggle('is-complete', i < index); });
		if (prevButton) { prevButton.disabled = index === 0 || state.busy; }
		if (nextButton) { nextButton.hidden = index === reviewStep; nextButton.disabled = state.busy; }
		if (stepStatus) { stepStatus.textContent = 'Step ' + (index + 1) + ' of ' + stepCount; }
		if (summaryProgressText) { summaryProgressText.textContent = 'Step ' + (index + 1) + ' of ' + stepCount; }
		if (summaryProgressBar) { summaryProgressBar.style.width = (((index + 1) / stepCount) * 100) + '%'; }
		if (reviewStep === index && !state.reviewFingerprint) { buildReview(); }
		window.scrollTo({top: Math.max(0, root.getBoundingClientRect().top + window.scrollY - 44), behavior:'smooth'});
	}
	function initStepper() {
		if (prevButton) { prevButton.addEventListener('click', function () { if (!state.busy) { goToStep(state.step - 1); } }); }
		if (nextButton) { nextButton.addEventListener('click', function () { if (!state.busy && validateStep(state.step)) { goToStep(state.step + 1); } }); }
		stepButtons.forEach(function (button, index) { button.addEventListener('click', function () { if (state.busy || index > state.step + 1) { return; } if (index > state.step && !validateStep(state.step)) { return; } goToStep(index); }); });
	}

	function buildReview() {
		if (!validateAllBeforeReview()) { return; }
		setBusy(true); showMessage(text('reviewing', 'Building server review…'), 'info');
		var stateBox = root.querySelector('[data-review-state]'); if (stateBox) { stateBox.hidden = true; }
		rest(cfg.reviewUrl, {method:'POST', body:{issuance:state.issuance,email:state.email}}).then(function (data) {
			state.reviewFingerprint = String(data.review_fingerprint || ''); state.reviewPayload = data.review || {}; state.reviewSignature = signature(); renderReview(); clearMessage();
		}).catch(function (error) {
			if (stateBox) { stateBox.hidden = false; }
			showRequestError(error);
			if ('certloom_manual_issue_design_data_missing' === error.code && error.data && Array.isArray(error.data.fields)) { renderDesignRequired(error.data.fields); }
		}).finally(function () { setBusy(false); });
	}
	function validateAllBeforeReview() { for (var i=0;i<reviewStep;i++) { if (!validateStep(i)) { goToStep(i); return false; } } return true; }
	function renderReview() {
		var review = state.reviewPayload || {}; var content = root.querySelector('[data-review-content]'); var tech = root.querySelector('[data-review-technical]'); var techBody = root.querySelector('[data-review-technical-body]'); var actions = root.querySelector('[data-review-actions]'); var stateBox = root.querySelector('[data-review-state]');
		if (stateBox) { stateBox.hidden = true; }
		if (content) {
			var rows = [
				['Recipient', review.recipient && review.recipient.name],
				[programTerm, review.program && review.program.title ? review.program.title : 'Independent credential'],
				[batchTerm, review.batch && review.batch.name ? review.batch.name : '—'],
				['Credential title', review.credential_title],
				['Title source', 'program' === review.title_source ? ('From ' + programTerm) : 'Custom for this credential'],
				['Effective date', review.valid_from],
				['Expiry', review.expires_at || 'No expiry'],
				['Certificate', review.presentation_mode === 'design' ? ((review.template && review.template.name) || 'Inherited design') : (review.presentation_mode === 'image' ? 'Uploaded image' : 'Details only')],
				[issuerTerm, review.issuer && review.issuer.name ? review.issuer.name : '—'],
				[signerTerm, review.signer && review.signer.name ? review.signer.name : '—'],
				[text('verificationFieldsReview', 'Verification fields'), review.verification_fields && review.verification_fields.length ? review.verification_fields.map(function (field) { return field.label || field.key || ''; }).filter(Boolean).join(', ') : 'None'],
				['Email', 'review' === state.email.mode ? ('Review next · ' + (state.email.template_id || 'automatic')) : 'Do not email now']
			];
			content.innerHTML = '<div class="certloom-review-grid">' + rows.map(function (row) { return '<div><small>' + escapeHtml(row[0]) + '</small><strong>' + escapeHtml(row[1] || '—') + '</strong></div>'; }).join('') + '</div>';
			content.hidden = false;
		}
		if (tech && techBody) {
			tech.hidden = false;
			var localeLabel = review.locale && review.locale.effective ? review.locale.effective : 'site default';
			var directionLabel = review.locale && review.locale.direction ? review.locale.direction.toUpperCase() : 'LTR';
			var designProvenance = review.template_resolution || 'not applicable';
			if (review.template_locale_variant && review.template_locale_variant.matched_locale) { designProvenance = 'Language variant ' + review.template_locale_variant.matched_locale + ' · ' + designProvenance; }
			techBody.innerHTML = '<dl><div><dt>Review SHA-256</dt><dd><code>' + escapeHtml(state.reviewFingerprint) + '</code></dd></div><div><dt>Credential language</dt><dd>' + escapeHtml(localeLabel) + ' · ' + escapeHtml(directionLabel) + '</dd></div><div><dt>Design provenance</dt><dd>' + escapeHtml(designProvenance) + '</dd></div><div><dt>Template revision</dt><dd>' + escapeHtml(review.technical && review.technical.template_revision_id ? '#' + review.technical.template_revision_id : '—') + '</dd></div></dl>';
		}
		if (actions) { actions.hidden = false; }
	}

	function issueCredential() {
		if (state.busy) { return; }
		if (!state.reviewFingerprint || state.reviewSignature !== signature()) { invalidateReview(); showMessage(text('reviewChanged', 'Something changed after Review. Review again before issuing.'), 'error'); buildReview(); return; }
		setBusy(true); showMessage(text('issuing', 'Issuing credential…'), 'info');
		rest(cfg.issueUrl, {method:'POST', body:{issuance:state.issuance,email:state.email,review_fingerprint:state.reviewFingerprint}}).then(function (data) { renderReceipt(data || {}); clearMessage(); }).catch(function (error) { showRequestError(error); }).finally(function () { setBusy(false); });
	}
	function renderReceipt(data) {
		var receipt = data.receipt || {}; var box = root.querySelector('[data-issue-receipt]'); var content = root.querySelector('[data-review-content]'); var tech = root.querySelector('[data-review-technical]'); var actions = root.querySelector('[data-review-actions]');
		if (content) { content.hidden = true; } if (tech) { tech.hidden = true; } if (actions) { actions.hidden = true; }
		if (!box) { return; }
		var continuations = data.continuations || {};
		box.innerHTML = '<div class="certloom-issue-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><span class="certloom-eyebrow">Issued</span><h3>Credential issued successfully</h3><p>' + escapeHtml(receipt.display_number || receipt.public_id || ('Version #' + receipt.version_id)) + '</p></div></div><div class="certloom-review-grid"><div><small>Certificate number</small><strong>' + escapeHtml(receipt.display_number || '—') + '</strong></div><div><small>Public credential ID</small><strong>' + escapeHtml(receipt.public_id || '—') + '</strong></div><div><small>Version</small><strong>#' + escapeHtml(receipt.version_id || '—') + '</strong></div><div><small>Issued</small><strong>' + escapeHtml(receipt.issued_at || '—') + '</strong></div></div><div class="certloom-issue-receipt-actions">' + ('review' === state.email.mode && continuations.email_url ? '<a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' + escapeHtml(continuations.email_url) + '">Review email</a>' : '') + (receipt.verification_url ? '<a class="button" href="' + escapeHtml(receipt.verification_url) + '" target="_blank" rel="noopener noreferrer">View credential</a>' : '') + (continuations.issue_another_url ? '<a class="button" target="_blank" rel="noopener noreferrer" href="' + escapeHtml(continuations.issue_another_url) + '">Issue another credential</a>' : '') + (continuations.credentials_url ? '<a class="button" target="_blank" rel="noopener noreferrer" href="' + escapeHtml(continuations.credentials_url) + '">Back to Credentials</a>' : '') + '</div><p class="certloom-issue-download-note">Online verification is available now. Downloadable PDF/PNG/JPEG certificate files are not available yet.</p><details class="certloom-issue-technical certloom-issue-receipt-technical"><summary>Technical details</summary><p>Certificate-file downloads remain unavailable until the certificate renderer is approved for this release.</p></details>';
		box.hidden = false;
		if (prevButton) { prevButton.hidden = true; } if (nextButton) { nextButton.hidden = true; }
	}

	function initReviewActions() {
		var build = root.querySelector('[data-build-review]'); if (build) { build.addEventListener('click', buildReview); }
		var issue = root.querySelector('[data-issue-confirm]'); if (issue) { issue.addEventListener('click', issueCredential); }
	}

	function updateSummary() {
		var recipient = root.querySelector('[data-summary-recipient]'); var program = root.querySelector('[data-summary-program]'); var batch = root.querySelector('[data-summary-batch]'); var title = root.querySelector('[data-summary-title]'); var certificate = root.querySelector('[data-summary-certificate]'); var email = root.querySelector('[data-summary-email]');
		if (recipient) { recipient.textContent = 'existing' === state.issuance.recipient_mode ? (state.selected.recipient ? state.selected.recipient.label : '—') : (state.issuance.new_recipient.display_name || 'New recipient'); }
		if (program) { program.textContent = 'independent' === state.credentialBasis ? 'Independent credential' : (state.selected.program ? state.selected.program.label : '—'); }
		if (batch) { batch.textContent = state.selected.batch ? state.selected.batch.label : '—'; }
		if (title) { title.textContent = state.issuance.credential_title || '—'; }
		if (certificate) { certificate.textContent = 'design' === state.issuance.presentation_mode ? ('assigned' === state.issuance.design_source ? (state.selected.template ? state.selected.template.label : 'Assigned design') : 'Inherited Certloom design') : ('image' === state.issuance.presentation_mode ? 'Uploaded image' : 'Details only'); }
		if (email) { email.textContent = 'review' === state.email.mode ? ('Review next · ' + (state.email.template_id || 'automatic')) : 'Do not email now'; }
	}

	function initQuickCreate() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-quick-open]'), function (button) { button.addEventListener('click', function () { var kind = button.getAttribute('data-quick-open'); if ('batch' === kind && !state.issuance.program_id) { showMessage('Choose a ' + programTerm + ' before adding a ' + batchTerm + '.', 'error'); return; } var dialog = root.querySelector('[data-quick-dialog="' + kind + '"]'); if (dialog && dialog.showModal) { dialog.showModal(); } }); });
		Array.prototype.forEach.call(root.querySelectorAll('[data-quick-close]'), function (button) { button.addEventListener('click', function () { var dialog = button.closest('dialog'); if (dialog && dialog.open) { dialog.close(); } }); });
		Array.prototype.forEach.call(root.querySelectorAll('[data-quick-form]'), function (quickForm) {
			quickForm.addEventListener('submit', function (event) {
				event.preventDefault(); var kind = quickForm.getAttribute('data-quick-form'); var formData = new FormData(quickForm); var payload = {}; formData.forEach(function (value,key) { payload[key] = value; });
				if ('batch' === kind) { payload.program_id = state.issuance.program_id; }
				var url = 'program' === kind ? cfg.quickProgramUrl : cfg.quickBatchUrl;
				var submit = quickForm.querySelector('button[type="submit"]'); if (submit) { submit.disabled = true; }
				rest(url, {method:'POST', body:payload}).then(function (data) {
					var choice = normalizeChoice(kind, data[kind] || {}); setPickerSelected(kind, choice); quickForm.reset(); var dialog = quickForm.closest('dialog'); if (dialog) { dialog.close(); } showMessage((choice.label || 'New context') + ' added and selected.', 'success');
				}).catch(function (error) { showMessage(error.message || 'Could not create context.', 'error'); }).finally(function () { if (submit) { submit.disabled = false; } });
			});
		});
	}

	function applyDeepLink(bootstrap) {
		var deep = bootstrap.deep_link || {};
		if (deep.recipient) { setPickerSelected('recipient', normalizeChoice('recipient', deep.recipient), {silent:true}); state.issuance.recipient_id = parseInt(deep.recipient.id || 0, 10) || 0; }
		if (deep.program) {
			var programChoice = normalizeChoice('program', deep.program); setPickerSelected('program', programChoice, {silent:true}); state.selected.program = programChoice; state.issuance.program_id = programChoice.id; state.issuance.credential_title = programChoice.title || programChoice.label; state.issuance.title_source = 'program'; setTitleInput(state.issuance.credential_title);
			return loadProgramContext(false).then(function () { searchPicker('batch', '', true); if (deep.batch) { var batchChoice = normalizeChoice('batch', deep.batch); setPickerSelected('batch', batchChoice, {silent:true}); state.issuance.batch_id = batchChoice.id; return loadProgramContext(false); } });
		}
		return Promise.resolve();
	}

	function boot() {
		initPickers(); initRecipientModes(); initBasis(); bindSimpleFields(); initOneOff(); initPresentation(); initEmail(); initStepper(); initReviewActions(); initQuickCreate();
		var deep = cfg.deepLink || {}; var url = new URL(cfg.bootstrapUrl, window.location.href); ['recipient_id','program_id','batch_id'].forEach(function (key) { if (deep[key]) { url.searchParams.set(key, String(deep[key])); } });
		rest(url.toString()).then(function (data) {
			state.bootstrap = data || {}; state.issuance.valid_from = String(data.today || ''); var date = root.querySelector('[data-field="valid_from"]'); if (date) { date.value = state.issuance.valid_from; }
			if (data.numbering && !data.numbering.automatic) {
				var numberField = root.querySelector('[data-display-number-field]');
				var numberInput = root.querySelector('[data-field="display_number"]');
				if (numberField) { numberField.hidden = false; }
				if (numberInput) { numberInput.required = true; numberInput.setAttribute('aria-required', 'true'); }
			}
			populateEmailDesigns(); renderRepeating(); renderExpiryMode();
			return applyDeepLink(data);
		}).then(function () {
			loading.hidden = true; body.hidden = false; renderScopedFields(); renderProgramContext(); renderPresentation(); renderOneOff(); updateSummary(); goToStep(0); clearMessage();
		}).catch(function (error) {
			loading.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(error.message || text('loadFailed', 'Certloom could not load this workspace.')) + '</p></div>';
		});
	}

	window.addEventListener('resize', function () { if (state.preview) { fitPreview(); } });
	boot();
})();
