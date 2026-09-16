/**
 * Upsell popup: a card in the corner offering a few products, one at a time.
 *
 * Which popups apply, and with which products, was settled on the server. This
 * decides when to show one, steps through its products, and adds the one on
 * show to the cart in place.
 *
 * The cart drawer is left shut when the popup adds. The button says it worked,
 * the badge and the drawer's contents update behind it, and the next product
 * takes its place. A popup never opens over the drawer, the menu or quick view
 * either: it waits for them to close.
 */
(function () {
	'use strict';

	var settings = window.guetaUpsell || {};
	var strings = settings.strings || {};
	var context = settings.context || {};

	if (!settings.addUrl || !settings.refreshUrl) {
		return;
	}

	var popups = settings.popups || [];
	var knownHash = settings.cartHash || '';

	var root = null;
	var nodes = {};
	var active = null;
	var index = 0;
	var hideTimer = null;
	var dismissed = {};

	/* ---------------------------------------------------------------------
	 * How often
	 * ------------------------------------------------------------------ */

	function storageFor(frequency) {
		try {
			if ('session' === frequency) {
				return window.sessionStorage;
			}

			if ('day' === frequency || 'once' === frequency) {
				return window.localStorage;
			}
		} catch (error) {
			// Storage switched off: fall through and show every time.
		}

		return null;
	}

	function today() {
		var date = new Date();

		return date.getFullYear() + '-' + (date.getMonth() + 1) + '-' + date.getDate();
	}

	function canShow(popup) {
		// Closed once on this page, it stays closed on this page.
		if (dismissed[popup.id] || !popup.items.length) {
			return false;
		}

		var storage = storageFor(popup.frequency);

		if (!storage) {
			return true;
		}

		try {
			var seen = storage.getItem('gueta-upsell-' + popup.id);

			return !seen || ('day' === popup.frequency && seen !== today());
		} catch (error) {
			return true;
		}
	}

	function markShown(popup) {
		var storage = storageFor(popup.frequency);

		if (!storage) {
			return;
		}

		try {
			storage.setItem('gueta-upsell-' + popup.id, 'day' === popup.frequency ? today() : '1');
		} catch (error) {
			// Private browsing: it simply shows again next time.
		}
	}

	/* ---------------------------------------------------------------------
	 * Products already taken
	 *
	 * A product added this visit leaves every popup and stays out, across
	 * reloads and whatever a refresh brings back, even if it is later taken
	 * out of the cart again.
	 * ------------------------------------------------------------------ */

	var ADDED_KEY = 'gueta-upsell-added';
	var added = {};

	try {
		JSON.parse(window.sessionStorage.getItem(ADDED_KEY) || '[]').forEach(function (id) {
			added[String(id)] = true;
		});
	} catch (error) {
		// Nothing remembered; the server still leaves out the cart's contents.
	}

	function prune() {
		// The one on screen too, which a refresh may have left out of the list.
		popups.concat(active ? [active] : []).forEach(function (popup) {
			popup.items = popup.items.filter(function (item) {
				return !added[String(item.id)];
			});
		});
	}

	function taken(productId) {
		if (!productId) {
			return;
		}

		added[String(productId)] = true;

		try {
			window.sessionStorage.setItem(ADDED_KEY, JSON.stringify(Object.keys(added)));
		} catch (error) {
			// Held in memory for this page instead.
		}

		prune();
	}

	/* ---------------------------------------------------------------------
	 * The card
	 * ------------------------------------------------------------------ */

	var paths = {
		close: 'm6 6 12 12M18 6 6 18',
		previous: 'm9 5 7 7-7 7',
		next: 'M15 5 8 12l7 7'
	};

	function icon(name) {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' + paths[name] + '"></path></svg>';
	}

	function label(text) {
		return String(text || '').replace(/"/g, '&quot;');
	}

	function build() {
		root = document.createElement('aside');
		root.className = 'gueta-upsell';
		root.setAttribute('dir', document.documentElement.getAttribute('dir') || 'rtl');
		root.setAttribute('aria-label', strings.dialog || '');
		root.hidden = true;
		root.innerHTML =
			'<div class="gueta-upsell__card">' +
				'<button type="button" class="gueta-upsell__close" data-upsell-close aria-label="' + label(strings.close) + '">' + icon('close') + '</button>' +
				'<div class="gueta-upsell__body">' +
					'<a class="gueta-upsell__media" href="#" tabindex="-1" aria-hidden="true">' +
						'<img class="gueta-upsell__image" src="" alt="" width="96" height="96">' +
					'</a>' +
					'<div class="gueta-upsell__text">' +
						'<p class="gueta-upsell__heading"></p>' +
						'<a class="gueta-upsell__name" href="#"></a>' +
						'<p class="gueta-upsell__price">' +
							'<span class="gueta-upsell__now"></span>' +
							'<del class="gueta-upsell__was"></del>' +
						'</p>' +
						'<button type="button" class="gueta-upsell__add" data-upsell-add></button>' +
					'</div>' +
				'</div>' +
				'<div class="gueta-upsell__nav">' +
					'<button type="button" class="gueta-upsell__arrow" data-upsell-step="-1" aria-label="' + label(strings.previous) + '">' + icon('previous') + '</button>' +
					'<span class="gueta-upsell__counter" aria-live="polite"></span>' +
					'<button type="button" class="gueta-upsell__arrow" data-upsell-step="1" aria-label="' + label(strings.next) + '">' + icon('next') + '</button>' +
				'</div>' +
			'</div>';

		document.body.appendChild(root);

		nodes = {
			card: root.querySelector('.gueta-upsell__card'),
			media: root.querySelector('.gueta-upsell__media'),
			image: root.querySelector('.gueta-upsell__image'),
			heading: root.querySelector('.gueta-upsell__heading'),
			name: root.querySelector('.gueta-upsell__name'),
			now: root.querySelector('.gueta-upsell__now'),
			was: root.querySelector('.gueta-upsell__was'),
			add: root.querySelector('.gueta-upsell__add'),
			nav: root.querySelector('.gueta-upsell__nav'),
			counter: root.querySelector('.gueta-upsell__counter')
		};

		root.addEventListener('click', function (event) {
			if (event.target.closest('[data-upsell-close]')) {
				close();
				return;
			}

			if (event.target.closest('[data-upsell-add]')) {
				add();
				return;
			}

			var step = event.target.closest('[data-upsell-step]');

			if (step) {
				move(parseInt(step.getAttribute('data-upsell-step'), 10));
			}
		});

		document.addEventListener('keydown', function (event) {
			// Escape belongs to a drawer while one is open over the page.
			if ('Escape' === event.key && isOpen() && !isLocked()) {
				close();
			}
		});
	}

	function render() {
		var item = active && active.items[index];

		if (!item) {
			close();
			return;
		}

		var total = active.items.length;

		nodes.heading.textContent = active.heading || '';
		nodes.heading.hidden = !active.heading;
		nodes.name.textContent = item.name;
		nodes.name.href = item.url || '#';
		nodes.media.href = item.url || '#';
		nodes.image.src = item.image;
		nodes.now.textContent = item.price || '';
		nodes.was.textContent = item.regular || '';
		nodes.was.hidden = !item.regular;
		nodes.counter.textContent = total > 1 ? (index + 1) + ' / ' + total : '';
		nodes.nav.hidden = total < 2;

		setButton('');
	}

	function setButton(state, message) {
		nodes.add.disabled = 'adding' === state || 'added' === state;
		nodes.add.className = 'gueta-upsell__add' + (state ? ' is-' + state : '');
		nodes.add.textContent = message || strings[state || 'add'] || '';
	}

	function swap() {
		nodes.card.classList.remove('is-swapping');
		// Reading layout restarts the animation on the same element.
		void nodes.card.offsetWidth;
		nodes.card.classList.add('is-swapping');
	}

	function move(step) {
		if (!active || active.items.length < 2 || nodes.add.disabled) {
			return;
		}

		index = (index + step + active.items.length) % active.items.length;
		swap();
		render();
	}

	/* ---------------------------------------------------------------------
	 * Showing and hiding
	 * ------------------------------------------------------------------ */

	/*
	 * Set the moment a popup is chosen, not when its class lands a frame
	 * later, so a second trigger in the same tick finds the corner taken.
	 */
	var showing = false;

	function isOpen() {
		return showing;
	}

	function isLocked() {
		return document.body.classList.contains('gueta-locked');
	}

	/**
	 * Run once nothing is laid over the page. The header marks the body while
	 * the cart, the menu, quick view or the lightbox is open.
	 */
	var waiting = null;
	var watcher = null;

	function whenFree(callback) {
		if (!isLocked()) {
			callback();
			return;
		}

		waiting = callback;

		if (watcher || !window.MutationObserver) {
			return;
		}

		watcher = new MutationObserver(function () {
			if (isLocked() || !waiting) {
				return;
			}

			var run = waiting;

			waiting = null;
			watcher.disconnect();
			watcher = null;
			// Let the drawer finish sliding away first.
			window.setTimeout(run, 450);
		});

		watcher.observe(document.body, { attributes: true, attributeFilter: ['class'] });
	}

	function find(id) {
		for (var i = 0; i < popups.length; i++) {
			if (popups[i].id === id) {
				return popups[i];
			}
		}

		return null;
	}

	function open(id) {
		whenFree(function () {
			var popup = find(id);

			if (!popup || isOpen() || !canShow(popup)) {
				return;
			}

			if (!root) {
				build();
			}

			window.clearTimeout(hideTimer);
			showing = true;
			active = popup;
			index = 0;
			markShown(popup);
			render();
			root.hidden = false;

			// Paint the hidden state once, so the card slides in rather than appears.
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () {
					root.classList.add('is-open');
				});
			});
		});
	}

	function close() {
		if (!root) {
			return;
		}

		if (active) {
			dismissed[active.id] = true;
		}

		showing = false;
		root.classList.remove('is-open');
		window.clearTimeout(hideTimer);
		hideTimer = window.setTimeout(function () {
			root.hidden = true;
		}, 400);
	}

	/** The first popup for this trigger that may show now. */
	function show(trigger) {
		for (var i = 0; i < popups.length; i++) {
			if (popups[i].trigger === trigger && canShow(popups[i])) {
				open(popups[i].id);
				return;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Adding
	 * ------------------------------------------------------------------ */

	function post(url, fields) {
		return window.fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: new URLSearchParams(fields).toString()
		}).then(function (response) {
			return response.json();
		});
	}

	/** Swap in WooCommerce's fragments: the header badge and the drawer. */
	function applyFragments(fragments) {
		Object.keys(fragments || {}).forEach(function (selector) {
			try {
				Array.prototype.forEach.call(document.querySelectorAll(selector), function (node) {
					node.outerHTML = fragments[selector];
				});
			} catch (error) {
				// A selector written for jQuery alone; nothing of ours.
			}
		});
	}

	function add() {
		var popup = active;
		var item = popup && popup.items[index];

		if (!item || nodes.add.disabled) {
			return;
		}

		setButton('adding');

		function fail(message) {
			setButton('error', message);

			window.setTimeout(function () {
				if (nodes.add.classList.contains('is-error')) {
					setButton('');
				}
			}, 2600);
		}

		post(settings.addUrl, { product_id: item.id, popup_id: popup.id })
			.then(function (result) {
				if (!result || !result.success) {
					// WooCommerce's own reason, when it gave one, such as a stock limit.
					fail(result && result.data && result.data.message);
					return;
				}

				setButton('added');
				applyFragments(result.data.fragments);
				knownHash = result.data.cartHash || knownHash;

				// The checkout draws its summary on the server; ask for it again.
				if (settings.isCheckout && window.jQuery) {
					window.jQuery(document.body).trigger('update_checkout');
				}

				taken(item.id);

				window.setTimeout(function () {
					if (active !== popup || !isOpen()) {
						return;
					}

					if (!popup.items.length) {
						close();
						return;
					}

					// The next product has moved up into this one's place.
					index = Math.min(index, popup.items.length - 1);
					swap();
					render();
				}, 1200);
			})
			.catch(function () {
				fail('');
			});
	}

	/* ---------------------------------------------------------------------
	 * When the cart changes
	 *
	 * The cart rules were applied to the cart the page was drawn for. Once
	 * that changes, the server is asked again. WooCommerce keeps a hash of the
	 * cart in a cookie, which is how a change is noticed, including on a page
	 * that came out of a cache for somebody else's cart.
	 * ------------------------------------------------------------------ */

	function cookieHash() {
		var match = document.cookie.match(/(?:^|;\s*)woocommerce_cart_hash=([^;]*)/);

		return match ? decodeURIComponent(match[1]) : '';
	}

	function refresh(done) {
		knownHash = cookieHash();

		post(settings.refreshUrl, { scope: context.scope || 'all', object_id: context.object_id || 0 })
			.then(function (result) {
				if (result && result.success) {
					popups = result.data.popups || [];
					prune();
					resync();
				}
			})
			.catch(function () {
				// Keep what the page had.
			})
			.then(function () {
				if (done) {
					done();
				}
			});
	}

	/** An open popup is a new object after a refresh; follow it there. */
	function resync() {
		if (!isOpen() || !active || nodes.add.disabled) {
			return;
		}

		var fresh = find(active.id);

		if (!fresh || !fresh.items.length) {
			close();
			return;
		}

		active = fresh;
		index = Math.min(index, fresh.items.length - 1);
		render();
	}

	/* ---------------------------------------------------------------------
	 * Triggers
	 * ------------------------------------------------------------------ */

	function start() {
		prune();

		popups.forEach(function (popup) {
			if ('immediate' === popup.trigger) {
				open(popup.id);
			} else if ('delay' === popup.trigger) {
				window.setTimeout(function () {
					open(popup.id);
				}, (parseInt(popup.delay, 10) || 0) * 1000);
			}
		});

		var ticking = false;

		window.addEventListener('scroll', function () {
			if (ticking) {
				return;
			}

			ticking = true;

			window.requestAnimationFrame(function () {
				ticking = false;

				var room = document.documentElement.scrollHeight - window.innerHeight;

				if (room > 0 && window.scrollY / room > 0.5) {
					show('scroll');
				}
			});
		}, { passive: true });

		// The pointer leaving through the top of the window, towards the tabs.
		document.addEventListener('mouseout', function (event) {
			if (!event.relatedTarget && event.clientY <= 0) {
				show('exit_intent');
			}
		});

		if (!window.jQuery) {
			return;
		}

		var $body = window.jQuery(document.body);

		// An ordinary add to cart, from a product page, quick view or a card.
		$body.on('added_to_cart', function (event, fragments, hash, $button) {
			var button = $button && $button.jquery ? $button[0] : $button;

			if (button && button.closest && button.closest('.gueta-upsell')) {
				return;
			}

			var form = button && button.closest ? button.closest('form') : null;
			var field = form ? form.querySelector('[name="add-to-cart"], [name="product_id"]') : null;

			taken((field && field.value) || (button && (button.value || button.getAttribute('data-product_id'))));

			refresh(function () {
				// The drawer has just opened, so this waits for it to close.
				show('add_to_cart');
			});
		});

		// The drawer changed a quantity or removed a line.
		$body.on('wc_fragments_refreshed removed_from_cart', function () {
			if (cookieHash() !== knownHash) {
				refresh();
			}
		});
	}

	function boot() {
		if (cookieHash() !== knownHash) {
			refresh(start);
		} else {
			start();
		}
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
