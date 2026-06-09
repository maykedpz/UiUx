/* Corpmerch — front-end interactions. Vanilla JS, no dependencies. */
(function () {
	'use strict';

	function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
	function qsa(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

	var DESKTOP = '(min-width: 1024px)';

	document.addEventListener('DOMContentLoaded', function () {
		var sidebar  = qs('#cm-sidebar');
		var scrim    = qs('#cm-scrim');
		var openBtn  = qs('#cm-menu-open');
		var closeBtn = qs('#cm-rail-close');

		function isDesktop() {
			return window.matchMedia && window.matchMedia(DESKTOP).matches;
		}

		// Off-canvas sidebar (mobile/tablet). On desktop the rail is persistent
		// so these handlers are effectively inert.
		function openSidebar() {
			if (!sidebar || isDesktop()) return;
			sidebar.classList.add('is-open');
			if (scrim) scrim.classList.add('is-open');
			sidebar.setAttribute('aria-hidden', 'false');
			if (openBtn) openBtn.setAttribute('aria-expanded', 'true');
			document.body.style.overflow = 'hidden';
			var first = qs('a, button', sidebar);
			if (first) first.focus();
		}
		function closeSidebar() {
			if (!sidebar) return;
			sidebar.classList.remove('is-open');
			if (scrim) scrim.classList.remove('is-open');
			sidebar.setAttribute('aria-hidden', 'true');
			if (openBtn) { openBtn.setAttribute('aria-expanded', 'false'); openBtn.focus(); }
			document.body.style.overflow = '';
		}

		if (openBtn) openBtn.addEventListener('click', openSidebar);
		if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
		if (scrim) scrim.addEventListener('click', closeSidebar);

		// Reset any mobile-open state when crossing into the desktop breakpoint.
		if (window.matchMedia) {
			var mq = window.matchMedia(DESKTOP);
			var onChange = function (e) {
				if (e.matches) {
					if (sidebar) { sidebar.classList.remove('is-open'); sidebar.removeAttribute('aria-hidden'); }
					if (scrim) scrim.classList.remove('is-open');
					document.body.style.overflow = '';
				} else if (sidebar) {
					sidebar.setAttribute('aria-hidden', 'true');
				}
			};
			if (mq.addEventListener) { mq.addEventListener('change', onChange); }
			else if (mq.addListener) { mq.addListener(onChange); }
			onChange(mq);
		}

		// Search bar toggle
		var searchToggle = qs('#cm-search-toggle');
		var searchBar = qs('#cm-searchbar');
		if (searchToggle && searchBar) {
			searchToggle.addEventListener('click', function () {
				var open = searchBar.classList.toggle('is-open');
				searchBar.setAttribute('aria-hidden', open ? 'false' : 'true');
				searchToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (open) {
					var input = qs('.cm-searchbar__input', searchBar);
					if (input) input.focus();
				}
			});
			document.addEventListener('keydown', function (e) {
				if (e.key === 'Escape' && searchBar.classList.contains('is-open')) {
					searchBar.classList.remove('is-open');
					searchBar.setAttribute('aria-hidden', 'true');
					searchToggle.setAttribute('aria-expanded', 'false');
					searchToggle.focus();
				}
			});
		}

		// Esc closes the mobile sidebar
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && sidebar && sidebar.classList.contains('is-open')) {
				closeSidebar();
			}
		});

		// Sidebar (rail) submenu accordions — work on every viewport.
		qsa('.cm-rail__toggle').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var sub = btn.parentElement.querySelector('.cm-rail__sub');
				if (!sub) return;
				var isOpen = sub.classList.toggle('is-open');
				btn.classList.toggle('is-open', isOpen);
				btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			});
		});

		// Footer accordions (mobile only; desktop has pointer-events:none)
		qsa('.cm-footer__acc').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var list = btn.parentElement.querySelector('.cm-footer__links');
				if (!list) return;
				var isOpen = list.classList.toggle('is-open');
				btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			});
		});
	});
})();
