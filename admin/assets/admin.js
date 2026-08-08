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

	document.addEventListener( 'DOMContentLoaded', function () {
		initFields();
		initConfirmations();
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
