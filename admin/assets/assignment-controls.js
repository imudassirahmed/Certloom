(function () {
	'use strict';
	const text = window.certloomAssignmentText || {};
	function enhance(select) {
		if (select.dataset.assignmentReady) { if (select.certloomSyncAssignment) { select.certloomSyncAssignment(); } return; }
		if (select.closest('[data-issuance-popup-template]')) { return; }
		if (select.matches('[data-certloom-scope-field-rule], [data-program-batch-field]')) { return; }
		const inherited = [...select.options].find(option => /inherit|system default|automatic.*hierarchy/i.test(option.textContent));
		if (!inherited) { return; }
		const noChange = [...select.options].find(option => option.value === 'no_change');
		select.dataset.assignmentReady = '1';
		const template = select.hasAttribute('data-email-template') || /design|template|certificate_choice|email_choice/i.test(select.name + ' ' + select.dataset.contextChoice + ' ' + (select.dataset.certloomContextLookup || ''));
		const host = document.createElement('div'); host.className = 'certloom-assignment-control';
		const label = document.createElement('label'), toggle = document.createElement('input'); toggle.type = 'checkbox';
		label.append(toggle, document.createTextNode(' ' + (template ? text.assignDesign || 'Assign a different design' : text.assignSetting || 'Use a different setting')));
		const note = document.createElement('p'); note.className = 'description'; note.dataset.assignmentNote = '';
		const lookup = select.closest('.certloom-context-lookup'), enclosingLabel = select.closest('label'), container = enclosingLabel || lookup || select;
		const dialog = select.closest('dialog'), kind = select.dataset.contextChoice;
		const summary = dialog && kind ? dialog.previousElementSibling : null;
		const effective = summary && summary.querySelector('[data-context-summary-label="' + kind + '"]'), source = summary && summary.querySelector('[data-context-summary-source="' + kind + '"]');
		if (effective && source && select.value === inherited.value) { select.dataset.inheritedDescription = effective.textContent + ' · ' + source.textContent; }
		if (enclosingLabel) { host.classList.add(...enclosingLabel.classList); }
		container.before(host); host.append(label, note, container);
		let enabled;
		if (noChange) { const changeLabel = document.createElement('label'); enabled = document.createElement('input'); enabled.type = 'checkbox'; changeLabel.append(enabled, document.createTextNode(' ' + (text.changeSetting || 'Change this setting'))); host.prepend(changeLabel); noChange.hidden = true; enabled.addEventListener('change', () => { select.value = enabled.checked ? inherited.value : noChange.value; select.dispatchEvent(new Event('change', {bubbles:true})); }); }
		let remembered = '', updating = false;
		function sync() {
			if (updating) { return; }
			const option = [...select.options].find(item => item.value === inherited.value); if (option) { option.hidden = true; }
			if (enabled) { enabled.checked = select.value !== noChange.value; label.hidden = !enabled.checked; }
			toggle.checked = select.value !== inherited.value && (!noChange || select.value !== noChange.value);
			if (toggle.checked) { remembered = select.value; }
			select.hidden = !toggle.checked;
			container.hidden = !toggle.checked;
			const currentLookup = select.closest('.certloom-context-lookup'); if (currentLookup) { currentLookup.hidden = !toggle.checked; }
			note.hidden = toggle.checked; note.textContent = enabled && !enabled.checked ? noChange.textContent : select.dataset.inheritedDescription || inherited.textContent;
		}
		toggle.addEventListener('change', () => {
			updating = true;
			select.value = toggle.checked ? remembered || ([...select.options].find(option => option.value !== inherited.value && option !== noChange && !option.disabled) || inherited).value : inherited.value;
			updating = false; select.dispatchEvent(new Event('change', {bubbles:true})); sync();
		});
		select.addEventListener('change', sync);
		select.addEventListener('certloom:assignment-info', event => { select.dataset.inheritedDescription = String(event.detail || ''); sync(); });
		select.certloomSyncAssignment = sync;
		new MutationObserver(sync).observe(select, {childList:true, subtree:true}); sync();
	}
	function scan(root) { root.querySelectorAll('select').forEach(enhance); }
	scan(document);
	new MutationObserver(records => { records.forEach(record => record.addedNodes.forEach(node => { if (node.nodeType === 1) { if (node.matches('select')) { enhance(node); } scan(node); } })); }).observe(document.body, {childList:true,subtree:true});
})();
