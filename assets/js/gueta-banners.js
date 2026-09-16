/**
 * Shop banners: a swipeable slider above the products.
 *
 * Scroll snapping does the moving, so a finger works with no script at all.
 * This adds the arrows, the dots and the timer.
 *
 * Every move is by the distance to the slide, never to an absolute offset. In
 * a right to left track scrollLeft counts from the other edge, and does so
 * differently between browsers, while a distance reads the same both ways.
 */
(function () {
	'use strict';

	Array.prototype.forEach.call(document.querySelectorAll('[data-banners]'), function (slider) {
		var track = slider.querySelector('[data-banners-track]');
		var slides = track ? track.children : [];
		var dots = slider.querySelectorAll('[data-banners-dot]');

		if (slides.length < 2) {
			return;
		}

		var index = 0;
		var timer = null;
		var stopped = false;

		function offset(slide) {
			return slide.getBoundingClientRect().left - track.getBoundingClientRect().left;
		}

		function paint() {
			Array.prototype.forEach.call(dots, function (dot, n) {
				dot.classList.toggle('is-active', n === index);

				if (n === index) {
					dot.setAttribute('aria-current', 'true');
				} else {
					dot.removeAttribute('aria-current');
				}
			});
		}

		function go(position) {
			index = (position + slides.length) % slides.length;
			track.scrollBy({ left: offset(slides[index]), behavior: 'smooth' });
			paint();
		}

		/*
		 * After a swipe, whichever slide ended up in place. Only a slide that
		 * has snapped counts: a pause in the middle of a long smooth scroll
		 * would otherwise be read as the end of it, and the dots would stop on
		 * a slide the track is only passing.
		 */
		var settle = null;

		track.addEventListener('scroll', function () {
			window.clearTimeout(settle);
			settle = window.setTimeout(function () {
				var closest = index;
				var best = Infinity;

				Array.prototype.forEach.call(slides, function (slide, n) {
					var distance = Math.abs(offset(slide));

					if (distance < best) {
						best = distance;
						closest = n;
					}
				});

				if (best <= 2 && closest !== index) {
					index = closest;
					paint();
				}
			}, 90);
		}, { passive: true });

		/* Timer: paused while a pointer or focus is on the slider, and gone for
		   good once somebody moves it themselves. */
		function pause() {
			window.clearInterval(timer);
			timer = null;
		}

		function stop() {
			stopped = true;
			pause();
		}

		function play() {
			var seconds = parseInt(slider.getAttribute('data-autoplay'), 10) || 0;

			if (stopped || timer || seconds <= 0 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
				return;
			}

			timer = window.setInterval(function () {
				// Nothing moves on a tab nobody is looking at.
				if (!document.hidden) {
					go(index + 1);
				}
			}, seconds * 1000);
		}

		slider.addEventListener('click', function (event) {
			var step = event.target.closest('[data-banners-step]');
			var dot = event.target.closest('[data-banners-dot]');

			if (step) {
				stop();
				go(index + parseInt(step.getAttribute('data-banners-step'), 10));
			} else if (dot) {
				stop();
				go(parseInt(dot.getAttribute('data-banners-dot'), 10));
			}
		});

		track.addEventListener('touchstart', stop, { passive: true });
		track.addEventListener('wheel', function (event) {
			if (Math.abs(event.deltaX) > Math.abs(event.deltaY)) {
				stop();
			}
		}, { passive: true });

		slider.addEventListener('mouseenter', pause);
		slider.addEventListener('mouseleave', play);
		slider.addEventListener('focusin', pause);
		slider.addEventListener('focusout', play);

		play();
	});
}());
