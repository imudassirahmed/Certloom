(function () {
	'use strict';

	var cfg = window.certloomDesignRules || {};

	function q(selector, root) {
		return (root || document).querySelector(selector);
	}

	function qa(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function reducedMotion() {
		return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
	}

	function targetFromKey(key) {
		var parts = String(key || '').split(':');
		return {
			scope_type: parts[0] || '',
			scope_id: parseInt(parts[1] || '0', 10) || 0
		};
	}

	function selection(form) {
		if (!form.__certloomRuleSelection) {
			form.__certloomRuleSelection = new Set();
			qa('.certloom-design-rule-target input[type="checkbox"]:checked', form).forEach(function (input) {
				form.__certloomRuleSelection.add(String(input.value || ''));
			});
		}
		return form.__certloomRuleSelection;
	}

	function targets(form) {
		var keys;
		if (form.classList.contains('certloom-design-rules-global-form')) {
			keys = qa('input[name="targets[]"]', form).map(function (input) { return String(input.value || ''); });
		} else {
			keys = Array.from(selection(form));
		}
		return keys.map(targetFromKey).filter(function (target) {
			if ('global' === target.scope_type) { return 0 === target.scope_id; }
			return /^(issuer|program|batch)$/.test(target.scope_type) && target.scope_id > 0;
		});
	}

	function addOption(fragment, value, label, selected) {
		var option = document.createElement('option');
		option.value = value;
		option.textContent = label;
		if (selected) {
			option.selected = true;
		}
		fragment.appendChild(option);
	}

	function setEmailOptions(form) {
		var purpose = q('[data-certloom-rule-purpose]', form);
		var select = q('[data-certloom-rule-choice="email"]', form);
		if (!purpose || !select) {
			return;
		}

		var currentPurpose = String(purpose.value || 'credential_issued');
		var global = form.classList.contains('certloom-design-rules-global-form');
		var chosen = global && cfg.globalEmailRules ? String(cfg.globalEmailRules[currentPurpose] || '') : '';
		var fragment = document.createDocumentFragment();

		if (!global) {
			addOption(fragment, 'no_change', cfg.noChange || 'No change', true);
		}
		addOption(
			fragment,
			'inherit',
			global ? (cfg.noGlobal || 'No explicit global design') : (cfg.inherit || 'Inherit from parent'),
			global && !chosen
		);
		(cfg.emailDesigns || []).forEach(function (design) {
			if (String(design.purpose || '') !== currentPurpose) {
				return;
			}
			addOption(fragment, 'design:' + design.id, design.name, global && String(design.id) === chosen);
		});
		select.replaceChildren(fragment);
	}

	function renderPreview(box, data) {
		if (!box) {
			return;
		}
		box.classList.remove('is-empty', 'is-loading');
		box.innerHTML = String(data.html || '');
		var footer = document.createElement('footer');
		var strong = document.createElement('strong');
		var source = document.createElement('span');
		strong.textContent = String(data.label || '');
		source.textContent = String(data.source || '');
		footer.appendChild(strong);
		footer.appendChild(source);
		box.appendChild(footer);

		if (data.varies) {
			var note = document.createElement('p');
			note.className = 'certloom-rule-preview-varies';
			note.textContent = cfg.varies || 'Inherited results vary across this selection.';
			box.appendChild(note);
		}
	}

	function preview(form, kind) {
		var choice = q('[data-certloom-rule-choice="' + kind + '"]', form);
		var box = q('[data-certloom-rule-preview="' + kind + '"]', form);
		var selected = targets(form);
		if (!choice || !box || !selected.length) {
			return;
		}

		box.classList.add('is-loading');
		var body = new URLSearchParams();
		body.set('action', 'certloom_design_rules_preview');
		body.set('nonce', cfg.nonce || '');
		body.set('kind', kind);
		body.set('choice', choice.value || 'no_change');
		var purpose = q('[data-certloom-rule-purpose]', form);
		body.set('purpose', purpose ? purpose.value : 'credential_issued');
		body.set('targets_json', JSON.stringify(selected));

		fetch(cfg.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response || !response.success) {
					throw new Error(response && response.data && response.data.message ? response.data.message : cfg.previewUnavailable);
				}
				renderPreview(box, response.data || {});
			})
			.catch(function (error) {
				box.classList.remove('is-loading');
				box.classList.add('is-empty');
				box.replaceChildren();
				var message = document.createElement('p');
				message.textContent = error.message || cfg.previewUnavailable || 'Preview unavailable';
				box.appendChild(message);
			});
	}

	function update(form) {
		var count = selection(form).size;
		var label = q('[data-certloom-rule-selected]', form);
		if (label) {
			label.textContent = count + ' selected';
		}
		var apply = q('[data-certloom-rule-apply]', form);
		if (apply) {
			apply.disabled = count < 1;
		}
		if (count) {
			preview(form, 'certificate');
			preview(form, 'email');
		}
	}

	function targetRow(payload, form) {
		var row = document.createElement('label');
		row.className = 'certloom-design-rule-target';
		row.setAttribute('data-certloom-rule-target', '');
		row.setAttribute('data-search', String((payload.name || '') + ' ' + (payload.meta || '')).toLowerCase());

		var input = document.createElement('input');
		input.type = 'checkbox';
		input.name = 'targets[]';
		input.value = String(payload.key || '');
		input.checked = selection(form).has(input.value);
		row.appendChild(input);

		var main = document.createElement('span');
		main.className = 'certloom-design-rule-target-main';
		var name = document.createElement('strong');
		name.textContent = String(payload.name || '');
		var meta = document.createElement('small');
		meta.textContent = String(payload.meta || '');
		var state = document.createElement('span');
		state.className = 'certloom-design-rule-target-state';
		var certificate = document.createElement('span');
		certificate.textContent = 'Certificate: ' + String(payload.certificate || 'Inherited');
		var email = document.createElement('span');
		email.textContent = 'Issued email: ' + String(payload.email || 'Inherited');
		state.appendChild(certificate);
		state.appendChild(email);
		main.appendChild(name);
		main.appendChild(meta);
		main.appendChild(state);
		row.appendChild(main);

		var edit = document.createElement('button');
		edit.type = 'button';
		edit.className = 'button button-small';
		edit.setAttribute('data-certloom-rule-edit', '');
		edit.textContent = 'Edit';
		row.appendChild(edit);
		return row;
	}

	function loadTargets(form, scope, page, append) {
		var list = q('[data-certloom-rule-list="' + scope + '"]', form);
		var group = q('[data-certloom-rule-group="' + scope + '"]', form);
		if (!list || !group) {
			return Promise.resolve();
		}
		var search = q('[data-certloom-rule-search]', form);
		var term = search ? String(search.value || '').trim() : '';
		var status = q('[data-certloom-rule-target-status]', group);
		var loadMore = q('[data-certloom-rule-load-more]', group);
		if (status) {
			status.textContent = cfg.loadingTargets || 'Loading…';
		}
		if (loadMore) {
			loadMore.disabled = true;
		}

		var body = new URLSearchParams();
		body.set('action', 'certloom_design_rules_targets');
		body.set('nonce', cfg.nonce || '');
		body.set('scope', scope);
		body.set('search', term);
		body.set('target_page', String(page));
		var usageType = String(form.getAttribute('data-certloom-usage-type') || '');
		var usageId = String(form.getAttribute('data-certloom-usage-id') || '');
		if (usageType && usageId) {
			body.set('design_type', usageType);
			body.set('design_id', usageId);
		}

		return fetch(cfg.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (response) {
				if (!response || !response.success) {
					throw new Error(response && response.data && response.data.message ? response.data.message : (cfg.targetsUnavailable || 'Targets could not be loaded.'));
				}
				var data = response.data || {};
				var fragment = document.createDocumentFragment();
				(data.items || []).forEach(function (item) {
					fragment.appendChild(targetRow(item, form));
				});
				if (!append) {
					list.replaceChildren(fragment);
				} else {
					list.appendChild(fragment);
				}
				var total = q('[data-certloom-rule-total]', group);
				if (total) {
					total.textContent = String(data.total || 0);
				}
				var pagination = q('[data-certloom-rule-pagination]', group);
				var button = q('[data-certloom-rule-load-more]', group);
				if (data.hasMore) {
					if (!button && pagination) {
						button = document.createElement('button');
						button.type = 'button';
						button.className = 'button';
						button.setAttribute('data-certloom-rule-load-more', '');
						button.setAttribute('data-scope', scope);
						button.textContent = cfg.loadMore || 'Load more';
						pagination.insertBefore(button, pagination.firstChild);
					}
					if (button) {
						button.disabled = false;
						button.setAttribute('data-page', String(data.page || page));
					}
				} else if (button) {
					button.remove();
				}
				if (status) {
					status.textContent = data.total ? '' : (cfg.noTargets || 'No matching records.');
				}
				update(form);
			})
			.catch(function (error) {
				if (status) {
					status.textContent = error.message || cfg.targetsUnavailable || 'Targets could not be loaded.';
				}
				if (loadMore) {
					loadMore.disabled = false;
				}
			});
	}

	function prepareSubmission(form) {
		qa('[data-certloom-rule-selection-hidden]', form).forEach(function (node) { node.remove(); });
		selection(form).forEach(function (key) {
			var hidden = document.createElement('input');
			hidden.type = 'hidden';
			hidden.name = 'targets[]';
			hidden.value = key;
			hidden.setAttribute('data-certloom-rule-selection-hidden', '');
			form.appendChild(hidden);
		});
	}

	function initBulkForm(form) {
		selection(form);
		var searchTimer = 0;

		form.addEventListener('change', function (event) {
			var checkbox = event.target.closest('.certloom-design-rule-target input[type="checkbox"]');
			if (!checkbox) {
				return;
			}
			var selected = selection(form);
			if (checkbox.checked) {
				selected.add(String(checkbox.value || ''));
			} else {
				selected.delete(String(checkbox.value || ''));
			}
			update(form);
		});

		form.addEventListener('click', function (event) {
			var edit = event.target.closest('[data-certloom-rule-edit]');
			if (edit) {
				event.preventDefault();
				var row = edit.closest('.certloom-design-rule-target');
				var input = row ? q('input[type="checkbox"]', row) : null;
				var selected = selection(form);
				selected.clear();
				qa('.certloom-design-rule-target input[type="checkbox"]', form).forEach(function (item) { item.checked = false; });
				if (input) {
					input.checked = true;
					selected.add(String(input.value || ''));
				}
				update(form);
				var editor = q('[data-certloom-rule-editor]', form);
				if (editor) {
					editor.scrollIntoView({behavior: reducedMotion() ? 'auto' : 'smooth', block: 'start'});
				}
				return;
			}

			var loadMore = event.target.closest('[data-certloom-rule-load-more]');
			if (loadMore) {
				event.preventDefault();
				var scope = String(loadMore.getAttribute('data-scope') || '');
				var nextPage = (parseInt(loadMore.getAttribute('data-page') || '1', 10) || 1) + 1;
				loadTargets(form, scope, nextPage, true);
			}
		});

		var clear = q('[data-certloom-rule-clear]', form);
		if (clear) {
			clear.addEventListener('click', function () {
				selection(form).clear();
				qa('.certloom-design-rule-target input[type="checkbox"]', form).forEach(function (input) { input.checked = false; });
				update(form);
			});
		}

		var search = q('[data-certloom-rule-search]', form);
		if (search) {
			search.addEventListener('input', function () {
				window.clearTimeout(searchTimer);
				searchTimer = window.setTimeout(function () {
					['issuer', 'program', 'batch'].forEach(function (scope) {
						loadTargets(form, scope, 1, false);
					});
				}, 250);
			});
		}

		form.addEventListener('submit', function () {
			prepareSubmission(form);
		});
		update(form);
	}

	function initForm(form) {
		setEmailOptions(form);
		qa('[data-certloom-rule-choice]', form).forEach(function (select) {
			select.addEventListener('change', function () {
				preview(form, select.getAttribute('data-certloom-rule-choice'));
			});
		});
		var purpose = q('[data-certloom-rule-purpose]', form);
		if (purpose) {
			purpose.addEventListener('change', function () {
				setEmailOptions(form);
				preview(form, 'email');
			});
		}

		if (form.classList.contains('certloom-design-rules-global-form')) {
			preview(form, 'certificate');
			preview(form, 'email');
		} else {
			initBulkForm(form);
		}
	}

	function init() {
		qa('[data-certloom-design-rule-form]').forEach(initForm);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
}());
