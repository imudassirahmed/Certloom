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

	document.addEventListener( 'DOMContentLoaded', function () {
		initFields();
		initConfirmations();
		initBulkOperationFields();
		initSecondaryCodeGenerator();
		initDomainKeyValueLists();
	} );
} )();

( function () {
	'use strict';
	var form = document.querySelector( 'form[data-certverify-auto-continue="1"]' );
	if ( ! form ) {
		return;
	}
	window.setTimeout( function () {
		if ( form.requestSubmit ) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}, 550 );
} )();
