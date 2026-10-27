( function () {
	'use strict';

	// Confirmation for destructive links and forms.
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( 'a[data-confirm]' );
		if ( a && ! window.confirm( a.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( f.matches( 'form[data-confirm]' ) && ! window.confirm( f.getAttribute( 'data-confirm' ) ) ) {
			e.preventDefault();
		}
	} );

	// 2FA enrollment QR code, drawn locally (no third-party service sees the secret).
	window.addEventListener( 'load', function () {
		var el = document.getElementById( 'wr-qr' );
		if ( ! el || typeof window.qrcode !== 'function' ) {
			return;
		}
		var qr = window.qrcode( 0, 'M' );
		qr.addData( el.getAttribute( 'data-uri' ) );
		qr.make();
		el.innerHTML = qr.createSvgTag( { cellSize: 5, margin: 4, scalable: false } );
	} );

	// Chunked import with progress.
	var box = document.getElementById( 'wr-import' );
	if ( ! box || box.getAttribute( 'data-status' ) !== 'pending' ) {
		return;
	}
	var bar = box.querySelector( '.wr-progress__bar' );
	var status = box.querySelector( '.wr-import-status' );
	var errors = box.querySelector( '.wr-import-errors' );
	var retries = 0;

	function render( d ) {
		[ 'processed', 'inserted', 'duplicates', 'invalid' ].forEach( function ( k ) {
			var el = box.querySelector( '[data-k="' + k + '"]' );
			if ( el ) {
				el.textContent = d[ k ];
			}
		} );
		bar.style.width = ( d.total ? Math.round( ( 100 * d.processed ) / d.total ) : 100 ) + '%';
		errors.innerHTML = '';
		d.errors.forEach( function ( msg ) {
			var li = document.createElement( 'li' );
			li.textContent = msg;
			errors.appendChild( li );
		} );
	}

	function step() {
		var body = new FormData();
		body.append( 'action', 'wr_import_chunk' );
		body.append( '_ajax_nonce', WR_ADMIN.nonce );
		body.append( 'batch', box.getAttribute( 'data-batch' ) );
		status.textContent = 'Εισαγωγή σε εξέλιξη… Μην κλείσετε τη σελίδα.';
		fetch( WR_ADMIN.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( res ) {
				if ( ! res.success ) {
					if ( res.data && res.data.message ) {
						retries = 99;
						status.textContent = res.data.message;
					}
					throw new Error( 'server' );
				}
				retries = 0;
				render( res.data );
				if ( res.data.status === 'pending' ) {
					step();
				} else if ( res.data.status === 'done' ) {
					status.textContent = 'Η εισαγωγή ολοκληρώθηκε.';
					box.classList.add( 'is-done' );
				} else {
					status.textContent = 'Η εισαγωγή απέτυχε.';
				}
			} )
			.catch( function () {
				if ( retries >= 99 ) {
					return;
				}
				if ( retries++ < 3 ) {
					setTimeout( step, 2000 * retries );
				} else {
					status.textContent = 'Διακοπή σύνδεσης. Ανανεώστε τη σελίδα για να συνεχίσει η εισαγωγή από εκεί που σταμάτησε.';
				}
			} );
	}
	step();
} )();
