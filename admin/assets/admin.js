( function () {
	'use strict';

	var certloomL10n = window.certloomAdminL10n || {};
	function certloomText( key, fallback ) {
		return 'string' === typeof certloomL10n[ key ] && certloomL10n[ key ] ? certloomL10n[ key ] : fallback;
	}
	function certloomCountText( count ) {
		if ( 1 === count ) { return certloomText( 'contextOneMatch', '1 match.' ); }
		return certloomText( 'contextMatches', '%d matches.' ).replace( '%d', String( count ) );
	}

	function certloomDesignCredentialDetailKey( field ) {
		field = field && 'object' === typeof field ? field : {};
		var explicit = String( field.field_key || '' ).toLowerCase().replace( /[^a-z0-9_-]/g, '' );
		if ( explicit ) { return explicit; }
		var token = String( field.token || '' ).trim().toLowerCase();
		var match = token.match( /^credential\.custom\.([a-z][a-z0-9_-]{0,63})$/ ) || token.match( /^credential:([a-z][a-z0-9_-]{0,63})$/ ) || token.match( /^custom\.([a-z][a-z0-9_-]{0,63})$/ ) || token.match( /^credential\.([a-z][a-z0-9_-]{0,63})$/ ) || token.match( /^([a-z][a-z0-9_-]{0,63})$/ );
		if ( match ) { return match[ 1 ]; }
		match = token.match( /^recipient\.([a-z][a-z0-9_-]{0,63})$/ );
		if ( match && [ 'name', 'first_name', 'middle_name', 'last_name', 'email', 'external_id' ].indexOf( match[ 1 ] ) === -1 ) { return match[ 1 ]; }
		return '';
	}

	function certloomNormalizeDesignReadinessField( field ) {
		var normalized = Object.assign( {}, field || {} );
		if ( 'missing' !== String( normalized.status || '' ) ) { return normalized; }
		var key = certloomDesignCredentialDetailKey( normalized );
		if ( ! key ) { return normalized; }
		var action = String( normalized.action || '' );
		var source = String( normalized.source || '' );
		if ( 'credential_detail' === action || 'context' === action || 'Credential context' === source ) {
			normalized.action = 'credential_detail';
			normalized.field_key = key;
			normalized.source = certloomText( 'designDataCredentialDetails', 'Credential details' );
		}
		return normalized;
	}

	function initUsageProfilePresentation() {
		var terms = certloomL10n.usageTerms && 'object' === typeof certloomL10n.usageTerms ? certloomL10n.usageTerms : {};
		var profileLabel = String( certloomL10n.usageProfileLabel || '' ).trim();
		function term( entity, form, fallback ) { return terms[ entity ] && terms[ entity ][ form ] ? String( terms[ entity ][ form ] ) : fallback; }
		var issuer = term( 'issuer', 'singular', 'Issuer' );
		var program = term( 'program', 'singular', 'Program' );
		var batch = term( 'batch', 'singular', 'Batch' );
		var signer = term( 'signer', 'singular', 'Signer' );
		var replacements = {
			'Issuer': issuer, 'Issuer (optional)': issuer + ' (optional)', 'Issuers': term( 'issuer', 'plural', 'Issuers' ), 'Issuer name': issuer + ' name',
			'Program': program, 'Program (optional)': program + ' (optional)', 'Programs': term( 'program', 'plural', 'Programs' ), 'Program title': program + ' title',
			'Batch': batch, 'Batch (optional)': batch + ' (optional)', 'Batches': term( 'batch', 'plural', 'Batches' ), 'Batch name': batch + ' name',
			'Signer': signer, 'Signer (optional)': signer + ' (optional)', 'Signers': term( 'signer', 'plural', 'Signers' ), 'Signer name': signer + ' name'
		};
		Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell form label > span, .certloom-admin-shell form label > strong, .certloom-admin-shell form th label, .ecapla-certverify-admin-wrap form label > span, .ecapla-certverify-admin-wrap form label > strong' ), function ( node ) {
			if ( node.children.length ) { return; }
			var original = String( node.textContent || '' ).trim();
			if ( Object.prototype.hasOwnProperty.call( replacements, original ) ) { node.textContent = replacements[ original ]; }
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell form label, .ecapla-certverify-admin-wrap form label' ), function ( label ) {
			Array.prototype.forEach.call( label.childNodes, function ( child ) {
				if ( 3 !== child.nodeType ) { return; }
				var original = String( child.nodeValue || '' ).trim();
				if ( Object.prototype.hasOwnProperty.call( replacements, original ) ) { child.nodeValue = child.nodeValue.replace( original, replacements[ original ] ); }
			} );
		} );
		var placeholderReplacements = {
			'Search Issuers…': term( 'issuer', 'search', 'Search Issuers…' ),
			'Search active Issuers…': term( 'issuer', 'search', 'Search Issuers…' ),
			'Search Programs…': term( 'program', 'search', 'Search Programs…' ),
			'Search active Programs…': term( 'program', 'search', 'Search Programs…' ),
			'Search Batches…': term( 'batch', 'search', 'Search Batches…' ),
			'Search Batches in this Program…': term( 'batch', 'search', 'Search Batches…' ),
			'Search Signers…': term( 'signer', 'search', 'Search Signers…' ),
			'Search active Signers…': term( 'signer', 'search', 'Search Signers…' )
		};
		Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell form input[placeholder], .ecapla-certverify-admin-wrap form input[placeholder]' ), function ( input ) {
			var placeholder = String( input.getAttribute( 'placeholder' ) || '' ).trim();
			if ( Object.prototype.hasOwnProperty.call( placeholderReplacements, placeholder ) ) { input.setAttribute( 'placeholder', placeholderReplacements[ placeholder ] ); }
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell form select option, .ecapla-certverify-admin-wrap form select option' ), function ( option ) {
			var text = String( option.textContent || '' ).trim();
			var optionMap = {
				'Choose Program…': term( 'program', 'choose', 'Choose Program…' ),
				'No explicit Issuer': 'No explicit ' + issuer,
				'No Batch': 'No ' + batch,
				'No explicit Signer': 'No explicit ' + signer
			};
			if ( Object.prototype.hasOwnProperty.call( optionMap, text ) ) { option.textContent = optionMap[ text ]; }
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell [data-certloom-context-lookup], .ecapla-certverify-admin-wrap [data-certloom-context-lookup]' ), function ( control ) {
			var entity = String( control.getAttribute( 'data-certloom-context-lookup' ) || '' );
			if ( ! terms[ entity ] ) { return; }
			if ( terms[ entity ].search ) { control.setAttribute( 'data-certloom-context-search-placeholder', String( terms[ entity ].search ) ); }
			var option = control.options && control.options.length ? control.options[ 0 ] : null;
			if ( ! option ) { return; }
			var current = String( option.textContent || '' ).trim();
			if ( 'program' === entity && /^(Choose|Any|No) Program/.test( current ) ) { option.textContent = current.replace( 'Program', program ); }
			if ( 'issuer' === entity && /^(Choose|Any|No explicit) Issuer/.test( current ) ) { option.textContent = current.replace( 'Issuer', issuer ); }
			if ( 'batch' === entity && /^(Choose|Any|No) Batch/.test( current ) ) { option.textContent = current.replace( 'Batch', batch ); }
			if ( 'signer' === entity && /^(Choose|Any|No explicit) Signer/.test( current ) ) { option.textContent = current.replace( 'Signer', signer ); }
		} );
		if ( profileLabel ) {
			Array.prototype.forEach.call( document.querySelectorAll( '.certloom-admin-shell > .certloom-page-hero, .certloom-admin-shell > .certloom-dashboard-hero, .certloom-admin-shell > .certloom-workspace-hero, .certloom-admin-shell .certloom-email-hero' ), function ( hero ) {
				if ( hero.querySelector( '[data-certloom-active-profile]' ) ) { return; }
				var copy = hero.querySelector( ':scope > div:first-child, .certloom-dashboard-hero-copy, .certloom-workspace-hero-copy, .certloom-email-hero__copy' ) || hero;
				var badge = document.createElement( 'span' ); badge.className = 'certloom-active-profile-badge'; badge.setAttribute( 'data-certloom-active-profile', '' );
				var prefix = document.createElement( 'span' ); prefix.textContent = certloomText( 'activeProfileLabel', 'Active profile' ); badge.appendChild( prefix );
				var strong = document.createElement( 'strong' ); strong.textContent = profileLabel; badge.appendChild( strong );
				var eyebrow = copy.querySelector( '.certloom-eyebrow' );
				if ( eyebrow ) {
					badge.classList.add( 'is-eyebrow-status' );
					eyebrow.appendChild( badge );
				} else {
					copy.appendChild( badge );
				}
			} );
		}
	}

	function certloomFieldPrivacyText( kind, value ) {
		var maps = {
			exposure: { private: [ 'privacyPrivate', 'Private' ], verification: [ 'privacyVerification', 'Verification only' ], profile: [ 'privacyProfile', 'Profile only' ], both: [ 'privacyBoth', 'Verification + profile' ] },
			sensitivity: { public_safe: [ 'sensitivityPublicSafe', 'Public-safe' ], personal: [ 'sensitivityPersonal', 'Personal' ], sensitive: [ 'sensitivitySensitive', 'Sensitive' ], internal: [ 'sensitivityInternal', 'Internal' ] }
		};
		var item = maps[ kind ] && maps[ kind ][ value ] ? maps[ kind ][ value ] : null;
		return item ? certloomText( item[ 0 ], item[ 1 ] ) : String( value || '' ).replace( /_/g, ' ' );
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( character ) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[ character ];
		} );
	}

	function escapeSelector( value ) { return window.CSS && 'function' === typeof window.CSS.escape ? window.CSS.escape( String( value ) ) : String( value ).replace( /[^a-zA-Z0-9_-]/g, function ( character ) { return '\\' + character; } ); }

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
				base.textContent = certloomText( 'programDefaultDesign', 'Program default design' );
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
				var newRow = rows[ rows.length - 1 ];
				initScopedContextLookups( newRow );
				var localeSelect = newRow.querySelector( '[name="profile_template_variant_locale[]"]' );
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

		var validityMode = document.querySelector( '[data-certloom-program-validity-mode]' );
		var durationField = document.querySelector( '[data-certloom-program-validity-duration]' );
		var renewalField = document.querySelector( '[data-certloom-program-renewal-field]' );
		var renewal = document.querySelector( '[data-certloom-program-renewal]' );
		function syncLifecyclePolicy() {
			if ( ! validityMode ) { return; }
			var mode = validityMode.value || 'permanent';
			var duration = 'duration' === mode;
			var permanent = 'permanent' === mode;
			if ( durationField ) {
				durationField.hidden = ! duration;
				Array.prototype.forEach.call( durationField.querySelectorAll( 'input,select' ), function ( field ) { field.disabled = ! duration; } );
			}
			if ( renewalField ) { renewalField.hidden = permanent; }
			if ( renewal ) {
				if ( permanent ) { renewal.value = 'not_applicable'; }
				renewal.disabled = permanent;
			}
		}

		purpose.addEventListener( 'change', updateSubcategories );
		if ( validityMode ) { validityMode.addEventListener( 'change', syncLifecyclePolicy ); }
		updateSubcategories();
		syncLifecyclePolicy();
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
			if ( status ) { status.textContent = 'backup' === kind ? certloomText( 'recoveryChooseBackup', 'Step 2: choose the Certloom backup you want to review.' ) : certloomText( 'recoveryChooseCsv', 'Step 2: choose the CSV file you want to review.' ); }
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
		var notices = Array.prototype.slice.call( wrap.querySelectorAll( ':scope > .notice.certloom-owned-notice, .certloom-dashboard-notice' ) ).filter( function ( notice, index, list ) {
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
		function revealTarget( hash ) {
			if ( ! hash || '#' === hash ) { return; }
			var target = document.getElementById( hash.replace( /^#/, '' ) );
			if ( ! target ) { return; }
			var details = target.closest( 'details' );
			if ( details ) { details.open = true; }
		}
		results.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href^="#"]' );
			if ( link ) { revealTarget( link.getAttribute( 'href' ) ); results.hidden = true; }
		} );
		Array.prototype.forEach.call( workspace.querySelectorAll( '.certloom-settings-local-nav a[href^="#"]' ), function ( link ) {
			link.addEventListener( 'click', function () { revealTarget( link.getAttribute( 'href' ) ); } );
		} );
		if ( window.location.hash ) { revealTarget( window.location.hash ); }
		window.addEventListener( 'hashchange', function () { revealTarget( window.location.hash ); } );
	}



	function initScopedContextLookups( root ) {
		root = root && root.querySelectorAll ? root : document;
		Array.prototype.forEach.call( root.querySelectorAll( 'select[data-certloom-context-lookup]' ), function ( select ) {
			if ( '1' === select.getAttribute( 'data-certloom-context-ready' ) ) { return; }
			var kind = select.getAttribute( 'data-certloom-context-lookup' );
			var nonce = select.getAttribute( 'data-certloom-context-nonce' ) || '';
			if ( [ 'recipient', 'program', 'batch', 'template', 'issuer', 'signer', 'signer_scope', 'wp_user', 'tutor_course', 'learndash_course' ].indexOf( kind ) === -1 || ! nonce || ( ! certloomL10n.restContextUrl && 'undefined' === typeof window.ajaxurl ) ) { return; }
			select.setAttribute( 'data-certloom-context-ready', '1' );

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'certloom-context-lookup';
			var search = document.createElement( 'input' );
			search.type = 'search';
			search.className = 'certloom-context-lookup__search';
			search.autocomplete = 'off';
			search.placeholder = select.getAttribute( 'data-certloom-context-search-placeholder' ) || ( 'batch' === kind ? certloomText( 'contextSearchBatches', 'Search Batches…' ) : ( 'template' === kind ? certloomText( 'contextSearchTemplates', 'Search published Templates…' ) : certloomText( 'contextSearchChoices', 'Search choices…' ) ) );
			search.setAttribute( 'aria-label', search.placeholder.replace( /…/g, '' ) );
			var status = document.createElement( 'span' );
			status.className = 'certloom-context-lookup__status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			status.setAttribute( 'aria-atomic', 'true' );
			select.parentNode.insertBefore( wrapper, select );
			wrapper.appendChild( search );
			wrapper.appendChild( select );
			wrapper.appendChild( status );
			if ( 'template' === kind ) {
				var manageDesigns = document.createElement( 'a' );
				manageDesigns.className = 'certloom-context-lookup__manage';
				manageDesigns.href = window.location.pathname + '?page=certloom-templates&design_view=templates';
				manageDesigns.textContent = certloomText( 'contextManageDesigns', 'Manage published designs' );
				wrapper.appendChild( manageDesigns );
			}

			var form = select.closest( 'form' );
			var requestSequence = 0;
			var timer = null;
			var defaultValue = select.hasAttribute( 'data-certloom-context-empty-value' ) ? select.getAttribute( 'data-certloom-context-empty-value' ) : ( 'batch' === kind ? '0' : '' );
			var defaultLabel = select.options.length ? select.options[ 0 ].textContent : ( 'batch' === kind ? certloomText( 'contextNoBatch', 'No Batch' ) : certloomText( 'contextChooseProgram', 'Choose Program…' ) );

			function optionLabel( choice ) { return String( choice && choice.label ? choice.label : '' ); }
			function programMetadataFromOption( option ) {
				if ( ! option ) { return {}; }
				return {
					title: option.getAttribute( 'data-program-title' ) || '',
					validity_mode: option.getAttribute( 'data-validity-mode' ) || '',
					validity_value: option.getAttribute( 'data-validity-value' ) || '0',
					validity_unit: option.getAttribute( 'data-validity-unit' ) || 'days',
					program_validity_available: option.getAttribute( 'data-program-validity-available' ),
					validity_summary: option.getAttribute( 'data-validity-summary' ) || '',
					renewal: option.getAttribute( 'data-renewal-policy' ) || 'not_applicable',
					repeat_policy: option.getAttribute( 'data-repeat-policy' ) || 'lifecycle',
					issuer_id: option.getAttribute( 'data-program-issuer-id' ) || '0',
					issuer_name: option.getAttribute( 'data-program-issuer-name' ) || ''
				};
			}
			function applyProgramMetadata( option, choice ) {
				if ( ! option || ! choice ) { return; }
				var map = {
					title: 'data-program-title', validity_mode: 'data-validity-mode', validity_value: 'data-validity-value', validity_unit: 'data-validity-unit',
					program_validity_available: 'data-program-validity-available', validity_summary: 'data-validity-summary', renewal: 'data-renewal-policy', repeat_policy: 'data-repeat-policy',
					issuer_id: 'data-program-issuer-id', issuer_name: 'data-program-issuer-name'
				};
				Object.keys( map ).forEach( function ( key ) {
					if ( choice[ key ] === undefined || choice[ key ] === null ) { return; }
					var value = choice[ key ];
					if ( 'program_validity_available' === key ) { value = value === true || value === 1 || value === '1' ? '1' : '0'; }
					option.setAttribute( map[ key ], String( value ) );
				} );
			}
			function selectedSnapshot() {
				var selected = select.options[ select.selectedIndex ];
				if ( ! selected || ! select.value || select.value === defaultValue ) { return null; }
				var snapshot = { id: String( select.value ), label: selected.textContent };
				if ( 'program' === kind ) { Object.assign( snapshot, programMetadataFromOption( selected ) ); }
				return snapshot;
			}
			function programId() {
				if ( 'batch' !== kind || ! form ) { return 0; }
				var program = form.querySelector( 'select[name="program_id"]' );
			var designSources = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
				return program ? parseInt( program.value || '0', 10 ) || 0 : 0;
			}
			function replaceChoices( choices ) {
				var previous = selectedSnapshot();
				var chosen = previous ? previous.id : defaultValue;
				select.innerHTML = '';
				var first = document.createElement( 'option' );
				first.value = defaultValue;
				first.textContent = defaultLabel;
				select.appendChild( first );
				var seen = {};
				( Array.isArray( choices ) ? choices : [] ).forEach( function ( choice ) {
					var value = choice && choice.value !== undefined && choice.value !== null ? String( choice.value ) : String( parseInt( choice.id || '0', 10 ) || 0 );
					if ( ! value || '0' === value || seen[ value ] ) { return; }
					seen[ value ] = true;
					var option = document.createElement( 'option' );
					option.value = value;
					option.textContent = optionLabel( choice );
					if ( 'batch' === kind && choice.program_id ) { option.setAttribute( 'data-program-id', String( choice.program_id ) ); }
					if ( 'program' === kind ) { applyProgramMetadata( option, choice ); }
					select.appendChild( option );
				} );
				if ( previous && ! seen[ previous.id ] ) {
					var keep = document.createElement( 'option' );
					keep.value = previous.id;
					keep.textContent = previous.label;
					if ( 'program' === kind ) { applyProgramMetadata( keep, previous ); }
					if ( 'batch' === kind && programId() ) { keep.setAttribute( 'data-program-id', String( programId() ) ); }
					select.appendChild( keep );
				}
				select.value = chosen;
			}
			function lookup( query ) {
				if ( 'batch' === kind && ! programId() ) {
					replaceChoices( [] );
					select.disabled = true;
					search.disabled = true;
					status.textContent = certloomText( 'contextChooseProgramFirst', 'Choose a Program first.' );
					return;
				}
				select.disabled = false;
				search.disabled = false;
				requestSequence++;
				var sequence = requestSequence;
				status.textContent = certloomText( 'contextSearching', 'Searching…' );
				var programStatus = select.getAttribute( 'data-certloom-context-program-status' ) || 'active';
				if ( [ 'active', 'inactive', 'all' ].indexOf( programStatus ) === -1 ) { programStatus = 'active'; }
				var requestPromise;
				if ( certloomL10n.restContextUrl ) {
					var restUrl = new URL( certloomL10n.restContextUrl );
					restUrl.searchParams.set( 'kind', kind );
					restUrl.searchParams.set( 'query', String( query || '' ) );
					if ( 'program' === kind ) { restUrl.searchParams.set( 'program_status', programStatus ); }
					if ( 'batch' === kind ) { restUrl.searchParams.set( 'program_id', String( programId() ) ); }
					requestPromise = fetch( restUrl.toString(), {
						method: 'GET', credentials: 'same-origin', headers: certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {}
					} ).then( function ( response ) { return response.json().then( function ( payload ) { if ( ! response.ok ) { throw new Error( payload && payload.message ? payload.message : certloomText( 'contextSearchFailed', 'Search failed.' ) ); } return payload; } ); } );
				} else {
					var body = new FormData();
					body.append( 'action', 'certloom_lookup_scoped_context' );
					body.append( 'nonce', nonce );
					body.append( 'kind', kind );
					body.append( 'query', String( query || '' ) );
					if ( 'program' === kind ) { body.append( 'program_status', programStatus ); }
					if ( 'batch' === kind ) { body.append( 'program_id', String( programId() ) ); }
					requestPromise = fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
						if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : certloomText( 'contextSearchFailed', 'Search failed.' ) ); }
						return response.data;
					} );
				}
				requestPromise.then( function ( data ) {
					if ( sequence !== requestSequence ) { return; }
					var choices = data && Array.isArray( data.choices ) ? data.choices : [];
					replaceChoices( choices );
					status.textContent = choices.length ? certloomCountText( choices.length ) : ( 'template' === kind ? certloomText( 'contextNoPublishedDesigns', 'No published designs match. Publish a design in Design first.' ) : certloomText( 'contextNoChoices', 'No matching choices.' ) );
				} ).catch( function ( error ) { if ( sequence === requestSequence ) { status.textContent = error && error.message ? error.message : certloomText( 'contextSearchFailed', 'Search failed.' ); } } );
			}
			function schedule() {
				if ( timer ) { window.clearTimeout( timer ); }
				timer = window.setTimeout( function () { lookup( search.value.trim() ); }, 220 );
			}
			search.addEventListener( 'input', schedule );
			search.addEventListener( 'focus', function () { if ( ! search.value ) { lookup( '' ); } } );

			if ( 'batch' === kind && form ) {
				var program = form.querySelector( 'select[name="program_id"]' );
			var designSources = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
				if ( program ) {
					program.addEventListener( 'change', function () {
						search.value = '';
						replaceChoices( [] );
						select.value = defaultValue;
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						lookup( '' );
					} );
				}
			}
			if ( 'batch' === kind && ! programId() ) { select.disabled = true; search.disabled = true; status.textContent = certloomText( 'contextChooseProgramFirst', 'Choose a Program first.' ); }
		} );
	}


	function initProgramBatchSelectors() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), function ( form ) {
			var program = form.querySelector( 'select[name="program_id"]' );
			var designSources = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			if ( ! program || ! batch ) { return; }
			function update() {
				var programId = String( program.value || '' );
				var selectedStillValid = false;
				Array.prototype.forEach.call( batch.options, function ( option ) {
					var owner = option.getAttribute( 'data-program-id' );
					var allowed = ! owner || ( programId && owner === programId );
					option.hidden = ! allowed;
					option.disabled = ! allowed;
					if ( allowed && option.selected ) { selectedStillValid = true; }
				} );
				if ( ! selectedStillValid ) { batch.value = '0'; }
			}
			program.addEventListener( 'change', update );
			update();
		} );
	}


	function initScopedFieldForms() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-certloom-scoped-fields-form]' ), function ( form ) {
			var program = form.querySelector( 'select[name="program_id"]' );
			var designSources = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			var nonce = form.getAttribute( 'data-certloom-scoped-fields-nonce' ) || '';
			if ( ! program || ! nonce ) { return; }
			var status = form.querySelector( '[data-certloom-scoped-field-status]' );
			var container = form.querySelector( '[data-certloom-scoped-field-inputs]' );
			var legacy = form.querySelector( '[data-certloom-legacy-custom-fields]' );
			var bulkSchema = form.querySelector( '[data-certloom-bulk-field-schema-body]' );
			var existingScript = form.querySelector( '[data-certloom-scoped-existing-values]' );
			var independentScript = form.querySelector( '[data-certloom-independent-field-catalog]' );
			var independent = form.querySelector( '[data-certloom-independent-details]' );
			var independentInputs = form.querySelector( '[data-certloom-independent-detail-inputs]' );
			var independentSelect = form.querySelector( '[data-certloom-independent-detail-select]' );
			var independentAdd = form.querySelector( '[data-certloom-independent-detail-add]' );
			var independentSuggestions = form.querySelector( '[data-certloom-independent-detail-suggestions]' );
			var detailsHelp = form.querySelector( '[data-certloom-credential-details-help]' );
			var basisRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="credential_basis"]' ) );
			var values = {};
			var independentCatalog = [];
			var requestSequence = 0;
			try { values = existingScript ? JSON.parse( existingScript.textContent || '{}' ) : {}; } catch ( error ) { values = {}; }
			try { independentCatalog = independentScript ? JSON.parse( independentScript.textContent || '[]' ) : []; } catch ( error ) { independentCatalog = []; }
			var independentCatalogByKey = {};
			independentCatalog.forEach( function ( field ) { if ( field && field.key ) { independentCatalogByKey[ field.key ] = field; } } );

			function cacheCurrentValues() {
				if ( ! container ) { return; }
				Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-scoped-value-key],[data-certloom-independent-value-key]' ), function ( input ) {
					var key = input.getAttribute( 'data-certloom-scoped-value-key' ) || input.getAttribute( 'data-certloom-independent-value-key' );
					if ( key ) { values[ key ] = input.value; }
				} );
			}

			function setLegacyEnabled( enabled ) {
				if ( ! legacy ) { return; }
				legacy.hidden = ! enabled;
				Array.prototype.forEach.call( legacy.querySelectorAll( 'input,select,textarea,button' ), function ( control ) { control.disabled = ! enabled; } );
			}

			function resetRecipientRequirements() {
				Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-field-contract-required="1"]' ), function ( input ) {
					input.required = false;
					input.removeAttribute( 'data-certloom-field-contract-required' );
				} );
			}

			function applyRecipientRequirements( fields ) {
				resetRecipientRequirements();
				var newMode = form.querySelector( 'input[name="recipient_mode"][value="new"]' );
				if ( ! newMode || ! newMode.checked ) { return; }
				fields.forEach( function ( field ) {
					if ( ! field.required || 'recipient' !== field.value_source ) { return; }
					var input = form.querySelector( '[name="new_recipient[' + field.key.replace( /[^a-z0-9_-]/g, '' ) + ']"]' );
					if ( input ) { input.required = true; input.setAttribute( 'data-certloom-field-contract-required', '1' ); }
				} );
			}

			function credentialBasis() {
				var checked = basisRadios.filter( function ( radio ) { return radio.checked; } )[ 0 ];
				return checked ? checked.value : ( program.value && '0' !== program.value ? 'program' : 'independent' );
			}

			function independentFieldDefinition( key, fallbackLabel ) {
				if ( independentCatalogByKey[ key ] ) { return independentCatalogByKey[ key ]; }
				return { key: key, label: fallbackLabel || key.replace( /[_\-.]+/g, ' ' ).replace( /\b\w/g, function ( char ) { return char.toUpperCase(); } ), type: 'text', sensitivity: 'personal', public_exposure: 'private' };
			}

			function renderIndependentField( key, focus, fallbackLabel ) {
				if ( ! independentInputs || ! key ) { return null; }
				var safeKey = key.replace( /[^a-z0-9_-]/g, '' );
				var scoped = form.querySelector( '[data-certloom-scoped-value-key="' + safeKey + '"]' );
				if ( scoped ) { if ( focus ) { scoped.focus(); } return scoped; }
				var existing = independentInputs.querySelector( '[data-certloom-independent-field="' + safeKey + '"]' );
				if ( existing ) { var existingInput = existing.querySelector( '[data-certloom-independent-value-key]' ); if ( focus && existingInput ) { existingInput.focus(); } return existingInput; }
				var field = independentFieldDefinition( key, fallbackLabel );
				var row = document.createElement( 'div' ); row.className = 'certloom-independent-detail-row'; row.setAttribute( 'data-certloom-independent-field', key );
				var heading = document.createElement( 'div' ); heading.className = 'certloom-independent-detail-row__head';
				var label = document.createElement( 'strong' ); label.textContent = field.label || key; heading.appendChild( label );
				var remove = document.createElement( 'button' ); remove.type = 'button'; remove.className = 'button-link-delete'; remove.textContent = certloomText( 'remove', 'Remove' ); heading.appendChild( remove ); row.appendChild( heading );
				var input;
				if ( 'textarea' === field.type ) { input = document.createElement( 'textarea' ); input.rows = 3; }
				else { input = document.createElement( 'input' ); input.type = 'date' === field.type ? 'date' : ( 'number' === field.type ? 'number' : 'text' ); if ( 'number' === field.type ) { input.step = 'any'; } }
				input.name = 'custom_merge_fields[' + key + ']'; input.maxLength = 2000; input.setAttribute( 'data-certloom-independent-value-key', key ); input.value = Object.prototype.hasOwnProperty.call( values, key ) ? String( values[ key ] ) : ''; input.setAttribute( 'aria-label', field.label || key ); row.appendChild( input );
				var privacy = document.createElement( 'small' ); privacy.className = 'description'; privacy.textContent = certloomText( 'privacyPrefix', 'Privacy:' ) + ' ' + certloomFieldPrivacyText( 'exposure', field.public_exposure || 'private' ) + ' · ' + certloomFieldPrivacyText( 'sensitivity', field.sensitivity || 'personal' ); row.appendChild( privacy );
				remove.addEventListener( 'click', function () { delete values[ key ]; row.remove(); renderIndependentEmpty(); form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true } ) ); } );
				input.addEventListener( 'input', function () { values[ key ] = input.value; } );
				independentInputs.appendChild( row );
				if ( focus ) { input.focus(); }
				return input;
			}

			function renderIndependentEmpty() {
				if ( ! independentInputs ) { return; }
				var empty = independentInputs.querySelector( '[data-certloom-independent-empty]' );
				var hasRows = !! independentInputs.querySelector( '[data-certloom-independent-field]' );
				if ( hasRows && empty ) { empty.remove(); return; }
				if ( ! hasRows && ! empty ) { empty = document.createElement( 'p' ); empty.className = 'description certloom-independent-empty'; empty.setAttribute( 'data-certloom-independent-empty', '' ); empty.textContent = certloomText( 'noIndependentDetails', 'No extra credential details added yet.' ); independentInputs.appendChild( empty ); }
			}

			function renderIndependent() {
				if ( ! independentInputs ) { return; }
				independentInputs.innerHTML = '';
				Object.keys( values ).sort().forEach( function ( key ) { if ( key ) { renderIndependentField( key, false, '' ); } } );
				renderIndependentEmpty();
			}

			function designAliasInput( targetKey ) { return form.querySelector( 'input[data-certloom-design-field-alias="' + targetKey + '"]' ); }
			function credentialDetailMappingCandidates( targetKey ) {
				var found = {};
				independentCatalog.forEach( function ( candidate ) {
					if ( ! candidate || ! candidate.key || candidate.key === targetKey ) { return; }
					found[ candidate.key ] = { key: candidate.key, label: candidate.label || candidate.key };
				} );
				Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-scoped-value-key],[data-certloom-independent-value-key]' ), function ( input ) {
					var key = String( input.getAttribute( 'data-certloom-scoped-value-key' ) || input.getAttribute( 'data-certloom-independent-value-key' ) || '' ).replace( /[^a-z0-9_-]/g, '' );
					if ( ! key || key === targetKey ) { return; }
					var row = input.closest( '[data-certloom-independent-field], .certloom-scoped-field-row' );
					var labelNode = row ? row.querySelector( 'strong' ) : null;
					var definition = independentCatalogByKey[ key ] || {};
					found[ key ] = { key: key, label: String( ( labelNode && labelNode.textContent ) || definition.label || key ).replace( /\s*\*\s*$/, '' ) };
				} );
				return Object.keys( found ).sort( function ( a, b ) { return String( found[ a ].label ).localeCompare( String( found[ b ].label ) ); } ).map( function ( key ) { return found[ key ]; } );
			}
			function designAliasValue( targetKey ) { var input = designAliasInput( targetKey ); return input ? String( input.value || '' ) : ''; }
			function setDesignAlias( targetKey, sourceKey ) {
				var input = designAliasInput( targetKey );
				if ( ! sourceKey ) { if ( input ) { input.remove(); } return; }
				if ( ! input ) { input = document.createElement( 'input' ); input.type = 'hidden'; input.setAttribute( 'data-certloom-design-field-alias', targetKey ); input.name = 'design_field_aliases[' + targetKey + ']'; form.appendChild( input ); }
				input.value = sourceKey;
			}

			function renderDesignDetailSuggestions( fields ) {
				if ( ! independentSuggestions || ! independentInputs ) { return; }
				independentSuggestions.innerHTML = '';
				var seen = {};
				var candidates = ( Array.isArray( fields ) ? fields : [] ).filter( function ( field ) {
					if ( ! field || 'missing' !== field.status || 'credential_detail' !== field.action ) { return false; }
					var key = String( field.field_key || '' ).replace( /[^a-z0-9_-]/g, '' );
					if ( ! key || seen[ key ] || independentInputs.querySelector( '[data-certloom-independent-field="' + key + '"]' ) ) { return false; }
					seen[ key ] = true; field._safeFieldKey = key; return true;
				} );
				if ( ! candidates.length ) { independentSuggestions.hidden = true; return; }
				var label = document.createElement( 'span' ); label.className = 'certloom-independent-detail-suggestions__label'; label.textContent = certloomText( 'designSuggestedDetails', 'Needed by the selected design' ); independentSuggestions.appendChild( label );
				candidates.forEach( function ( field ) {
					var row = document.createElement( 'div' ); row.className = 'certloom-design-required-detail'; row.setAttribute( 'data-certloom-design-required-detail', field._safeFieldKey );
					var copy = document.createElement( 'div' ); copy.className = 'certloom-design-required-detail__copy';
					var strong = document.createElement( 'strong' ); strong.textContent = String( field.label || field._safeFieldKey ); copy.appendChild( strong );
					var meta = document.createElement( 'small' ); meta.textContent = certloomText( 'designSuggestedMissing', 'Required by the selected design and not filled yet.' ); copy.appendChild( meta ); row.appendChild( copy );
					var actions = document.createElement( 'div' ); actions.className = 'certloom-design-required-detail__actions';
					var button = document.createElement( 'button' ); button.type = 'button'; button.className = 'button certloom-independent-detail-suggestion';
					button.setAttribute( 'data-certloom-suggested-field', field._safeFieldKey );
					var text = String( certloomText( 'designSuggestedAdd', 'Add %s' ) ).replace( '%s', String( field.label || field._safeFieldKey ) );
					button.innerHTML = '<span aria-hidden="true">+</span> ' + escapeHtml( text );
					button.addEventListener( 'click', function () { setDesignAlias( field._safeFieldKey, '' ); renderIndependentField( field._safeFieldKey, true, field.label || '' ); renderIndependentEmpty(); renderDesignDetailSuggestions( candidates ); form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true } ) ); } );
					actions.appendChild( button );

					var mapCandidates = credentialDetailMappingCandidates( field._safeFieldKey );
					if ( mapCandidates.length ) {
						var mapping = document.createElement( 'label' ); mapping.className = 'certloom-design-required-detail__mapping';
						var mapLabel = document.createElement( 'span' ); mapLabel.textContent = certloomText( 'designSuggestedMap', 'Assign existing detail' ); mapping.appendChild( mapLabel );
						var mapSelect = document.createElement( 'select' ); mapSelect.setAttribute( 'data-certloom-design-field-map', field._safeFieldKey );
						var placeholder = document.createElement( 'option' ); placeholder.value = ''; placeholder.textContent = certloomText( 'designSuggestedChooseExisting', 'Choose an existing credential detail…' ); mapSelect.appendChild( placeholder );
						mapCandidates.forEach( function ( candidate ) { var option = document.createElement( 'option' ); option.value = candidate.key; option.textContent = candidate.label || candidate.key; mapSelect.appendChild( option ); } );
						mapSelect.value = designAliasValue( field._safeFieldKey );
						mapSelect.addEventListener( 'change', function () {
							var sourceKey = String( mapSelect.value || '' ).replace( /[^a-z0-9_-]/g, '' ); setDesignAlias( field._safeFieldKey, sourceKey );
							if ( sourceKey ) { var sourceDefinition = independentCatalogByKey[ sourceKey ] || {}; renderIndependentField( sourceKey, true, sourceDefinition.label || sourceKey ); renderIndependentEmpty(); }
							form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true } ) );
						} );
						mapping.appendChild( mapSelect ); actions.appendChild( mapping );
					}
					row.appendChild( actions ); independentSuggestions.appendChild( row );
				} );
				independentSuggestions.hidden = false;
			}

			function setIndependentMode( enabled ) {
				// Credential-specific one-off values are valid for both Program-backed and
				// Independent issuance. Program field-contract inputs remain authoritative
				// when the same key is already scoped by the selected Program/Batch.
				if ( independent ) { independent.hidden = false; }
				if ( detailsHelp ) { detailsHelp.textContent = enabled ? certloomText( 'credentialDetailsIndependent', 'Add the credential-specific facts you want to store with this independent credential.' ) : certloomText( 'credentialDetailsProgram', 'Program and Batch field rules appear here. Add one-off credential details below when this issuance or its design needs them.' ); }
				if ( enabled ) {
					setLegacyEnabled( false ); resetRecipientRequirements();
					if ( container ) { container.innerHTML = ''; }
					if ( status ) { status.textContent = certloomText( 'credentialDetailsIndependent', 'Add the credential-specific facts you want to store with this independent credential.' ); }
					var gradeInput = form.querySelector( 'input[name="grade"]' ); var gradeWrap = form.querySelector( '[data-certloom-grade-field]' );
					if ( gradeWrap ) { gradeWrap.hidden = false; } if ( gradeInput ) { gradeInput.disabled = false; gradeInput.required = false; }
				}
				renderIndependent();
			}

			function renderManual( payload ) {
				if ( ! container ) { return; }
				container.innerHTML = '';
				var fields = Array.isArray( payload.fields ) ? payload.fields : [];
				setLegacyEnabled( ! payload.configured );
				applyRecipientRequirements( fields );

				var gradeInput = form.querySelector( 'input[name="grade"]' );
				var gradeWrap = form.querySelector( '[data-certloom-grade-field]' );
				if ( gradeInput ) { gradeInput.required = false; gradeInput.removeAttribute( 'data-certloom-field-contract-required' ); gradeInput.disabled = false; }
				var gradeField = fields.filter( function ( field ) { return 'grade' === field.key; } )[ 0 ];
				var gradeAvailable = ! payload.configured || !! gradeField;
				if ( gradeWrap ) { gradeWrap.hidden = ! gradeAvailable; }
				if ( gradeInput ) { gradeInput.disabled = ! gradeAvailable; }
				if ( gradeInput && gradeField && gradeField.required ) { gradeInput.required = true; gradeInput.setAttribute( 'data-certloom-field-contract-required', '1' ); }

				if ( ! payload.configured ) {
					if ( status ) { status.textContent = certloomText( 'legacyProgramFieldMode', 'Legacy Program field mode — optional free custom keys remain available until this Program adopts Credential fields.' ); }
					return;
				}
				if ( ! fields.length ) {
					if ( status ) { status.textContent = certloomText( 'programNoCredentialFields', 'This Program uses Credential fields but currently assigns no additional fields.' ); }
					return;
				}
				if ( status ) { status.textContent = batch && batch.value && '0' !== batch.value ? certloomText( 'credentialFieldsLoadedBatch', 'Credential fields loaded from the selected Program + Batch.' ) : certloomText( 'credentialFieldsLoadedProgram', 'Credential fields loaded from the selected Program.' ); }

				fields.forEach( function ( field ) {
					var row = document.createElement( 'div' );
					row.className = 'certloom-scoped-field-row';
					var heading = document.createElement( 'div' );
					heading.className = 'certloom-scoped-field-row__label';
					var strong = document.createElement( 'strong' );
					strong.textContent = field.label + ( field.required ? ' *' : '' );
					heading.appendChild( strong );
					var meta = document.createElement( 'small' );
					meta.textContent = ( 'batch' === field.origin ? certloomText( 'scopeBatch', 'Batch' ) : certloomText( 'scopeProgram', 'Program' ) ) + ' · ' + ( field.required ? certloomText( 'fieldRequired', 'Required' ) : certloomText( 'fieldOptional', 'Optional' ) );
					heading.appendChild( meta );
					row.appendChild( heading );

					if ( 'recipient' === field.value_source ) {
						var source = document.createElement( 'p' );
						source.className = 'description';
						source.textContent = certloomText( 'recipientValueFrozen', 'Value comes from the Recipient profile and is frozen into the issued credential.' );
						row.appendChild( source );
					} else if ( 'credential' === field.value_source && 'grade' === field.key ) {
						var grade = document.createElement( 'p' );
						grade.className = 'description';
						grade.textContent = certloomText( 'useGradeField', 'Use the Grade / result field above.' );
						row.appendChild( grade );
					} else {
						var input;
						if ( 'textarea' === field.type ) {
							input = document.createElement( 'textarea' ); input.rows = 3;
						} else {
							input = document.createElement( 'input' );
							input.type = 'date' === field.type ? 'date' : ( 'number' === field.type ? 'number' : 'text' );
							if ( 'number' === field.type ) { input.step = 'any'; }
						}
						input.name = 'custom_merge_fields[' + field.key + ']';
						input.setAttribute( 'data-certloom-scoped-value-key', field.key );
						input.maxLength = 2000;
						input.required = !! field.required;
						input.value = Object.prototype.hasOwnProperty.call( values, field.key ) ? String( values[ field.key ] ) : '';
						input.setAttribute( 'aria-label', field.label );
						row.appendChild( input );
					}
					var privacy = document.createElement( 'small' );
					privacy.className = 'description';
					privacy.textContent = certloomText( 'privacyPrefix', 'Privacy:' ) + ' ' + certloomFieldPrivacyText( 'exposure', field.public_exposure || 'private' ) + ' · ' + certloomFieldPrivacyText( 'sensitivity', field.sensitivity || 'personal' );
					row.appendChild( privacy );
					container.appendChild( row );
				} );
			}

			function renderBulkSchema( payload ) {
				if ( ! bulkSchema ) { return; }
				var fields = Array.isArray( payload.fields ) ? payload.fields : [];
				var html = '<code>recipient_id</code> <em>' + escapeHtml( certloomText( 'bulkOr', 'or' ) ) + '</em> <code>display_name</code> + <code>email</code>/<code>external_id</code>, <code>first_name</code>, <code>middle_name</code>, <code>last_name</code>, <code>certificate_number</code>';
				var extras = [];
				fields.forEach( function ( field ) {
					if ( 'recipient' === field.value_source ) { return; }
					var column = 'grade' === field.key ? 'grade' : 'custom:' + field.key;
					extras.push( '<code>' + escapeHtml( column ) + '</code>' + ( field.required ? ' <strong>(' + escapeHtml( certloomText( 'bulkRequired', 'required' ) ) + ')</strong>' : '' ) );
				} );
				if ( ! payload.configured ) { extras.push( '<code>grade</code>', '<code>custom:machine_key</code>' ); }
				bulkSchema.innerHTML = html + ( extras.length ? ', ' + extras.join( ', ' ) : '' ) + '.';
			}

			function renderError( message ) {
				if ( status ) { status.textContent = message || certloomText( 'credentialFieldsLoadFailed', 'Credential fields could not be loaded. The server will still validate before issuance.' ); }
			}

			function resolve() {
				cacheCurrentValues();
				requestSequence++;
				var sequence = requestSequence;
				if ( 'independent' === credentialBasis() ) {
					setIndependentMode( true );
					renderBulkSchema( { configured: false, fields: [] } );
					return;
				}
				setIndependentMode( false );
				if ( ! program.value || '0' === program.value ) {
					renderManual( { configured: false, fields: [] } );
					renderBulkSchema( { configured: false, fields: [] } );
					if ( status ) { status.textContent = certloomText( 'chooseProgramLoadFields', 'Choose a Program to load its credential fields.' ); }
					return;
				}
				if ( status ) { status.textContent = certloomText( 'loadingCredentialFields', 'Loading credential fields…' ); }
				var body = new FormData();
				body.append( 'action', 'certloom_resolve_scoped_fields' );
				body.append( 'nonce', nonce );
				body.append( 'program_id', program.value );
				body.append( 'batch_id', batch ? ( batch.value || '0' ) : '0' );
				window.fetch( window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { if ( ! response.ok ) { throw new Error( 'HTTP ' + response.status ); } return response.json(); } )
					.then( function ( response ) {
						if ( sequence !== requestSequence ) { return; }
						if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : certloomText( 'invalidResponse', 'Invalid response' ) ); }
						renderManual( response.data );
						renderBulkSchema( response.data );
					} )
					.catch( function ( error ) { if ( sequence === requestSequence ) { renderError( error && error.message ? error.message : '' ); } } );
			}

			program.addEventListener( 'change', resolve );
			if ( batch ) { batch.addEventListener( 'change', resolve ); }
			basisRadios.forEach( function ( input ) { input.addEventListener( 'change', resolve ); } );
			Array.prototype.forEach.call( form.querySelectorAll( 'input[name="recipient_mode"]' ), function ( input ) { input.addEventListener( 'change', resolve ); } );
			if ( independentAdd ) { independentAdd.addEventListener( 'click', function () { var key = independentSelect ? independentSelect.value : ''; if ( ! key ) { if ( independentSelect ) { independentSelect.focus(); } return; } renderIndependentField( key, true, '' ); renderIndependentEmpty(); if ( independentSelect ) { independentSelect.value = ''; } form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true } ) ); } ); }
			form.addEventListener( 'certloom:add-credential-detail', function ( event ) { var detail = event.detail || {}; var key = String( detail.fieldKey || '' ).replace( /[^a-z0-9_-]/g, '' ); if ( key ) { renderIndependentField( key, true, detail.label || '' ); renderIndependentEmpty(); form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true } ) ); } } );
			form.addEventListener( 'certloom:design-missing-fields', function ( event ) { renderDesignDetailSuggestions( event.detail && Array.isArray( event.detail.fields ) ? event.detail.fields : [] ); } );
			resolve();
		} );
	}

	function initAdaptiveSetupWizard() {
		var root = document.querySelector( '[data-certloom-setup-v2]' );
		if ( ! root ) { return; }

		var wizardForm = root.querySelector( '#certloom-wizard-step-form' );
		var dirty = false;
		function setDirty( value ) { dirty = !!value; root.setAttribute( 'data-certloom-dirty', dirty ? '1' : '0' ); }
		if ( wizardForm ) {
			wizardForm.addEventListener( 'input', function ( event ) { if ( event.target && event.target.name ) { setDirty( true ); event.target.removeAttribute( 'aria-invalid' ); } } );
			wizardForm.addEventListener( 'change', function ( event ) { if ( event.target && event.target.name ) { setDirty( true ); event.target.removeAttribute( 'aria-invalid' ); } } );
			wizardForm.addEventListener( 'submit', function () { setDirty( false ); } );
			wizardForm.addEventListener( 'invalid', function ( event ) {
				var control = event.target;
				if ( control && control.setAttribute ) { control.setAttribute( 'aria-invalid', 'true' ); window.setTimeout( function () { if ( control.focus ) { control.focus(); } }, 0 ); }
			}, true );
			window.addEventListener( 'beforeunload', function ( event ) { if ( ! dirty ) { return; } event.preventDefault(); event.returnValue = ''; } );
		}
		var setupError = root.querySelector( '.certloom-setup-v2-notices .notice-error' );
		if ( setupError ) { setupError.setAttribute( 'tabindex', '-1' ); window.setTimeout( function () { setupError.focus(); }, 0 ); }

		var profileForm = root.querySelector( '[data-certloom-profile-form]' );
		if ( profileForm ) {
			var hierarchyTargets = {};
			Array.prototype.forEach.call( profileForm.querySelectorAll( '[data-certloom-hierarchy]' ), function ( node ) {
				hierarchyTargets[ node.getAttribute( 'data-certloom-hierarchy' ) ] = node;
			} );
			function syncProfileHelp() {
				var selected = profileForm.querySelector( 'input[name="usage_profile"]:checked' );
				var card = selected ? selected.closest( '[data-certloom-profile-card]' ) : null;
				if ( ! card ) { return; }
				[ 'issuer', 'program', 'batch', 'signer' ].forEach( function ( key ) {
					if ( hierarchyTargets[ key ] ) { hierarchyTargets[ key ].textContent = card.getAttribute( 'data-hierarchy-' + key ) || ''; }
				} );
			}
			Array.prototype.forEach.call( profileForm.querySelectorAll( 'input[name="usage_profile"]' ), function ( input ) { input.addEventListener( 'change', syncProfileHelp ); } );
			syncProfileHelp();
		}

		var fieldsForm = root.querySelector( '[data-certloom-fields-form]' );
		if ( fieldsForm ) {
			var list = fieldsForm.querySelector( '[data-certloom-custom-field-list]' );
			var fresh = fieldsForm.querySelector( '[data-certloom-custom-field-new]' );
			var add = fieldsForm.querySelector( '[data-certloom-custom-field-add]' );
			function addCustomField() {
				if ( ! list || ! fresh ) { return; }
				var value = String( fresh.value || '' ).trim();
				if ( ! value ) { fresh.focus(); return; }
				var row = document.createElement( 'div' ); row.className = 'certloom-setup-v2-custom-field';
				var input = document.createElement( 'input' ); input.type = 'text'; input.name = 'custom_fields[]'; input.maxLength = 100; input.value = value;
				var remove = document.createElement( 'button' ); remove.type = 'button'; remove.className = 'button-link'; remove.setAttribute( 'data-certloom-custom-field-remove', '' ); remove.textContent = certloomText( 'remove', 'Remove' );
				row.appendChild( input ); row.appendChild( remove ); list.appendChild( row ); fresh.value = ''; input.focus();
			}
			if ( add ) { add.addEventListener( 'click', addCustomField ); }
			if ( fresh ) { fresh.addEventListener( 'keydown', function ( event ) { if ( 'Enter' === event.key ) { event.preventDefault(); addCustomField(); } } ); }
			fieldsForm.addEventListener( 'click', function ( event ) { var remove = event.target.closest( '[data-certloom-custom-field-remove]' ); if ( remove ) { var row = remove.closest( '.certloom-setup-v2-custom-field' ); if ( row ) { row.remove(); if ( fresh ) { fresh.focus(); } } } } );
		}

		var appearanceForm = root.querySelector( '[data-certloom-appearance-form]' );
		if ( appearanceForm ) {
			var panels = appearanceForm.querySelectorAll( '[data-certloom-presentation-panel]' );
			var imageControl = appearanceForm.querySelector( '[data-certloom-presentation-panel="image"] [data-certloom-media-control]' );
			var imageId = imageControl ? imageControl.querySelector( '[data-certloom-media-id]' ) : null;
			var imagePreview = imageControl ? imageControl.querySelector( '[data-certloom-media-preview]' ) : null;
			var imageClear = imageControl ? imageControl.querySelector( '[data-certloom-media-clear]' ) : null;
			var designSelect = appearanceForm.querySelector( 'select[name="design_template_id"]' );
			function syncPresentation() {
				var selected = appearanceForm.querySelector( 'input[name="presentation_mode"]:checked' );
				var mode = selected ? selected.value : 'details_only';
				Array.prototype.forEach.call( panels, function ( panel ) { panel.hidden = panel.getAttribute( 'data-certloom-presentation-panel' ) !== mode; } );
				if ( 'image' === mode ) {
					if ( designSelect ) { designSelect.value = '0'; }
				} else if ( 'design' === mode ) {
					if ( imageId ) { imageId.value = ''; }
					if ( imagePreview ) { imagePreview.innerHTML = '<span>' + escapeHtml( certloomText( 'noCertificateImage', 'No certificate image selected' ) ) + '</span>'; }
					if ( imageClear ) { imageClear.hidden = true; }
					var previewPanel = appearanceForm.querySelector( '[data-certloom-wizard-template-preview]' );
					if ( previewPanel ) { window.requestAnimationFrame( function () { fitWizardTemplatePreview( previewPanel ); } ); }
				} else {
					if ( designSelect ) { designSelect.value = '0'; }
					if ( imageId ) { imageId.value = ''; }
					if ( imagePreview ) { imagePreview.innerHTML = '<span>' + escapeHtml( certloomText( 'noCertificateImage', 'No certificate image selected' ) ) + '</span>'; }
					if ( imageClear ) { imageClear.hidden = true; }
				}
			}
			Array.prototype.forEach.call( appearanceForm.querySelectorAll( 'input[name="presentation_mode"]' ), function ( input ) { input.addEventListener( 'change', syncPresentation ); } );
			syncPresentation();
		}


		var contextForm = root.querySelector( '[data-certloom-setup-context]' );
		if ( contextForm ) {
			var programSelect = contextForm.querySelector( '[data-certloom-setup-program]' );
			var batchSelect = contextForm.querySelector( '[data-certloom-setup-batch]' );
			var issuerSelect = contextForm.querySelector( 'select[name="setup_issuer_id"]' );
			function syncSetupContext() {
				var programId = programSelect ? String( programSelect.value || '' ) : '';
				if ( batchSelect ) {
					Array.prototype.forEach.call( batchSelect.options, function ( option ) {
						if ( ! option.value ) { option.hidden = false; option.disabled = false; return; }
						var belongs = !!programId && option.getAttribute( 'data-program-id' ) === programId;
						option.hidden = !belongs; option.disabled = !belongs;
					} );
					var selectedBatch = batchSelect.options[ batchSelect.selectedIndex ];
					if ( selectedBatch && selectedBatch.value && selectedBatch.disabled ) { batchSelect.value = '0'; }
				}
				if ( programSelect && issuerSelect ) {
					var selected = programSelect.options[ programSelect.selectedIndex ];
					var programIssuer = selected ? String( selected.getAttribute( 'data-issuer-id' ) || '' ) : '';
					if ( programIssuer ) { Array.prototype.some.call( issuerSelect.options, function ( option ) { if ( option.value === programIssuer ) { issuerSelect.value = programIssuer; return true; } return false; } ); }
				}
			}
			if ( programSelect ) { programSelect.addEventListener( 'change', syncSetupContext ); }
			syncSetupContext();
		}

		Array.prototype.forEach.call( root.querySelectorAll( '[data-copy-text]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var text = button.getAttribute( 'data-copy-text' ) || '';
				if ( ! text ) { return; }
				var original = button.textContent;
				var done = function ( ok ) { button.textContent = ok ? certloomText( 'copied', 'Copied' ) : certloomText( 'copyFailed', 'Press Ctrl+C or Cmd+C to copy' ); window.setTimeout( function () { button.textContent = original; }, 1800 ); };
				if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( text ).then( function () { done( true ); }, function () { done( false ); } ); return; }
				var scratch = document.createElement( 'textarea' ); scratch.value = text; scratch.setAttribute( 'readonly', '' ); scratch.style.position = 'fixed'; scratch.style.opacity = '0'; document.body.appendChild( scratch ); scratch.select(); var ok = false; try { ok = document.execCommand( 'copy' ); } catch ( error ) { ok = false; } scratch.remove(); done( ok );
			} );
		} );
	}


	function initSetupNumberingPreview() {
		var form = document.querySelector( '[data-certloom-numbering-form]' );
		if ( ! form ) { return; }
		var mode = form.querySelector( '[data-certloom-numbering-mode]' );
		var prefix = form.querySelector( '[data-certloom-numbering-prefix]' );
		var suffix = form.querySelector( '[data-certloom-numbering-suffix]' );
		var start = form.querySelector( '[data-certloom-numbering-start]' );
		var padding = form.querySelector( '[data-certloom-numbering-padding]' );
		var example = form.querySelector( '[data-certloom-numbering-example]' );
		var format = form.querySelector( '[data-certloom-numbering-format]' );
		if ( ! mode || ! example || ! format ) { return; }
		function update() {
			var value = mode.value || 'manual';
			format.hidden = 'manual' === value;
			Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-sequential-only]' ), function ( row ) { row.hidden = 'sequential' !== value; } );
			if ( 'manual' === value ) { example.textContent = certloomText( 'enteredWhenIssuing', 'Entered when issuing' ); return; }
			var lead = prefix ? prefix.value : '';
			var tail = suffix ? suffix.value : '';
			if ( 'sequential' === value ) {
				var n = Math.max( 1, parseInt( start && start.value ? start.value : '1', 10 ) || 1 );
				var width = Math.max( 1, Math.min( 12, parseInt( padding && padding.value ? padding.value : '6', 10 ) || 6 ) );
				example.textContent = lead + String( n ).padStart( width, '0' ) + tail;
			} else if ( 'random' === value ) { example.textContent = lead + '8K3M-2Q7P' + tail; }
			else { example.textContent = lead + '550e8400-e29b-41d4-a716-446655440000' + tail; }
		}
		[ mode, prefix, suffix, start, padding ].forEach( function ( input ) { if ( input ) { input.addEventListener( 'input', update ); input.addEventListener( 'change', update ); } } );
		update();
	}

	var wizardPreviewObservers = typeof WeakMap === 'function' ? new WeakMap() : null;

	function fitWizardTemplatePreview( panel ) {
		if ( ! panel || ! panel.querySelector ) { return; }
		var canvas = panel.querySelector( '[data-certloom-template-preview-canvas]' );
		var scroll = canvas ? canvas.querySelector( '.certloom-template-preview-scroll' ) : null;
		var page = scroll ? scroll.querySelector( '.certloom-template-preview-page' ) : null;
		if ( ! canvas || ! scroll || ! page || canvas.clientWidth < 1 ) { return; }
		var naturalWidth = parseFloat( scroll.getAttribute( 'data-certloom-preview-width' ) || '' ) || parseFloat( page.style.width || '' );
		var naturalHeight = parseFloat( scroll.getAttribute( 'data-certloom-preview-height' ) || '' ) || parseFloat( page.style.height || '' );
		if ( ! naturalWidth || ! naturalHeight ) { return; }
		var stage = page.parentNode && page.parentNode.classList && page.parentNode.classList.contains( 'certloom-wizard-template-preview-stage' ) ? page.parentNode : null;
		if ( ! stage ) {
			stage = document.createElement( 'div' );
			stage.className = 'certloom-wizard-template-preview-stage';
			page.parentNode.insertBefore( stage, page );
			stage.appendChild( page );
		}
		var scrollStyle = window.getComputedStyle ? window.getComputedStyle( scroll ) : null;
		var horizontalPadding = scrollStyle ? ( parseFloat( scrollStyle.paddingLeft || 0 ) + parseFloat( scrollStyle.paddingRight || 0 ) ) : 0;
		var verticalPadding = scrollStyle ? ( parseFloat( scrollStyle.paddingTop || 0 ) + parseFloat( scrollStyle.paddingBottom || 0 ) ) : 0;
		var availableWidth = Math.max( 1, scroll.clientWidth - horizontalPadding );
		var availableHeight = Math.max( 1, scroll.clientHeight - verticalPadding );
		var scale = Math.min( 1, availableWidth / naturalWidth, availableHeight / naturalHeight );
		page.style.transformOrigin = 'top left';
		page.style.transform = 'scale(' + scale.toFixed( 6 ) + ')';
		stage.style.width = Math.max( 1, naturalWidth * scale ) + 'px';
		stage.style.height = Math.max( 1, naturalHeight * scale ) + 'px';
		stage.style.margin = 'auto';
		scroll.setAttribute( 'data-certloom-preview-fitted', '1' );
	}

	function observeWizardTemplatePreview( panel ) {
		if ( ! panel || panel.getAttribute( 'data-certloom-preview-observed' ) === '1' ) { return; }
		panel.setAttribute( 'data-certloom-preview-observed', '1' );
		fitWizardTemplatePreview( panel );
		if ( typeof ResizeObserver === 'function' ) {
			var observer = new ResizeObserver( function () { fitWizardTemplatePreview( panel ); } );
			var canvas = panel.querySelector( '[data-certloom-template-preview-canvas]' );
			if ( canvas ) { observer.observe( canvas ); if ( wizardPreviewObservers ) { wizardPreviewObservers.set( panel, observer ); } }
		} else { window.addEventListener( 'resize', function () { fitWizardTemplatePreview( panel ); } ); }
	}

	function initWizardTemplatePreview() {
		var select = document.getElementById( 'certloom-wizard-template' );
		var panel = document.querySelector( '[data-certloom-wizard-template-preview]' );
		if ( ! select || ! panel ) { return; }
		observeWizardTemplatePreview( panel );
		if ( 'undefined' === typeof window.ajaxurl ) { return; }
		var name = panel.querySelector( '[data-certloom-template-preview-name]' );
		var description = panel.querySelector( '[data-certloom-template-preview-description]' );
		var canvas = panel.querySelector( '[data-certloom-template-preview-canvas]' );
		var nonce = panel.getAttribute( 'data-preview-nonce' ) || '';
		var sequence = 0;
		select.addEventListener( 'change', function () {
			sequence++;
			var request = sequence;
			panel.setAttribute( 'aria-busy', 'true' );
			if ( name ) { name.textContent = certloomText( 'loadingPreview', 'Loading preview…' ); }
			if ( description ) { description.textContent = ''; }
			if ( canvas ) { canvas.innerHTML = ''; }
			var body = new FormData();
			body.append( 'action', 'certloom_wizard_template_preview' ); body.append( 'nonce', nonce ); body.append( 'template_id', select.value || '0' );
			fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( request !== sequence ) { return; }
				if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ) ); }
				if ( name ) { name.textContent = response.data.name || certloomText( 'noDefaultDesign', 'No default design selected' ); }
				if ( description ) { description.textContent = response.data.description || ''; }
				if ( canvas ) { canvas.innerHTML = response.data.html || ''; }
				panel.setAttribute( 'aria-busy', 'false' );
				window.requestAnimationFrame( function () { fitWizardTemplatePreview( panel ); } );
			} ).catch( function ( error ) {
				if ( request !== sequence ) { return; }
				if ( name ) { name.textContent = certloomText( 'previewUnavailable', 'Preview unavailable' ); }
				if ( description ) { description.textContent = error && error.message ? error.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ); }
				panel.setAttribute( 'aria-busy', 'false' );
			} );
		} );
	}

	function initGoogleDrivePopupConnect() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-certloom-google-popup-form]' ), function ( form ) {
			form.addEventListener( 'submit', function () {
				var popup = null;
				try { popup = window.open( '', 'certloomGoogleDriveOAuth', 'width=620,height=720,resizable=yes,scrollbars=yes' ); } catch ( error ) { popup = null; }
				if ( ! popup ) { form.removeAttribute( 'target' ); var returnMode = form.querySelector( 'input[name="certloom_google_return_mode"]' ); if ( returnMode ) { returnMode.value = 'backup'; } }
				var button = document.querySelector( '[form="' + form.id + '"][data-certloom-google-connect]' ) || form.querySelector( '[data-certloom-google-connect]' );
				var feedback = document.querySelector( '[data-certloom-google-popup-feedback]' );
				if ( button ) { button.setAttribute( 'aria-busy', 'true' ); button.dataset.certloomOriginalLabel = button.textContent; button.textContent = popup ? certloomText( 'googleWaiting', 'Waiting for Google…' ) : certloomText( 'googleOpening', 'Opening Google…' ); }
				if ( feedback ) { feedback.textContent = popup ? certloomText( 'googleCompletePermission', 'Complete permission in the Google window.' ) : certloomText( 'googlePopupBlocked', 'Your browser blocked the popup, so Google will open in this tab.' ); }
			} );
		} );
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin || ! event.data || 'certloom-google-drive-oauth' !== event.data.type ) { return; }
			var success = 'connected' === event.data.status;
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-popup-feedback]' ), function ( feedback ) { feedback.textContent = event.data.message || ( success ? certloomText( 'googleConnected', 'Google Drive connected.' ) : certloomText( 'googleNotConnected', 'Google Drive was not connected.' ) ); } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-status]' ), function ( status ) { if ( success ) { status.textContent = certloomText( 'connected', 'Connected' ); status.classList.remove( 'is-disconnected' ); status.classList.add( 'is-connected' ); } } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-enable]' ), function ( input ) { if ( success ) { input.disabled = false; } } );
			Array.prototype.forEach.call( document.querySelectorAll( '.certloom-google-advanced' ), function ( details ) { if ( success ) { details.hidden = true; } } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-connect]' ), function ( button ) { button.removeAttribute( 'aria-busy' ); if ( success ) { button.textContent = certloomText( 'connected', 'Connected' ); button.disabled = true; } else if ( button.dataset.certloomOriginalLabel ) { button.textContent = button.dataset.certloomOriginalLabel; } } );
			var refresh = document.querySelector( 'form[data-certloom-google-popup-form][data-certloom-google-refresh-on-success="1"]' );
			if ( success && refresh ) { window.setTimeout( function () { window.location.reload(); }, 350 ); }
		} );
	}



	function initCertloomWordPressMenuState() {
		var params = new URLSearchParams( window.location.search || '' );
		var page = params.get( 'page' ) || '';
		if ( 0 !== page.indexOf( 'certloom' ) ) { return; }
		var top = document.getElementById( 'toplevel_page_certloom' );
		if ( ! top ) { return; }
		top.classList.add( 'wp-has-current-submenu', 'wp-menu-open' );
		top.classList.remove( 'wp-not-current-submenu' );
		var link = top.querySelector( ':scope > a.menu-top' ) || top.querySelector( 'a.menu-top' );
		if ( link ) { link.classList.add( 'wp-has-current-submenu', 'wp-menu-open' ); link.setAttribute( 'aria-current', 'page' ); }
		var submenu = top.querySelector( '.wp-submenu' );
		if ( submenu ) { submenu.removeAttribute( 'hidden' ); }
	}

	function initHumanFormValidation() {
		document.querySelectorAll( 'form[data-certloom-human-form]' ).forEach( function ( form ) {
			function fieldFor( control ) {
				return control && control.closest ? control.closest( 'label,.certloom-form-field,.certloom-domain-field' ) : null;
			}
			function clear( control ) {
				if ( ! control ) { return; }
				control.removeAttribute( 'aria-invalid' );
				var field = fieldFor( control );
				if ( field ) {
					field.classList.remove( 'is-invalid' );
					var error = field.querySelector( '.certloom-inline-field-error' );
					if ( error ) { error.remove(); }
				}
			}
			function show( control ) {
				if ( ! control ) { return; }
				control.setAttribute( 'aria-invalid', 'true' );
				var field = fieldFor( control );
				if ( ! field ) { return; }
				field.classList.add( 'is-invalid' );
				var error = field.querySelector( '.certloom-inline-field-error' );
				if ( ! error ) {
					error = document.createElement( 'small' );
					error.className = 'certloom-inline-field-error';
					error.setAttribute( 'role', 'alert' );
					field.appendChild( error );
				}
				error.textContent = control.getAttribute( 'data-certloom-required-example' ) || form.getAttribute( 'data-certloom-invalid-message' ) || certloomText( 'requiredField', 'Please fill in this required field.' );
			}
			form.addEventListener( 'invalid', function ( event ) {
				show( event.target );
				var field = fieldFor( event.target );
				if ( field && field.scrollIntoView ) { window.setTimeout( function () { field.scrollIntoView( { block:'center', behavior:'smooth' } ); }, 0 ); }
			}, true );
			form.addEventListener( 'input', function ( event ) { if ( event.target && event.target.checkValidity && event.target.checkValidity() ) { clear( event.target ); } } );
			form.addEventListener( 'change', function ( event ) { if ( event.target && event.target.checkValidity && event.target.checkValidity() ) { clear( event.target ); } } );
		} );
	}

	function initRecipientCompose() {
		var compose = document.querySelector( '[data-certloom-recipient-compose]' );
		if ( ! compose ) { return; }
		var radios = Array.prototype.slice.call( compose.querySelectorAll( 'input[name="recipient_mode"]' ) );
		var existingSelect = compose.querySelector( '[data-certloom-recipient-existing-select]' );
		var newPanel = compose.querySelector( '[data-certloom-recipient-new-panel]' );
		var dialog = compose.querySelector( '[data-certloom-recipient-dialog]' );
		var newName = dialog ? dialog.querySelector( 'input[name="new_recipient[display_name]"]' ) : null;
		var summaryName = compose.querySelector( '[data-certloom-recipient-new-summary-name]' );
		var saveProfile = dialog ? dialog.querySelector( '[data-certloom-recipient-profile-save]' ) : null;
		function mode() {
			var active = radios.find( function ( radio ) { return radio.checked; } );
			return active ? active.value : 'existing';
		}
		function openProfile() {
			if ( ! dialog ) { return; }
			if ( 'function' === typeof dialog.showModal ) { if ( ! dialog.open ) { dialog.showModal(); } }
			else { dialog.setAttribute( 'open', 'open' ); }
			if ( newName ) { window.setTimeout( function () { newName.focus(); }, 0 ); }
		}
		function syncSummary() {
			if ( summaryName ) { summaryName.textContent = newName && newName.value.trim() ? newName.value.trim() : certloomText( 'newRecipientProfile', 'New Recipient profile' ); }
		}
		function sync( openWhenNew ) {
			var current = mode();
			document.querySelectorAll( '[data-certloom-recipient-existing-panel]' ).forEach( function ( panel ) { panel.hidden = 'existing' !== current; } );
			if ( newPanel ) { newPanel.hidden = 'new' !== current; }
			if ( existingSelect ) {
				existingSelect.required = 'existing' === current;
				existingSelect.disabled = 'existing' !== current;
			}
			if ( dialog ) {
				dialog.querySelectorAll( 'input,select,textarea' ).forEach( function ( control ) { control.disabled = 'new' !== current; } );
			}
			if ( newName ) { newName.required = 'new' === current; }
			if ( 'new' === current && openWhenNew && ( ! newName || ! newName.value.trim() ) ) { openProfile(); }
		}
		radios.forEach( function ( radio ) { radio.addEventListener( 'change', function () { sync( true ); } ); } );
		if ( saveProfile ) { saveProfile.addEventListener( 'click', function () {
			if ( newName && ! newName.checkValidity() ) { newName.reportValidity(); return; }
			syncSummary();
			if ( dialog && dialog.open && 'function' === typeof dialog.close ) { dialog.close(); }
		} ); }
		if ( newName ) { newName.addEventListener( 'input', syncSummary ); }
		syncSummary();
		sync( false );
	}




	function initBulkDesignSource() {
		document.querySelectorAll( '[data-certloom-bulk-design-source]' ).forEach( function ( root ) {
			var form = root.closest( 'form' );
			if ( ! form ) { return; }
			var radios = Array.prototype.slice.call( root.querySelectorAll( 'input[name="bulk_design_source"]' ) );
			var assigned = root.querySelector( '[data-certloom-bulk-assigned-design]' );
			var template = root.querySelector( 'select[name="template_id"]' );
			var status = root.querySelector( '[data-certloom-bulk-design-source-status]' );
			var program = form.querySelector( 'select[name="program_id"]' );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			var sequence = 0;
			var controller = null;
			if ( ! radios.length || ! template ) { return; }
			function mode() { var checked = radios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : 'inherited'; }
			function setStatus( text ) { if ( status ) { status.textContent = text || ''; } }
			function sync() {
				var useAssigned = 'assigned' === mode();
				if ( assigned ) { assigned.hidden = ! useAssigned; }
				template.disabled = ! useAssigned;
				template.required = useAssigned;
				if ( useAssigned ) { setStatus( certloomText( 'bulkAssignedDesignHint', 'The selected published design overrides inheritance only for this bulk job.' ) ); return; }
				if ( ! certloomL10n.restTemplatePreviewUrl || ! program || ! program.value ) { setStatus( certloomText( 'bulkInheritedDesignHint', 'Inherited design follows Global → Issuer → Program → Batch. Choose a Program to see the effective source.' ) ); return; }
				sequence++; var requestId = sequence;
				if ( controller && controller.abort ) { controller.abort(); }
				controller = 'undefined' !== typeof AbortController ? new AbortController() : null;
				setStatus( certloomText( 'resolvingInheritedDesign', 'Resolving inherited design…' ) );
				var url = new URL( certloomL10n.restTemplatePreviewUrl );
				url.searchParams.set( 'mode', 'inherited' );
				url.searchParams.set( 'program_id', program.value || '0' );
				url.searchParams.set( 'batch_id', batch ? ( batch.value || '0' ) : '0' );
				fetch( url.toString(), { method: 'GET', credentials: 'same-origin', headers: certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {}, signal: controller ? controller.signal : undefined } )
					.then( function ( response ) { return response.json().then( function ( payload ) { if ( ! response.ok ) { throw new Error( payload && payload.message ? payload.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ) ); } return payload; } ); } )
					.then( function ( payload ) { if ( requestId !== sequence ) { return; } setStatus( payload && payload.source_label ? payload.source_label : certloomText( 'bulkInheritedDesignHint', 'Inherited design follows Global → Issuer → Program → Batch.' ) ); } )
					.catch( function ( error ) { if ( error && 'AbortError' === error.name ) { return; } if ( requestId !== sequence ) { return; } setStatus( error && error.message ? error.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ) ); } );
			}
			radios.forEach( function ( radio ) { radio.addEventListener( 'change', sync ); } );
			if ( program ) { program.addEventListener( 'change', sync ); }
			if ( batch ) { batch.addEventListener( 'change', sync ); }
			sync();
		} );
	}

	function initBulkLifecycle() {
		document.querySelectorAll( 'form[data-certloom-bulk-lifecycle]' ).forEach( function ( form ) {
			var program = form.querySelector( 'select[name="program_id"]' );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			var batchPolicy = form.querySelector( '[data-certloom-bulk-batch-policy]' );
			var radios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="expiry_mode"]' ) );
			var programRadio = form.querySelector( 'input[name="expiry_mode"][value="program"]' );
			var permanentRadio = form.querySelector( 'input[name="expiry_mode"][value="permanent"]' );
			var dateRadio = form.querySelector( 'input[name="expiry_mode"][value="date"]' );
			var dateField = form.querySelector( 'input[name="expires_at"]' );
			var summary = form.querySelector( '[data-certloom-bulk-validity-summary]' );
			if ( ! program || ! radios.length ) { return; }

			function selectedProgram() { return program.options[ program.selectedIndex ] || null; }
			function text( key, fallback ) { return certloomText( key, fallback ); }
			function formatOne( template, value ) { return String( template || '%s' ).replace( '%s', String( value || '' ) ); }
			function syncDate() {
				var specific = !! ( dateRadio && dateRadio.checked );
				if ( dateField ) { dateField.required = specific; dateField.disabled = ! specific; }
			}
			function sync() {
				var option = selectedProgram();
				var hasProgram = !! ( option && program.value && '0' !== program.value );
				var validityAvailable = ! hasProgram || '0' !== option.getAttribute( 'data-program-validity-available' );
				var renewal = hasProgram ? ( option.getAttribute( 'data-renewal-policy' ) || 'not_applicable' ) : 'not_applicable';
				var repeat = hasProgram ? ( option.getAttribute( 'data-repeat-policy' ) || 'lifecycle' ) : 'lifecycle';
				var validitySummary = hasProgram ? ( option.getAttribute( 'data-validity-summary' ) || '' ) : '';

				if ( programRadio ) { programRadio.disabled = hasProgram && ! validityAvailable; }
				if ( permanentRadio ) { permanentRadio.disabled = hasProgram && 'required' === renewal; }
				if ( programRadio && programRadio.disabled && programRadio.checked ) { programRadio.checked = false; }
				if ( permanentRadio && permanentRadio.disabled && permanentRadio.checked ) { permanentRadio.checked = false; }

				if ( hasProgram && validityAvailable && ! radios.some( function ( radio ) { return radio.checked && ! radio.disabled; } ) && programRadio ) { programRadio.checked = true; }
				radios.forEach( function ( radio ) { radio.required = ! radio.disabled; } );

				if ( batch ) { batch.required = hasProgram && 'batch' === repeat; }
				if ( batchPolicy ) {
					batchPolicy.textContent = hasProgram && 'batch' === repeat
						? text( 'bulkBatchRequired', 'Required: this Program issues one credential per Batch or session.' )
						: text( 'bulkBatchOptional', 'Optional. Search is limited to the selected Program; Batch field and design overrides are revalidated for every row.' );
				}

				if ( summary ) {
					if ( ! hasProgram ) { summary.textContent = text( 'bulkChooseProgramLifecycle', 'Choose a Program to confirm its default validity and repeat-issuance policy.' ); }
					else if ( ! validityAvailable ) { summary.textContent = text( 'bulkDecideValidity', 'This Program decides validity at issuance. Choose Permanent or a specific expiry date for this bulk job.' ); }
					else {
						summary.textContent = formatOne( text( 'bulkProgramValidity', 'Program default: %s.' ), validitySummary || text( 'defaultCredentialTerm', 'Credential' ) );
						if ( 'required' === renewal ) { summary.textContent += text( 'bulkRenewalRequired', ' Renewal is required after expiry.' ); }
					}
				}
				syncDate();
			}

			program.addEventListener( 'change', sync );
			radios.forEach( function ( radio ) { radio.addEventListener( 'change', syncDate ); } );
			sync();
		} );
	}

	function initIssueDesignPreview() {
		var panel = document.querySelector( '[data-certloom-issue-design-preview]' );
		var form = panel ? panel.closest( 'form[data-certloom-phase2-issuance]' ) : null;
		if ( ! panel || ! form ) { return; }
		var select = form.querySelector( '[data-certloom-issue-template-select]' );
		var assignedField = form.querySelector( '[data-certloom-assigned-design-field]' );
		var sourceRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
		var presentationRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="presentation_mode"]' ) );
		if ( ! select || ! sourceRadios.length ) { return; }
		observeWizardTemplatePreview( panel );
		var name = panel.querySelector( '[data-certloom-template-preview-name]' );
		var description = panel.querySelector( '[data-certloom-template-preview-description]' );
		var source = panel.querySelector( '[data-certloom-template-preview-source]' );
		var canvas = panel.querySelector( '[data-certloom-template-preview-canvas]' );
		var readiness = panel.querySelector( '[data-certloom-design-data-check]' );
		var readinessSummary = panel.querySelector( '[data-certloom-design-data-summary]' );
		var readinessFields = panel.querySelector( '[data-certloom-design-data-fields]' );
		var additional = form.querySelector( '[data-certloom-additional-details]' );
		var sequence = 0;
		var controller = null;
		var timer = null;

		function presentationMode() { var checked = presentationRadios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : 'details_only'; }
		function sourceMode() { var checked = sourceRadios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : 'inherited'; }
		function recipientReady() {
			var mode = form.querySelector( 'input[name="recipient_mode"]:checked' );
			if ( ! mode || 'existing' === mode.value ) { var recipient = form.querySelector( '[data-certloom-recipient-existing-select]' ); return !! ( recipient && recipient.value ); }
			var newName = form.querySelector( 'input[name="new_recipient[display_name]"]' ); return !! ( newName && newName.value.trim() );
		}
		function basicsReady() {
			if ( ! recipientReady() ) { return false; }
			var basis = form.querySelector( 'input[name="credential_basis"]:checked' ); basis = basis ? basis.value : 'program';
			var program = form.querySelector( 'select[name="program_id"]' );
			if ( 'program' === basis && ( ! program || ! program.value || '0' === program.value ) ) { return false; }
			var titleSource = form.querySelector( 'input[name="title_source"]:checked' ); titleSource = titleSource ? titleSource.value : ( 'program' === basis ? 'program' : 'custom' );
			var title = form.querySelector( 'input[name="credential_title"]' );
			if ( ( 'independent' === basis || 'custom' === titleSource ) && ( ! title || ! title.value.trim() ) ) { return false; }
			var validFrom = form.querySelector( 'input[name="valid_from"]' ); if ( ! validFrom || ! validFrom.value ) { return false; }
			var expiry = form.querySelector( 'input[name="expiry_mode"]:checked' ); if ( ! expiry || expiry.disabled ) { return false; }
			if ( 'date' === expiry.value ) { var expiryDate = form.querySelector( 'input[name="expires_at"]' ); if ( ! expiryDate || ! expiryDate.value ) { return false; } }
			return true;
		}
		function emptyPreview( title, copy, provenance ) {
			if ( name ) { name.textContent = title; }
			if ( description ) { description.textContent = copy; }
			if ( source ) { source.textContent = provenance || ''; source.hidden = ! provenance; }
			if ( canvas ) { canvas.innerHTML = '<span class="certloom-issue-design-preview-empty">' + escapeHtml( certloomText( 'previewFitHint', 'Preview fits automatically in this window.' ) ) + '</span>'; }
			panel.removeAttribute( 'aria-busy' );
		}
		function setReadinessState( state, message ) {
			form.setAttribute( 'data-certloom-design-data-ready', state );
			form.setAttribute( 'data-certloom-design-data-message', message || '' );
			form.dispatchEvent( new CustomEvent( 'certloom:design-readiness', { bubbles: true, detail: { state: state, message: message || '' } } ) );
		}
		function syncSourceControls() {
			var assigned = 'assigned' === sourceMode();
			if ( assignedField ) { assignedField.hidden = ! assigned; }
			select.disabled = ! assigned || 'design' !== presentationMode();
			select.required = assigned && 'design' === presentationMode();
			select.setAttribute( 'aria-required', select.required ? 'true' : 'false' );
		}
		function openAdditional( selector ) {
			if ( additional ) { additional.open = true; additional.scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
			if ( selector ) { var target = form.querySelector( selector ); if ( target ) { window.setTimeout( function () { target.focus(); }, 250 ); } }
		}
		function readinessAction( field ) {
			var action = String( field.action || '' );
			var button = document.createElement( 'button' ); button.type = 'button'; button.className = 'button-link certloom-design-data-action';
			if ( 'recipient' === action ) {
				button.textContent = certloomText( 'designDataEditRecipient', 'Edit recipient' );
				button.addEventListener( 'click', function () {
					var mode = form.querySelector( 'input[name="recipient_mode"]:checked' );
					if ( mode && 'new' === mode.value ) { var dialogButton = form.querySelector( '[data-certloom-dialog-open="certloom-new-recipient-dialog"]' ); if ( dialogButton ) { dialogButton.click(); } return; }
					var recipient = form.querySelector( '[data-certloom-recipient-existing-select]' );
					if ( recipient && recipient.value && certloomL10n.recipientEditBase ) { window.open( certloomL10n.recipientEditBase + encodeURIComponent( recipient.value ), '_blank', 'noopener' ); }
				} ); return button;
			}
			if ( 'credential_detail' === action ) {
				button.textContent = certloomText( 'designDataAddValue', 'Add value' );
				button.addEventListener( 'click', function () { form.dispatchEvent( new CustomEvent( 'certloom:add-credential-detail', { bubbles: true, detail: { fieldKey: field.field_key || '', label: field.label || '' } } ) ); var card = form.querySelector( '[data-certloom-credential-data]' ); if ( card ) { card.scrollIntoView( { behavior: 'smooth', block: 'center' } ); } } ); return button;
			}
			if ( 'grade' === action ) { button.textContent = certloomText( 'designDataAddValue', 'Add value' ); button.addEventListener( 'click', function () { var grade = form.querySelector( 'input[name="grade"]' ); if ( grade ) { grade.scrollIntoView( { behavior: 'smooth', block: 'center' } ); grade.focus(); } } ); return button; }
			if ( 'program' === action ) { button.textContent = certloomText( 'designDataChooseProgram', 'Choose Program' ); button.addEventListener( 'click', function () { var programBasis = form.querySelector( 'input[name="credential_basis"][value="program"]' ); if ( programBasis ) { programBasis.focus(); } var basis = form.querySelector( '[data-certloom-credential-basis]' ); if ( basis ) { basis.scrollIntoView( { behavior: 'smooth', block: 'center' } ); } } ); return button; }
			if ( 'repeating' === action ) { button.textContent = certloomText( 'designDataOpenDetails', 'Open details' ); button.addEventListener( 'click', function () { if ( additional ) { additional.open = true; } var repeating = form.querySelector( '[data-certloom-repeating-data]' ); if ( repeating ) { repeating.open = true; repeating.scrollIntoView( { behavior: 'smooth', block: 'center' } ); } } ); return button; }
			if ( 'context' === action ) { button.textContent = certloomText( 'designDataOpenDetails', 'Open details' ); button.addEventListener( 'click', function () { openAdditional( '' ); } ); return button; }
			return null;
		}
		function renderReadiness( payload ) {
			payload = payload && payload.data_readiness ? payload.data_readiness : { ready: true, fields: [], missing_count: 0 };
			var fields = Array.isArray( payload.fields ) ? payload.fields.map( certloomNormalizeDesignReadinessField ) : [];
			var missingCount = parseInt( payload.missing_count || '0', 10 ) || 0;
			var summaryText = ! missingCount ? certloomText( 'designDataReady', 'All design fields are ready.' ) : ( 1 === missingCount ? certloomText( 'designDataMissingOne', '1 design field still needs a value.' ) : String( certloomText( 'designDataMissingMany', '%d design fields still need values.' ) ).replace( '%d', String( missingCount ) ) );
			if ( readiness ) { readiness.classList.toggle( 'has-missing', missingCount > 0 ); readiness.classList.toggle( 'is-ready', 0 === missingCount ); }
			if ( readinessSummary ) { readinessSummary.textContent = summaryText; }
			if ( readinessFields ) {
				readinessFields.innerHTML = '';
				fields.forEach( function ( field ) {
					var row = document.createElement( 'div' ); row.className = 'certloom-design-data-row is-' + ( field.status || 'missing' );
					var text = document.createElement( 'div' ); text.className = 'certloom-design-data-row__text';
					var strong = document.createElement( 'strong' ); strong.textContent = field.label || field.token || ''; text.appendChild( strong );
					var meta = document.createElement( 'small' ); meta.textContent = ( field.source || '' ) + ( field.value_hint ? ' · ' + field.value_hint : '' ); text.appendChild( meta ); row.appendChild( text );
					var badge = document.createElement( 'span' ); badge.className = 'certloom-design-data-badge is-' + ( field.status || 'missing' ); badge.textContent = 'generated' === field.status ? certloomText( 'designDataGenerated', 'Created when issued' ) : ( 'ready' === field.status ? certloomText( 'designDataReadyBadge', 'Ready' ) : certloomText( 'designDataMissingBadge', 'Needs value' ) ); row.appendChild( badge );
					if ( 'missing' === field.status ) { var action = readinessAction( field ); if ( action ) { row.appendChild( action ); } }
					readinessFields.appendChild( row );
				} );
			}
			form.dispatchEvent( new CustomEvent( 'certloom:design-missing-fields', { bubbles: true, detail: { fields: fields } } ) );
			setReadinessState( missingCount ? '0' : '1', summaryText );
		}
		function bodyFromForm() {
			var body = new URLSearchParams();
			var data = new FormData( form );
			data.forEach( function ( value, key ) {
				// The Manual Issuance form has its own action nonce. Never send that nonce
				// to the REST endpoint: WordPress REST cookie authentication prefers a
				// request _wpnonce over X-WP-Nonce and would reject the valid REST nonce.
				if ( '_wpnonce' === key || '_wp_http_referer' === key ) { return; }
				if ( 'string' === typeof value ) { body.append( key, value ); }
			} );
			return body;
		}
		function loadPreview() {
			syncSourceControls();
			if ( 'design' !== presentationMode() ) { setReadinessState( '1', '' ); return; }
			var mode = sourceMode(); var templateId = parseInt( select.value || '0', 10 ) || 0;
			if ( 'assigned' === mode && ! templateId ) { setReadinessState( '0', certloomText( 'phase2ChooseDesign', 'Choose a published assigned design, or use the inherited design.' ) ); emptyPreview( certloomText( 'assignedDesign', 'Assigned design' ), certloomText( 'chooseAssignedDesignPreview', 'Choose a published design to preview it here.' ), '' ); return; }
			if ( ! basicsReady() ) { setReadinessState( '0', certloomText( 'issuancePreviewCompleteCore', 'Choose a recipient and complete the credential basics to preview real issuance data.' ) ); emptyPreview( certloomText( 'inheritedDesign', 'Design preview' ), certloomText( 'issuancePreviewCompleteCore', 'Choose a recipient and complete the credential basics to preview real issuance data.' ), '' ); return; }
			if ( ! certloomL10n.restIssuancePreviewUrl ) { setReadinessState( '0', certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ) ); emptyPreview( certloomText( 'previewUnavailable', 'Preview unavailable' ), certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ), '' ); return; }
			sequence++; var requestId = sequence;
			if ( controller && controller.abort ) { controller.abort(); } controller = 'undefined' !== typeof AbortController ? new AbortController() : null;
			setReadinessState( 'pending', certloomText( 'designDataChecking', 'Checking design data…' ) );
			panel.setAttribute( 'aria-busy', 'true' ); if ( name ) { name.textContent = certloomText( 'loadingPreview', 'Loading preview…' ); } if ( description ) { description.textContent = ''; } if ( source ) { source.textContent = ''; source.hidden = true; } if ( canvas ) { canvas.innerHTML = ''; }
			fetch( certloomL10n.restIssuancePreviewUrl, { method: 'POST', credentials: 'same-origin', headers: Object.assign( { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {} ), body: bodyFromForm().toString(), signal: controller ? controller.signal : undefined } )
				.then( function ( response ) { return response.json().then( function ( payload ) {
					if ( ! response.ok ) {
						var previewError = payload && payload.message ? payload.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' );
						if ( payload && 'rest_cookie_invalid_nonce' === payload.code ) { previewError = certloomText( 'previewSessionExpired', 'The preview session expired. Refresh this page and try again.' ); }
						throw new Error( previewError );
					}
					return payload;
				} ); } )
				.then( function ( payload ) { if ( requestId !== sequence ) { return; } if ( name ) { name.textContent = payload && payload.name ? payload.name : certloomText( 'previewUnavailable', 'Preview unavailable' ); } if ( description ) { description.textContent = payload && payload.description ? payload.description : ''; } if ( source ) { source.textContent = payload && payload.source_label ? payload.source_label : ''; source.hidden = ! source.textContent; } if ( canvas ) { canvas.innerHTML = payload && payload.html ? payload.html : ''; } renderReadiness( payload ); panel.removeAttribute( 'aria-busy' ); window.requestAnimationFrame( function () { fitWizardTemplatePreview( panel ); } ); } )
				.catch( function ( error ) { if ( error && 'AbortError' === error.name ) { return; } if ( requestId !== sequence ) { return; } var message = error && error.message ? error.message : certloomText( 'previewLoadFailed', 'Template preview could not be loaded.' ); setReadinessState( '0', message ); emptyPreview( 'inherited' === mode ? certloomText( 'inheritedDesign', 'Inherited design' ) : certloomText( 'previewUnavailable', 'Preview unavailable' ), message, '' ); if ( readinessSummary ) { readinessSummary.textContent = message; } } );
		}
		function schedule() { if ( timer ) { window.clearTimeout( timer ); } timer = window.setTimeout( loadPreview, 260 ); }
		sourceRadios.forEach( function ( radio ) { radio.addEventListener( 'change', schedule ); } ); select.addEventListener( 'change', schedule ); presentationRadios.forEach( function ( radio ) { radio.addEventListener( 'change', schedule ); } );
		form.addEventListener( 'change', function ( event ) { if ( event.target && event.target.name && 'certloom_manual_issuance_step' !== event.target.name ) { schedule(); } } );
		form.addEventListener( 'input', function ( event ) { if ( event.target && event.target.name ) { schedule(); } } );
		form.addEventListener( 'certloom:issuance-data-changed', schedule );
		loadPreview();
	}

	function initPhase2Issuance() {
		document.querySelectorAll( 'form[data-certloom-phase2-issuance]' ).forEach( function ( form ) {
			var presentationRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="presentation_mode"]' ) );
			var panels = Array.prototype.slice.call( form.querySelectorAll( '[data-certloom-presentation-panel]' ) );
			var template = form.querySelector( 'select[name="template_id"]' );
			var imageId = form.querySelector( 'input[name="certificate_image_id"]' );
			var imagePreview = form.querySelector( '.certloom-certificate-image-control [data-certloom-media-preview]' );
			var imageClear = form.querySelector( '.certloom-certificate-image-control [data-certloom-media-clear]' );
			var program = form.querySelector( 'select[name="program_id"]' );
			var basisRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="credential_basis"]' ) );
			var programPanel = form.querySelector( '[data-certloom-program-basis-panel]' );
			var titleSourceRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="title_source"]' ) );
			var titlePolicy = form.querySelector( '[data-certloom-title-policy]' );
			var inheritedTitle = form.querySelector( '[data-certloom-inherited-title]' );
			var customTitlePanel = form.querySelector( '[data-certloom-custom-title-panel]' );
			var designSources = Array.prototype.slice.call( form.querySelectorAll( 'input[name="design_source"]' ) );
			var title = form.querySelector( 'input[name="credential_title"]' );
			var expiryRadios = Array.prototype.slice.call( form.querySelectorAll( 'input[name="expiry_mode"]' ) );
			var expiryPanel = form.querySelector( '[data-certloom-expiry-date-panel]' );
			var expiryDate = form.querySelector( 'input[name="expires_at"]' );
			var expirySummary = form.querySelector( '[data-certloom-program-validity-summary]' );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			var batchPolicy = form.querySelector( '[data-certloom-manual-batch-policy]' );
			var issuer = form.querySelector( 'select[name="issuer_id"]' );
			var issuerPolicy = form.querySelector( '[data-certloom-manual-issuer-policy]' );
			var review = form.querySelector( '[data-certloom-manual-review]' );
			var message = form.querySelector( '[data-certloom-phase2-compatibility-message]' );
			var appearanceTouched = '1' === form.getAttribute( 'data-certloom-presentation-explicit' );
			var autoDesignSequence = 0;
			var autoDesignController = null;
			var autoApplyingAppearance = false;
			function presentationMode() { var checked = presentationRadios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : 'details_only'; }
			function credentialBasis() { var checked = basisRadios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : ( program && program.value && '0' !== program.value ? 'program' : 'independent' ); }
			function titleSource() { var checked = titleSourceRadios.find( function ( radio ) { return radio.checked; } ); return checked ? checked.value : ( 'program' === credentialBasis() ? 'program' : 'custom' ); }
			function programTitle() {
				if ( ! program || ! program.value || '0' === program.value ) { return ''; }
				var option = program.options[ program.selectedIndex ];
				if ( ! option ) { return ''; }
				return ( option.getAttribute( 'data-program-title' ) || option.textContent || '' ).trim();
			}
			function selectedProgramOption() { return program && program.value && '0' !== program.value ? program.options[ program.selectedIndex ] : null; }
			function programValidityAvailable() {
				var option = selectedProgramOption(); if ( ! option ) { return false; }
				var value = option.getAttribute( 'data-program-validity-available' );
				return null === value ? true : '1' === value;
			}
			function programRenewalPolicy() { var option = selectedProgramOption(); return option ? ( option.getAttribute( 'data-renewal-policy' ) || 'not_applicable' ) : 'not_applicable'; }
			function programRepeatPolicy() { var option = selectedProgramOption(); return option ? ( option.getAttribute( 'data-repeat-policy' ) || 'lifecycle' ) : 'lifecycle'; }
			function programValiditySummary() { var option = selectedProgramOption(); return option ? ( option.getAttribute( 'data-validity-summary' ) || '' ).trim() : ''; }
			function programIssuerId() { var option = selectedProgramOption(); return option ? ( parseInt( option.getAttribute( 'data-program-issuer-id' ) || '0', 10 ) || 0 ) : 0; }
			function programIssuerName() { var option = selectedProgramOption(); return option ? ( option.getAttribute( 'data-program-issuer-name' ) || '' ).trim() : ''; }
			function ensureIssuerOption( id, label ) {
				if ( ! issuer || ! id ) { return; }
				var option = issuer.querySelector( 'option[value="' + escapeSelector( String( id ) ) + '"]' );
				if ( ! option ) { option = document.createElement( 'option' ); option.value = String( id ); option.textContent = label || certloomText( 'issuerInheritedFallback', 'Program Issuer' ); issuer.appendChild( option ); }
			}
			function syncIssuerPolicy() {
				if ( ! issuer ) { return; }
				var inheritedId = 'program' === credentialBasis() ? programIssuerId() : 0;
				if ( inheritedId ) {
					var inheritedName = programIssuerName() || certloomText( 'issuerInheritedFallback', 'Program Issuer' );
					ensureIssuerOption( inheritedId, inheritedName );
					issuer.value = String( inheritedId );
					issuer.disabled = true;
					if ( issuerPolicy ) { issuerPolicy.textContent = certloomText( 'issuerInheritedPrefix', 'Inherited from the selected Program:' ) + ' ' + inheritedName + '. ' + certloomText( 'issuerInheritedChangeProgram', 'Change the Program to change this Issuer.' ); }
				} else {
					var wasDisabled = issuer.disabled;
					issuer.disabled = false;
					if ( wasDisabled ) { issuer.value = '0'; }
					if ( issuerPolicy ) { issuerPolicy.textContent = certloomText( 'issuerOptionalHelp', 'Optional for an Independent credential or a Program without an assigned Issuer.' ); }
				}
			}
			function recipientReady() {
				var mode = form.querySelector( 'input[name="recipient_mode"]:checked' );
				if ( ! mode || 'existing' === mode.value ) { var select = form.querySelector( '[data-certloom-recipient-existing-select]' ); return !! ( select && select.value ); }
				var name = form.querySelector( 'input[name="new_recipient[display_name]"]' ); return !! ( name && name.value.trim() );
			}
			function clearImage() { if ( imageId ) { imageId.value = ''; } if ( imagePreview ) { imagePreview.innerHTML = '<span>' + escapeHtml( certloomText( 'noCertificateImage', 'No certificate image selected' ) ) + '</span>'; } if ( imageClear ) { imageClear.hidden = true; } }
			function syncBatchPolicy() {
				if ( ! batch ) { return; }
				var required = 'program' === credentialBasis() && !! selectedProgramOption() && 'batch' === programRepeatPolicy();
				batch.required = required;
				batch.setAttribute( 'aria-required', required ? 'true' : 'false' );
				if ( batchPolicy ) {
					batchPolicy.textContent = required
						? certloomText( 'manualBatchRequired', 'Required: this Program issues one credential per Batch or session.' )
						: certloomText( 'manualBatchOptional', 'Optional unless the selected Program issues one credential per Batch or session. When selected, Batch must belong to the Program.' );
				}
			}
			function syncCompatibility() {
				if ( ! review ) { return; }
				syncBatchPolicy();
				var mode = presentationMode(); var basis = credentialBasis(); var selectedProgram = !! ( program && program.value && '0' !== program.value ); var source = titleSource();
				var hasTitle = 'program' === basis && 'program' === source ? selectedProgram : !! ( title && title.value.trim() ); var hasRecipient = recipientReady();
				var designSource = designSources.find( function ( radio ) { return radio.checked; } ); designSource = designSource ? designSource.value : 'inherited';
				var hasDesign = 'inherited' === designSource || !! ( template && template.value && '0' !== template.value );
				var hasImage = !! ( imageId && imageId.value && '0' !== imageId.value );
				var basisReady = 'independent' === basis || selectedProgram;
				var selectedExpiry = expiryRadios.find( function ( radio ) { return radio.checked && ! radio.disabled; } );
				var expiryReady = !! selectedExpiry && ( 'date' !== selectedExpiry.value || !! ( expiryDate && expiryDate.value ) );
				var batchReady = ! ( 'program' === basis && selectedProgram && 'batch' === programRepeatPolicy() ) || !! ( batch && batch.value && '0' !== batch.value );
				var designDataReady = 'design' !== mode || '1' === form.getAttribute( 'data-certloom-design-data-ready' );
				var compatible = basisReady && hasTitle && hasRecipient && expiryReady && batchReady && designDataReady && ( 'details_only' === mode || ( 'image' === mode && hasImage ) || ( 'design' === mode && hasDesign ) );
				review.disabled = ! compatible;
				if ( ! message ) { return; }
				if ( ! hasRecipient ) { message.textContent = certloomText( 'phase2ChooseRecipient', 'Choose or create a recipient to continue.' ); }
				else if ( 'program' === basis && ! selectedProgram ) { message.textContent = certloomText( 'phase2ChooseProgram', 'Choose a Program, or switch to Independent credential.' ); }
				else if ( ! hasTitle ) { message.textContent = certloomText( 'phase2EnterTitle', 'Enter the custom credential title to continue.' ); }
				else if ( ! expiryReady ) { message.textContent = certloomText( 'phase2ChooseValidity', 'Choose how long this credential should remain valid.' ); }
				else if ( ! batchReady ) { message.textContent = certloomText( 'phase2ChooseBatchForRepeat', 'This Program issues one credential per Batch or session. Choose a Batch in Additional details.' ); }
				else if ( 'image' === mode && ! hasImage ) { message.textContent = certloomText( 'phase2ChooseImage', 'Choose a PNG or JPEG certificate image to continue.' ); }
				else if ( 'design' === mode && ! hasDesign ) { message.textContent = certloomText( 'phase2ChooseDesign', 'Choose a published assigned design, or use the inherited design.' ); }
				else if ( 'design' === mode && ! designDataReady ) { message.textContent = form.getAttribute( 'data-certloom-design-data-message' ) || certloomText( 'designDataChecking', 'Checking design data…' ); }
				else if ( 'details_only' === mode ) { message.textContent = certloomText( 'phase2DetailsReady', 'Ready to review without certificate artwork.' ); }
				else if ( 'image' === mode ) { message.textContent = certloomText( 'phase2ImageReady', 'Ready to review with the selected certificate image.' ); }
				else { message.textContent = certloomText( 'phase2Compatible', 'Ready to review with the selected Certloom design.' ); }
			}
			function setCheckedValue( radios, value ) { radios.forEach( function ( radio ) { radio.checked = radio.value === value; } ); }
			function autoApplyInheritedDesign( programId ) {
				programId = String( programId || '' );
				if ( appearanceTouched || ! programId || '0' === programId || ! certloomL10n.restTemplatePreviewUrl ) { return; }
				autoDesignSequence++; var requestId = autoDesignSequence;
				if ( autoDesignController && autoDesignController.abort ) { autoDesignController.abort(); }
				autoDesignController = 'undefined' !== typeof AbortController ? new AbortController() : null;
				var url = new URL( certloomL10n.restTemplatePreviewUrl );
				url.searchParams.set( 'mode', 'inherited' );
				url.searchParams.set( 'program_id', programId );
				url.searchParams.set( 'batch_id', batch ? ( batch.value || '0' ) : '0' );
				fetch( url.toString(), { method: 'GET', credentials: 'same-origin', headers: certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {}, signal: autoDesignController ? autoDesignController.signal : undefined } )
					.then( function ( response ) { return response.json().then( function ( payload ) { if ( ! response.ok ) { throw new Error( payload && payload.message ? payload.message : '' ); } return payload; } ); } )
					.then( function ( payload ) {
						if ( requestId !== autoDesignSequence || appearanceTouched || ! program || String( program.value || '' ) !== programId || ! payload || ! payload.template_id ) { return; }
						autoApplyingAppearance = true;
						setCheckedValue( presentationRadios, 'design' );
						setCheckedValue( designSources, 'inherited' );
						syncPresentation( false );
						autoApplyingAppearance = false;
						form.dispatchEvent( new CustomEvent( 'certloom:issuance-data-changed', { bubbles: true, detail: { reason: 'program-design-auto' } } ) );
					} )
					.catch( function ( error ) { if ( error && 'AbortError' === error.name ) { return; } } );
			}
			function syncCredentialBasis( programJustChosen ) {
				var basis = credentialBasis();
				var usesProgram = 'program' === basis;
				if ( programPanel ) { programPanel.hidden = ! usesProgram; }
				if ( program ) { program.disabled = ! usesProgram; program.required = usesProgram; program.setAttribute( 'aria-required', usesProgram ? 'true' : 'false' ); }
				if ( titlePolicy ) { titlePolicy.hidden = ! usesProgram; }
				if ( ! usesProgram ) {
					if ( program ) { program.value = '0'; previousProgramValue = '0'; }
					setCheckedValue( titleSourceRadios, 'custom' );
					var programValidityOff = expiryRadios.find( function ( radio ) { return 'program' === radio.value && radio.checked; } );
					if ( programValidityOff ) { var permanent = expiryRadios.find( function ( radio ) { return 'permanent' === radio.value; } ); if ( permanent ) { permanent.checked = true; } }
				} else if ( programJustChosen ) {
					setCheckedValue( titleSourceRadios, 'program' );
					var programValidity = expiryRadios.find( function ( radio ) { return 'program' === radio.value; } );
					if ( programValidityAvailable() ) { if ( programValidity ) { programValidity.checked = true; } }
					else { expiryRadios.forEach( function ( radio ) { radio.checked = false; } ); }
				}
				syncIssuerPolicy();
				var source = titleSource();
				if ( inheritedTitle ) { inheritedTitle.textContent = programTitle() || certloomText( 'chooseProgramForTitle', 'Choose a Program to inherit its title.' ); }
				var customVisible = ! usesProgram || 'custom' === source;
				if ( customTitlePanel ) { customTitlePanel.hidden = ! customVisible; }
				if ( title ) { title.required = customVisible; title.disabled = ! customVisible; title.setAttribute( 'aria-required', customVisible ? 'true' : 'false' ); }
			}
			function syncPresentation( changed ) {
				var mode = presentationMode();
				panels.forEach( function ( panel ) { panel.hidden = panel.getAttribute( 'data-certloom-presentation-panel' ) !== mode; } );
				if ( template ) { var designSource = designSources.find( function ( radio ) { return radio.checked; } ); template.disabled = 'design' !== mode || ! designSource || 'assigned' !== designSource.value; }
				if ( changed && 'image' !== mode ) { clearImage(); }
				syncCompatibility();
			}
			function syncExpiry() {
				var usesProgram = 'program' === credentialBasis() && !! selectedProgramOption();
				var programValidity = expiryRadios.find( function ( radio ) { return 'program' === radio.value; } );
				var permanent = expiryRadios.find( function ( radio ) { return 'permanent' === radio.value; } );
				if ( programValidity ) {
					programValidity.disabled = ! usesProgram || ! programValidityAvailable();
					if ( programValidity.checked && programValidity.disabled ) { programValidity.checked = false; }
				}
				if ( permanent ) {
					permanent.disabled = usesProgram && 'required' === programRenewalPolicy();
					if ( permanent.checked && permanent.disabled ) { permanent.checked = false; if ( programValidity && ! programValidity.disabled ) { programValidity.checked = true; } }
				}
				var selected = expiryRadios.find( function ( radio ) { return radio.checked && ! radio.disabled; } ); var isDate = selected && 'date' === selected.value;
				if ( expiryPanel ) { expiryPanel.hidden = ! isDate; }
				if ( expiryDate ) { expiryDate.disabled = ! isDate; expiryDate.required = !! isDate; }
				if ( expirySummary ) {
					if ( ! usesProgram ) { expirySummary.textContent = certloomText( 'chooseProgramValidity', 'Choose a Program to use its validity default.' ); }
					else if ( programValidityAvailable() ) { expirySummary.textContent = certloomText( 'programDefaultPrefix', 'Program default:' ) + ' ' + ( programValiditySummary() || certloomText( 'programValidityConfigured', 'Configured validity' ) ); }
					else { expirySummary.textContent = certloomText( 'programValidityAtIssuance', 'This Program decides validity at issuance. Choose No expiry or a specific expiry date.' ); }
				}
				syncCompatibility();
			}
			presentationRadios.forEach( function ( radio ) { radio.addEventListener( 'change', function () { if ( ! autoApplyingAppearance ) { appearanceTouched = true; } syncPresentation( true ); } ); } );
			designSources.forEach( function ( radio ) { radio.addEventListener( 'change', function () { if ( ! autoApplyingAppearance ) { appearanceTouched = true; } syncCompatibility(); } ); } );
			basisRadios.forEach( function ( radio ) { radio.addEventListener( 'change', function () { syncCredentialBasis( false ); syncExpiry(); syncCompatibility(); } ); } );
			titleSourceRadios.forEach( function ( radio ) { radio.addEventListener( 'change', function () { syncCredentialBasis( false ); syncCompatibility(); } ); } );
			if ( template ) { template.addEventListener( 'change', syncCompatibility ); }
			if ( imageId ) { imageId.addEventListener( 'change', syncCompatibility ); }
			expiryRadios.forEach( function ( radio ) { radio.addEventListener( 'change', syncExpiry ); } );
			if ( program ) {
				var previousProgramValue = String( program.value || '0' );
				program.addEventListener( 'change', function () {
					var nextProgramValue = String( program.value || '0' );
					var programJustChosen = ( ! previousProgramValue || '0' === previousProgramValue ) && nextProgramValue && '0' !== nextProgramValue;
					previousProgramValue = nextProgramValue;
					syncCredentialBasis( programJustChosen ); syncIssuerPolicy(); syncExpiry(); syncCompatibility();
					if ( nextProgramValue && '0' !== nextProgramValue ) { autoApplyInheritedDesign( nextProgramValue ); }
				} );
			}
			if ( title ) { title.addEventListener( 'input', syncCompatibility ); }
			if ( batch ) { batch.addEventListener( 'change', syncCompatibility ); }
			form.addEventListener( 'change', function ( event ) { if ( event.target && ( event.target.name === 'recipient_id' || event.target.name === 'recipient_mode' ) ) { syncCompatibility(); } } );
			form.addEventListener( 'input', function ( event ) { if ( event.target && event.target.name === 'new_recipient[display_name]' ) { syncCompatibility(); } } );
			form.addEventListener( 'certloom:design-readiness', syncCompatibility );
			syncCredentialBasis( false ); syncIssuerPolicy(); syncPresentation( false ); syncExpiry(); syncCompatibility();
			if ( program && program.value && '0' !== program.value && ! appearanceTouched ) { autoApplyInheritedDesign( program.value ); }
		} );
	}


	function initManualRepeatingData() {
		document.querySelectorAll( '[data-certloom-repeating-rowset]' ).forEach( function ( rowset ) {
			var host = rowset.querySelector( '[data-certloom-repeating-rows]' );
			var template = rowset.querySelector( 'template[data-certloom-repeating-template]' );
			var add = rowset.querySelector( '[data-certloom-repeating-add]' );
			if ( ! host || ! template || ! add ) { return; }
			function currentRows() { return Array.prototype.slice.call( host.querySelectorAll( ':scope > [data-certloom-repeating-row]' ) ); }
			function syncLimit() {
				var count = currentRows().length;
				add.disabled = count >= 50;
				add.setAttribute( 'aria-disabled', add.disabled ? 'true' : 'false' );
			}
			function nextIndex() {
				var next = parseInt( rowset.getAttribute( 'data-certloom-next-row' ) || '0', 10 );
				if ( ! Number.isFinite( next ) || next < 0 ) { next = currentRows().length; }
				rowset.setAttribute( 'data-certloom-next-row', String( next + 1 ) );
				return next;
			}
			add.addEventListener( 'click', function () {
				if ( currentRows().length >= 50 ) { syncLimit(); return; }
				var index = nextIndex();
				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( index ) ).trim();
				var row = wrapper.firstElementChild;
				if ( ! row ) { return; }
				host.appendChild( row );
				syncLimit();
				var first = row.querySelector( 'input,select,textarea' );
				if ( first ) { first.focus(); }
			} );
			rowset.addEventListener( 'click', function ( event ) {
				var remove = event.target && event.target.closest ? event.target.closest( '[data-certloom-repeating-remove]' ) : null;
				if ( ! remove || ! rowset.contains( remove ) ) { return; }
				var row = remove.closest( '[data-certloom-repeating-row]' );
				if ( row ) { row.remove(); syncLimit(); add.focus(); }
			} );
			syncLimit();
		} );
	}

	function initPremiumDialogs() {
		function closeDialog( dialog ) { if ( ! dialog ) { return; } if ( dialog.open && 'function' === typeof dialog.close ) { dialog.close(); } else { dialog.removeAttribute( 'open' ); } }
		function feedbackFor( form ) {
			var feedback = form.querySelector( '[data-certloom-quick-feedback]' );
			if ( feedback ) { return feedback; }
			feedback = document.createElement( 'div' ); feedback.className = 'certloom-domain-form-feedback'; feedback.setAttribute( 'data-certloom-quick-feedback', '' ); feedback.setAttribute( 'aria-live', 'polite' );
			var actions = form.querySelector( '.certloom-dialog-actions' ); if ( actions ) { form.insertBefore( feedback, actions ); } else { form.appendChild( feedback ); }
			return feedback;
		}
		function setQuickFeedback( form, message, error ) { var feedback = feedbackFor( form ); feedback.textContent = message || ''; feedback.classList.toggle( 'is-visible', !! message ); feedback.classList.toggle( 'is-error', !! error ); feedback.classList.toggle( 'is-success', !! message && ! error ); }
		function addOption( select, value, label, programId, programTitle, programMeta ) {
			if ( ! select || ! value ) { return; } var option = select.querySelector( 'option[value="' + escapeSelector( String( value ) ) + '"]' ); if ( ! option ) { option = document.createElement( 'option' ); option.value = String( value ); select.appendChild( option ); } option.textContent = label || String( value ); if ( programId ) { option.setAttribute( 'data-program-id', String( programId ) ); } if ( programTitle ) { option.setAttribute( 'data-program-title', String( programTitle ) ); }
			if ( programMeta ) {
				var map = { validity_mode:'data-validity-mode', validity_value:'data-validity-value', validity_unit:'data-validity-unit', program_validity_available:'data-program-validity-available', validity_summary:'data-validity-summary', renewal:'data-renewal-policy', repeat_policy:'data-repeat-policy' };
				Object.keys( map ).forEach( function ( key ) { if ( programMeta[ key ] === undefined || programMeta[ key ] === null ) { return; } var metaValue = programMeta[ key ]; if ( 'program_validity_available' === key ) { metaValue = metaValue === true || metaValue === 1 || metaValue === '1' ? '1' : '0'; } option.setAttribute( map[ key ], String( metaValue ) ); } );
			}
		}
		document.addEventListener( 'click', function ( event ) {
			var open = event.target.closest( '[data-certloom-dialog-open]' );
			if ( open ) {
				var id = open.getAttribute( 'data-certloom-dialog-open' );
				var dialog = id ? document.getElementById( id ) : null;
				if ( dialog ) {
					event.preventDefault();
					var quickForm = dialog.querySelector( 'form[data-certloom-quick-create]' );
					if ( quickForm ) {
						setQuickFeedback( quickForm, '', false );
						var sourceSelector = quickForm.getAttribute( 'data-certloom-quick-program-source' ); var programField = quickForm.querySelector( 'select[name="program_id"]' ); var source = sourceSelector ? document.querySelector( sourceSelector ) : null;
						if ( source && programField && source.value ) { addOption( programField, source.value, source.options[source.selectedIndex] ? source.options[source.selectedIndex].textContent : source.value ); programField.value = source.value; }
					}
					if ( 'function' === typeof dialog.showModal ) { if ( ! dialog.open ) { dialog.showModal(); } }
					else { dialog.setAttribute( 'open', 'open' ); }
					var focus = dialog.querySelector( 'input:not([type="hidden"]),select,textarea,button' ); if ( focus ) { window.setTimeout( function () { focus.focus(); }, 0 ); }
				}
				return;
			}
			var close = event.target.closest( '[data-certloom-dialog-close]' );
			if ( close ) { var parent = close.closest( 'dialog' ); if ( parent ) { event.preventDefault(); closeDialog( parent ); } }
		} );
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target && event.target.matches && event.target.matches( 'form[data-certloom-quick-create]' ) ? event.target : null; if ( ! form ) { return; } event.preventDefault();
			if ( ! form.checkValidity() ) { form.reportValidity(); setQuickFeedback( form, form.getAttribute( 'data-certloom-invalid-message' ) || certloomText( 'quickInvalid', 'Complete the highlighted required field.' ), true ); return; }
			var button = form.querySelector( 'button[type="submit"]' ); var original = button ? button.textContent : ''; if ( button ) { button.disabled = true; button.setAttribute( 'aria-busy', 'true' ); button.textContent = certloomText( 'adding', 'Adding…' ); } setQuickFeedback( form, certloomText( 'adding', 'Adding…' ), false );
			var data = new FormData( form );
			fetch( window.ajaxurl || '', { method:'POST', credentials:'same-origin', body:data } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( ! response || ! response.success || ! response.data || ! response.data.id ) { throw new Error( response && response.data && response.data.message ? response.data.message : certloomText( 'itemAddFailed', 'This item could not be added.' ) ); }
				var kind = form.getAttribute( 'data-certloom-quick-create' ); var targetSelector = form.getAttribute( 'data-certloom-quick-target' ); var target = targetSelector ? document.querySelector( targetSelector ) : null; addOption( target, response.data.id, response.data.label || '', response.data.program_id || 0, 'program' === kind ? ( response.data.title || response.data.label || '' ) : '', 'program' === kind ? response.data : null ); if ( target ) { target.value = String( response.data.id ); target.dispatchEvent( new Event( 'change', { bubbles:true } ) ); }
				if ( 'program' === kind ) { document.querySelectorAll( 'select[data-certloom-quick-program-mirror], #certloom-quick-batch-dialog select[name="program_id"]' ).forEach( function ( select ) { addOption( select, response.data.id, response.data.label || '', 0, response.data.title || response.data.label || '', response.data ); } ); }
				setQuickFeedback( form, response.data.message || certloomText( 'added', 'Added.' ), false );
				var review = document.querySelector( '[data-certloom-manual-review]' ); if ( review && ! review.closest( 'form[data-certloom-phase2-issuance]' ) ) { var program = document.querySelector( 'select[name="program_id"]' ); review.disabled = ! ( program && program.value ) || '1' !== review.getAttribute( 'data-certloom-has-templates' ); }
				window.setTimeout( function () { closeDialog( form.closest( 'dialog' ) ); if ( 'program' === kind ) { var title = form.querySelector( '[name="program_title"]' ), code = form.querySelector( '[name="program_code"]' ); if ( title ) { title.value = ''; } if ( code ) { code.value = ''; } } else if ( 'batch' === kind ) { var name = form.querySelector( '[name="batch_name"]' ), batchCode = form.querySelector( '[name="batch_code"]' ); if ( name ) { name.value = ''; } if ( batchCode ) { batchCode.value = ''; } } }, 350 );
			} ).catch( function ( error ) { setQuickFeedback( form, error && error.message ? error.message : certloomText( 'itemAddFailed', 'This item could not be added.' ), true ); } ).finally( function () { if ( button ) { button.disabled = false; button.removeAttribute( 'aria-busy' ); button.textContent = original; } } );
		} );
		document.querySelectorAll( 'dialog.certloom-premium-dialog' ).forEach( function ( dialog ) { dialog.addEventListener( 'click', function ( event ) { if ( event.target === dialog ) { var rect = dialog.getBoundingClientRect(); var inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom; if ( ! inside ) { closeDialog( dialog ); } } } ); } );
	}


	function initDomainEntityFormFeedback() {
		document.querySelectorAll( '#certloom-domain-editor form[data-certloom-entity-form]' ).forEach( function ( form ) {
			var feedback = form.querySelector( '[data-certloom-domain-form-feedback]' );
			var submit = form.querySelector( 'button[type="submit"], input[type="submit"]' );
			if ( ! feedback || ! submit ) { return; }
			function show( message, state ) {
				feedback.textContent = message || '';
				feedback.classList.remove( 'is-error', 'is-saving', 'is-visible' );
				if ( message ) { feedback.classList.add( 'is-visible', state || 'is-error' ); }
			}
			function clearFieldState( control ) {
				var field = control ? control.closest( '.certloom-domain-field' ) : null;
				if ( field ) {
					field.classList.remove( 'is-invalid' );
					var inlineError = field.querySelector( '.certloom-inline-field-error' );
					if ( inlineError ) { inlineError.remove(); }
				}
				if ( control ) { control.removeAttribute( 'aria-invalid' ); }
			}
			form.addEventListener( 'invalid', function ( event ) {
				var control = event.target;
				var field = control && control.closest ? control.closest( '.certloom-domain-field' ) : null;
				if ( control ) { control.setAttribute( 'aria-invalid', 'true' ); }
				if ( field ) {
					field.classList.add( 'is-invalid' );
					var inlineError = field.querySelector( '.certloom-inline-field-error' );
					if ( ! inlineError ) {
						inlineError = document.createElement( 'small' );
						inlineError.className = 'certloom-inline-field-error';
						inlineError.setAttribute( 'role', 'alert' );
						field.appendChild( inlineError );
					}
					inlineError.textContent = control.getAttribute( 'data-certloom-required-example' ) || 'Please fill in this required field.';
				}
				show( form.getAttribute( 'data-certloom-invalid-message' ) || 'Please complete the highlighted required field before saving.', 'is-error' );
				if ( field && field.scrollIntoView ) { window.setTimeout( function () { field.scrollIntoView( { block:'center', behavior:'smooth' } ); }, 0 ); }
			}, true );
			form.addEventListener( 'input', function ( event ) { clearFieldState( event.target ); } );
			form.addEventListener( 'change', function ( event ) { clearFieldState( event.target ); } );
			form.addEventListener( 'submit', function () {
				show( form.getAttribute( 'data-certloom-saving-message' ) || certloomText( 'saving', 'Saving…' ), 'is-saving' );
				submit.setAttribute( 'aria-busy', 'true' ); submit.disabled = true;
				if ( 'INPUT' === submit.tagName ) { if ( ! submit.getAttribute( 'data-certloom-original-label' ) ) { submit.setAttribute( 'data-certloom-original-label', submit.value || '' ); } submit.value = form.getAttribute( 'data-certloom-saving-message' ) || certloomText( 'saving', 'Saving…' ); }
				else { if ( ! submit.getAttribute( 'data-certloom-original-label' ) ) { submit.setAttribute( 'data-certloom-original-label', submit.textContent || '' ); } submit.textContent = form.getAttribute( 'data-certloom-saving-message' ) || certloomText( 'saving', 'Saving…' ); }
			} );
		} );
	}


	function initDomainFormPersistence() {
		var prefix = 'certloomDomainDraft:';
		var storage = null;
		try { storage = window.sessionStorage; } catch ( error ) { storage = null; }
		// Form-draft recovery is a progressive enhancement. If browser/storage policy denies
		// sessionStorage, the rest of Certloom's admin JavaScript must continue normally.
		if ( ! storage ) { return; }
		var params = new URLSearchParams( window.location.search || '' );
		var retry = '1' === params.get( 'certloom_retry' );
		var forms = document.querySelectorAll( 'form[data-certloom-preserve-on-error]' );
		if ( ! forms.length ) {
			if ( ! retry ) { Object.keys( storage ).forEach( function ( key ) { if ( 0 === key.indexOf( prefix ) ) { storage.removeItem( key ); } } ); }
			return;
		}
		function keyFor( form ) {
			var action = form.querySelector( 'input[name="action"]' );
			var id = form.querySelector( 'input[name$="_id"]' );
			return prefix + ( action ? action.value : 'domain' ) + ':' + ( id ? id.value : 'new' );
		}
		forms.forEach( function ( form ) {
			var key = keyFor( form );
			if ( retry ) {
				try {
					var saved = JSON.parse( storage.getItem( key ) || 'null' );
					if ( saved && Array.isArray( saved ) ) {
						saved.forEach( function ( item ) {
							if ( ! item || ! item.name ) { return; }
							var nodes = form.querySelectorAll( '[name="' + escapeSelector( item.name ) + '"]' );
							nodes.forEach( function ( node ) {
								if ( 'checkable' === item.kind || 'checkbox' === node.type || 'radio' === node.type ) { node.checked = Array.isArray( item.value ) && item.value.indexOf( node.value ) !== -1; }
								else if ( 'multiple' === item.kind && node.options ) { Array.prototype.forEach.call( node.options, function ( option ) { option.selected = Array.isArray( item.value ) && item.value.indexOf( option.value ) !== -1; } ); node.dispatchEvent( new Event( 'change', { bubbles:true } ) ); }
								else { node.value = item.value; node.dispatchEvent( new Event( 'change', { bubbles:true } ) ); }
							} );
						} );
						var editor = document.getElementById( 'certloom-domain-editor' ); if ( editor ) { editor.scrollIntoView( { block:'start' } ); }
					}
				} catch ( error ) { try { storage.removeItem( key ); } catch ( ignored ) {} }
			} else { try { storage.removeItem( key ); } catch ( ignored ) {} }
			form.addEventListener( 'submit', function () {
				var map = {}, kinds = {}, data = new FormData( form );
				data.forEach( function ( value, name ) {
					if ( '_wpnonce' === name || '_wp_http_referer' === name || value instanceof File ) { return; }
					if ( Object.prototype.hasOwnProperty.call( map, name ) ) { map[ name ] = Array.isArray( map[ name ] ) ? map[ name ].concat( [ String( value ) ] ) : [ map[ name ], String( value ) ]; }
					else { map[ name ] = String( value ); }
				} );
				var checkable = {};
				form.querySelectorAll( 'input[type="checkbox"][name], input[type="radio"][name]' ).forEach( function ( node ) {
					if ( '_wpnonce' === node.name || '_wp_http_referer' === node.name ) { return; }
					if ( ! Object.prototype.hasOwnProperty.call( checkable, node.name ) ) { checkable[ node.name ] = []; }
					if ( node.checked ) { checkable[ node.name ].push( String( node.value ) ); }
				} );
				Object.keys( checkable ).forEach( function ( name ) { map[ name ] = checkable[ name ]; kinds[ name ] = 'checkable'; } );
				form.querySelectorAll( 'select[multiple][name]' ).forEach( function ( node ) {
					map[ node.name ] = Array.prototype.filter.call( node.options, function ( option ) { return option.selected; } ).map( function ( option ) { return String( option.value ); } ); kinds[ node.name ] = 'multiple';
				} );
				var payload = Object.keys( map ).map( function ( name ) { return { name:name, value:map[ name ], kind:kinds[ name ] || 'value' }; } );
				try { storage.setItem( key, JSON.stringify( payload ) ); } catch ( error ) {}
			} );
		} );
	}

	function initDomainMediaAndColorControls() {
		document.querySelectorAll( '[data-certloom-color-control]' ).forEach( function ( control ) {
			var hidden = control.querySelector( '[data-certloom-color-value]' ), picker = control.querySelector( '[data-certloom-color-picker]' ), label = control.querySelector( '[data-certloom-color-label]' ), clear = control.querySelector( '[data-certloom-color-clear]' );
			if ( picker && hidden ) { picker.addEventListener( 'input', function () { hidden.value = picker.value.toUpperCase(); if ( label ) { label.textContent = hidden.value; } } ); }
			if ( clear && hidden ) { clear.addEventListener( 'click', function () { hidden.value = ''; if ( label ) { label.textContent = certloomText( 'defaultLabel', 'Default' ); } } ); }
		} );
		document.querySelectorAll( '[data-certloom-media-control]' ).forEach( function ( control ) {
			var id = control.querySelector( '[data-certloom-media-id]' ), preview = control.querySelector( '[data-certloom-media-preview]' ), choose = control.querySelector( '[data-certloom-media-select]' ), clear = control.querySelector( '[data-certloom-media-clear]' );
			var allowedTypes = String( control.getAttribute( 'data-certloom-media-types' ) || '' ).split( ',' ).map( function ( value ) { return value.trim(); } ).filter( Boolean );
			if ( preview ) { preview.setAttribute( 'role', 'status' ); preview.setAttribute( 'aria-live', 'polite' ); }
			if ( choose ) { choose.setAttribute( 'aria-haspopup', 'dialog' ); choose.addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) { return; }
				var frame = wp.media( { title:'Choose media', button:{ text:'Use this file' }, multiple:false, library:{ type:'image' } } );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					if ( allowedTypes.length && allowedTypes.indexOf( String( attachment.mime || '' ) ) === -1 ) {
						if ( preview ) { preview.setAttribute( 'role', 'alert' ); preview.innerHTML = '<span>' + escapeHtml( certloomText( 'certificateImageTypeInvalid', 'Choose a PNG or JPEG image.' ) ) + '</span>'; }
						return;
					}
					if ( preview ) { preview.setAttribute( 'role', 'status' ); }
					id.value = attachment.id || '';
					var src = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
					preview.innerHTML = ( src ? '<img src="' + escapeHtml( src ) + '" alt="">' : '' ) + '<span>' + escapeHtml( attachment.filename || attachment.title || certloomText( 'selectedFile', 'Selected file' ) ) + '</span>';
					if ( clear ) { clear.hidden = false; }
					if ( id && typeof window.Event === 'function' ) { id.dispatchEvent( new Event( 'change', { bubbles:true } ) ); }
				} );
				frame.on( 'close', function () { if ( choose ) { window.setTimeout( function () { choose.focus(); }, 0 ); } } );
				frame.open();
			} ); }
			if ( clear ) { clear.addEventListener( 'click', function () { id.value = ''; if ( preview ) { preview.setAttribute( 'role', 'status' ); preview.innerHTML = '<span>' + escapeHtml( control.classList.contains( 'certloom-certificate-image-control' ) ? certloomText( 'noCertificateImage', 'No certificate image selected' ) : certloomText( 'noFileSelected', 'No file selected' ) ) + '</span>'; } clear.hidden = true; if ( id && typeof window.Event === 'function' ) { id.dispatchEvent( new Event( 'change', { bubbles:true } ) ); } if ( choose ) { choose.focus(); } } ); }
		} );
	}

	function initScopedFieldEditors() {
		document.querySelectorAll( '[data-certloom-scope-field-editor]' ).forEach( function ( editor ) {
			var picker = editor.querySelector( '[data-certloom-scope-field-picker]' ), add = editor.querySelector( '[data-certloom-scope-field-add]' ), empty = editor.querySelector( '[data-certloom-scope-field-empty]' );
			function refreshEmpty() { if ( empty ) { empty.hidden = !!editor.querySelector( '[data-certloom-scope-field-row]:not([hidden])' ); } }
			function activate( key, defaultRule ) { var row = editor.querySelector( '[data-certloom-scope-field-row="' + escapeSelector( key ) + '"]' ); if ( ! row ) { return; } row.hidden = false; var select = row.querySelector( '[data-certloom-scope-field-rule]' ); if ( select && ! select.value ) { select.value = defaultRule || 'optional'; } var option = picker ? picker.querySelector( 'option[value="' + escapeSelector( key ) + '"]' ) : null; if ( option ) { option.remove(); } if ( picker ) { picker.value = ''; } refreshEmpty(); }
			if ( add && picker ) { add.addEventListener( 'click', function () { if ( picker.value ) { activate( picker.value, 'optional' ); } } ); }
			editor.addEventListener( 'click', function ( event ) { var remove = event.target.closest( '[data-certloom-scope-field-remove]' ); if ( ! remove ) { return; } var row = remove.closest( '[data-certloom-scope-field-row]' ), select = row ? row.querySelector( '[data-certloom-scope-field-rule]' ) : null; if ( ! row || ! select ) { return; } select.value = ''; row.hidden = true; var key = row.getAttribute( 'data-certloom-scope-field-row' ), label = row.querySelector( '[data-certloom-scope-field-label]' ); if ( picker && key && ! picker.querySelector( 'option[value="' + escapeSelector( key ) + '"]' ) ) { var option = document.createElement( 'option' ); option.value = key; option.textContent = label ? label.textContent : key; picker.appendChild( option ); } refreshEmpty(); } );
			var dialog = editor.querySelector( '[data-certloom-quick-field-dialog]' );
			Array.prototype.forEach.call( editor.querySelectorAll( '[data-certloom-quick-field-open]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog && dialog.showModal ) { dialog.showModal(); var input = dialog.querySelector( '[data-certloom-quick-field-label]' ); if ( input ) { input.focus(); } } } ); } );
			Array.prototype.forEach.call( editor.querySelectorAll( '[data-certloom-quick-field-close]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog && dialog.open ) { dialog.close(); } } ); } );
			var create = editor.querySelector( '[data-certloom-quick-field-create]' );
			var sensitivityInput = dialog ? dialog.querySelector( '[data-certloom-quick-field-sensitivity]' ) : null, exposureInput = dialog ? dialog.querySelector( '[data-certloom-quick-field-exposure]' ) : null;
			function syncQuickFieldPrivacy() { if ( ! sensitivityInput || ! exposureInput ) { return; } var internal = 'internal' === sensitivityInput.value; if ( internal ) { exposureInput.value = 'private'; } exposureInput.disabled = internal; exposureInput.setAttribute( 'aria-disabled', internal ? 'true' : 'false' ); }
			if ( sensitivityInput ) { sensitivityInput.addEventListener( 'change', syncQuickFieldPrivacy ); syncQuickFieldPrivacy(); }
			if ( create && dialog ) { create.addEventListener( 'click', function () {
				var labelInput = dialog.querySelector( '[data-certloom-quick-field-label]' ), typeInput = dialog.querySelector( '[data-certloom-quick-field-type]' ), feedback = dialog.querySelector( '[data-certloom-quick-field-feedback]' ), labelValue = labelInput ? labelInput.value.trim() : '';
				if ( ! labelValue ) { if ( feedback ) { feedback.textContent = certloomText( 'enterFieldLabel', 'Enter a field label.' ); } return; }
				create.disabled = true; if ( feedback ) { feedback.textContent = certloomText( 'creatingField', 'Creating field…' ); }
				var body = new URLSearchParams(); body.set( 'action', 'certloom_quick_add_scoped_field' ); body.set( 'nonce', editor.getAttribute( 'data-certloom-field-nonce' ) || '' ); body.set( 'label', labelValue ); body.set( 'field_type', typeInput ? typeInput.value : 'text' ); body.set( 'sensitivity', sensitivityInput ? sensitivityInput.value : 'personal' ); body.set( 'public_exposure', exposureInput ? exposureInput.value : 'private' );
				fetch( window.ajaxurl || '', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body:body.toString() } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
					if ( ! response || ! response.success || ! response.data || ! response.data.field ) { throw new Error( response && response.data && response.data.message ? response.data.message : certloomText( 'fieldCreateFailed', 'Field could not be created.' ) ); }
					var field = response.data.field, list = editor.querySelector( '[data-certloom-scope-field-list]' ), scope = editor.getAttribute( 'data-certloom-scope-field-editor' ), row = document.createElement( 'div' ); row.className = 'certloom-scoped-field-row'; row.setAttribute( 'data-certloom-scope-field-row', field.key );
					var name = 'program' === scope ? 'program_field_requirement[' + field.key + ']' : 'batch_field_requirement[' + field.key + ']';
					var options = 'program' === scope
						? '<option value="">' + escapeHtml( certloomText( 'scopeNotUsed', 'Not used' ) ) + '</option><option value="optional" selected>' + escapeHtml( certloomText( 'fieldOptional', 'Optional' ) ) + '</option><option value="required">' + escapeHtml( certloomText( 'fieldRequired', 'Required' ) ) + '</option>'
						: '<option value="">' + escapeHtml( certloomText( 'scopeInheritProgram', 'Inherit Program' ) ) + '</option><option value="optional" selected>' + escapeHtml( certloomText( 'fieldOptional', 'Optional' ) ) + '</option><option value="required">' + escapeHtml( certloomText( 'fieldRequired', 'Required' ) ) + '</option><option value="exclude">' + escapeHtml( certloomText( 'scopeExclude', 'Exclude' ) ) + '</option>';
					row.innerHTML = '<div><strong data-certloom-scope-field-label>' + escapeHtml( field.label ) + '</strong><span class="certloom-scoped-field-meta">' + escapeHtml( field.type ) + ' · ' + escapeHtml( field.sensitivity_label || field.sensitivity || certloomText( 'sensitivityPersonal', 'Personal' ) ) + ' · ' + escapeHtml( field.public_exposure_label || field.public_exposure || certloomText( 'privacyPrivate', 'Private' ) ) + '</span></div><label><span class="screen-reader-text">' + escapeHtml( certloomText( 'fieldRule', 'Field rule' ) ) + '</span><select name="' + escapeHtml( name ) + '" data-certloom-scope-field-rule>' + options + '</select></label><button type="button" class="button-link-delete" data-certloom-scope-field-remove>' + escapeHtml( certloomText( 'remove', 'Remove' ) ) + '</button>';
					if ( list ) { list.appendChild( row ); } if ( feedback ) { feedback.textContent = response.data.message || certloomText( 'fieldCreated', 'Field created.' ); } if ( labelInput ) { labelInput.value = ''; } window.setTimeout( function () { if ( dialog.open ) { dialog.close(); } }, 450 ); refreshEmpty();
				} ).catch( function ( error ) { if ( feedback ) { feedback.textContent = error.message || certloomText( 'fieldCreateFailed', 'Field could not be created.' ); } } ).finally( function () { create.disabled = false; } );
			} ); }
			refreshEmpty();
		} );
	}


	function initTemplateCardPublishing() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-certloom-card-publish], [data-certloom-card-unpublish]' );
			if ( ! button ) { return; }
			var publishing = button.hasAttribute( 'data-certloom-card-publish' );
			var card = button.closest( '.certloom-template-library-card' ), feedback = card ? card.querySelector( '[data-certloom-card-feedback]' ) : null;
			button.disabled = true; button.setAttribute( 'aria-busy', 'true' ); if ( feedback ) { feedback.textContent = ''; }
			var body = new URLSearchParams();
			body.set( 'action', publishing ? 'certloom_template_quick_publish' : 'certloom_template_quick_unpublish' );
			body.set( 'template_id', button.getAttribute( 'data-template-id' ) || '0' );
			body.set( 'nonce', button.getAttribute( 'data-nonce' ) || '' );
			if ( publishing ) {
				body.set( 'expected_revision_id', button.getAttribute( 'data-revision-id' ) || '0' );
				body.set( 'expected_revision_sha256', button.getAttribute( 'data-revision-sha' ) || '' );
			} else {
				body.set( 'expected_updated_at', button.getAttribute( 'data-expected-updated-at' ) || '' );
			}
			fetch( window.ajaxurl || '', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body:body.toString() } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( ! response || ! response.success ) { throw new Error( response && response.data && response.data.message ? response.data.message : ( publishing ? certloomText( 'designPublishFailed', 'This design could not be published.' ) : certloomText( 'designDraftFailed', 'This design could not be moved to Draft.' ) ) ); }
				if ( feedback ) { feedback.classList.remove( 'is-error' ); feedback.classList.add( 'is-success' ); feedback.textContent = response.data.message || ( publishing ? certloomText( 'designPublished', 'Design published.' ) : certloomText( 'designMovedDraft', 'Design moved to Draft.' ) ); }
				var state = card ? card.querySelector( '[data-certloom-publish-state]' ) : null;
				if ( state ) { state.textContent = publishing ? certloomText( 'published', 'Published' ) : certloomText( 'draft', 'Draft' ); state.classList.toggle( 'is-draft', ! publishing ); state.classList.toggle( 'is-published', publishing ); }
				button.removeAttribute( 'aria-busy' ); button.disabled = false;
				button.classList.toggle( 'is-on', publishing ); button.setAttribute( 'aria-checked', publishing ? 'true' : 'false' );
				if ( publishing ) {
					button.removeAttribute( 'data-certloom-card-publish' ); button.setAttribute( 'data-certloom-card-unpublish', '' ); button.removeAttribute( 'data-revision-id' ); button.removeAttribute( 'data-revision-sha' );
					button.setAttribute( 'data-expected-updated-at', response.data.updated_at || '' ); button.setAttribute( 'data-nonce', response.data.unpublish_nonce || '' );
					var srPublished = button.querySelector( '.screen-reader-text' ); if ( srPublished ) { srPublished.textContent = certloomText( 'movePublishedDraft', 'Move published design back to Draft' ); }
				} else {
					button.removeAttribute( 'data-certloom-card-unpublish' ); button.setAttribute( 'data-certloom-card-publish', '' ); button.removeAttribute( 'data-expected-updated-at' );
					button.setAttribute( 'data-revision-id', response.data.revision_id || '0' ); button.setAttribute( 'data-revision-sha', response.data.revision_sha256 || '' ); button.setAttribute( 'data-nonce', response.data.publish_nonce || '' );
					var srDraft = button.querySelector( '.screen-reader-text' ); if ( srDraft ) { srDraft.textContent = certloomText( 'publishDraftDesign', 'Publish this draft design' ); }
				}
			} ).catch( function ( error ) {
				button.disabled = false; button.removeAttribute( 'aria-busy' );
				if ( feedback ) { feedback.classList.remove( 'is-success' ); feedback.classList.add( 'is-error' ); feedback.textContent = error && error.message ? error.message : ( publishing ? certloomText( 'designPublishFailed', 'This design could not be published.' ) : certloomText( 'designDraftFailed', 'This design could not be moved to Draft.' ) ); }
			} );
		} );
	}


	function initGuidedDashboardTour() {
		var root = document.querySelector( '[data-certloom-guided-tour]' );
		if ( ! root ) { return; }
		var mode = root.getAttribute( 'data-mode' ) || '';
		if ( 'resume' !== mode && 'restart' !== mode ) { return; }
		var steps = [];
		var state = {};
		try { steps = JSON.parse( root.getAttribute( 'data-steps' ) || '[]' ); } catch ( error ) { steps = []; }
		try { state = JSON.parse( root.getAttribute( 'data-state' ) || '{}' ); } catch ( error ) { state = {}; }
		if ( ! Array.isArray( steps ) || ! steps.length ) { return; }

		var panel = root.querySelector( '.certloom-guided-tour-card' );
		var progress = root.querySelector( '[data-certloom-tour-progress]' );
		var title = root.querySelector( '[data-certloom-tour-title]' );
		var body = root.querySelector( '[data-certloom-tour-body]' );
		var back = root.querySelector( '[data-certloom-tour-back]' );
		var next = root.querySelector( '[data-certloom-tour-next]' );
		var open = root.querySelector( '[data-certloom-tour-open]' );
		var dismiss = root.querySelector( '[data-certloom-tour-dismiss]' );
		if ( ! panel || ! progress || ! title || ! body || ! back || ! next || ! open || ! dismiss ) { return; }

		var current = 'restart' === mode ? 0 : Math.min( steps.length - 1, Math.max( 0, parseInt( state.last_step || 0, 10 ) || 0 ) );
		if ( 'completed' === state.status || 'dismissed' === state.status ) { current = 0; }
		var activeTarget = null;
		var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
		var progressTemplate = root.getAttribute( 'data-progress-template' ) || 'Step %1$d of %2$d';
		var nextLabel = root.getAttribute( 'data-next-label' ) || 'Next';
		var finishLabel = root.getAttribute( 'data-finish-label' ) || 'Finish tour';
		var nonce = root.getAttribute( 'data-nonce' ) || '';

		function persist( eventName, stepIndex ) {
			if ( ! nonce || ! window.ajaxurl || ! window.fetch ) { return Promise.resolve(); }
			var payload = new URLSearchParams();
			payload.set( 'action', 'certloom_guided_tour_state' );
			payload.set( 'nonce', nonce );
			payload.set( 'event', eventName );
			payload.set( 'step', String( Math.max( 0, stepIndex || 0 ) ) );
			return fetch( window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' },
				body: payload.toString()
			} ).catch( function () { return null; } );
		}

		function clearTarget() {
			if ( activeTarget ) { activeTarget.classList.remove( 'certloom-guided-tour-target' ); }
			activeTarget = null;
		}

		function closeTour( permanently ) {
			clearTarget();
			root.hidden = true;
			if ( permanently ) { persist( 'dismiss', current ); }
			var heading = document.getElementById( 'certloom-dashboard-title' );
			if ( heading && 'function' === typeof heading.focus ) {
				heading.setAttribute( 'tabindex', '-1' );
				heading.focus();
			}
		}

		function renderStep() {
			clearTarget();
			var step = steps[ current ] || {};
			var selector = 'string' === typeof step.target ? step.target : '';
			activeTarget = selector ? document.querySelector( selector ) : null;
			if ( activeTarget ) {
				activeTarget.classList.add( 'certloom-guided-tour-target' );
				var rect = activeTarget.getBoundingClientRect();
				if ( rect.top < 0 || rect.bottom > window.innerHeight ) {
					activeTarget.scrollIntoView( { block:'center', behavior:reduceMotion ? 'auto' : 'smooth' } );
				}
			}
			progress.textContent = progressTemplate.replace( '%1$d', String( current + 1 ) ).replace( '%2$d', String( steps.length ) );
			title.textContent = step.title || '';
			body.textContent = step.body || '';
			back.disabled = 0 === current;
			next.textContent = current === steps.length - 1 ? finishLabel : nextLabel;
			var targetLink = activeTarget && activeTarget.matches( 'a[href]' ) ? activeTarget : ( activeTarget ? activeTarget.closest( 'a[href]' ) : null );
			if ( targetLink ) {
				open.hidden = false;
				open.href = targetLink.href;
				open.textContent = step.action || open.textContent;
			} else {
				open.hidden = true;
				open.removeAttribute( 'href' );
			}
			persist( 'progress', current );
		}

		back.addEventListener( 'click', function () {
			if ( current > 0 ) { current--; renderStep(); }
		} );
		next.addEventListener( 'click', function () {
			if ( current < steps.length - 1 ) { current++; renderStep(); return; }
			persist( 'complete', current );
			closeTour( false );
		} );
		dismiss.addEventListener( 'click', function () { closeTour( true ); } );
		root.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) { event.preventDefault(); closeTour( true ); }
		} );

		root.hidden = false;
		persist( 'restart' === mode ? 'restart' : 'start', current );
		renderStep();
		window.setTimeout( function () { next.focus(); }, 0 );
	}

	function initPluginLifecycleDialog() {
		var dialog = document.getElementById( 'certloom-plugin-lifecycle-dialog' );
		if ( ! dialog ) { return; }
		var pluginFile = dialog.getAttribute( 'data-certloom-plugin-file' ) || '';
		if ( ! pluginFile ) { return; }
		var row = Array.prototype.find.call( document.querySelectorAll( 'tr[data-plugin]' ), function ( candidate ) { return candidate.getAttribute( 'data-plugin' ) === pluginFile; } ) || null;
		var deactivate = row ? row.querySelector( '.deactivate a' ) : null;
		var redirect = dialog.querySelector( '[data-certloom-deactivate-redirect]' );
		var firstChoice = dialog.querySelector( 'input[name="delete_data_on_uninstall"]' );
		if ( ! deactivate || ! redirect ) { return; }
		deactivate.addEventListener( 'click', function ( event ) {
			event.preventDefault(); redirect.value = deactivate.href;
			if ( 'function' === typeof dialog.showModal ) { dialog.showModal(); if ( firstChoice ) { window.setTimeout( function () { firstChoice.focus(); }, 0 ); } }
			else if ( window.confirm( certloomText( 'deactivateVerificationWarning', 'Deactivating Certloom stops public credential verification. Continue?' ) ) ) { window.location.href = deactivate.href; }
		} );
		Array.prototype.forEach.call( dialog.querySelectorAll( '[data-certloom-dialog-close]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog.open ) { dialog.close(); } } ); } );
		dialog.addEventListener( 'click', function ( event ) { if ( event.target === dialog ) { dialog.close(); } } );
	}


	function initCredentialLiveSearch() {
		var form = document.querySelector( 'form[data-certloom-credential-live-filter]' );
		var results = document.querySelector( '[data-certloom-credential-results]' );
		var status = document.querySelector( '[data-certloom-credential-live-status]' );
		if ( ! form || ! results || ! certloomL10n.restCredentialsUrl ) { return; }
		var search = form.querySelector( 'input[name="s"]' );
		var program = form.querySelector( 'select[name="program_id"]' );
		var statusInput = form.querySelector( 'input[name="credential_status"]' );
		var orderby = form.querySelector( 'input[name="orderby"]' );
		var order = form.querySelector( 'input[name="order"]' );
		var timer = null;
		var sequence = 0;
		var controller = null;

		function setStatus( message ) { if ( status ) { status.textContent = message || ''; } }
		function currentParams( overrides ) {
			var params = {
				s: search ? search.value.trim() : '',
				credential_status: statusInput ? statusInput.value : 'all',
				program_id: program ? program.value || '0' : '0',
				orderby: orderby ? orderby.value || 'recent' : 'recent',
				order: order ? order.value || 'desc' : 'desc',
				paged: '1'
			};
			Object.keys( overrides || {} ).forEach( function ( key ) { params[ key ] = String( overrides[ key ] ); } );
			return params;
		}
		function updateBrowserUrl( params ) {
			var url = new URL( window.location.href );
			[ 's', 'credential_status', 'program_id', 'orderby', 'order', 'paged' ].forEach( function ( key ) { url.searchParams.delete( key ); } );
			url.searchParams.set( 'page', 'certloom-domain-credentials' );
			if ( params.s ) { url.searchParams.set( 's', params.s ); }
			if ( params.credential_status && 'all' !== params.credential_status ) { url.searchParams.set( 'credential_status', params.credential_status ); }
			if ( params.program_id && '0' !== params.program_id ) { url.searchParams.set( 'program_id', params.program_id ); }
			if ( params.orderby && 'recent' !== params.orderby ) { url.searchParams.set( 'orderby', params.orderby ); url.searchParams.set( 'order', params.order || 'desc' ); }
			if ( params.paged && '1' !== params.paged ) { url.searchParams.set( 'paged', params.paged ); }
			window.history.replaceState( {}, '', url.toString() );
		}
		function request( overrides ) {
			var params = currentParams( overrides );
			sequence++;
			var requestId = sequence;
			if ( controller && controller.abort ) { controller.abort(); }
			controller = 'undefined' !== typeof AbortController ? new AbortController() : null;
			var url = new URL( certloomL10n.restCredentialsUrl );
			Object.keys( params ).forEach( function ( key ) { url.searchParams.set( key, params[ key ] ); } );
			results.setAttribute( 'aria-busy', 'true' );
			setStatus( certloomText( 'liveSearchUpdating', 'Updating results…' ) );
			fetch( url.toString(), {
				method: 'GET', credentials: 'same-origin',
				headers: certloomL10n.restNonce ? { 'X-WP-Nonce': certloomL10n.restNonce } : {},
				signal: controller ? controller.signal : undefined
			} ).then( function ( response ) {
				return response.json().then( function ( payload ) {
					if ( ! response.ok ) { throw new Error( payload && payload.message ? payload.message : certloomText( 'liveSearchFailed', 'Live search was unavailable. Use Filter to reload the page.' ) ); }
					return payload;
				} );
			} ).then( function ( payload ) {
				if ( requestId !== sequence ) { return; }
				if ( ! payload || 'string' !== typeof payload.html ) { throw new Error( certloomText( 'liveSearchFailed', 'Live search was unavailable. Use Filter to reload the page.' ) ); }
				results.innerHTML = payload.html;
				results.removeAttribute( 'aria-busy' );
				if ( orderby ) { orderby.value = payload.orderby || params.orderby; }
				if ( order ) { order.value = payload.order || params.order; }
				updateBrowserUrl( params );
				setStatus( certloomText( 'liveSearchUpdated', 'Results updated.' ) );
			} ).catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) { return; }
				if ( requestId !== sequence ) { return; }
				results.removeAttribute( 'aria-busy' );
				setStatus( error && error.message ? error.message : certloomText( 'liveSearchFailed', 'Live search was unavailable. Use Filter to reload the page.' ) );
			} );
		}
		function schedule() {
			if ( timer ) { window.clearTimeout( timer ); }
			timer = window.setTimeout( function () { request( { paged: '1' } ); }, 260 );
		}
		if ( search ) { search.addEventListener( 'input', schedule ); }
		if ( program ) { program.addEventListener( 'change', function () { request( { paged: '1' } ); } ); }
		form.addEventListener( 'submit', function ( event ) { event.preventDefault(); request( { paged: '1' } ); } );
		var clear = form.querySelector( '.button-link' );
		if ( clear ) {
			clear.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( search ) { search.value = ''; }
				if ( program ) { program.value = '0'; program.dispatchEvent( new Event( 'change', { bubbles: true } ) ); }
				else { request( { paged: '1', s: '', program_id: '0' } ); }
			} );
		}
		results.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '.certloom-sort-link, .tablenav-pages a' );
			if ( ! link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) { return; }
			var target = new URL( link.href, window.location.origin );
			if ( 'certloom-domain-credentials' !== target.searchParams.get( 'page' ) ) { return; }
			event.preventDefault();
			var next = {
				paged: target.searchParams.get( 'paged' ) || '1',
				orderby: target.searchParams.get( 'orderby' ) || 'recent',
				order: target.searchParams.get( 'order' ) || 'desc'
			};
			if ( orderby ) { orderby.value = next.orderby; }
			if ( order ) { order.value = next.order; }
			request( next );
		} );
	}


	function initProductSubnavPlacement() {
		var nav = document.querySelector( '.certloom-product-subnav[data-certloom-product-subnav]' );
		if ( ! nav ) { return; }
		var shell = document.querySelector( '.ecapla-certverify-admin-wrap.certloom-admin-shell, .certloom-admin-shell, .ecapla-certverify-admin-wrap' );
		if ( ! shell ) { return; }
		var hero = shell.querySelector( ':scope > .certloom-page-hero, :scope > .certloom-dashboard-hero, :scope > .certloom-workspace-hero' );
		if ( ! hero ) { return; }
		if ( nav.previousElementSibling !== hero ) { hero.insertAdjacentElement( 'afterend', nav ); }
		nav.classList.add( 'is-below-hero' );
	}

	function initDescriptionAssociations() {
		var descriptions = document.querySelectorAll( '.ecapla-certverify-admin-wrap p.description, .certloom-admin-shell p.description' );
		var serial = 0;
		Array.prototype.forEach.call( descriptions, function ( description ) {
			var container = description.closest( 'td, label, fieldset, .certloom-domain-field, .certloom-form-field, .certloom-manual-fieldset, .certloom-premium-dialog, .ecapla-certverify-admin-card' );
			if ( ! container ) { return; }
			var controls = container.querySelectorAll( 'input:not([type="hidden"]), select, textarea' );
			if ( 1 !== controls.length ) { return; }
			var control = controls[ 0 ];
			if ( ! description.id ) { serial++; description.id = 'certloom-description-' + serial; }
			var describedBy = ( control.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( Boolean );
			if ( describedBy.indexOf( description.id ) === -1 ) { describedBy.push( description.id ); control.setAttribute( 'aria-describedby', describedBy.join( ' ' ) ); }
		} );
	}

	function initCopyBlocks() {
		var l10n = window.certloomAdminL10n || {};
		var blocks = document.querySelectorAll( '.ecapla-certverify-code-copy' );
		Array.prototype.forEach.call( blocks, function ( block ) {
			var code = block.querySelector( 'code' );
			if ( ! code || block.querySelector( '.certloom-copy-button' ) ) { return; }
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button button-small certloom-copy-button';
			button.textContent = l10n.copy || 'Copy';
			button.addEventListener( 'click', function () {
				var text = code.textContent || '';
				var done = function ( ok ) {
					button.textContent = ok ? ( l10n.copied || 'Copied' ) : ( l10n.copyFailed || 'Press Ctrl+C or Cmd+C to copy' );
					window.setTimeout( function () { button.textContent = l10n.copy || 'Copy'; }, 2000 );
				};
				if ( navigator.clipboard && window.isSecureContext ) { navigator.clipboard.writeText( text ).then( function () { done( true ); }, function () { done( false ); } ); return; }
				var scratch = document.createElement( 'textarea' ); scratch.value = text; scratch.setAttribute( 'readonly', '' ); scratch.style.position = 'absolute'; scratch.style.left = '-9999px'; document.body.appendChild( scratch ); scratch.select();
				var ok = false; try { ok = document.execCommand( 'copy' ); } catch ( error ) { ok = false; } document.body.removeChild( scratch ); done( ok );
			} );
			block.appendChild( button );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initUsageProfilePresentation();
		initCopyBlocks();
		initProductSubnavPlacement();
		initDescriptionAssociations();
		initCertloomWordPressMenuState();
		initHumanFormValidation();
		initPremiumDialogs();
		initRecipientCompose();
		initPhase2Issuance();
		initManualRepeatingData();
		initDomainEntityFormFeedback();
		initDomainFormPersistence();
		initDomainMediaAndColorControls();
		initScopedFieldEditors();
		initTemplateCardPublishing();
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
		initGuidedDashboardTour();
		initSetupNumberingPreview();
		initWizardTemplatePreview();
		initGoogleDrivePopupConnect();
		initPluginLifecycleDialog();
		initScopedContextLookups();
		initProgramBatchSelectors();
		initScopedFieldForms();
		initIssueDesignPreview();
		initBulkLifecycle();
		initBulkDesignSource();
		initCredentialLiveSearch();
	} );
} )();
