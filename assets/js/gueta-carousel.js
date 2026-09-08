/**
 * Product carousels on a phone.
 *
 * The "you might like" rail belongs to a third party widget, and it is set to
 * one product per view on a narrow screen. One product filling the width says
 * nothing about there being more behind it, so the rail looks like a single
 * card and nobody swipes.
 *
 * Showing one and a half puts the edge of the next card on screen, which is
 * the whole message: there is more this way.
 *
 * The widget drives Swiper, which writes its own width onto every slide, so
 * asking in CSS would only start a fight it recalculates its way out of on the
 * next resize. This asks Swiper itself and lets it do the arithmetic.
 */
(function () {
	'use strict';

	var settings = window.guetaCarousel || {};
	var breakpoint = settings.breakpoint || 1024;
	var perView = settings.perView || 1.5;
	var gap = settings.gap || 12;

	function narrow() {
		return window.matchMedia('(max-width: ' + breakpoint + 'px)').matches;
	}

	/**
	 * Ask one carousel for a different number of slides.
	 *
	 * Swiper keeps the number it was built with, so the original is kept and
	 * put back when the window grows, rather than being guessed at later.
	 */
	function apply(element) {
		var swiper = element.swiper;

		if (!swiper || !swiper.params) {
			return;
		}

		if (undefined === element.guetaOriginal) {
			element.guetaOriginal = {
				perView: swiper.params.slidesPerView,
				gap: swiper.params.spaceBetween
			};
		}

		var wanted = narrow() ? perView : element.guetaOriginal.perView;
		var wantedGap = narrow() ? gap : element.guetaOriginal.gap;

		if (swiper.params.slidesPerView === wanted && swiper.params.spaceBetween === wantedGap) {
			return;
		}

		swiper.params.slidesPerView = wanted;
		swiper.params.spaceBetween = wantedGap;

		// The widget sets its counts per breakpoint, and those win on update.
		if (swiper.params.breakpoints && swiper.params.breakpoints[0]) {
			swiper.params.breakpoints[0].slidesPerView = narrow() ? perView : element.guetaOriginal.perView;
			swiper.params.breakpoints[0].spaceBetween = wantedGap;
		}

		swiper.update();
	}

	function scan() {
		var rails = document.querySelectorAll('.smart-products-swiper');

		Array.prototype.forEach.call(rails, apply);
	}

	scan();

	// The widget initialises Swiper on its own schedule, so look again shortly.
	window.setTimeout(scan, 600);
	window.setTimeout(scan, 1800);

	var resizeTimer = null;

	window.addEventListener('resize', function () {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(scan, 200);
	});
}());
