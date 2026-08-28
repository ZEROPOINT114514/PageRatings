/*!
 * PageRating client-side controller.
 * Loaded via ResourceLoader module "ext.pageRating.scripts".
 *
 *   - Hooks into <div class="pagerating-widget" data-pageid="…"> elements.
 *   - Toggles the collapsed/expanded states, calls the action=vote API.
 *
 * Dependencies: jQuery (mw.config, mw.Api are available by default).
 */
( function () {
	'use strict';

	/** Internal vote weights used by the API. Mirrors Store::VOTE_* */
	var VALUES = {
		positive1: 2,
		positive05: 1,
		zero: 0,
		negative05: -1,
		negative1: -2,
		cancel: 100
	};

	function $( root, selector ) {
		return root.querySelector( selector );
	}

	function ce( tag, attrs, html ) {
		var el = document.createElement( tag );
		if ( attrs ) {
			for ( var k in attrs ) {
				if ( k === 'class' ) {
					el.className = attrs[ k ];
				} else if ( k === 'text' ) {
					el.textContent = attrs[ k ];
				} else {
					el.setAttribute( k, attrs[ k ] );
				}
			}
		}
		if ( html !== undefined ) {
			el.innerHTML = html;
		}
		return el;
	}

	function getSelectedValue( widget ) {
		var checked = widget.querySelector( 'input.pagerating-option__radio:checked' );
		return checked ? checked.value : null;
	}

	function maxBucket( buckets ) {
		var max = 1;
		for ( var k in buckets ) {
			if ( buckets[ k ] > max ) {
				max = buckets[ k ];
			}
		}
		return max;
	}

	function renderBars( widget, stats ) {
		if ( !stats ) {
			return;
		}
		var max = Math.max( maxBucket( stats.buckets ), 1 );
		widget.querySelectorAll( '.pagerating-option' ).forEach( function ( li ) {
			var key = li.getAttribute( 'data-bucket' );
			var count = ( stats.buckets[ key ] || 0 );
			var fill = li.querySelector( '.pagerating-option__bar-fill' );
			if ( fill ) {
				fill.style.setProperty( '--pr-bar-fill', ( count / max ).toString() );
			}
			var counter = li.querySelector( '.pagerating-option__count' );
			if ( counter ) {
				counter.textContent = String( count );
			}
		} );
		widget.querySelector( '.pagerating-meta__total' ).textContent = String( stats.total );
	}

	function setStatus( widget, msg ) {
		var el = widget.querySelector( '.pagerating-meta__created' );
		if ( el ) {
			el.innerHTML = msg;
		}
	}

	function sendVote( widget, value ) {
		var pageId = widget.getAttribute( 'data-pageid' );
		if ( !pageId ) {
			return;
		}

		// Disable the button + show spinner
		var btn = widget.querySelector( '.pagerating-button' );
		var oldLabel = btn.textContent;
		btn.setAttribute( 'disabled', 'disabled' );
		btn.classList.add( 'is-busy' );
		btn.setAttribute( 'data-label-busy', oldLabel );
		btn.textContent = '投票中...';

		var api = new mw.Api();
		api.postWithToken( 'csrf', {
			action: 'vote',
			pageid: parseInt( pageId, 10 ),
			value: parseInt( value, 10 )
		} ).done( function ( res ) {
			console.log( '[PageRating] vote response:', res );
			// Judge success by the presence of `stats` (not `ok`, which
			// older MediaWiki serializes as '' = falsy).
			if ( res && res.vote && res.vote.stats ) {
				console.log( '[PageRating] ok=true, hard-reloading…' );
				// Schedule the refresh FIRST so no DOM exception below can
				// ever prevent it. Plain location.href navigation — the
				// exact mechanism the wiki's dark-mode toggle already uses
				// and which is known to work with the Cosmos skin.
				setTimeout( function () {
					console.log( '[PageRating] refresh firing' );
					var u = new URL( location.href );
					u.searchParams.set( 'rdm', Date.now() );
					u.hash = 'Voting';
					location.href = u.toString();
				}, 80 );

				// The DOM updates below are best-effort — wrapped so a
				// rendering quirk in the skin can't break the refresh path.
				try {
					renderBars( widget, res.vote.stats );

					// Sync "what the user just voted" into the widget DOM.
					var isCancel = ( value === '100' );
					var newCurrent = isCancel ? null : parseInt( value, 10 );
					widget.__pageratingCurrent = newCurrent;

					// Tick the matching radio, untick the others.
					widget.querySelectorAll( '.pagerating-option__radio' ).forEach( function ( r ) {
						r.checked = ( newCurrent !== null && parseInt( r.value, 10 ) === newCurrent );
					} );

					// Toggle the per-option --selected class so the
					// highlighted row matches the actual vote.
					widget.querySelectorAll( '.pagerating-option' ).forEach( function ( li ) {
						var bucket = parseInt( li.getAttribute( 'data-bucket' ) || '0', 10 );
						li.classList.toggle( 'pagerating-option--selected', bucket === newCurrent );
					} );

					// Enter or leave the "voted" visual state.
					if ( newCurrent === null ) {
						widget.classList.remove( 'pagerating-widget--voted' );
					} else {
						widget.classList.add( 'pagerating-widget--voted' );
					}

					if ( isCancel ) {
						setStatus( widget, '已取消投票' );
					} else {
						setStatus( widget, '已投票' );
					}
					widget.dispatchEvent( new CustomEvent( 'pagerating:afterVote', {
						bubbles: true,
						detail: { pageId: pageId, value: value, stats: res.vote.stats }
					} ) );
				} catch ( _e ) {
					// Ignore DOM patching failures — the scheduled refresh
					// above will show the correct state anyway.
				}
			}
		} ).fail( function ( code, result ) {
			// Show the REAL error instead of a vague "网络错误", so failures
			// are diagnosable: prefer the API's info text, else its code,
			// else the HTTP-level code mw.Api reported (e.g. "http").
			var reason = '';
			if ( result && result.error ) {
				reason = result.error.info || result.error.code || '';
			}
			if ( !reason ) {
				reason = '请求失败(' + ( code || 'unknown' ) + ')';
			}
			setStatus( widget, reason );
		} ).always( function () {
			btn.removeAttribute( 'disabled' );
			btn.classList.remove( 'is-busy' );
			btn.textContent = '投票';
		} );
	}

	function buildOption( widgetId, key, name, count ) {
		var li = document.createElement( 'li' );
		li.className = 'pagerating-option pagerating-option--' + key;
		// data-bucket MUST be the numeric vote weight (matches the server's
		// stats.buckets keys: "-2","-1","0","1","2"), not the semantic key.
		li.setAttribute( 'data-bucket', String( VALUES[ key ] ) );

		var input = document.createElement( 'input' );
		input.type = 'radio';
		// All radio inputs in one widget MUST share the same name so the
		// browser enforces single-selection. Use a per-widget unique name.
		input.name = 'pagerating-' + widgetId;
		input.value = VALUES[ key ];
		input.className = 'pagerating-option__radio';

		var label = document.createElement( 'label' );
		label.className = 'pagerating-option__label';

		var nameSpan = document.createElement( 'span' );
		nameSpan.className = 'pagerating-option__name';
		nameSpan.textContent = name;
		label.appendChild( nameSpan );

		// "取消投票" is an ACTION, not a rating bucket — no bar/counter.
		var isCancel = ( key === 'cancel' );
		if ( !isCancel ) {
			var bar = document.createElement( 'span' );
			bar.className = 'pagerating-option__bar';
			var fill = document.createElement( 'span' );
			fill.className = 'pagerating-option__bar-fill';
			bar.appendChild( fill );

			var counter = document.createElement( 'span' );
			counter.className = 'pagerating-option__count';
			counter.textContent = String( count || 0 );
		}

		// radio + label inside the li; bar/count placed separately by CSS.
		li.appendChild( input );
		li.appendChild( label );
		if ( !isCancel ) {
			li.appendChild( bar );
			li.appendChild( counter );
		}

		return li;
	}

	function renderOptions( widget, pageName, stats, currentVote ) {
		var list = $( widget, '.pagerating-options' );
		list.innerHTML = '';
		var optKeys = [
			[ 'positive1', '+1（出类拔萃）' ],
			[ 'positive05', '+0.5（笔酣墨饱）' ],
			[ 'zero', '0（差强人意）' ],
			[ 'negative05', '-0.5（千篇一律）' ],
			[ 'negative1', '-1（平淡无味）' ],
			[ 'cancel', '取消我的投票' ]
		];
		optKeys.forEach( function ( pair ) {
			var key = pair[ 0 ];
			var bucketKey = String( VALUES[ key ] );
			var li = buildOption( widget.__pageratingWidgetId, key, pair[ 1 ], ( stats && stats.buckets && stats.buckets[ bucketKey ] ) || 0 );
			if ( currentVote !== null && currentVote !== undefined &&
				String( VALUES[ key ] ) === String( currentVote ) ) {
				li.classList.add( 'pagerating-option--selected' );
				li.querySelector( 'input' ).checked = true;
			}
			list.appendChild( li );
		} );

		// Update the heading to include the page name (auto-fetched server-side too,
		// but we re-render in case translation was needed).
		var pageLabel = widget.querySelector( '.pagerating-expanded__heading-page' );
		if ( pageLabel ) {
			pageLabel.textContent = pageName;
		}
	}

	function initWidget( widget ) {
		// Skip if already initialized
		if ( widget.dataset.initialized === '1' ) {
			return;
		}
		widget.dataset.initialized = '1';

		if ( !widget.__pageratingWidgetId ) {
			widget.__pageratingWidgetId = 'w' + Math.random().toString( 36 ).slice( 2, 9 );
		}

		// Read initial state from the server-injected JSON blob, if present.
		var dataEl = widget.querySelector( 'script.pagerating-data' );
		if ( dataEl && !widget.__pageratingStats ) {
			try {
				var payload = JSON.parse( dataEl.textContent );
				widget.__pageratingStats = payload.stats || null;
				widget.__pageratingCurrent = payload.current;
				widget.__pageratingPageName = payload.pageName || '';
				// Show expanded form only if the user already voted.
				if ( payload.current !== null && payload.current !== undefined ) {
					widget.classList.add( 'pagerating-widget--voted' );
					var expanded = widget.querySelector( '.pagerating-expanded' );
					if ( expanded ) {
						expanded.style.display = '';
					}
				}
			} catch ( err ) {
				/* ignore */
			}
		}

		var collapsed = widget.querySelector( '.pagerating-collapsed' );
		var expanded = widget.querySelector( '.pagerating-expanded' );

		function openForm() {
			widget.classList.add( 'pagerating-widget--open' );
			// Move focus to first input for accessibility.
			var firstInput = widget.querySelector( '.pagerating-option__radio' );
			if ( firstInput ) {
				firstInput.focus();
			}
		}

		function closeForm() {
			widget.classList.remove( 'pagerating-widget--open' );
		}

		// EVENT DELEGATION on the widget container.
		// The widget contains TWO .pagerating-collapsed divs (default + hover).
		// On hover the default becomes display:none and the hover div is
		// pointer-events:none, so click events bubble all the way up to the
		// widget container itself. We can't use closest('.pagerating-collapsed')
		// because the click target IS the widget container (not a descendant
		// of .pagerating-collapsed). The correct guard is "click anywhere
		// inside the widget EXCEPT inside the already-open form".
		widget.addEventListener( 'click', function ( e ) {
			if ( e.target && e.target.closest && e.target.closest( '.pagerating-expanded' ) ) {
				// Click was inside the expanded form (e.g. on a radio or the
				// vote button). Let the inner handlers handle it.
				return;
			}
			openForm();
		} );
		widget.addEventListener( 'keydown', function ( e ) {
			if ( ( e.key === 'Enter' || e.key === ' ' ) &&
				e.target && e.target.closest && e.target.closest( '.pagerating-collapsed' ) ) {
				e.preventDefault();
				openForm();
			}
		} );

		if ( collapsed ) {
			// Make the collapsed card focusable for keyboard users.
			if ( !collapsed.hasAttribute( 'tabindex' ) ) {
				collapsed.setAttribute( 'tabindex', '0' );
				collapsed.setAttribute( 'role', 'button' );
			}
		}

		var btn = widget.querySelector( '.pagerating-button' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				var val = getSelectedValue( widget );
				if ( val === null ) {
					setStatus( widget, '请先选择一个选项' );
					return;
				}
				sendVote( widget, val );
			} );
		}

		// Cancel-link (optional)
		var cancelLink = widget.querySelector( '.pagerating-cancel-link' );
		if ( cancelLink ) {
			cancelLink.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeForm();
			} );
		}

		// Initial bars
		var stats = widget.__pageratingStats;
		var current = widget.__pageratingCurrent;
		if ( stats ) {
			renderBars( widget, stats );
		}
		if ( current !== undefined ) {
			renderOptions( widget, widget.__pageratingPageName || '', stats, current );
		}
	}

	function bootstrap() {
		var widgets = document.querySelectorAll( '.pagerating-widget' );
		widgets.forEach( initWidget );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', bootstrap );
	} else {
		bootstrap();
	}

	// Listen to explicit init events from the parser tag (in case the
	// markup is injected after DOMContentLoaded).
	document.addEventListener( 'pagerating:init', function () {
		bootstrap();
	} );

}() );
