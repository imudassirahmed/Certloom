(function () {
	'use strict';

	function q(selector, root) { return (root || document).querySelector(selector); }
	function qa(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

	function initBackground() {
		var toggle = q('[data-certloom-library-background-toggle]');
		var panel = q('[data-certloom-library-background-panel]');
		if (!toggle || !panel) { return; }
		toggle.addEventListener('click', function () {
			panel.hidden = !panel.hidden;
			toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
			if (!panel.hidden) {
				var first = q('input:not([type="hidden"]),select,button', panel);
				if (first) { first.focus(); }
			}
		});
		toggle.setAttribute('aria-expanded', 'false');
		var choose = q('[data-certloom-library-background-media]', panel);
		var idField = q('[data-certloom-library-background-id]', panel);
		var name = q('[data-certloom-library-background-name]', panel);
		var submit = q('[data-certloom-library-background-submit]', panel);
		if (!choose || !idField || !name || !submit) { return; }
		choose.addEventListener('click', function () {
			if (!window.wp || !wp.media) { return; }
			var frame = wp.media({ title: choose.textContent, button: { text: choose.textContent }, library: { type: 'image' }, multiple: false });
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first();
				var value = attachment && attachment.toJSON ? attachment.toJSON() : {};
				var mime = String(value.mime || '');
				if (mime !== 'image/png' && mime !== 'image/jpeg') {
					idField.value = '';
					name.textContent = value.filename || 'Unsupported image type';
					submit.disabled = true;
					return;
				}
				idField.value = String(value.id || '');
				name.textContent = String(value.filename || value.title || 'Selected image');
				submit.disabled = !idField.value;
			});
			frame.open();
		});
	}

	function initPreview() {
		var dialog = q('[data-certloom-library-dialog]');
		if (!dialog || typeof dialog.showModal !== 'function') { return; }
		var body = q('[data-certloom-library-dialog-body]', dialog);
		var title = q('[data-certloom-library-dialog-title]', dialog);
		var close = q('[data-certloom-library-dialog-close]', dialog);
		var opener = null;
		function fitPreview() {
			var stage = q('[data-certloom-library-dialog-stage]', dialog);
			var clone = stage && q('.certloom-template-preview-scroll', stage);
			if (!dialog.open || !clone || !body) { return; }
			var width = parseFloat(clone.style.width), height = parseFloat(clone.style.height);
			var style = window.getComputedStyle(body);
			var availableWidth = body.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
			var head = q('.certloom-template-library-dialog-head', dialog);
			var foot = q('p.description', dialog);
			var availableHeight = window.innerHeight - 40 - (head ? head.offsetHeight : 0) - (foot ? foot.offsetHeight : 0) - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
			var scale = Math.min(860 / width, 600 / height, 1.65, Math.max(1, availableWidth) / width, Math.max(80, availableHeight) / height);
			stage.style.width = String(width * scale) + 'px';
			stage.style.height = String(height * scale) + 'px';
			clone.style.setProperty('--certloom-dialog-scale', String(scale));
		}
		qa('[data-certloom-library-preview]').forEach(function (button) {
			button.addEventListener('click', function () {
				var card = button.closest('.certloom-template-library-card');
				var source = card ? q('[data-certloom-library-preview-source]', card) : null;
				if (!source || !body) { return; }
				var width = Math.max(1, parseFloat(source.getAttribute('data-page-width') || '1'));
				var height = Math.max(1, parseFloat(source.getAttribute('data-page-height') || '1'));
				var scale = Math.min(860 / width, 600 / height, 1.65);
				var stage = document.createElement('div');
				stage.setAttribute('data-certloom-library-dialog-stage', '');
				stage.style.width = String(Math.ceil(width * scale)) + 'px';
				stage.style.height = String(Math.ceil(height * scale)) + 'px';
				var preview = q('.certloom-template-preview-scroll', source);
				if (preview) {
					var clone = preview.cloneNode(true);
					clone.style.setProperty('--certloom-dialog-scale', String(scale));
					clone.style.width = String(width) + 'px';
					clone.style.height = String(height) + 'px';
					clone.style.position = 'absolute';
					clone.style.inset = '0 auto auto 0';
					clone.setAttribute('tabindex', '0');
					stage.appendChild(clone);
				} else {
					stage.appendChild(source.cloneNode(true));
				}
				body.replaceChildren(stage);
				if (title) { title.textContent = source.getAttribute('data-template-title') || 'Template Preview'; }
				opener = button;
				dialog.showModal();
				fitPreview();
				if (window.CertloomTextFit) { window.CertloomTextFit.refresh(dialog); }
				if (close) { close.focus(); }
			});
		});
		function closeDialog() {
			if (dialog.open) { dialog.close(); }
		}
		if (close) { close.addEventListener('click', closeDialog); }
		window.addEventListener('resize', fitPreview);
		dialog.addEventListener('click', function (event) { if (event.target === dialog) { closeDialog(); } });
		dialog.addEventListener('close', function () {
			if (body) { body.replaceChildren(); }
			if (opener) { opener.focus(); opener = null; }
		});
	}

	function initImport() {
		var open = q('[data-certloom-certificate-import-open]');
		var dialog = q('[data-certloom-certificate-import-dialog]');
		var close = dialog ? q('[data-certloom-certificate-import-close]', dialog) : null;
		if (!open || !dialog || typeof dialog.showModal !== 'function') { return; }
		open.addEventListener('click', function () { dialog.showModal(); var input=q('input[type=\"file\"]',dialog); if(input){input.focus();} });
		if(close){close.addEventListener('click',function(){dialog.close();});}
		dialog.addEventListener('click',function(event){if(event.target===dialog){dialog.close();}});
		dialog.addEventListener('close',function(){open.focus();});
	}

	function initPackExport() {
		var button = q('[data-certloom-pack-export]');
		var boxes = qa('[data-certloom-pack-select]');
		if (!button || !boxes.length) { return; }
		function sync() {
			var count = boxes.filter(function (box) { return !!box.checked; }).length;
			var emptyLabel = button.getAttribute('data-label-empty') || 'Export selected package';
			var selectedLabel = button.getAttribute('data-label-selected') || 'Export selected package (%d)';
			button.disabled = count < 1;
			button.textContent = count ? selectedLabel.replace('%d', String(count)) : emptyLabel;
		}
		boxes.forEach(function (box) { box.addEventListener('change', sync); });
		sync();
	}

	function init() { initBackground(); initPreview(); initImport(); initPackExport(); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());
