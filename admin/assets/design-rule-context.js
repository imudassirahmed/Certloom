(function () {
	'use strict';

	var cfg = window.certloomDesignRules || {};
	var issuedPurpose = 'credential_issued';

	function q(selector, root) { return (root || document).querySelector(selector); }
	function qa(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

	function parseRules(dialog) {
		try {
			var raw = dialog.getAttribute('data-email-rules') || '{}';
			var parsed = JSON.parse(raw);
			return parsed && typeof parsed === 'object' ? parsed : {};
		} catch (error) {
			return {};
		}
	}

	function targetPayload(dialog) {
		var parts = String(dialog.getAttribute('data-target') || '').split(':');
		return { scope_type: parts[0] || '', scope_id: parseInt(parts[1] || '0', 10) || 0 };
	}

	function feedback(dialog, message, isError) {
		var node = q('[data-context-feedback]', dialog);
		if (!node) { return; }
		node.textContent = message || '';
		node.classList.toggle('is-error', !!isError);
	}

	function renderPreview(box, data) {
		if (!box) { return; }
		box.classList.remove('is-loading', 'is-empty');
		box.innerHTML = String(data && data.html ? data.html : '');
		if (window.CertloomTextFit) { window.CertloomTextFit.refresh(box); }
		var footer = document.createElement('footer');
		var strong = document.createElement('strong');
		var span = document.createElement('span');
		strong.textContent = String(data && data.label ? data.label : '');
		span.textContent = String(data && data.source ? data.source : '');
		footer.appendChild(strong);
		footer.appendChild(span);
		box.appendChild(footer);
		if (data && data.varies) {
			var note = document.createElement('p');
			note.className = 'certloom-rule-preview-varies';
			note.textContent = cfg.varies || 'Inherited results vary across this selection.';
			box.appendChild(note);
		}
	}

	function request(params) {
		var body = new URLSearchParams();
		Object.keys(params).forEach(function (key) { body.set(key, params[key]); });
		return fetch(cfg.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) { return response.json(); });
	}

	function preview(dialog, kind) {
		var target = targetPayload(dialog);
		var choice = q('[data-context-choice="' + kind + '"]', dialog);
		var purpose = q('[data-context-purpose]', dialog);
		var box = q('[data-context-preview="' + kind + '"]', dialog);
		if (!choice || !box || !target.scope_type || !target.scope_id) { return; }
		box.classList.add('is-loading');
		request({
			action: 'certloom_design_rules_preview',
			nonce: cfg.nonce || '',
			kind: kind,
			choice: choice.value || 'inherit',
			purpose: purpose ? purpose.value : issuedPurpose,
			targets_json: JSON.stringify([target])
		}).then(function (response) {
			if (!response || !response.success) {
				throw new Error(response && response.data && response.data.message ? response.data.message : (cfg.previewUnavailable || 'Preview unavailable'));
			}
			renderPreview(box, response.data || {});
		}).catch(function (error) {
			box.classList.remove('is-loading');
			box.classList.add('is-empty');
			box.textContent = error.message || cfg.previewUnavailable || 'Preview unavailable';
		});
	}

	function option(value, label, selected) {
		var node = document.createElement('option');
		node.value = value;
		node.textContent = label;
		if (selected) { node.selected = true; }
		return node;
	}

	function rebuildEmailChoices(dialog) {
		var purpose = q('[data-context-purpose]', dialog);
		var select = q('[data-context-choice="email"]', dialog);
		if (!purpose || !select) { return; }
		var rules = parseRules(dialog);
		var currentPurpose = String(purpose.value || issuedPurpose);
		var directId = String(rules[currentPurpose] || '');
		var fragment = document.createDocumentFragment();
		fragment.appendChild(option('inherit', cfg.inherit || 'Inherit from parent', !directId));
		(cfg.emailDesigns || []).forEach(function (design) {
			if (String(design.purpose || '') !== currentPurpose) { return; }
			fragment.appendChild(option('design:' + String(design.id || ''), String(design.name || ''), String(design.id || '') === directId));
		});
		select.replaceChildren(fragment);
		preview(dialog, 'email');
	}

	function originalEmailChoice(dialog, purpose) {
		var rules = parseRules(dialog);
		var id = String(rules[purpose] || '');
		return id ? 'design:' + id : 'inherit';
	}

	function updateSummary(dialog, kind, data) {
		var card = dialog.previousElementSibling;
		if (!card || !card.classList.contains('certloom-context-design-card')) { return; }
		var label = q('[data-context-summary-label="' + kind + '"]', card);
		var source = q('[data-context-summary-source="' + kind + '"]', card);
		if (label) { label.textContent = String(data && data.label ? data.label : ''); }
		if (source) { source.textContent = String(data && data.source ? data.source : ''); }
	}

	function save(dialog) {
		var button = q('[data-context-save]', dialog);
		var cert = q('[data-context-choice="certificate"]', dialog);
		var email = q('[data-context-choice="email"]', dialog);
		var purpose = q('[data-context-purpose]', dialog);
		if (!button || !cert || !email || !purpose) { return; }

		var currentPurpose = String(purpose.value || issuedPurpose);
		var certActual = String(cert.value || 'inherit');
		var emailActual = String(email.value || 'inherit');
		var certOriginal = String(dialog.getAttribute('data-cert-original') || 'inherit');
		var emailOriginal = originalEmailChoice(dialog, currentPurpose);
		var certMutation = certActual === certOriginal ? 'no_change' : certActual;
		var emailMutation = emailActual === emailOriginal ? 'no_change' : emailActual;

		if ('no_change' === certMutation && 'no_change' === emailMutation) {
			feedback(dialog, cfg.contextNoChanges || 'No design changes to save.', false);
			return;
		}

		button.disabled = true;
		feedback(dialog, cfg.contextSaving || 'Saving…', false);
		request({
			action: 'certloom_design_rules_apply_ajax',
			nonce: cfg.nonce || '',
			target: String(dialog.getAttribute('data-target') || ''),
			certificate_choice: certMutation,
			email_choice: emailMutation,
			purpose: currentPurpose
		}).then(function (response) {
			if (!response || !response.success) {
				throw new Error(response && response.data && response.data.message ? response.data.message : (cfg.contextFailed || 'The design changes could not be saved.'));
			}
			if ('no_change' !== certMutation) { dialog.setAttribute('data-cert-original', certActual); }
			if ('no_change' !== emailMutation) {
				var rules = parseRules(dialog);
				if ('inherit' === emailActual) { delete rules[currentPurpose]; }
				else { rules[currentPurpose] = emailActual.replace(/^design:/, ''); }
				dialog.setAttribute('data-email-rules', JSON.stringify(rules));
			}
			if (response.data && response.data.certificate) {
				renderPreview(q('[data-context-preview="certificate"]', dialog), response.data.certificate);
				updateSummary(dialog, 'certificate', response.data.certificate);
			}
			if (response.data && response.data.email) {
				renderPreview(q('[data-context-preview="email"]', dialog), response.data.email);
				if (currentPurpose === issuedPurpose) { updateSummary(dialog, 'email', response.data.email); }
			}
			feedback(dialog, response.data && response.data.message ? response.data.message : (cfg.contextSaved || 'Designs updated.'), false);
		}).catch(function (error) {
			feedback(dialog, error.message || cfg.contextFailed || 'The design changes could not be saved.', true);
		}).finally(function () {
			button.disabled = false;
		});
	}

	function openDialog(dialog, opener) {
		dialog._certloomOpener = opener || null;
		feedback(dialog, '', false);
		if (typeof dialog.showModal === 'function') { dialog.showModal(); }
		else { dialog.setAttribute('open', 'open'); }
		if (window.CertloomTextFit) { window.CertloomTextFit.refresh(dialog); }
		var focusTarget = q('[data-context-choice="certificate"]', dialog) || q('[data-context-purpose]', dialog);
		if (focusTarget) { window.setTimeout(function () { focusTarget.focus(); }, 0); }
	}

	function closeDialog(dialog) {
		if (typeof dialog.close === 'function' && dialog.open) { dialog.close(); }
		else { dialog.removeAttribute('open'); }
		if (dialog._certloomOpener && typeof dialog._certloomOpener.focus === 'function') { dialog._certloomOpener.focus(); }
	}

	function initDialog(dialog) {
		var cert = q('[data-context-choice="certificate"]', dialog);
		if (cert) {
			dialog.setAttribute('data-cert-original', String(cert.value || 'inherit'));
			cert.addEventListener('change', function () { feedback(dialog, '', false); preview(dialog, 'certificate'); });
		}
		var email = q('[data-context-choice="email"]', dialog);
		if (email) { email.addEventListener('change', function () { feedback(dialog, '', false); preview(dialog, 'email'); }); }
		var purpose = q('[data-context-purpose]', dialog);
		if (purpose) { purpose.addEventListener('change', function () { feedback(dialog, '', false); rebuildEmailChoices(dialog); }); }
		var saveButton = q('[data-context-save]', dialog);
		if (saveButton) { saveButton.addEventListener('click', function () { save(dialog); }); }
		qa('[data-certloom-context-design-close]', dialog).forEach(function (button) { button.addEventListener('click', function () { closeDialog(dialog); }); });
		dialog.addEventListener('close', function () {
			if (dialog._certloomOpener && typeof dialog._certloomOpener.focus === 'function') { dialog._certloomOpener.focus(); }
		});
	}

	function init() {
		qa('[data-certloom-context-design-dialog]').forEach(initDialog);
		qa('[data-certloom-context-design-open]').forEach(function (button) {
			button.addEventListener('click', function () {
				var id = button.getAttribute('data-certloom-context-design-open');
				var dialog = id ? document.getElementById(id) : null;
				if (dialog) { openDialog(dialog, button); }
			});
		});
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
	else { init(); }
}());
