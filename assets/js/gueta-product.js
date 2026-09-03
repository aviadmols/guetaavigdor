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

		/*
		 * WooCommerce narrows the select down to the combinations that exist,
		 * dropping the rest of the options rather than disabling them. So a
		 * value the select no longer carries is one that cannot be bought —
		 * unless the select still holds nothing at all, which is how it looks
		 * for the moment before the variation script has had its say.
		 */
		var narrowed = Object.keys(available).length > 0;

		Array.prototype.forEach.call(group.querySelectorAll('[data-swatch]'), function (button) {
			var value = button.getAttribute('data-swatch');
			var chosen = select.value === value;

			button.classList.toggle('is-selected', chosen);
			button.setAttribute('aria-pressed', String(chosen));
			button.disabled = Object.prototype.hasOwnProperty.call(available, value)
				? !available[value]
				: narrowed;
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

		/*
		 * A native event. It reaches the listener below and, because jQuery
		 * binds through addEventListener, WooCommerce's delegated one as well.
		 * jQuery's own trigger() would only reach the second: it runs jQuery
		 * handlers directly and never dispatches anything the DOM can hear,
		 * which is why the buttons used to keep their old state after a click.
		 */
		select.dispatchEvent(new Event('change', { bubbles: true }));
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

/**
 * Buy box.
 *
 * WooCommerce's own form still does the work inside it. This carries the price
 * that form reports over to wherever the page shows a price, drives the
 * quantity steppers, and — in quick view only — adds to the cart without
 * leaving the modal.
 */
(function () {
	'use strict';

	var settings = window.guetaProduct || {};

	/**
	 * Show the image a variation was given, when the gallery already has it.
	 */
	function showGalleryImage(scope, src) {
		var gallery = (scope || document).querySelector('[data-gallery]');

		if (!gallery || !src) {
			return;
		}

		Array.prototype.forEach.call(gallery.querySelectorAll('[data-gallery-slide]'), function (slide, index) {
			if (slide.getAttribute('data-full') !== src) {
				return;
			}

			var thumb = gallery.querySelector('[data-gallery-thumb="' + index + '"]');

			if (thumb) {
				thumb.click();
			}
		});
	}

	function quantityField(wrap) {
		return wrap ? wrap.querySelector('input.qty, input[name="quantity"]') : null;
	}

	function stepQuantity(input, delta) {
		var by = parseFloat(input.getAttribute('step')) || 1;
		var min = parseFloat(input.getAttribute('min'));
		var max = parseFloat(input.getAttribute('max'));
		var value = parseFloat(input.value);

		if (isNaN(min)) {
			min = 0;
		}

		if (isNaN(value)) {
			value = min;
		}

		value = Math.max(min, value + delta * by);

		if (!isNaN(max) && max > 0) {
			value = Math.min(max, value);
		}

		// Keep the precision the step implies: some of these sell by weight.
		var decimals = (String(by).split('.')[1] || '').length;

		input.value = decimals ? value.toFixed(decimals) : String(Math.round(value));
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	// A step that would leave the allowed range is offered as spent, not broken.
	function syncSteps(wrap) {
		var input = quantityField(wrap);

		if (!input) {
			return;
		}

		var min = parseFloat(input.getAttribute('min'));
		var max = parseFloat(input.getAttribute('max'));
		var value = parseFloat(input.value);

		Array.prototype.forEach.call(wrap.querySelectorAll('[data-qty-step]'), function (button) {
			button.disabled = Number(button.getAttribute('data-qty-step')) < 0
				? !isNaN(min) && value <= min
				: !isNaN(max) && max > 0 && value >= max;
		});
	}

	function setup(box) {
		if (box.getAttribute('data-buy-ready')) {
			return;
		}

		box.setAttribute('data-buy-ready', '1');

		var form = box.querySelector('form.cart');

		if (!form) {
			return;
		}

		var own = box.querySelector('[data-buy-price]');
		var stock = box.querySelector('[data-buy-stock]');
		var hint = box.querySelector('[data-buy-hint]');
		var scope = box.closest('.gueta-quickview');
		var mode = box.getAttribute('data-price') || 'auto';
		var prices = [];

		/*
		 * Whatever the page already uses to show the price is what a chosen
		 * variation should change, so the box finds those first and, on auto,
		 * keeps its own out of the way rather than repeating them.
		 */
		Array.prototype.forEach.call(
			(scope || document).querySelectorAll('.elementor-widget-woocommerce-product-price .price, [data-gueta-price]'),
			function (element) {
				if (!box.contains(element)) {
					prices.push(element);
				}
			}
		);

		if (own) {
			own.hidden = 'off' === mode || ('auto' === mode && prices.length > 0);
			prices.push(own);
		}

		var defaults = prices.map(function (element) {
			return element.innerHTML;
		});

		function setPrice(html) {
			prices.forEach(function (element, index) {
				element.innerHTML = html || defaults[index];
			});
		}

		function refreshSteps() {
			Array.prototype.forEach.call(form.querySelectorAll('.quantity'), syncSteps);
		}

		refreshSteps();

		form.addEventListener('click', function (event) {
			var button = event.target.closest('[data-qty-step]');

			if (!button) {
				return;
			}

			var wrap = button.closest('.quantity');
			var input = quantityField(wrap);

			if (input) {
				stepQuantity(input, Number(button.getAttribute('data-qty-step')));
				syncSteps(wrap);
			}
		});

		form.addEventListener('input', function (event) {
			if (event.target.matches('input.qty, input[name="quantity"]')) {
				syncSteps(event.target.closest('.quantity'));
			}
		});

		if (window.jQuery && form.classList.contains('variations_form')) {
			var $form = window.jQuery(form);

			$form.on('show_variation', function (event, variation) {
				/*
				 * WooCommerce announces this 300ms after it settles on the
				 * variation, so a quick second click can be overtaken by the
				 * first one's news. The form's own field is set at once, and
				 * says which variation is actually current.
				 */
				var current = form.querySelector('input[name="variation_id"], input.variation_id');

				if (current && String(current.value) !== String(variation.variation_id)) {
					return;
				}

				var open = false;

				Object.keys(variation.attributes || {}).forEach(function (name) {
					if (!variation.attributes[name]) {
						open = true;
					}
				});

				/*
				 * A variation that matches any value of an attribute still
				 * needs the one the customer picked, and only the form carries
				 * it, so that combination is left to post in the ordinary way.
				 */
				box.setAttribute('data-buy-open', open ? '1' : '');

				setPrice(variation.price_html);

				if (stock) {
					stock.innerHTML = variation.availability_html || '';
				}

				if (hint) {
					hint.hidden = true;
				}

				if (variation.image && variation.image.full_src) {
					showGalleryImage(scope, variation.image.full_src);
				}

				refreshSteps();
			});

			/*
			 * Both of these mean no variation is on show, and WooCommerce
			 * raises them together, so what is left to say is read from the
			 * form: something still unchosen is worth a nudge, while a chosen
			 * combination that matches nothing already has its own message.
			 */
			$form.on('hide_variation reset_data', function () {
				var fields = form.querySelectorAll('.variations select');
				var chosen = 0;

				Array.prototype.forEach.call(fields, function (field) {
					if (field.value) {
						chosen += 1;
					}
				});

				setPrice('');

				if (stock) {
					stock.innerHTML = '';
				}

				if (hint) {
					hint.hidden = fields.length > 0 && chosen === fields.length;
				}
			});
		}

		if (!box.hasAttribute('data-buy-ajax') || !settings.addToCart || !window.jQuery) {
			return;
		}

		var falling = false;

		form.addEventListener('submit', function (event) {
			if (falling) {
				return;
			}

			var button = form.querySelector('.single_add_to_cart_button');

			// WooCommerce disables the button until a combination is chosen.
			if (button && (button.disabled || button.classList.contains('disabled'))) {
				return;
			}

			if ('1' === box.getAttribute('data-buy-open')) {
				return;
			}

			var data = new FormData(form);
			var quantity = data.get('quantity');

			// A grouped product posts a quantity per child: not this path.
			if (null === quantity) {
				return;
			}

			var chosen = parseInt(data.get('variation_id') || '0', 10) || 0;

			// Nothing to add until a combination is settled on.
			if (!chosen && form.classList.contains('variations_form')) {
				event.preventDefault();

				if (hint) {
					hint.hidden = false;
				}

				return;
			}

			var product = chosen
				|| parseInt(data.get('add-to-cart') || data.get('product_id') || '0', 10)
				|| (button ? parseInt(button.value || '0', 10) : 0);

			if (!product) {
				return;
			}

			event.preventDefault();

			var payload = new URLSearchParams();

			payload.append('product_id', String(product));
			payload.append('quantity', String(quantity));

			box.classList.add('is-adding');

			window.fetch(settings.addToCart, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: payload.toString()
			})
				.then(function (response) {
					return response.json();
				})
				.then(function (result) {
					box.classList.remove('is-adding');

					if (!result || result.error) {
						if (result && result.product_url) {
							window.location = result.product_url;
							return;
						}

						throw new Error('rejected');
					}

					// The drawer and the modal both listen for this.
					window.jQuery(document.body).trigger('added_to_cart', [
						result.fragments,
						result.cart_hash,
						window.jQuery(button)
					]);
				})
				.catch(function () {
					// Whatever went wrong, the ordinary post still works.
					box.classList.remove('is-adding');
					falling = true;

					if (form.requestSubmit) {
						form.requestSubmit(button || undefined);
					} else if (button) {
						button.click();
					} else {
						form.submit();
					}
				});
		});
	}

	function setupAll() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-buy]'), setup);
	}

	setupAll();

	document.addEventListener('gueta:quickview-rendered', function () {
		// Bind this box before the variation form starts reporting, so the
		// price it announces on load is not missed.
		setupAll();

		if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.wc_variation_form) {
			return;
		}

		// The form arrived after WooCommerce had bound the ones already on the
		// page, so this one is introduced to its script by hand.
		Array.prototype.forEach.call(document.querySelectorAll('[data-buy] form.variations_form'), function (form) {
			if (form.getAttribute('data-buy-variations')) {
				return;
			}

			form.setAttribute('data-buy-variations', '1');
			window.jQuery(form).wc_variation_form();
		});
	});
}());
