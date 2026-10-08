(function () {
	'use strict';
	document.querySelectorAll('[data-attention-open]').forEach(button => button.addEventListener('click', () => {
		const dialog = document.querySelector(`[data-attention-dialog="${button.dataset.attentionOpen}"]`);
		if (!dialog) { return; } dialog._opener = button; dialog.showModal();
		dialog.querySelectorAll('[data-attention-deferred]').forEach(root => root.removeAttribute('data-attention-deferred'));
		document.dispatchEvent(new Event('certloom:attention-ready'));
	}));
	document.querySelectorAll('[data-attention-dialog]').forEach(dialog => {
		const close = () => { dialog.close(); dialog._opener?.focus({preventScroll:true}); };
		dialog.querySelector('[data-attention-close]').addEventListener('click', close);
		dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
	});
})();
