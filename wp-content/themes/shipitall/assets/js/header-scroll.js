( function () {
	'use strict';

	var topbar = document.querySelector( '.site-topbar' );

	if ( ! topbar ) {
		return;
	}

	var toggle = function () {
		topbar.classList.toggle( 'is-scrolled', window.scrollY > 4 );
	};

	toggle();
	window.addEventListener( 'scroll', toggle, { passive: true } );
} )();
