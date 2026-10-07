(function () {
	'use strict';
	// Browser-only fitting. Canonical layouts and accepted artifact renderers stay unchanged.
	function fit(box, content, mode, minimum) {
		if (!box || !content || box.clientWidth <= 0 || box.clientHeight <= 0) { return; }
		var base = parseFloat(box.getAttribute('data-certloom-text-base-px') || box.style.fontSize || window.getComputedStyle(box).fontSize);
		if (!isFinite(base) || base <= 0) { return; }
		box.setAttribute('data-certloom-text-base-px', String(base));
		var runs = Array.prototype.slice.call(content.querySelectorAll('span[style]')).filter(function (run) { return !!run.style.fontSize; });
		runs.forEach(function (run) {
			if (!run.hasAttribute('data-certloom-text-base-px')) { run.setAttribute('data-certloom-text-base-px', run.style.fontSize); }
		});
		content.style.display = 'block';
		content.style.flex = '0 0 auto';
		content.style.width = '100%';
		content.style.maxWidth = '100%';
		content.style.whiteSpace = 'wrap' === mode ? 'pre-wrap' : 'pre';
		content.style.wordBreak = 'normal';
		content.style.overflowWrap = 'wrap' === mode ? 'anywhere' : 'normal';
		function scale(value) {
			box.style.fontSize = String(base * value) + 'px';
			runs.forEach(function (run) { run.style.fontSize = String(parseFloat(run.getAttribute('data-certloom-text-base-px')) * value) + 'px'; });
		}
		function fits() { return content.scrollWidth <= box.clientWidth && content.scrollHeight <= box.clientHeight; }
		scale(1);
		if ('shrink' !== mode || fits()) { return; }
		var low = Math.min(1, Math.max(0.01, Number(minimum) || base) / base), high = 1;
		scale(low);
		// Never shrink below the renderer's minimum. Truly oversized text remains clipped.
		if (!fits()) { return; }
		for (var i = 0; i < 16; i++) {
			var mid = (low + high) / 2;
			scale(mid);
			if (fits()) { low = mid; } else { high = mid; }
		}
		scale(low);
	}
	function refresh(root) {
		Array.prototype.forEach.call((root || document).querySelectorAll('[data-certloom-text-overflow]'), function (box) {
			fit(box, box.firstElementChild, box.getAttribute('data-certloom-text-overflow'), parseFloat(box.getAttribute('data-certloom-text-min-px')));
		});
	}
	window.CertloomTextFit = { fit: fit, refresh: refresh };
	function init() { refresh(); if (document.fonts) { document.fonts.ready.then(function () { refresh(); }); document.fonts.addEventListener('loadingdone', function () { refresh(); }); } }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
