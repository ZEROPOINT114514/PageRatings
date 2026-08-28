/*!
 * PageRating — voters disclosure for Special:ViewRatings.
 * Clicking a bucket count (count > 0) fetches and shows the list of
 * users who voted that value on that page. Counts of 0 are plain text
 * and not clickable (the server doesn't even render a link for them).
 */
( function () {
	'use strict';

	function closeAllPanels() {
		document.querySelectorAll( '.pagerating-voters-panel' ).forEach( function ( p ) {
			p.remove();
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var link = e.target && e.target.closest
			? e.target.closest( '.pagerating-voters' )
			: null;

		if ( !link ) {
			// Click outside any panel → close them all.
			if ( !( e.target && e.target.closest && e.target.closest( '.pagerating-voters-panel' ) ) ) {
				closeAllPanels();
			}
			return;
		}

		e.preventDefault();

		var cell = link.parentElement;
		var pageid = link.getAttribute( 'data-pageid' );
		var value = link.getAttribute( 'data-value' );

		// Already open → toggle closed.
		var existing = cell.querySelector( '.pagerating-voters-panel' );
		if ( existing ) {
			existing.remove();
			return;
		}

		closeAllPanels();

		var panel = document.createElement( 'div' );
		panel.className = 'pagerating-voters-panel';
		panel.textContent = '加载中…';
		cell.appendChild( panel );

		new mw.Api().get( {
			action: 'voters',
			pageid: pageid,
			value: value
		} ).done( function ( res ) {
			var users = ( res && res.voters && res.voters.users ) || [];
			panel.innerHTML = '';
			if ( !users.length ) {
				panel.textContent = '（无）';
				return;
			}
			var ul = document.createElement( 'ul' );
			users.forEach( function ( name ) {
				var li = document.createElement( 'li' );
				li.textContent = name;
				ul.appendChild( li );
			} );
			panel.appendChild( ul );
		} ).fail( function () {
			panel.textContent = '加载失败';
		} );
	} );
} )();
