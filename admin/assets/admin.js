( function () {
	'use strict';

	function updateFieldIndexes( list ) {
		var rows = list.querySelectorAll( '.ecapla-certverify-field-row' );
		for ( var index = 0; index < rows.length; index++ ) {
			var inputs = rows[ index ].querySelectorAll( '[name^="fields["]' );
			for ( var inputIndex = 0; inputIndex < inputs.length; inputIndex++ ) {
				inputs[ inputIndex ].name = inputs[ inputIndex ].name.replace( /^fields\[\d+\]/, 'fields[' + index + ']' );
			}
		}
	}

	function moveRow( row, direction ) {
		if ( 'up' === direction && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
		}
		if ( 'down' === direction && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
		}
		updateFieldIndexes( row.parentNode );
		row.querySelector( '.ecapla-certverify-field-label' ).focus();
	}

	function initFields() {
		var list = document.getElementById( 'ecapla-certloom-fields-list' );
		if ( ! list ) {
			return;
		}
		var dragged = null;
		list.addEventListener( 'dragstart', function ( event ) {
			var row = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! row ) {
				return;
			}
			dragged = row;
			row.classList.add( 'is-dragging' );
			event.dataTransfer.effectAllowed = 'move';
		} );
		list.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			var target = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! dragged || ! target || dragged === target ) {
				return;
			}
			var rect = target.getBoundingClientRect();
			list.insertBefore( dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextElementSibling );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragged ) {
				dragged.classList.remove( 'is-dragging' );
			}
			dragged = null;
			updateFieldIndexes( list );
		} );
		list.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! row ) {
				return;
			}
			if ( event.target.classList.contains( 'ecapla-certverify-move-up' ) ) {
				moveRow( row, 'up' );
			}
			if ( event.target.classList.contains( 'ecapla-certverify-move-down' ) ) {
				moveRow( row, 'down' );
			}
		} );
	}

	function initConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var control = event.target.closest( '[data-ecapla-confirm]' );
			if ( ! control ) {
				return;
			}
			if ( ! window.confirm( control.getAttribute( 'data-ecapla-confirm' ) ) ) {
				event.preventDefault();
				return;
			}
		} );
	}

	function initBulkOperationFields() {
		var select = document.querySelector( '[data-certloom-bulk-operation]' );
		if ( ! select ) { return; }
		function update() {
			var value = select.value;
			var groups = document.querySelectorAll( '[data-certloom-bulk-fields]' );
			for ( var i = 0; i < groups.length; i++ ) {
				var allowed = groups[ i ].getAttribute( 'data-certloom-bulk-fields' ).split( ',' );
				groups[ i ].classList.toggle( 'is-active', allowed.indexOf( value ) !== -1 );
			}
			var valueField = document.querySelector( '[data-certloom-bulk-value]' );
			if ( valueField ) { valueField.hidden = 'clear_custom_field' === value; }
		}
		select.addEventListener( 'change', update );
		update();
	}

	function initSecondaryCodeGenerator() {
		var button = document.querySelector( '[data-certloom-generate-secondary-code]' );
		var input = document.getElementById( 'certloom-secondary-code' );
		if ( ! button || ! input ) { return; }
		button.addEventListener( 'click', function () {
			if ( ! window.crypto || typeof window.crypto.getRandomValues !== 'function' ) { input.focus(); return; }
			var alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
			var bytes = new Uint8Array( 16 );
			window.crypto.getRandomValues( bytes );
			var raw = '';
			for ( var i = 0; i < bytes.length; i++ ) { raw += alphabet.charAt( bytes[ i ] % 32 ); }
			input.value = raw.match( /.{1,4}/g ).join( '-' );
			input.focus();
			input.select();
		} );
	}

	function initDomainKeyValueLists() {
		document.addEventListener( 'click', function ( event ) {
			var add = event.target.closest( '[data-certloom-add-kv]' );
			if ( add ) {
				var type = add.getAttribute( 'data-certloom-add-kv' );
				var list = document.querySelector( '[data-certloom-kv-list="' + type + '"]' );
				var source = list ? list.querySelector( '.certloom-domain-kv-row' ) : null;
				if ( source ) {
					var clone = source.cloneNode( true );
					var inputs = clone.querySelectorAll( 'input' );
					for ( var i = 0; i < inputs.length; i++ ) { inputs[ i ].value = ''; }
					list.appendChild( clone );
					inputs[ 0 ].focus();
				}
				return;
			}
			var remove = event.target.closest( '.certloom-domain-remove-kv' );
			if ( ! remove ) { return; }
			var row = remove.closest( '.certloom-domain-kv-row' );
			var parent = row ? row.parentNode : null;
			if ( ! row || ! parent ) { return; }
			if ( parent.querySelectorAll( '.certloom-domain-kv-row' ).length <= 1 ) {
				var rowInputs = row.querySelectorAll( 'input' );
				for ( var j = 0; j < rowInputs.length; j++ ) { rowInputs[ j ].value = ''; }
				rowInputs[ 0 ].focus();
				return;
			}
			row.remove();
		} );
	}


	function initProgramProfile() {
		var purpose = document.getElementById( 'certloom-program-purpose' );
		var subcategory = document.getElementById( 'certloom-program-subcategory' );
		if ( ! purpose || ! subcategory ) { return; }

		function updateSubcategories() {
			var activePurpose = purpose.value;
			var options = Array.prototype.slice.call( subcategory.options );
			var firstAllowed = null;
			var currentAllowed = false;
			options.forEach( function ( option ) {
				var allowed = option.getAttribute( 'data-purpose' ) === activePurpose;
				option.hidden = ! allowed;
				option.disabled = ! allowed;
				if ( allowed && ! firstAllowed ) { firstAllowed = option; }
				if ( allowed && option.selected ) { currentAllowed = true; }
			} );
			if ( ! currentAllowed && firstAllowed ) {
				firstAllowed.selected = true;
			}
			subcategory.disabled = ! firstAllowed;
		}

		function initLocaleVariants() {
			var list = document.querySelector( '[data-certloom-locale-variant-list]' );
			var add = document.querySelector( '[data-certloom-add-locale-variant]' );
			var tpl = document.getElementById( 'certloom-program-locale-variant-template' );
			var fallback = document.querySelector( '[data-certloom-locale-fallback]' );
			if ( ! list || ! add || ! tpl || ! fallback ) { return; }

			function normalizedLocale( value ) {
				return String( value || '' ).trim().replace( /-/g, '_' );
			}
			function refreshFallback() {
				var selected = fallback.value || fallback.getAttribute( 'data-selected' ) || 'program_default';
				var seen = {};
				var values = [];
				Array.prototype.forEach.call( list.querySelectorAll( '[name="profile_template_variant_locale[]"]' ), function ( input ) {
					var locale = normalizedLocale( input.value );
					if ( locale && ! seen[ locale ] ) { seen[ locale ] = true; values.push( locale ); }
				} );
				fallback.textContent = '';
				var base = document.createElement( 'option' );
				base.value = 'program_default';
				base.textContent = 'Program default design';
				fallback.appendChild( base );
				values.forEach( function ( locale ) { var option = document.createElement( 'option' ); option.value = locale; option.textContent = locale; fallback.appendChild( option ); } );
				fallback.value = values.indexOf( selected ) !== -1 ? selected : 'program_default';
				fallback.setAttribute( 'data-selected', fallback.value );
			}

			add.addEventListener( 'click', function () {
				if ( list.querySelectorAll( '.certloom-locale-variant-row' ).length >= 30 ) { return; }
				var fragment = tpl.content.cloneNode( true );
				list.appendChild( fragment );
				var rows = list.querySelectorAll( '.certloom-locale-variant-row' );
				var localeSelect = rows[ rows.length - 1 ].querySelector( '[name="profile_template_variant_locale[]"]' );
				if ( localeSelect ) { localeSelect.focus(); }
				refreshFallback();
			} );
			list.addEventListener( 'click', function ( event ) {
				var remove = event.target.closest( '[data-certloom-remove-locale-variant]' );
				if ( ! remove ) { return; }
				var row = remove.closest( '.certloom-locale-variant-row' );
				if ( row ) { row.remove(); refreshFallback(); }
			} );
			list.addEventListener( 'change', function ( event ) {
				if ( event.target.matches( '[name="profile_template_variant_locale[]"]' ) ) { refreshFallback(); }
			} );
			fallback.addEventListener( 'change', function () { fallback.setAttribute( 'data-selected', fallback.value ); } );
			refreshFallback();
		}

		purpose.addEventListener( 'change', updateSubcategories );
		updateSubcategories();
		initLocaleVariants();
	}



	function initRestoreAutoContinue() {
		var form = document.querySelector( 'form[data-certverify-auto-continue="1"]' );
		if ( ! form ) { return; }
		var button = form.querySelector( '[data-certverify-continue-button]' );
		var status = form.querySelector( '[data-certverify-auto-continue-status]' );
		var submitting = false;
		var timer = null;
		function setStatus( message ) { if ( status ) { status.textContent = message; } }
		function submitRestore() {
			if ( submitting || ! document.documentElement.contains( form ) ) { return; }
			submitting = true;
			if ( button ) { button.disabled = true; }
			setStatus( 'Continuing restore…' );
			try {
				if ( form.requestSubmit ) { form.requestSubmit( button || undefined ); } else { form.submit(); }
			} catch ( error ) {
				submitting = false;
				if ( button ) { button.disabled = false; }
				setStatus( 'Automatic continuation could not start. Use Continue restore.' );
			}
		}
		form.addEventListener( 'submit', function () {
			submitting = true;
			if ( timer ) { window.clearTimeout( timer ); }
			if ( button ) { button.disabled = true; }
			setStatus( 'Continuing restore…' );
		} );
		timer = window.setTimeout( submitRestore, 700 );
		window.setTimeout( function () {
			if ( document.documentElement.contains( form ) ) {
				submitting = false;
				if ( button ) { button.disabled = false; }
				setStatus( 'This restore is still on this step. Use Continue restore to retry safely.' );
			}
		}, 7000 );
	}



	function initRecoveryIntakeGuide() {
		var guide = document.querySelector( '[data-certloom-recovery-intake]' );
		if ( ! guide ) { return; }
		var status = guide.querySelector( '[data-certloom-recovery-intake-status]' );
		var steps = guide.querySelectorAll( '[data-certloom-recovery-step]' );
		function activate( kind ) {
			var targetId = 'backup' === kind ? 'ecapla-certverify-backup-file' : 'ecapla-certloom-import-file';
			var target = document.getElementById( targetId );
			if ( ! target ) { return; }
			for ( var i = 0; i < steps.length; i++ ) {
				steps[ i ].classList.toggle( 'is-complete', '1' === steps[ i ].getAttribute( 'data-certloom-recovery-step' ) );
				steps[ i ].classList.toggle( 'is-current', '2' === steps[ i ].getAttribute( 'data-certloom-recovery-step' ) );
			}
			var buttons = guide.querySelectorAll( '[data-certloom-recovery-choice]' );
			for ( var j = 0; j < buttons.length; j++ ) {
				var active = buttons[ j ].getAttribute( 'data-certloom-recovery-choice' ) === kind;
				buttons[ j ].classList.toggle( 'button-primary', active );
				buttons[ j ].setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			}
			if ( status ) { status.textContent = 'backup' === kind ? 'Step 2: choose the Certloom backup you want to review.' : 'Step 2: choose the CSV file you want to review.'; }
			target.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			window.setTimeout( function () { target.focus(); }, 0 );
		}
		guide.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-certloom-recovery-choice]' );
			if ( button ) { activate( button.getAttribute( 'data-certloom-recovery-choice' ) ); }
		} );
	}

	function initProductNoticeRail() {
		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) { return; }
		var rail = wrap.querySelector( ':scope > .certloom-product-notice-rail' );
		var notices = Array.prototype.slice.call( wrap.querySelectorAll( ':scope > .notice:not(.inline), .certloom-dashboard-notice' ) ).filter( function ( notice, index, list ) {
			return list.indexOf( notice ) === index && ( ! rail || ! rail.contains( notice ) );
		} );
		if ( ! rail && ! notices.length ) { return; }
		if ( ! rail ) {
			rail = document.createElement( 'div' );
			rail.className = 'certloom-product-notice-rail';
			rail.setAttribute( 'aria-live', 'polite' );
		}
		var heading = wrap.querySelector( ':scope > h1' );
		var pageHead = wrap.querySelector( ':scope > .certloom-page-head' );
		var anchor = heading || pageHead;
		if ( anchor ) {
			if ( anchor.nextSibling !== rail ) { anchor.parentNode.insertBefore( rail, anchor.nextSibling ); }
		} else if ( wrap.firstChild !== rail ) {
			wrap.insertBefore( rail, wrap.firstChild );
		}
		notices.forEach( function ( notice ) { rail.appendChild( notice ); } );
	}

	function initSettingsSearch() {
		var workspace = document.querySelector( '.certloom-settings-workspace' );
		var input = document.getElementById( 'certloom-settings-search' );
		var results = document.getElementById( 'certloom-settings-search-results' );
		if ( ! workspace || ! input || ! results ) { return; }
		var headings = Array.prototype.slice.call( workspace.querySelectorAll( 'h2[id], h3[id]' ) );
		function escapeHtml( value ) {
			return String( value ).replace( /[&<>"']/g, function ( c ) {
				return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[ c ];
			} );
		}
		function update() {
			var query = input.value.trim().toLocaleLowerCase();
			if ( query.length < 2 ) {
				results.hidden = true;
				results.innerHTML = '';
				return;
			}
			var matches = headings.filter( function ( heading ) {
				return heading.textContent.toLocaleLowerCase().indexOf( query ) !== -1;
			} ).slice( 0, 8 );
			if ( ! matches.length ) {
				results.innerHTML = '<span>' + escapeHtml( input.getAttribute( 'data-empty-label' ) || 'No settings sections match.' ) + '</span>';
				results.hidden = false;
				return;
			}
			results.innerHTML = matches.map( function ( heading ) {
				return '<a href="#' + heading.id.replace( /[^A-Za-z0-9_-]/g, '' ) + '">' + escapeHtml( heading.textContent ) + '</a>';
			} ).join( '' );
			results.hidden = false;
		}
		input.setAttribute( 'data-empty-label', input.getAttribute( 'data-empty-label' ) || 'No settings sections match.' );
		input.addEventListener( 'input', update );
		results.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href^="#"]' );
			if ( link ) { results.hidden = true; }
		} );
	}


	function initAdaptiveSetupWizard() {
		var form = document.querySelector( '[data-certloom-purpose-form]' );
		if ( ! form ) { return; }
		var purpose = document.getElementById( 'certloom-wizard-purpose' );
		var subcategory = document.getElementById( 'certloom-wizard-subcategory' );
		var scheme = form.querySelector( '[data-certloom-scheme-field]' );
		if ( ! purpose || ! subcategory ) { return; }
		var summaryPurpose = document.querySelector( '[data-certloom-wizard-summary-purpose]' );
		var summaryType = document.querySelector( '[data-certloom-wizard-summary-type]' );
		var summaryTerminology = document.querySelector( '[data-certloom-wizard-summary-terminology]' );
		var summaryDesign = document.querySelector( '[data-certloom-wizard-summary-design]' );

		function update() {
			var activePurpose = purpose.value;
			var firstAllowed = null;
			var currentAllowed = false;
			Array.prototype.forEach.call( subcategory.options, function ( option ) {
				var allowed = option.getAttribute( 'data-purpose' ) === activePurpose;
				option.hidden = ! allowed;
				option.disabled = ! allowed;
				if ( allowed && ! firstAllowed ) { firstAllowed = option; }
				if ( allowed && option.selected ) { currentAllowed = true; }
			} );
			if ( ! currentAllowed && firstAllowed ) { firstAllowed.selected = true; }
			subcategory.disabled = ! firstAllowed;
			var selected = subcategory.options[ subcategory.selectedIndex ];
			var purposeOption = purpose.options[ purpose.selectedIndex ];
			if ( scheme ) { scheme.hidden = 'certification_standards' !== activePurpose; }
			if ( summaryPurpose && purposeOption ) { summaryPurpose.textContent = purposeOption.textContent; }
			if ( summaryType && selected ) { summaryType.textContent = selected.textContent; }
			if ( summaryTerminology && selected ) { summaryTerminology.textContent = selected.getAttribute( 'data-terminology' ) || 'Credential'; }
			if ( summaryDesign && selected ) { summaryDesign.textContent = selected.getAttribute( 'data-design' ) || 'Clear and professional'; }
		}
		purpose.addEventListener( 'change', update );
		subcategory.addEventListener( 'change', update );
		update();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initFields();
		initConfirmations();
		initBulkOperationFields();
		initSecondaryCodeGenerator();
		initDomainKeyValueLists();
		initProgramProfile();
		initSettingsSearch();
		initRecoveryIntakeGuide();
		initProductNoticeRail();
		initRestoreAutoContinue();
		initAdaptiveSetupWizard();
	} );
} )();
