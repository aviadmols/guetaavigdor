/**
 * Gueta storefront header.
 *
 * Owns the announcement rotator, the mega menu, the predictive search and the
 * cart drawer. WooCommerce keeps the drawer contents fresh through cart
 * fragments; everything here only drives the interaction.
 */
(function () {
	'use strict';

	var settings = window.guetaHeader || {};
	var strings = settings.strings || {};
	var header = document.querySelector('[data-header]');

	if (!header) {
		return;
	}

	var scrim = header.querySelector('[data-nav-scrim]');
	var body = document.body;

	function on(element, type, handler) {
		if (element) {
			element.addEventListener(type, handler);
		}
	}

	/* ---------------------------------------------------------------------
	 * Condensed header
	 *
	 * Collapse the navigation row once the page is scrolled, so the sticky
	 * header keeps taking less room the further down you are.
	 * ------------------------------------------------------------------ */

	(function condense() {
		var threshold = 90;
		var ticking = false;

		function apply() {
			ticking = false;

			var condensed = window.scrollY > threshold;

			if (condensed === header.classList.contains('is-condensed')) {
				return;
			}

			// Never collapse the row out from under an open mega menu.
			if (condensed && header.classList.contains('is-dimmed')) {
				return;
			}

			header.classList.toggle('is-condensed', condensed);
		}

		window.addEventListener('scroll', function () {
			if (!ticking) {
				ticking = true;
				window.requestAnimationFrame(apply);
			}
		}, { passive: true });

		apply();
	}());

	/* ---------------------------------------------------------------------
	 * Announcement rotator
	 * ------------------------------------------------------------------ */

	(function announcements() {
		var strip = document.querySelector('[data-announce]');

		if (!strip) {
			return;
		}

		var items = Array.prototype.slice.call(strip.querySelectorAll('[data-announce-item]'));

		if (items.length < 2) {
			var arrows = strip.querySelectorAll('.gueta-announce__arrow');
			Array.prototype.forEach.call(arrows, function (arrow) {
				arrow.hidden = true;
			});
			return;
		}

		var index = 0;
		var timer = null;

		function show(next) {
			items[index].classList.remove('is-active');
			index = (next + items.length) % items.length;
			items[index].classList.add('is-active');
		}

		function start() {
			stop();
			timer = window.setInterval(function () {
				show(index + 1);
			}, 5000);
		}

		function stop() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		on(strip.querySelector('[data-announce-prev]'), 'click', function () {
			show(index - 1);
			start();
		});

		on(strip.querySelector('[data-announce-next]'), 'click', function () {
			show(index + 1);
			start();
		});

		on(strip, 'mouseenter', stop);
		on(strip, 'mouseleave', start);

		start();
	}());

	/* ---------------------------------------------------------------------
	 * Mega menu
	 * ------------------------------------------------------------------ */

	var megaMenu = (function () {
		var nav = header.querySelector('[data-nav]');
		var openItem = null;
		var closeTimer = null;

		function close() {
			if (!openItem) {
				return;
			}

			var panel = openItem.querySelector('[data-nav-panel]');
			var trigger = openItem.querySelector('[data-nav-trigger]');

			openItem.classList.remove('is-open');

			if (trigger) {
				trigger.setAttribute('aria-expanded', 'false');
			}

			openItem = null;
			header.classList.remove('is-dimmed');
		}

		function open(item) {
			if (openItem === item) {
				return;
			}

			close();

			var panel = item.querySelector('[data-nav-panel]');
			var trigger = item.querySelector('[data-nav-trigger]');

			if (!panel) {
				return;
			}

			item.classList.add('is-open');

			if (trigger) {
				trigger.setAttribute('aria-expanded', 'true');
			}

			openItem = item;
			header.classList.add('is-dimmed');
		}

		if (nav) {
			var items = nav.querySelectorAll('.gueta-nav__item.has-mega');

			Array.prototype.forEach.call(items, function (item) {
				item.addEventListener('mouseenter', function () {
					window.clearTimeout(closeTimer);
					open(item);
				});

				item.addEventListener('mouseleave', function () {
					window.clearTimeout(closeTimer);
					closeTimer = window.setTimeout(close, 140);
				});

				item.addEventListener('focusin', function () {
					window.clearTimeout(closeTimer);
					open(item);
				});

				// Touch and keyboard: the first activation opens, the second follows.
				var trigger = item.querySelector('[data-nav-trigger]');

				on(trigger, 'click', function (event) {
					if (window.matchMedia('(hover: hover)').matches) {
						return;
					}

					if (openItem !== item) {
						event.preventDefault();
						open(item);
					}
				});
			});

			nav.addEventListener('focusout', function (event) {
				if (openItem && !openItem.contains(event.relatedTarget)) {
					close();
				}
			});
		}

		on(scrim, 'mouseenter', close);

		return { close: close };
	}());

	/* ---------------------------------------------------------------------
	 * Predictive search
	 * ------------------------------------------------------------------ */

	(function search() {
		var searchOpen = header.querySelector('[data-search-open]');
		var wrappers = header.querySelectorAll('[data-search]');

		Array.prototype.forEach.call(wrappers, function (wrapper) {
			var input = wrapper.querySelector('[data-search-input]');
			var results = wrapper.querySelector('[data-search-results]');
			var reset = wrapper.querySelector('[data-search-reset]');
			var spinner = wrapper.querySelector('[data-search-spinner]');
			var minChars = settings.minChars || 2;
			var timer = null;
			var controller = null;
			var lastTerm = '';

			function hidePanel() {
				results.hidden = true;
				results.innerHTML = '';
				input.setAttribute('aria-expanded', 'false');
			}

			function showPanel(html) {
				results.innerHTML = html;
				results.hidden = false;
				input.setAttribute('aria-expanded', 'true');
			}

			function setBusy(busy) {
				if (spinner) {
					spinner.hidden = !busy;
				}
			}

			function request(term) {
				if (controller) {
					controller.abort();
				}

				controller = window.AbortController ? new AbortController() : null;
				setBusy(true);

				var payload = new URLSearchParams();
				payload.append('action', 'gueta_header_search');
				payload.append('nonce', settings.nonce || '');
				payload.append('term', term);

				fetch(settings.ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
					body: payload.toString(),
					signal: controller ? controller.signal : undefined
				})
					.then(function (response) {
						return response.json();
					})
					.then(function (data) {
						setBusy(false);

						if (term !== lastTerm) {
							return;
						}

						if (data && data.success && data.data.html) {
							showPanel(data.data.html);
						} else {
							hidePanel();
						}
					})
					.catch(function (error) {
						if (error && 'AbortError' === error.name) {
							return;
						}

						setBusy(false);
						showPanel('<p class="gueta-suggest__empty-text" style="padding:20px;text-align:center">' + (strings.error || '') + '</p>');
					});
			}

			on(input, 'input', function () {
				var term = input.value.trim();

				lastTerm = term;

				if (reset) {
					reset.hidden = '' === term;
				}

				window.clearTimeout(timer);

				if (term.length < minChars) {
					if (controller) {
						controller.abort();
						controller = null;
					}
					setBusy(false);
					hidePanel();
					return;
				}

				timer = window.setTimeout(function () {
					request(term);
				}, 220);
			});

			on(input, 'focus', function () {
				if (input.value.trim().length >= minChars && results.innerHTML) {
					results.hidden = false;
					input.setAttribute('aria-expanded', 'true');
				}
			});

			on(reset, 'click', function () {
				input.value = '';
				lastTerm = '';
				reset.hidden = true;
				hidePanel();
				input.focus();
			});

			document.addEventListener('click', function (event) {
				if (!wrapper.contains(event.target)) {
					results.hidden = true;
					input.setAttribute('aria-expanded', 'false');
				}
			});

			document.addEventListener('keydown', function (event) {
				if ('Escape' === event.key && !results.hidden) {
					hidePanel();
					input.blur();
				}
			});
		});

		// Mobile: the icon reveals the field.
		on(searchOpen, 'click', function () {
			var isOpen = header.classList.toggle('is-searching');

			searchOpen.setAttribute('aria-expanded', String(isOpen));

			if (isOpen) {
				var field = header.querySelector('.gueta-header__search [data-search-input]');

				if (field) {
					field.focus();
				}
			}
		});
	}());

	/* ---------------------------------------------------------------------
	 * Drawers
	 * ------------------------------------------------------------------ */

	var drawers = (function () {
		var lastFocused = null;
		var current = null;

		function open(drawer) {
			if (!drawer || current === drawer) {
				return;
			}

			close();
			megaMenu.close();

			lastFocused = document.activeElement;
			drawer.classList.add('is-open');
			body.classList.add('gueta-locked');
			current = drawer;

			var focusable = drawer.querySelector('.gueta-drawer__head [data-drawer-close]')
				|| drawer.querySelector('a[href], button');

			if (focusable) {
				focusable.focus();
			}
		}

		function close() {
			if (!current) {
				return;
			}

			var drawer = current;

			current = null;
			drawer.classList.remove('is-open');
			body.classList.remove('gueta-locked');

			var trigger = drawer.hasAttribute('data-cart-drawer')
				? header.querySelector('[data-cart-open]')
				: header.querySelector('[data-menu-open]');

			if (trigger) {
				trigger.setAttribute('aria-expanded', 'false');
			}

			if (lastFocused && lastFocused.focus) {
				lastFocused.focus();
				lastFocused = null;
			}
		}

		document.addEventListener('click', function (event) {
			var closer = event.target.closest('[data-drawer-close]');

			if (closer) {
				event.preventDefault();
				close();
			}
		});

		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key && current) {
				close();
			}
		});

		return {
			open: open,
			close: close,
			isOpen: function (drawer) {
				return current === drawer;
			}
		};
	}());

	/* ---------------------------------------------------------------------
	 * Mobile menu
	 * ------------------------------------------------------------------ */

	(function mobileMenu() {
		var toggle = header.querySelector('[data-menu-open]');
		var drawer = document.querySelector('[data-menu-drawer]');

		on(toggle, 'click', function () {
			toggle.setAttribute('aria-expanded', 'true');
			drawers.open(drawer);
		});

		if (!drawer) {
			return;
		}

		drawer.addEventListener('click', function (event) {
			var accordion = event.target.closest('[data-accordion]');

			if (!accordion) {
				return;
			}

			var panel = accordion.nextElementSibling;
			var expanded = 'true' === accordion.getAttribute('aria-expanded');

			accordion.setAttribute('aria-expanded', String(!expanded));

			if (panel) {
				panel.hidden = expanded;
			}
		});
	}());

	/* ---------------------------------------------------------------------
	 * Cart drawer
	 * ------------------------------------------------------------------ */

	(function cart() {
		var drawer = document.querySelector('[data-cart-drawer]');

		if (!drawer) {
			return;
		}

		var refreshed = false;

		function replacePanel(html) {
			var panel = drawer.querySelector('.gueta-cart-panel');

			if (panel && html) {
				panel.innerHTML = html;
			}
		}

		function post(action, extra) {
			var payload = new URLSearchParams();

			payload.append('action', action);
			payload.append('nonce', settings.nonce || '');

			Object.keys(extra || {}).forEach(function (key) {
				payload.append(key, extra[key]);
			});

			return fetch(settings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: payload.toString()
			}).then(function (response) {
				return response.json();
			});
		}

		function refresh() {
			// Page caches can serve a stale drawer, so pull it once per visit.
			if (refreshed) {
				return;
			}

			refreshed = true;

			post('gueta_cart_refresh', {})
				.then(function (data) {
					if (data && data.success) {
						replacePanel(data.data.panel);
					}
				})
				.catch(function () {
					refreshed = false;
				});
		}

		function openDrawer() {
			var trigger = header.querySelector('[data-cart-open]');

			if (trigger) {
				trigger.setAttribute('aria-expanded', 'true');
			}

			drawers.open(drawer);
			refresh();
		}

		document.addEventListener('click', function (event) {
			if (event.target.closest('[data-cart-open]')) {
				event.preventDefault();
				openDrawer();
			}
		});

		function update(key, quantity) {
			drawer.classList.add('is-busy');

			post('gueta_cart_update', { key: key, quantity: quantity })
				.then(function (data) {
					drawer.classList.remove('is-busy');

					if (!data || !data.success) {
						return;
					}

					replacePanel(data.data.panel);

					var badge = header.querySelector('[data-cart-count]');

					if (badge && data.data.badge) {
						badge.outerHTML = data.data.badge;
					}

					if (window.jQuery) {
						// Let WooCommerce widgets refresh alongside the drawer.
						window.jQuery(document.body).trigger('wc_fragment_refresh');
					}
				})
				.catch(function () {
					drawer.classList.remove('is-busy');
				});
		}

		function lineKey(element) {
			var line = element.closest('[data-cart-line]');

			return line ? line.getAttribute('data-cart-line') : '';
		}

		drawer.addEventListener('click', function (event) {
			var remove = event.target.closest('[data-cart-remove]');

			if (remove) {
				update(lineKey(remove), 0);
				return;
			}

			var step = event.target.closest('[data-cart-increase], [data-cart-decrease]');

			if (!step) {
				return;
			}

			var line = step.closest('[data-cart-line]');
			var input = line ? line.querySelector('[data-cart-qty]') : null;

			if (!input) {
				return;
			}

			var next = parseInt(input.value, 10) || 0;

			next += step.hasAttribute('data-cart-increase') ? 1 : -1;
			next = Math.max(0, next);
			input.value = next;

			update(lineKey(step), next);
		});

		drawer.addEventListener('change', function (event) {
			var input = event.target.closest('[data-cart-qty]');

			if (input) {
				update(lineKey(input), Math.max(0, parseInt(input.value, 10) || 0));
			}
		});

		if (window.jQuery) {
			// WooCommerce announces AJAX add to cart on the body.
			window.jQuery(document.body).on('added_to_cart', function () {
				refreshed = true;
				openDrawer();
			});

			window.jQuery(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function () {
				refreshed = true;
			});
		}

		// A product page posts its form and redirects; the server tells us to open.
		if (settings.openCart) {
			refreshed = true;
			openDrawer();
		}
	}());
}());

/**
 * Category slider under the header.
 *
 * Scroll snapping and native touch scrolling do the moving; the buttons just
 * page the track. Kept direction agnostic: in RTL a container's scrollLeft runs
 * from 0 down to negative, so distances are compared on the absolute value.
 */
(function () {
	'use strict';

	var slider = document.querySelector('[data-cats]');

	if (!slider) {
		return;
	}

	var track = slider.querySelector('[data-cats-track]');
	var prev = slider.querySelector('[data-cats-prev]');
	var next = slider.querySelector('[data-cats-next]');

	if (!track || !prev || !next) {
		return;
	}

	var isRtl = 'rtl' === getComputedStyle(track).direction;

	function page() {
		// Move by a full viewport of cards, minus one so context carries over.
		var item = track.firstElementChild;
		var step = item ? item.getBoundingClientRect().width + 20 : 240;

		return Math.max(step, Math.floor(track.clientWidth / step) * step - step);
	}

	function scrollBy(forward) {
		var amount = page() * (forward ? 1 : -1);

		track.scrollBy({ left: isRtl ? -amount : amount, behavior: 'smooth' });
	}

	function sync() {
		var travelled = Math.abs(track.scrollLeft);
		var max = track.scrollWidth - track.clientWidth;

		prev.disabled = travelled < 4;
		next.disabled = travelled >= max - 4;
	}

	next.addEventListener('click', function () {
		scrollBy(true);
	});

	prev.addEventListener('click', function () {
		scrollBy(false);
	});

	track.addEventListener('scroll', function () {
		window.clearTimeout(track.guetaSyncTimer);
		track.guetaSyncTimer = window.setTimeout(sync, 80);
	});

	window.addEventListener('resize', sync);
	sync();
}());
