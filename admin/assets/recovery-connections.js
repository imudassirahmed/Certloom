(function () {
	'use strict';
	var config = window.certloomRecoveryConnections;
	if (!config || !window.fetch) { return; }
	document.querySelectorAll('[data-recovery-connection]').forEach(function (original, index) {
		var field = document.createElement('input'), list = document.createElement('div'), status = document.createElement('span'), clear = document.createElement('button');
		var container = original.closest('td'), timer, controller, current = -1, options = [], listId = 'certloom-recovery-options-' + index;
		field.type = 'search'; field.autocomplete = 'off'; field.placeholder = config.search; field.value = original.dataset.label || original.value;
		field.setAttribute('role', 'combobox'); field.setAttribute('aria-autocomplete', 'list'); field.setAttribute('aria-expanded', 'false'); field.setAttribute('aria-controls', listId); field.setAttribute('aria-label', original.getAttribute('aria-label'));
		list.id = listId; list.hidden = true; list.className = 'certloom-recovery-options'; list.setAttribute('role', 'listbox');
		status.className = 'description'; status.setAttribute('role', 'status'); clear.type = 'button'; clear.className = 'button button-small'; clear.textContent = config.disconnect;
		original.type = 'hidden'; container.append(field, clear, list, status);
		if (original.dataset.kind === 'objects' && !original.dataset.type) { field.readOnly = true; }
		function close() { list.hidden = true; field.setAttribute('aria-expanded', 'false'); field.removeAttribute('aria-activedescendant'); current = -1; }
		function choose(item) { original.value = item.id; field.value = item.label; field.setCustomValidity(''); status.textContent = config.selected; close(); field.focus(); }
		function render(items) {
			list.replaceChildren(); options = [];
			items.forEach(function (item, number) { var option = document.createElement('button'); option.type = 'button'; option.id = listId + '-' + number; option.className = 'certloom-recovery-option'; option.setAttribute('role', 'option'); option.setAttribute('aria-selected', 'false'); option.textContent = item.label; option.addEventListener('click', function () { choose(item); }); list.append(option); options.push({node: option, item: item}); });
			list.hidden = !items.length; field.setAttribute('aria-expanded', String(!!items.length)); status.textContent = items.length ? config.choose : config.empty;
		}
		field.addEventListener('input', function () {
			clearTimeout(timer); if (controller) { controller.abort(); } close(); original.value = ''; field.setCustomValidity(config.choose); status.textContent = config.choose;
			var query = field.value.trim(); if (query.length < 2 && !/^\d+$/.test(query)) { return; }
			timer = setTimeout(function () {
				controller = new AbortController(); var url = new URL(config.endpoint); url.searchParams.set('kind', original.dataset.kind); url.searchParams.set('type', original.dataset.type || ''); url.searchParams.set('q', query); status.textContent = config.loading;
				fetch(url, {credentials: 'same-origin', headers: {'X-WP-Nonce': config.nonce}, signal: controller.signal}).then(function (response) { if (!response.ok) { throw new Error(); } return response.json(); }).then(function (result) { if (field.value.trim() === query && Array.isArray(result.items)) { render(result.items); } }).catch(function (error) { if (error.name !== 'AbortError') { status.textContent = config.failed; } });
			}, 200);
		});
		field.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') { close(); }
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { if (list.hidden || !options.length) { return; } event.preventDefault(); current = (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length; options.forEach(function (option, i) { option.node.setAttribute('aria-selected', String(i === current)); }); field.setAttribute('aria-activedescendant', options[current].node.id); options[current].node.scrollIntoView({block: 'nearest'}); }
			if (event.key === 'Enter' && !list.hidden) { event.preventDefault(); if (current >= 0) { choose(options[current].item); } }
		});
		clear.addEventListener('click', function () { clearTimeout(timer); if (controller) { controller.abort(); } original.value = ''; field.value = ''; field.setCustomValidity(''); status.textContent = config.disconnected; close(); field.focus(); });
		document.addEventListener('pointerdown', function (event) { if (!container.contains(event.target)) { close(); } });
	});
}());
