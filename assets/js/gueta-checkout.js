/**
 * Checkout: completing the settlement from the government's list.
 *
 * The list is one small file, fetched the first time somebody reaches for the
 * field and then searched in memory, so the suggestions appear as fast as the
 * typing. Nothing is asked of the server while a shopper types.
 *
 * The field will not accept a place that is not on the list. That is enforced
 * again in PHP, because this half can be skipped.
 */
(function () {
	'use strict';

	var settings = window.guetaCheckout || {};
	var strings = settings.strings || {};

	var cities = null;
	var request = null;

	/* ---------------------------------------------------------------------
	 * Matching
	 * ------------------------------------------------------------------ */

	var marks = /[֑-ׇ]/g;
	var quotes = /["'`׳״]/g;
	var finals = /[ךםןףץ]/g;
	var folded = { 'ך': 'כ', 'ם': 'מ', 'ן': 'נ', 'ף': 'פ', 'ץ': 'צ' };

	var punctuation = (function () {
		try {
			return new RegExp('[^\\p{L}\\p{N}]+', 'gu');
		} catch (error) {
			return /[^0-9a-z֐-׿]+/g;
		}
	}());

	/**
	 * The same folding PHP does, so both halves agree on what a name is.
	 */
	function fold(text) {
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

	function loadCities() {
		if (cities) {
			return Promise.resolve(cities);
		}

		if (request) {
			return request;
		}

		if (!settings.cities) {
			return Promise.reject(new Error('no list'));
		}

		request = fetch(settings.cities, { credentials: 'omit' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('list ' + response.status);
				}

				return response.json();
			})
			.then(function (data) {
				cities = (data.c || []).map(function (row) {
					return {
						name: row[0],
						english: row[1] || '',
						key: fold(row[0]),
						englishKey: fold(row[1] || '')
					};
				});

				return cities;
			})
			.catch(function (error) {
				request = null;
				throw error;
			});

		return request;
	}

	/**
	 * Settlements worth offering for what has been typed so far.
	 *
	 * A name that starts with the query comes before one that merely contains
	 * it, because somebody typing "רמת" wants Ramat Gan before Givat Ramat.
	 */
	function suggest(query, limit) {
		var needle = fold(query);

		if (!needle || !cities) {
			return [];
		}

		var starts = [];
		var contains = [];
		var i;

		for (i = 0; i < cities.length; i++) {
			var city = cities[i];
			var at = city.key.indexOf(needle);

			if (0 === at) {
				starts.push(city);
			} else if (at > 0 || (city.englishKey && -1 !== city.englishKey.indexOf(needle))) {
				contains.push(city);
			}

			if (starts.length >= limit) {
				break;
			}
		}

		return starts.concat(contains).slice(0, limit);
	}

	function exact(value) {
		var needle = fold(value);

		if (!needle || !cities) {
			return null;
		}

		for (var i = 0; i < cities.length; i++) {
			if (cities[i].key === needle || cities[i].englishKey === needle) {
				return cities[i];
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * The field
	 * ------------------------------------------------------------------ */

	function esc(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function attach(input) {
		if (input.guetaCity) {
			return;
		}

		input.guetaCity = true;

		var wrap = document.createElement('div');
		wrap.className = 'gueta-city';
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);

		var list = document.createElement('ul');
		list.className = 'gueta-city__list';
		list.setAttribute('role', 'listbox');
		list.hidden = true;
		wrap.appendChild(list);

		var note = document.createElement('p');
		note.className = 'gueta-city__note';
		note.hidden = true;
		wrap.appendChild(note);

		var matches = [];
		var active = -1;

		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		input.setAttribute('aria-expanded', 'false');
		input.setAttribute('autocomplete', 'off');

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			active = -1;
			input.setAttribute('aria-expanded', 'false');
		}

		function say(message) {
			note.textContent = message || '';
			note.hidden = !message;
			wrap.classList.toggle('is-invalid', Boolean(message));
		}

		function render() {
			if (!matches.length) {
				close();
				return;
			}

			var html = '';

			for (var i = 0; i < matches.length; i++) {
				html += '<li class="gueta-city__option' + (i === active ? ' is-active' : '') + '"'
					+ ' role="option" aria-selected="' + (i === active) + '"'
					+ ' data-index="' + i + '">' + esc(matches[i].name) + '</li>';
			}

			list.innerHTML = html;
			list.hidden = false;
			input.setAttribute('aria-expanded', 'true');
		}

		/*
		 * The truck is priced by the distance to the settlement, so the order
		 * summary is worked out again once one is set. WooCommerce only does
		 * that by itself for a field that was typed into, and a pick from the
		 * list, or a spelling put right on blur, may not count.
		 */
		function recalculate() {
			input.dispatchEvent(new Event('change', { bubbles: true }));

			if (window.jQuery) {
				window.jQuery(document.body).trigger('update_checkout');
			}
		}

		function choose(index) {
			if (!matches[index]) {
				return;
			}

			input.value = matches[index].name;
			say('');
			close();
			recalculate();
		}

		function search() {
			loadCities()
				.then(function () {
					matches = suggest(input.value, 8);
					active = matches.length ? 0 : -1;
					render();

					if (input.value.trim() && !matches.length) {
						say(strings.noMatch || '');
					} else {
						say('');
					}
				})
				.catch(function () {
					// Without the list the field is an ordinary text box, and
					// PHP stops letting it through only if the list is there.
					close();
				});
		}

		input.addEventListener('focus', function () {
			loadCities().catch(function () {});
		});

		input.addEventListener('input', search);

		input.addEventListener('keydown', function (event) {
			if (list.hidden) {
				return;
			}

			if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
				event.preventDefault();
				active += 'ArrowDown' === event.key ? 1 : -1;

				if (active < 0) {
					active = matches.length - 1;
				}

				if (active >= matches.length) {
					active = 0;
				}

				render();
				return;
			}

			if ('Enter' === event.key && active > -1) {
				event.preventDefault();
				choose(active);
				return;
			}

			if ('Escape' === event.key) {
				close();
			}
		});

		list.addEventListener('mousedown', function (event) {
			var option = event.target.closest('[data-index]');

			if (option) {
				// Before blur, or the click lands on a field that has moved.
				event.preventDefault();
				choose(Number(option.getAttribute('data-index')));
			}
		});

		input.addEventListener('blur', function () {
			window.setTimeout(function () {
				close();

				if (!cities || !input.value.trim()) {
					say('');
					return;
				}

				var hit = exact(input.value);

				if (hit) {
					// Store the government's spelling, whatever was typed.
					if (input.value !== hit.name) {
						input.value = hit.name;
						recalculate();
					}

					say('');
				} else {
					say(strings.pick || '');
				}
			}, 120);
		});
	}

	function scan() {
		var inputs = document.querySelectorAll('[data-gueta-city]');

		Array.prototype.forEach.call(inputs, attach);
	}

	scan();

	// WooCommerce redraws parts of the checkout as the order changes.
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout country_to_state_changed', scan);
	}
}());

/**
 * Checkout: taking a line out of the order.
 *
 * The row goes at once. Its key is put in the checkout form and WooCommerce
 * is asked to refresh the summary; the refresh posts the form, the theme
 * takes the line out before the totals are worked out, and the same answer
 * brings the new totals, the delivery prices, the drawer and the header
 * count. One request, where it used to take three.
 *
 * Taking out the last line leaves nothing to check out, so that one goes
 * through the drawer's handler and the page reloads for WooCommerce to show
 * the empty cart. Should a refresh come back with no lines, as when two were
 * removed together, the page reloads too.
 *
 * The drawer changes the same cart, so a change made there refreshes the
 * summary as well.
 */
(function () {
	'use strict';

	var settings = window.guetaCheckout || {};
	var strings = settings.strings || {};

	function rows() {
		return Array.prototype.filter.call(
			document.querySelectorAll('.woocommerce-checkout-review-order-table .cart_item'),
			function (row) {
				return !row.classList.contains('is-removing');
			}
		);
	}

	function hide(row) {
		if (!row) {
			return;
		}

		row.classList.add('is-removing');

		window.setTimeout(function () {
			row.hidden = true;
		}, 200);
	}

	function restore(row, button) {
		if (row) {
			row.hidden = false;
			row.classList.remove('is-removing');
		}

		if (button) {
			button.disabled = false;
		}

		if (window.guetaNotices && strings.removeError) {
			window.guetaNotices.show('error', strings.removeError);
		}
	}

	// The last line: through the drawer's handler, then a fresh page.
	function removeLast(key, row, button) {
		var payload = new URLSearchParams();
		var url = settings.wcAjaxUrl ? settings.wcAjaxUrl.replace('%%endpoint%%', 'gueta_cart_update') : settings.ajaxUrl;

		payload.append('action', 'gueta_cart_update');
		payload.append('nonce', settings.nonce || '');
		payload.append('key', key);
		payload.append('quantity', '0');

		fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: payload.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (data) {
				if (data && data.success) {
					window.location.reload();
				} else {
					restore(row, button);
				}
			})
			.catch(function () {
				restore(row, button);
			});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-review-remove]');

		if (!button || button.disabled) {
			return;
		}

		event.preventDefault();

		var key = button.getAttribute('data-review-remove');
		var row = button.closest('.cart_item');
		var form = document.querySelector('form.checkout');
		var last = rows().length <= 1;

		button.disabled = true;
		hide(row);

		if (last || !form || !window.jQuery) {
			removeLast(key, row, button);
			return;
		}

		var field = document.createElement('input');

		field.type = 'hidden';
		field.name = 'gueta_remove_cart_item[]';
		field.value = key;
		field.setAttribute('data-review-removal', '');
		form.appendChild(field);

		window.jQuery(document.body).trigger('update_checkout');
	});

	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', function () {
			var sent = document.querySelectorAll('[data-review-removal]');

			if (!sent.length) {
				return;
			}

			Array.prototype.forEach.call(sent, function (field) {
				field.parentNode.removeChild(field);
			});

			if (!document.querySelector('.woocommerce-checkout-review-order-table .cart_item')) {
				window.location.reload();
			}
		});
	}

	document.addEventListener('gueta:cart-updated', function (event) {
		var count = event.detail ? event.detail.count : null;

		if (null !== count && undefined !== count && 0 === Number(count)) {
			window.location.reload();
			return;
		}

		if (window.jQuery) {
			window.jQuery(document.body).trigger('update_checkout');
		}
	});
}());
