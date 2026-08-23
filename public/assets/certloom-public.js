( function () {
	'use strict';

	var initialized = typeof WeakSet === 'function' ? new WeakSet() : null;
	var inFlightRequests = Object.create( null );
	var REQUEST_TIMEOUT_MS = 15000;

	function escapeHtml( value ) {
		var div = document.createElement( 'div' );
		div.textContent = value === null || value === undefined ? '' : String( value );
		// textContent -> innerHTML escapes &, < and > but leaves quotes intact,
		// so quotes are handled here for values that land inside an attribute.
		return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	}

	// Status keys come from the server as a fixed vocabulary. Anything unexpected
	// is dropped rather than reflected into a class attribute.
	function statusModifier( value ) {
		return /^[a-z0-9_-]{1,32}$/.test( String( value || '' ) ) ? String( value ) : 'unknown';
	}

	function statusIcon( value ) {
		var icons = { valid: '✓', suspended: '!', withdrawn: '×', expired: '○' };
		return icons[ statusModifier( value ) ] || '•';
	}

	function getConfig( root ) {
		if ( root.classList.contains( 'cv-widget' ) ) {
			return {
				prefix: 'cv',
				form: '.cv-search-form',
				input: '.cv-search-input',
				button: '.cv-search-btn',
				buttonText: '.cv-btn-text',
				result: '.cv-result'
			};
		}

		return {
			prefix: 'cvx',
			form: '.cvx-search-form',
			input: '.cvx-search-input',
			button: '.cvx-search-btn',
			buttonText: '.cvx-btn-text',
			result: '.cvx-result'
		};
	}

	function renderRows( fields, prefix ) {
		fields = Array.isArray( fields ) ? fields : [];
		return fields.map( function ( field ) {
			var rawValue = field.value === '' ? '—' : field.value;
			var value = field.is_status
				? '<span class="' + prefix + '-badge ' + prefix + '-badge-' + statusModifier( field.status_key ) + '">' + escapeHtml( rawValue ) + '</span>'
				: escapeHtml( rawValue );
			return '<div class="' + prefix + '-result-row"><dt>' + escapeHtml( field.label ) + '</dt><dd>' + value + '</dd></div>';
		} ).join( '' );
	}

	function acquireCertificateRequest( endpoint, number ) {
		var key = endpoint + '\n' + number;
		var entry = inFlightRequests[ key ];

		if ( ! entry ) {
			entry = {
				controller: typeof AbortController === 'function' ? new AbortController() : null,
				consumers: 0,
				settled: false,
				timedOut: false,
				timeoutId: null,
				promise: null
			};
			inFlightRequests[ key ] = entry;
			if ( entry.controller ) {
				entry.timeoutId = window.setTimeout( function () {
					entry.timedOut = true;
					entry.controller.abort();
				}, REQUEST_TIMEOUT_MS );
			}
			entry.promise = fetch( endpoint + '?number=' + encodeURIComponent( number ), {
				headers: { Accept: 'application/json' },
				credentials: 'same-origin',
				signal: entry.controller ? entry.controller.signal : undefined
			} ).then( function ( response ) {
				return response.text().then( function ( body ) {
					var data;
					try {
						data = JSON.parse( body );
					} catch ( error ) {
						throw new Error( 'Certloom received an invalid verification response.' );
					}
					return { ok: response.ok, data: data };
				} );
			} ).then(
				function ( result ) {
					entry.settled = true;
					if ( entry.timeoutId ) {
						window.clearTimeout( entry.timeoutId );
					}
					if ( inFlightRequests[ key ] === entry ) {
						delete inFlightRequests[ key ];
					}
					return result;
				},
				function ( error ) {
					entry.settled = true;
					if ( entry.timeoutId ) {
						window.clearTimeout( entry.timeoutId );
					}
					if ( inFlightRequests[ key ] === entry ) {
						delete inFlightRequests[ key ];
					}
					if ( entry.timedOut ) {
						throw new Error( 'Certloom verification request timed out.' );
					}
					throw error;
				}
			);
		}

		entry.consumers++;
		var released = false;
		return {
			promise: entry.promise,
			release: function () {
				if ( released ) {
					return;
				}
				released = true;
				entry.consumers = Math.max( 0, entry.consumers - 1 );
				if ( 0 === entry.consumers && ! entry.settled && entry.controller && typeof entry.controller.abort === 'function' ) {
					entry.controller.abort();
				}
			}
		};
	}

	function initWidget( root ) {
		if ( ! root || ( initialized && initialized.has( root ) ) ) {
			return;
		}

		var config = getConfig( root );
		var form = root.querySelector( config.form );
		var input = root.querySelector( config.input );
		var button = root.querySelector( config.button );
		var buttonText = root.querySelector( config.buttonText );
		var resultEl = root.querySelector( config.result );
		var endpoint = root.getAttribute( 'data-endpoint' );
		var notFound = root.getAttribute( 'data-notfound-text' ) || 'No certificate found with that number.';
		var foundText = root.getAttribute( 'data-found-text' ) || 'Certificate found.';
		var errorText = root.getAttribute( 'data-error-text' ) || 'Something went wrong. Please try again.';
		var loadingText = root.getAttribute( 'data-loading-text' ) || 'Checking…';
		var requiredText = root.getAttribute( 'data-required-text' ) || 'Enter a certificate number before searching.';
		var originalButtonText = buttonText ? buttonText.textContent : '';
		var requestSequence = 0;
		var activeRequest = null;

		if ( ! form || ! input || ! button || ! resultEl || ! endpoint ) {
			return;
		}
		if ( initialized ) {
			initialized.add( root );
		}

		function setLoading( isLoading ) {
			button.disabled = isLoading;
			button.classList.toggle( config.prefix + '-loading', isLoading );
			root.setAttribute( 'aria-busy', isLoading ? 'true' : 'false' );
			if ( buttonText ) {
				buttonText.textContent = isLoading ? loadingText : originalButtonText;
			}
		}

		function showMessage( message ) {
			resultEl.innerHTML = '<div class="' + config.prefix + '-result-notfound"><p>' + escapeHtml( message ) + '</p></div>';
		}

		function showResult( fields, presentation ) {
			presentation = presentation && 'object' === typeof presentation ? presentation : {};
			var status = presentation.status && 'object' === typeof presentation.status ? presentation.status : {};
			var statusKey = statusModifier( status.key );
			var statusLabel = 'string' === typeof status.label && status.label ? status.label : foundText;
			var statusDescription = 'string' === typeof status.description ? status.description : '';
			var issuer = 'string' === typeof presentation.issuer ? presentation.issuer : '';
			var labels = presentation.labels && 'object' === typeof presentation.labels ? presentation.labels : {};
			var issuedByLabel = 'string' === typeof labels.issued_by && labels.issued_by ? labels.issued_by : 'Issued by';
			var verifiedLabel = 'string' === typeof labels.verified && labels.verified ? labels.verified : 'Verified';
			var rows = renderRows( fields || [], config.prefix );
			var list = rows ? '<dl class="' + config.prefix + '-result-list">' + rows + '</dl>' : '';
			var summary = '<div class="' + config.prefix + '-result-summary"><span class="' + config.prefix + '-status-mark" aria-hidden="true">✓</span><div><p class="' + config.prefix + '-result-success">' + escapeHtml( statusLabel ) + '</p>' +
				( statusDescription ? '<p class="' + config.prefix + '-status-description">' + escapeHtml( statusDescription ) + '</p>' : '' ) +
				( issuer ? '<p class="' + config.prefix + '-result-issuer"><span>' + escapeHtml( issuedByLabel ) + '</span> ' + escapeHtml( issuer ) + '</p>' : '' ) + '</div></div>';
			var verified = '';
			if ( 'string' === typeof presentation.verified_at && presentation.verified_at ) {
				var date = new Date( presentation.verified_at );
				verified = '<p class="' + config.prefix + '-verified-at">' + escapeHtml( verifiedLabel ) + ' ' + escapeHtml( isNaN( date.getTime() ) ? presentation.verified_at : date.toLocaleString() ) + '</p>';
			}
			var actionHtml = '';
			var actions = Array.isArray( presentation.actions ) ? presentation.actions : [];
			actions.forEach( function ( action ) {
				var type = statusModifier( action && action.type );
				var label = action && 'string' === typeof action.label ? action.label : '';
				var url = action && 'string' === typeof action.url ? action.url : '';
				if ( ! label ) { return; }
				if ( 'copy' === type && url ) {
					actionHtml += '<button type="button" class="certloom-result-action ' + config.prefix + '-result-action certloom-copy-link" data-copy-url="' + escapeHtml( url ) + '">' + escapeHtml( label ) + '</button>';
				} else if ( 'print' === type ) {
					actionHtml += '<button type="button" class="certloom-result-action ' + config.prefix + '-result-action certloom-print">' + escapeHtml( label ) + '</button>';
				} else if ( url ) {
					actionHtml += '<a class="certloom-result-action ' + config.prefix + '-result-action" href="' + escapeHtml( url ) + '">' + escapeHtml( label ) + '</a>';
				}
			} );
			var actionBar = actionHtml ? '<div class="' + config.prefix + '-result-actions">' + actionHtml + '<span class="certloom-action-feedback" aria-live="polite"></span></div>' : '';
			resultEl.innerHTML = '<div class="' + config.prefix + '-result-card ' + config.prefix + '-status-' + statusKey + '">' + summary + list + verified + actionBar + '</div>';
		}

		function search( number ) {
			requestSequence++;
			var thisRequest = requestSequence;

			if ( activeRequest ) {
				activeRequest.release();
			}
			activeRequest = null;
			setLoading( false );

			number = ( number || '' ).trim();
			if ( ! number ) {
				input.setAttribute( 'aria-invalid', 'true' );
				showMessage( requiredText );
				input.focus();
				return;
			}
			input.removeAttribute( 'aria-invalid' );

			activeRequest = acquireCertificateRequest( endpoint, number );
			var thisRequestHandle = activeRequest;
			setLoading( true );
			resultEl.innerHTML = '';

			thisRequestHandle.promise
				.then( function ( result ) {
					if ( thisRequest !== requestSequence ) {
						return;
					}
					if ( ! result.ok ) {
						showMessage( result.data && 'string' === typeof result.data.message ? result.data.message : errorText );
						return;
					}
					if ( ! result.data || 'object' !== typeof result.data || 'boolean' !== typeof result.data.found ) {
						throw new Error( 'Certloom received an unexpected verification response.' );
					}
					if ( result.data.found ) {
						if ( ! Array.isArray( result.data.fields ) ) {
							throw new Error( 'Certloom received an incomplete verification response.' );
						}
						showResult( result.data.fields, result.data.presentation );
					} else {
						showMessage( notFound );
					}
				} )
				.catch( function ( error ) {
					if ( error && 'AbortError' === error.name ) {
						return;
					}
					if ( thisRequest === requestSequence ) {
						showMessage( errorText );
					}
				} )
				.finally( function () {
					thisRequestHandle.release();
					if ( thisRequest === requestSequence ) {
						activeRequest = null;
						setLoading( false );
					}
				} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			search( input.value );
		} );

		input.addEventListener( 'input', function () {
			if ( input.value.trim() ) {
				input.removeAttribute( 'aria-invalid' );
			}
		} );

		var direct = root.getAttribute( 'data-direct-number' );
		if ( direct ) {
			input.value = direct;
			if ( '1' !== root.getAttribute( 'data-server-rendered' ) ) {
				search( direct );
			}
		}
	}

	function actionRoot( element ) {
		return element && element.closest ? element.closest( '.cv-widget, .cvx-widget, .cvp-widget' ) : null;
	}

	function actionFeedback( button, message ) {
		var root = actionRoot( button );
		var target = root ? root.querySelector( '.certloom-action-feedback' ) : null;
		if ( target ) { target.textContent = message || ''; }
	}

	function fallbackCopy( text ) {
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		var copied = false;
		try { copied = document.execCommand( 'copy' ); } catch ( error ) { copied = false; }
		document.body.removeChild( area );
		return copied;
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target && event.target.closest ? event.target.closest( '.certloom-copy-link, .certloom-print' ) : null;
		if ( ! target ) { return; }
		if ( target.classList.contains( 'certloom-print' ) ) {
			event.preventDefault();
			window.print();
			return;
		}
		var url = target.getAttribute( 'data-copy-url' ) || '';
		if ( ! url ) { return; }
		event.preventDefault();
		var root = actionRoot( target );
		var successText = root ? root.getAttribute( 'data-copy-success-text' ) : '';
		var errorText = root ? root.getAttribute( 'data-copy-error-text' ) : '';
		var done = function ( ok ) { actionFeedback( target, ok ? ( successText || 'Verification link copied.' ) : ( errorText || 'Copy the verification link from your browser.' ) ); };
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function () { done( true ); }, function () { done( fallbackCopy( url ) ); } );
		} else {
			done( fallbackCopy( url ) );
		}
	} );

	function scan( scope ) {
		var root = scope || document;
		if ( root.matches && root.matches( '.cv-widget, .cvx-widget' ) ) {
			initWidget( root );
		}
		var widgets = root.querySelectorAll ? root.querySelectorAll( '.cv-widget, .cvx-widget' ) : [];
		for ( var i = 0; i < widgets.length; i++ ) {
			initWidget( widgets[ i ] );
		}
	}

	function boot() {
		scan( document );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	window.CertloomInit = scan;

	window.addEventListener( 'elementor/frontend/init', function () {
		if ( ! window.elementorFrontend || ! window.elementorFrontend.hooks ) {
			return;
		}
		[
			'certloom',
			'certloom-meridian',
			'certloom-halo',
			'certloom-axis',
			'certloom-nova',
			'certloom-forge'
		].forEach( function ( widgetName ) {
			window.elementorFrontend.hooks.addAction( 'frontend/element_ready/' + widgetName + '.default', function ( scope ) {
				scan( scope && scope[ 0 ] ? scope[ 0 ] : scope );
			} );
		} );
	} );
} )();
