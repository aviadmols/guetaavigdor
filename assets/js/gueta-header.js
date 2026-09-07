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
		/*
		 * Collapsing removes the row's height from the flow, which shortens the
		 * document and can pull the scroll position back above a single
		 * threshold, expanding it again: a flicker loop. Two thresholds far
		 * enough apart to clear the row's own height break the cycle, and
		 * collapsing at 4px means it goes as soon as the page moves.
		 */
		if (!settings.navCondense) {
			return;
		}

		var collapseAt = 4;
		var expandAt = 1;
		var ticking = false;

		function apply() {
			ticking = false;

			var isCondensed = header.classList.contains('is-condensed');
			var y = window.scrollY;

			if (!isCondensed && y > collapseAt) {
				// Never collapse the row out from under an open mega menu.
				if (!header.classList.contains('is-dimmed')) {
					header.classList.add('is-condensed');
				}

				return;
			}

			if (isCondensed && y <= expandAt) {
				header.classList.remove('is-condensed');
			}
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

		/* -----------------------------------------------------------------
		 * The static index
		 *
		 * A JSON file built from the admin holds every product, category, tag
		 * and article. It is fetched once, on the first sign that somebody is
		 * about to search, and then queried in memory, so keystrokes never
		 * reach the database. If the file is missing or fails to load, every
		 * request falls back to the server endpoint that used to serve them.
		 * -------------------------------------------------------------- */

		var searchConfig = settings.search || {};
		var index = null;
		var indexRequest = null;
		var indexBroken = false;

		// Unicode property escapes are not everywhere yet; Hebrew and Latin
		// cover what this catalogue is written in.
		var punctuation = (function () {
			try {
				return new RegExp('[^\\p{L}\\p{N}]+', 'gu');
			} catch (error) {
				return /[^0-9a-z֐-׿]+/g;
			}
		}());

		var marks = /[֑-ׇ]/g;
		var quotes = /[׳״'"`]/g;

		// A shopper types עץ and means עצים, so the five final letters fold
		// into their ordinary forms on both sides of the comparison.
		var finals = /[ךםןףץ]/g;
		var folded = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };

		function normalize(text) {
			return String(text == null ? '' : text)
				.toLowerCase()
				.replace(marks, '')
				.replace(quotes, '')
				.replace(finals, function (letter) {
					return folded[letter];
				})
				.replace(punctuation, ' ')
				.trim();
		}

		function esc(value) {
			return String(value == null ? '' : value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
		}

		function absolute(base, value) {
			if (!value) {
				return '';
			}

			if ('/' === value.charAt(0) || /^https?:/i.test(value) || 0 === value.indexOf('//')) {
				return value;
			}

			return base + value;
		}

		function indexAvailable() {
			return Boolean(searchConfig.index) && !indexBroken;
		}

		function indexReady() {
			return null !== index;
		}

		/**
		 * Normalise every row once, so a keystroke is a scan over strings that
		 * are already lowercase and stripped of niqqud.
		 */
		function prepareIndex(raw) {
			var products = [];
			var i;

			for (i = 0; i < raw.p.length; i++) {
				var row = raw.p[i];

				products.push({
					title: row[0],
					url: row[1],
					thumb: row[2],
					price: row[3],
					regular: row[4],
					max: row[5],
					name: normalize(row[0]),
					hay: normalize(row[0] + ' ' + (row[6] || '') + ' ' + (row[7] || ''))
				});
			}

			function terms(rows) {
				var out = [];

				for (var j = 0; j < rows.length; j++) {
					out.push({
						name: rows[j][0],
						url: rows[j][1],
						count: rows[j][2] || 0,
						path: rows[j][3] || '',
						match: normalize(rows[j][0])
					});
				}

				return out;
			}

			return {
				home: raw.home || '/',
				uploads: raw.up || '',
				currency: raw.cur || null,
				woo: Boolean(raw.woo),
				limits: {
					products: (raw.lim && raw.lim.p) || 6,
					terms: (raw.lim && raw.lim.t) || 6,
					articles: (raw.lim && raw.lim.a) || 3
				},
				products: products,
				categories: terms(raw.c || []),
				tags: terms(raw.t || []),
				articles: terms(raw.a || [])
			};
		}

		function loadIndex() {
			if (index) {
				return Promise.resolve(index);
			}

			if (indexRequest) {
				return indexRequest;
			}

			if (!indexAvailable()) {
				return Promise.reject(new Error('no index'));
			}

			indexRequest = fetch(searchConfig.index, { credentials: 'omit' })
				.then(function (response) {
					if (!response.ok) {
						throw new Error('index ' + response.status);
					}

					return response.json();
				})
				.then(function (raw) {
					index = prepareIndex(raw);

					return index;
				})
				.catch(function (error) {
					// One failure is enough; from here on the server answers.
					indexBroken = true;
					indexRequest = null;

					throw error;
				});

			return indexRequest;
		}

		function warmIndex() {
			if (indexAvailable() && !indexReady()) {
				loadIndex().catch(function () {});
			}
		}

		/**
		 * How well one product answers the query. Lower is better, and a
		 * negative result means it does not answer it at all.
		 */
		function rank(item, query, tokens) {
			var i;

			for (i = 0; i < tokens.length; i++) {
				if (-1 === item.hay.indexOf(tokens[i])) {
					return -1;
				}
			}

			if (item.name === query) {
				return 0;
			}

			if (0 === item.name.indexOf(query)) {
				return 1;
			}

			if (-1 !== (' ' + item.name).indexOf(' ' + query)) {
				return 2;
			}

			if (-1 !== item.name.indexOf(query)) {
				return 3;
			}

			for (i = 0; i < tokens.length; i++) {
				if (-1 === (' ' + item.name).indexOf(' ' + tokens[i])) {
					// Matched through a category, a tag or the SKU.
					return 5;
				}
			}

			return 4;
		}

		function matchProducts(idx, query, tokens) {
			var found = [];
			var i;

			for (i = 0; i < idx.products.length; i++) {
				var score = rank(idx.products[i], query, tokens);

				if (score >= 0) {
					found.push({ item: idx.products[i], score: score });
				}
			}

			found.sort(function (a, b) {
				if (a.score !== b.score) {
					return a.score - b.score;
				}

				return a.item.title.length - b.item.title.length;
			});

			return found;
		}

		function matchTerms(rows, query, limit) {
			var found = [];
			var i;

			for (i = 0; i < rows.length; i++) {
				if (-1 !== rows[i].match.indexOf(query)) {
					found.push(rows[i]);
				}
			}

			found.sort(function (a, b) {
				return b.count - a.count;
			});

			return found.slice(0, limit);
		}

		function number(value) {
			try {
				return Number(value).toLocaleString('he-IL');
			} catch (error) {
				return String(value);
			}
		}

		function formatPrice(currency, value) {
			var amount = parseFloat(value);

			if (!currency || !isFinite(amount)) {
				return '';
			}

			var digits = 'number' === typeof currency.d ? currency.d : 2;
			var parts = amount.toFixed(digits).split('.');

			parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, currency.ts || '');

			var text = parts[0];

			if (parts.length > 1) {
				var decimals = currency.trim ? parts[1].replace(/0+$/, '') : parts[1];

				if (decimals) {
					text += (currency.ds || '.') + decimals;
				}
			}

			var symbol = '<span class="woocommerce-Price-currencySymbol">' + esc(currency.s || '') + '</span>';

			return String(currency.f || '%1$s%2$s')
				.replace('%1$s', symbol)
				.replace('%2$s', text);
		}

		function priceHtml(currency, item) {
			if (!item.price) {
				return '';
			}

			var now = formatPrice(currency, item.price);

			if (!now) {
				return '';
			}

			if (item.regular) {
				return '<del aria-hidden="true">' + formatPrice(currency, item.regular) + '</del> <ins>' + now + '</ins>';
			}

			if (item.max) {
				return now + ' &ndash; ' + formatPrice(currency, item.max);
			}

			return now;
		}

		function renderProduct(idx, item) {
			var thumb = absolute(idx.uploads, item.thumb);
			var price = priceHtml(idx.currency, item);
			var html = '<a class="gueta-suggest__product" href="' + esc(absolute(idx.home, item.url)) + '">'
				+ '<span class="gueta-suggest__thumb">';

			if (thumb) {
				html += '<img src="' + esc(thumb) + '" alt="" loading="lazy" decoding="async">';
			} else {
				html += '<span class="gueta-suggest__thumb-empty" aria-hidden="true"></span>';
			}

			if (item.regular) {
				html += '<span class="gueta-suggest__badge">מבצע</span>';
			}

			html += '</span><span class="gueta-suggest__body">'
				+ '<span class="gueta-suggest__title">' + esc(item.title) + '</span>';

			if (price) {
				html += '<span class="gueta-suggest__price">' + price + '</span>';
			}

			return html + '</span></a>';
		}

		function renderTermGroup(idx, title, rows, withPath) {
			if (!rows.length) {
				return '';
			}

			var html = '<div class="gueta-suggest__group">'
				+ '<p class="gueta-suggest__heading">' + esc(title) + '</p>'
				+ '<ul class="gueta-suggest__list">';

			for (var i = 0; i < rows.length; i++) {
				html += '<li><a href="' + esc(absolute(idx.home, rows[i].url)) + '">'
					+ '<span class="gueta-suggest__term">' + esc(rows[i].name) + '</span>';

				if (withPath && rows[i].path) {
					html += '<span class="gueta-suggest__path">' + esc(rows[i].path) + '</span>';
				}

				html += '<span class="gueta-suggest__count">' + esc(number(rows[i].count)) + '</span>'
					+ '</a></li>';
			}

			return html + '</ul></div>';
		}

		function renderArticles(idx, rows) {
			if (!rows.length) {
				return '';
			}

			var html = '<div class="gueta-suggest__group">'
				+ '<p class="gueta-suggest__heading">מידע ומאמרים</p>'
				+ '<ul class="gueta-suggest__list">';

			for (var i = 0; i < rows.length; i++) {
				html += '<li><a href="' + esc(absolute(idx.home, rows[i].url)) + '">'
					+ '<span class="gueta-suggest__term">' + esc(rows[i].name) + '</span>'
					+ '</a></li>';
			}

			return html + '</ul></div>';
		}

		function resultsUrl(idx, term) {
			return idx.home + '?s=' + encodeURIComponent(term) + (idx.woo ? '&post_type=product' : '');
		}

		/**
		 * The same panel the server used to render, built from the index.
		 */
		function renderSuggestions(idx, rawTerm) {
			var query = normalize(rawTerm);

			if (!query) {
				return '';
			}

			var tokens = query.split(' ');
			var matches = matchProducts(idx, query, tokens);
			var products = matches.slice(0, idx.limits.products);
			var categories = matchTerms(idx.categories, query, idx.limits.terms);
			var tags = matchTerms(idx.tags, query, idx.limits.terms);
			var articles = matchTerms(idx.articles, query, idx.limits.articles);
			var hasSide = categories.length || tags.length || articles.length;
			var html = '<div class="gueta-suggest__layout' + (hasSide ? '' : ' is-single') + '">';
			var i;

			if (hasSide) {
				html += '<aside class="gueta-suggest__side">'
					+ renderTermGroup(idx, 'קטגוריות מתאימות', categories, true)
					+ renderTermGroup(idx, 'תגיות', tags, false)
					+ renderArticles(idx, articles)
					+ '</aside>';
			}

			html += '<div class="gueta-suggest__main">';

			if (products.length) {
				html += '<p class="gueta-suggest__heading">' + (idx.woo ? 'מוצרים' : 'תוצאות') + '</p>'
					+ '<div class="gueta-suggest__products">';

				for (i = 0; i < products.length; i++) {
					html += renderProduct(idx, products[i].item);
				}

				html += '</div>';
			} else {
				html += '<div class="gueta-suggest__empty">'
					+ '<p class="gueta-suggest__empty-title">לא נמצאו תוצאות עבור &laquo;' + esc(rawTerm) + '&raquo;</p>'
					+ '<p class="gueta-suggest__empty-text">נסו מילה אחרת, או עיינו בקטגוריות שלנו.</p>'
					+ '</div>';
			}

			html += '</div></div>';

			if (matches.length) {
				html += '<a class="gueta-suggest__all" href="' + esc(resultsUrl(idx, rawTerm)) + '">'
					+ '<span>הצג את כל ' + esc(number(matches.length)) + ' התוצאות</span>'
					+ '<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14 5 7 12l7 7"></path></svg>'
					+ '</a>';
			}

			return html;
		}


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

			/**
			 * Answer from the index when it is there, and from the server when
			 * it is not.
			 */
			function request(term) {
				if (!indexAvailable()) {
					remoteRequest(term);
					return;
				}

				if (!indexReady()) {
					setBusy(true);
				}

				loadIndex()
					.then(function (idx) {
						setBusy(false);

						if (term !== lastTerm) {
							return;
						}

						var html = renderSuggestions(idx, term);

						if (html) {
							showPanel(html);
						} else {
							hidePanel();
						}
					})
					.catch(function () {
						remoteRequest(term);
					});
			}

			function remoteRequest(term) {
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
				}, indexReady() ? 60 : 220);
			});

			on(input, 'focus', function () {
				warmIndex();

				if (input.value.trim().length >= minChars && results.innerHTML) {
					results.hidden = false;
					input.setAttribute('aria-expanded', 'true');
				}
			});

			// Reaching for the field is hint enough to fetch the index.
			on(wrapper, 'pointerdown', warmIndex);

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

		/* -----------------------------------------------------------------
		 * Coupon
		 * -------------------------------------------------------------- */

		function panelFor(name) {
			return drawer.querySelector('[data-cart-panel-body="' + name + '"]');
		}

		function setStatus(element, message, isError) {
			if (!element) {
				return;
			}

			element.textContent = message;
			element.classList.toggle('is-error', Boolean(isError));
		}

		drawer.addEventListener('click', function (event) {
			var toggle = event.target.closest('[data-cart-panel]');

			if (toggle) {
				var panel = panelFor(toggle.getAttribute('data-cart-panel'));
				var open = 'true' === toggle.getAttribute('aria-expanded');

				toggle.setAttribute('aria-expanded', String(!open));

				if (panel) {
					panel.hidden = open;

					if (!open) {
						var field = panel.querySelector('textarea, input');

						if (field) {
							field.focus();
						}
					}
				}

				return;
			}

			var apply = event.target.closest('[data-cart-coupon-apply]');
			var drop = event.target.closest('[data-cart-coupon-remove]');

			if (!apply && !drop) {
				return;
			}

			var status = drawer.querySelector('[data-cart-coupon-status]');
			var field = drawer.querySelector('[data-cart-coupon]');
			var code = drop ? drop.getAttribute('data-cart-coupon-remove') : (field ? field.value.trim() : '');

			if (!code) {
				setStatus(status, 'צריך להזין קוד קופון.', true);
				return;
			}

			setStatus(status, 'בודקים…', false);
			drawer.classList.add('is-busy');

			post('gueta_cart_coupon', { code: code, remove: drop ? '1' : '' })
				.then(function (data) {
					drawer.classList.remove('is-busy');

					var payload = data && data.data ? data.data : {};

					if (payload.panel) {
						replacePanel(payload.panel);
						// The coupon section stays open so the result is visible.
						var reopened = drawer.querySelector('[data-cart-panel="coupon"]');
						var body = panelFor('coupon');

						if (reopened && body) {
							reopened.setAttribute('aria-expanded', 'true');
							body.hidden = false;
						}
					}

					setStatus(
						drawer.querySelector('[data-cart-coupon-status]'),
						payload.message || '',
						!(data && data.success)
					);

					if (payload.badge) {
						var badge = header.querySelector('[data-cart-count]');

						if (badge) {
							badge.outerHTML = payload.badge;
						}
					}
				})
				.catch(function () {
					drawer.classList.remove('is-busy');
					setStatus(drawer.querySelector('[data-cart-coupon-status]'), strings.error || '', true);
				});
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
