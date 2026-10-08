(function () {
	'use strict';
	const root = document.querySelector('[data-certloom-email-composer]'); if (!root) { return; }
	const cfg = JSON.parse(root.querySelector('[data-composer-config]').textContent), form = root.querySelector('form'), blocks = cfg.blocks, theme = cfg.theme, controls = cfg.controls;
	const text = cfg.text, feedback = root.querySelector('[data-composer-feedback]'), frame = root.querySelector('[data-composer-frame]');
	let timer, controller, sequence = 0, saving = false, messageEdited = false, activeInput = form.elements.subject, initial = '';
	const at = path => path.reduce((value, key) => value[key], blocks);
	function slot(name, property, value) {
		if (property === 'plain_footer') { property = 'html'; value = '<p>' + String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])).replace(/\n/g, '<br>') + '</p>'; }
		let control = controls[name];
		if (!control && !String(value).trim()) { return; }
		if (!control) {
			const type = {heading:'heading',message:'text',action:'button',logo:'image',footer:'text'}[name];
			const node = {type}; if (name === 'action') { node.text = ''; node.url = '{{verification_url}}'; } if (name === 'logo') { node.alt = ''; node.style = {width:25}; }
			if (name === 'logo') { blocks.unshift(node); Object.values(controls).forEach(item => { if (item?.path) { item.path[0]++; } }); controls.additional.forEach(item => item.path[0]++); control = controls[name] = {path:[0],block:node}; }
			else { control = controls[name] = {path:[blocks.length],block:node}; blocks.push(node); }
		}
		at(control.path)[property] = value;
	}
	function serialize() { form.elements.blocks_json.value = JSON.stringify(blocks); form.elements.composer_theme_json.value = JSON.stringify(theme); }
	const snapshot = () => JSON.stringify({blocks,theme,metadata:[...new FormData(form)].filter(([key]) => !['_wpnonce','_wp_http_referer','blocks_json','composer_theme_json','composer_message'].includes(key))});
	function changed() { serialize(); root.querySelector('[data-composer-save-state]').textContent = snapshot() === initial ? text.saved : text.unsaved; clearTimeout(timer); if (controller) { controller.abort(); } ++sequence; timer = setTimeout(preview, 300); }
	async function preview() {
		const ticket = ++sequence; controller = new AbortController(); feedback.textContent = text.loading; frame.setAttribute('aria-busy','true');
		try {
			const response = await fetch(cfg.previewUrl, {method:'POST',signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-WP-Nonce':cfg.nonce},body:JSON.stringify({blocks,theme,subject:form.elements.subject.value,preheader:form.elements.preheader.value})});
			const result = await response.json(); if (!response.ok) { throw new Error(result.message || text.error); } if (ticket !== sequence) { return; }
			frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0}table{max-width:100%}img{max-width:100%;height:auto}</style></head><body>' + result.html + '</body></html>';
			root.querySelector('[data-composer-preview-subject]').textContent = result.subject; root.querySelector('[data-composer-preview-preheader]').textContent = result.preheader; feedback.textContent = '';
		} catch (error) { if (ticket === sequence && error.name !== 'AbortError') { feedback.textContent = error.message || text.error; } }
		finally { if (ticket === sequence) { frame.setAttribute('aria-busy','false'); } }
	}
	root.querySelectorAll('[data-composer-slot]').forEach(input => input.addEventListener('input', () => { slot(input.dataset.composerSlot,input.dataset.composerProperty,input.value); changed(); }));
	root.querySelectorAll('[data-composer-theme]').forEach(input => input.addEventListener('input', () => { theme[input.dataset.composerTheme] = input.type === 'number' ? Number(input.value) : input.value; changed(); }));
	function messageChanged(html) { messageEdited = true; slot('message','html',html); changed(); }
	const textarea = document.getElementById('certloom_email_message'); textarea.addEventListener('input', () => messageChanged(textarea.value));
	function bindEditor(editor) { if (editor.id !== 'certloom_email_message' || editor._certloomComposer) { return; } editor._certloomComposer = true; editor.on('input change undo redo', () => { if (editor.isDirty()) { messageChanged(editor.getContent()); } }); editor.on('focus', () => { activeInput = textarea; }); }
	if (window.tinymce) { const editor = window.tinymce.get('certloom_email_message'); if (editor) { bindEditor(editor); } window.tinymce.on('AddEditor', event => bindEditor(event.editor)); }
	if (window.jQuery) { window.jQuery(document).on('tinymce-editor-init.certloomComposer', (_, editor) => bindEditor(editor)); }
	form.addEventListener('focusin', event => { if (event.target.matches('input:not([type=hidden]),textarea')) { activeInput = event.target; } });
	root.querySelector('[data-composer-token]').addEventListener('change', event => {
		const value = event.target.value; if (!value) { return; } const editor = window.tinymce?.get('certloom_email_message');
		if (activeInput === textarea && editor && !editor.isHidden()) { editor.insertContent(value); messageChanged(editor.getContent()); }
		else if (activeInput && ['text','textarea','search',''].includes(activeInput.type)) { const start = activeInput.selectionStart ?? activeInput.value.length, end = activeInput.selectionEnd ?? start; activeInput.value = activeInput.value.slice(0,start) + value + activeInput.value.slice(end); activeInput.dispatchEvent(new Event('input',{bubbles:true})); activeInput.focus(); }
		event.target.value = '';
	});
	form.addEventListener('input', event => { if (event.target.name && event.target !== textarea) { changed(); } });
	const additional = root.querySelector('[data-composer-additional]'), extra = root.querySelector('[data-composer-additional-value]');
	function selectAdditional() { const item = additional && controls.additional[additional.value]; if (extra) { extra.hidden = !item; if (item) { const node = at(item.path); extra.value = node.type === 'heading' ? node.text : node.html; } } }
	if (additional) { additional.addEventListener('change',selectAdditional); extra.addEventListener('input', () => { const item = controls.additional[additional.value], node = at(item.path); node[node.type === 'heading' ? 'text' : 'html'] = extra.value; changed(); }); selectAdditional(); }
	root.querySelectorAll('[data-composer-width]').forEach(button => button.addEventListener('click', () => { root.querySelector('.certloom-email-preview-stage').classList.toggle('is-mobile', button.dataset.composerWidth === 'mobile'); root.querySelectorAll('[data-composer-width]').forEach(other => other.setAttribute('aria-pressed',String(other === button))); }));
	let attachments = JSON.parse(form.elements.attachments_json.value || '[]');
	function renderAttachments() { const list = root.querySelector('[data-composer-attachments]'); list.replaceChildren(); attachments.forEach(id => { const li = document.createElement('li'), remove = document.createElement('button'); li.textContent = 'Attachment #' + id; remove.type = 'button'; remove.className = 'button-link-delete'; remove.textContent = 'Remove'; remove.addEventListener('click', () => { attachments = attachments.filter(value => value !== id); form.elements.attachments_json.value = JSON.stringify(attachments); renderAttachments(); changed(); }); li.append(remove); list.append(li); }); }
	function media(kind) { if (!window.wp?.media) { return; } const picker = wp.media({title:kind === 'logo' ? 'Choose logo' : 'Choose attachment',multiple:kind !== 'logo',library:kind === 'logo' ? {type:'image'} : {}}); picker.on('select', () => { const items = picker.state().get('selection').toJSON(); if (kind === 'logo') { const input = root.querySelector('[data-composer-slot="logo"]'); input.value = items[0].url; input.dispatchEvent(new Event('input',{bubbles:true})); } else { attachments = [...new Set(attachments.concat(items.map(item => item.id)))]; form.elements.attachments_json.value = JSON.stringify(attachments); renderAttachments(); changed(); } }); picker.open(); }
	root.querySelector('[data-composer-logo]').addEventListener('click', () => media('logo')); root.querySelector('[data-composer-add-attachment]').addEventListener('click', () => media('attachment'));
	form.addEventListener('submit', event => { if (!form.checkValidity()) { return; } const editor = window.tinymce?.get('certloom_email_message'); if (messageEdited && editor && !editor.isHidden()) { slot('message','html',editor.getContent()); } serialize(); saving = true; });
	window.addEventListener('beforeunload', event => { if (!saving && snapshot() !== initial) { event.preventDefault(); event.returnValue = ''; } });
	serialize(); initial = snapshot(); renderAttachments(); preview();
})();
