/* Corpmerch hero slider — vanilla, no deps. Autoplay + prev/next + dots + swipe. */
(function () {
	'use strict';
	var root = document.querySelector('.cm-heroslider');
	if (!root) { return; }
	var slides = Array.prototype.slice.call(root.querySelectorAll('.cm-heroslide'));
	if (slides.length < 2) { return; } // single slide: nothing to drive

	var dots  = Array.prototype.slice.call(root.querySelectorAll('.cm-heroslider__dot'));
	var prev  = root.querySelector('.cm-heroslider__prev');
	var next  = root.querySelector('.cm-heroslider__next');
	var i = 0, timer = null;
	var autoplay = root.getAttribute('data-autoplay') === '1';
	var interval = parseInt(root.getAttribute('data-interval'), 10) || 6000;

	function show(n) {
		i = (n + slides.length) % slides.length;
		slides.forEach(function (s, idx) { s.classList.toggle('is-active', idx === i); });
		dots.forEach(function (d, idx) { d.classList.toggle('is-active', idx === i); });
	}
	function nextSlide() { show(i + 1); }
	function prevSlide() { show(i - 1); }

	function start() { if (autoplay) { stop(); timer = setInterval(nextSlide, interval); } }
	function stop()  { if (timer) { clearInterval(timer); timer = null; } }

	if (next) { next.addEventListener('click', function () { nextSlide(); start(); }); }
	if (prev) { prev.addEventListener('click', function () { prevSlide(); start(); }); }
	dots.forEach(function (d) {
		d.addEventListener('click', function () { show(parseInt(d.getAttribute('data-i'), 10) || 0); start(); });
	});

	// Pause on hover (desktop) and when tab hidden.
	root.addEventListener('mouseenter', stop);
	root.addEventListener('mouseleave', start);
	document.addEventListener('visibilitychange', function () { document.hidden ? stop() : start(); });

	// Touch swipe.
	var x0 = null;
	root.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; stop(); }, { passive: true });
	root.addEventListener('touchend', function (e) {
		if (x0 === null) { return; }
		var dx = e.changedTouches[0].clientX - x0;
		if (Math.abs(dx) > 40) { dx < 0 ? nextSlide() : prevSlide(); }
		x0 = null; start();
	}, { passive: true });

	show(0);
	start();
})();
