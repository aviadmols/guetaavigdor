/**
 * Single product page: gallery switching, the zoomable lightbox and the sticky
 * add to cart bar.
 *
 * The lightbox feeds from whichever gallery the page has: the theme's own
 * [data-gallery], or the shop's Elementor shortcode gallery
 * (#smart-main-product-image with .thumbnail-item[data-full-src]).
 */
(function () {
	'use strict';

	/* ---------------------------------------------------------------------
	 * Gallery and lightbox
	 * ------------------------------------------------------------------ */

	function galleryAndLightbox() {
		var lightbox = document.querySelector('[data-lightbox]');

		if (!lightbox) {
			return;
		}

		var image = lightbox.querySelector('[data-lightbox-image]');
		var stage = lightbox.querySelector('[data-lightbox-stage]');
		var prev = lightbox.querySelector('[data-lightbox-prev]');
		var next = lightbox.querySelector('[data-lightbox-next]');
		var close = lightbox.querySelector('[data-lightbox-close]');
		var sources = [];
		var index = 0;
		var opener = null;
		var onSelect = null;

		var gallery = document.querySelector('[data-gallery]');
		var smartMain = document.getElementById('smart-main-product-image');

		if (gallery) {
			var slides = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-slide]'));
			var thumbs = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-thumb]'));

			sources = slides.map(function (slide) {
				return {
					full: slide.getAttribute('data-full') || '',
					alt: (slide.querySelector('img') || {}).alt || ''
				};
			});

			onSelect = function (i) {
				slides.forEach(function (slide, n) {
					slide.classList.toggle('is-active', n === i);
				});

				thumbs.forEach(function (thumb, n) {
					thumb.classList.toggle('is-active', n === i);
					thumb.setAttribute('aria-selected', String(n === i));
				});
			};

			thumbs.forEach(function (thumb, i) {
				thumb.addEventListener('click', function () {
					select(i);
				});
			});

			slides.forEach(function (slide, i) {
				slide.addEventListener('click', function () {
					select(i);
					openLightbox();
				});
			});
		} else if (smartMain) {
			var smartThumbs = Array.prototype.slice.call(
				document.querySelectorAll('.smart-gallery-thumbnails .thumbnail-item[data-full-src]')
			);

			sources = smartThumbs.length
				? smartThumbs.map(function (thumb) {
					return { full: thumb.getAttribute('data-full-src'), alt: smartMain.alt || '' };
				})
				: [{ full: smartMain.currentSrc || smartMain.src, alt: smartMain.alt || '' }];

			smartMain.classList.add('gueta-zoomable');
			smartMain.setAttribute('title', 'לחצו להגדלה');

			smartMain.addEventListener('click', function () {
				// Open on whichever image the shortcode currently shows.
				var current = smartMain.getAttribute('src') || '';
				var found = sources.findIndex(function (source) {
					return source.full === current;
				});

				select(found >= 0 ? found : 0);
				openLightbox();
			});
		}

		if (!sources.length) {
			return;
		}

		function select(i) {
			index = (i + sources.length) % sources.length;

			if (onSelect) {
				onSelect(index);
			}
		}

		function unzoom() {
			lightbox.classList.remove('is-zoomed');
		}

		function show() {
			var source = sources[index];

			unzoom();
			image.src = source.full;
			image.alt = source.alt;

			prev.hidden = sources.length < 2;
			next.hidden = sources.length < 2;
		}

		function openLightbox() {
			opener = document.activeElement;
			show();
			lightbox.hidden = false;
			document.body.classList.add('gueta-locked');
			close.focus();
		}

		function closeLightbox() {
			lightbox.hidden = true;
			unzoom();
			document.body.classList.remove('gueta-locked');

			if (opener && opener.focus) {
				opener.focus();
				opener = null;
			}
		}

		function step(delta) {
			select(index + delta);
			show();
		}

		prev.addEventListener('click', function () {
			step(-1);
		});

		next.addEventListener('click', function () {
			step(1);
		});

		close.addEventListener('click', closeLightbox);

		// Click the image to zoom, click the surrounding stage to dismiss.
		image.addEventListener('click', function (event) {
			event.stopPropagation();
			lightbox.classList.toggle('is-zoomed');
		});

		stage.addEventListener('click', function () {
			if (lightbox.classList.contains('is-zoomed')) {
				unzoom();
				return;
			}

			closeLightbox();
		});

		document.addEventListener('keydown', function (event) {
			if (lightbox.hidden) {
				return;
			}

			if ('Escape' === event.key) {
				closeLightbox();
			} else if ('ArrowLeft' === event.key) {
				step(1);
			} else if ('ArrowRight' === event.key) {
				step(-1);
			}
		});

		// Swiping the stage moves between images.
		var touchX = null;

		stage.addEventListener('touchstart', function (event) {
			touchX = event.changedTouches[0].clientX;
		}, { passive: true });

		stage.addEventListener('touchend', function (event) {
			if (null === touchX || lightbox.classList.contains('is-zoomed')) {
				return;
			}

			var delta = event.changedTouches[0].clientX - touchX;

			if (Math.abs(delta) > 50) {
				step(delta > 0 ? -1 : 1);
			}

			touchX = null;
		}, { passive: true });
	}

	galleryAndLightbox();

	// Quick view injects a fresh gallery, so bind to that one too.
	document.addEventListener('gueta:quickview-rendered', galleryAndLightbox);

	/* ---------------------------------------------------------------------
	 * Sticky add to cart
	 * ------------------------------------------------------------------ */

	(function stickyAddToCart() {
		var bar = document.querySelector('[data-sticky-atc]');
		var form = document.querySelector('form.cart');

		if (!bar || !form) {
			return;
		}

		var button = bar.querySelector('[data-sticky-add]');
		var label = button.textContent;

		bar.hidden = false;

		// Show the bar only once the real add to cart button has scrolled away.
		if ('IntersectionObserver' in window) {
			var observer = new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					bar.classList.toggle('is-visible', !entry.isIntersecting && entry.boundingClientRect.top < 0);
				});
			}, { rootMargin: '0px 0px -40% 0px' });

			observer.observe(form);
		} else {
			bar.classList.add('is-visible');
		}

		button.addEventListener('click', function () {
			// Reuse the real form so variations, quantity and validation apply.
			var submit = form.querySelector('button[type="submit"], input[type="submit"], .single_add_to_cart_button');

			if (submit && !submit.disabled) {
				submit.click();
				return;
			}

			if (form.requestSubmit) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		});

		// Mirror the price of the chosen variation.
		if (window.jQuery) {
			var price = bar.querySelector('[data-sticky-price]');

			window.jQuery(form).on('show_variation', function (event, variation) {
				if (price && variation && variation.price_html) {
					price.innerHTML = variation.price_html;
				}
			});

			window.jQuery(document.body).on('added_to_cart', function () {
				button.textContent = 'נוסף לעגלה';
				button.classList.add('is-added');

				window.setTimeout(function () {
					button.textContent = label;
					button.classList.remove('is-added');
				}, 2200);
			});
		}
	}());
}());

/**
 * Variation swatches.
 *
 * The buttons drive WooCommerce's own <select>, which stays the source of
 * truth, and mirror back whatever it reports: the chosen option and any
 * combination it has marked unavailable.
 */
(function () {
	'use strict';

	function selectFor(group) {
		var native = group.parentElement && group.parentElement.querySelector('.gueta-swatches__native select');

		return native || null;
	}

	function sync(group) {
		var select = selectFor(group);

		if (!select) {
			return;
		}

		var available = {};

		Array.prototype.forEach.call(select.options, function (option) {
			if (option.value) {
				available[option.value] = !option.disabled;
			}
		});

		Array.prototype.forEach.call(group.querySelectorAll('[data-swatch]'), function (button) {
			var value = button.getAttribute('data-swatch');
			var chosen = select.value === value;

			button.classList.toggle('is-selected', chosen);
			button.setAttribute('aria-pressed', String(chosen));
			// Only disable what the select itself offers and marks unavailable.
			button.disabled = Object.prototype.hasOwnProperty.call(available, value) && !available[value];
		});
	}

	function syncAll() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-swatches]'), sync);
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-swatch]');

		if (!button || button.disabled) {
			return;
		}

		event.preventDefault();

		var group = button.closest('[data-swatches]');
		var select = group && selectFor(group);

		if (!select) {
			return;
		}

		var value = button.getAttribute('data-swatch');

		// Clicking the chosen one again clears it, as the reset link would.
		select.value = select.value === value ? '' : value;

		if (window.jQuery) {
			window.jQuery(select).trigger('change');
		} else {
			select.dispatchEvent(new Event('change', { bubbles: true }));
		}
	});

	document.addEventListener('change', function (event) {
		if (event.target.matches('.gueta-swatches__native select')) {
			syncAll();
		}
	});

	if (window.jQuery) {
		// WooCommerce recalculates availability on these.
		window.jQuery(document.body).on(
			'woocommerce_update_variation_values check_variations found_variation reset_data',
			function () {
				window.setTimeout(syncAll, 0);
			}
		);
	}

	document.addEventListener('gueta:quickview-rendered', syncAll);
	syncAll();
}());
