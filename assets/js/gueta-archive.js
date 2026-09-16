/**
 * Category archive: filtering, sorting, load more and quick view.
 *
 * Filter state is mirrored into the URL so a filtered view can be shared and
 * the back button behaves, and the grid is fetched rather than reloaded.
 */
(function () {
	'use strict';

	var settings = window.guetaArchive || {};
	var archive = document.querySelector('[data-archive]');

	if (!archive) {
		return;
	}

	var form = archive.querySelector('[data-archive-form]');
	var grid = archive.querySelector('[data-archive-grid]');
	var countLabel = archive.querySelector('[data-archive-count]');
	var sortSelect = archive.querySelector('[data-archive-sort]');
	var activeCount = archive.querySelector('[data-archive-active-count]');
	var clearButton = archive.querySelector('.gueta-archive__clear');
	var moreButton = archive.querySelector('[data-archive-more]');
	var compareToggle = archive.querySelector('[data-archive-compare-toggle]');
	var category = archive.getAttribute('data-category') || '';
	var page = 1;
	var pending = null;
	var debounce = null;

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

	/* ---------------------------------------------------------------------
	 * Sub category chips
	 *
	 * The arrows show only while there is more row to see on their side. A
	 * press moves most of a view, leaving a chip of the last one in sight so
	 * the eye keeps its place, or goes to the end when that is nearly all
	 * that is left. In right to left scrollLeft runs from 0 down to negative
	 * numbers, so position is read from its size and a step is mirrored.
	 * ------------------------------------------------------------------ */

	(function chips() {
		var wrap = archive.querySelector('[data-chips]');
		var list = wrap ? wrap.querySelector('[data-chips-list]') : null;

		if (!list) {
			return;
		}

		var prev = wrap.querySelector('[data-chips-step="-1"]');
		var next = wrap.querySelector('[data-chips-step="1"]');
		var rtl = 'rtl' === window.getComputedStyle(list).direction;
		var frame = 0;

		function update() {
			frame = 0;

			var travelled = Math.abs(list.scrollLeft);
			var room = list.scrollWidth - list.clientWidth;

			prev.hidden = travelled <= 1;
			next.hidden = travelled >= room - 1;
			wrap.classList.toggle('has-before', !prev.hidden);
			wrap.classList.toggle('has-after', !next.hidden);
		}

		function schedule() {
			if (!frame) {
				frame = window.requestAnimationFrame(update);
			}
		}

		wrap.addEventListener('click', function (event) {
			var arrow = event.target.closest('[data-chips-step]');

			if (!arrow) {
				return;
			}

			var view = list.clientWidth;
			var room = list.scrollWidth - view;
			var target = Math.abs(list.scrollLeft) + Math.max(view * 0.8, 120) * Number(arrow.getAttribute('data-chips-step'));
			var still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

			// Close to an end, go all the way, rather than leave a sliver for one more press.
			if (target > room - view * 0.2) {
				target = room;
			}

			if (target < view * 0.2) {
				target = 0;
			}

			list.scrollTo({ left: rtl ? -target : target, behavior: still ? 'auto' : 'smooth' });
		});

		list.addEventListener('scroll', schedule, { passive: true });
		window.addEventListener('resize', schedule);
		window.addEventListener('load', schedule);

		if (document.fonts && document.fonts.ready) {
			document.fonts.ready.then(schedule);
		}

		update();
	}());

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	function collect() {
		var state = { category: category, sort: sortSelect ? sortSelect.value : 'menu_order', paged: page };
		var groups = {};

		Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"]'), function (input) {
			if (!input.checked) {
				return;
			}

			groups[input.name] = groups[input.name] || [];
			groups[input.name].push(input.value);
		});

		Object.keys(groups).forEach(function (name) {
			state[name] = groups[name];
		});

		Array.prototype.forEach.call(form.querySelectorAll('input[type="number"]'), function (input) {
			if ('' !== input.value) {
				state[input.name] = input.value;
			}
		});

		return state;
	}

	function countActive(state) {
		var total = 0;

		Object.keys(state).forEach(function (key) {
			if ('category' === key || 'sort' === key || 'paged' === key) {
				return;
			}

			total += Array.isArray(state[key]) ? state[key].length : 1;
		});

		return total;
	}

	function syncUrl(state) {
		var params = new URLSearchParams();

		Object.keys(state).forEach(function (key) {
			if ('category' === key || 'paged' === key) {
				return;
			}

			if ('sort' === key && 'menu_order' === state[key]) {
				return;
			}

			params.set(key, Array.isArray(state[key]) ? state[key].join(',') : state[key]);
		});

		var query = params.toString();

		window.history.replaceState({}, '', query ? location.pathname + '?' + query : location.pathname);
	}

	/* ---------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	function load(append) {
		var state = collect();
		var active = countActive(state);

		if (activeCount) {
			activeCount.textContent = String(active);
			activeCount.hidden = !active;
		}

		if (clearButton) {
			clearButton.hidden = !active;
		}

		if (!append) {
			syncUrl(state);
		}

		archive.classList.add('is-loading');

		if (pending) {
			pending.abort();
		}

		pending = window.AbortController ? new AbortController() : null;

		post('gueta_archive', { state: JSON.stringify(state) })
			.then(function (data) {
				archive.classList.remove('is-loading');

				if (!data || !data.success) {
					return;
				}

				if (append) {
					grid.insertAdjacentHTML('beforeend', data.data.html);
				} else {
					grid.innerHTML = data.data.html;
				}

				if (countLabel) {
					countLabel.textContent = data.data.label;
				}

				if (moreButton) {
					moreButton.hidden = page >= data.data.pages;
				}

				// New cards need their compare switches reconciled.
				document.dispatchEvent(new CustomEvent('gueta:cards-rendered'));
			})
			.catch(function () {
				archive.classList.remove('is-loading');
			});
	}

	function reload() {
		page = 1;

		if (moreButton) {
			moreButton.hidden = false;
		}

		load(false);
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	form.addEventListener('change', function (event) {
		if (event.target.matches('input[type="checkbox"]')) {
			reload();
		}
	});

	form.addEventListener('input', function (event) {
		if (!event.target.matches('input[type="number"]')) {
			return;
		}

		window.clearTimeout(debounce);
		debounce = window.setTimeout(reload, 450);
	});

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		reload();
	});

	if (sortSelect) {
		sortSelect.addEventListener('change', reload);
	}

	if (moreButton) {
		moreButton.addEventListener('click', function () {
			page += 1;
			load(true);
		});
	}

	// Delegated: reset exists both in the toolbar and in the drawer's footer.
	archive.addEventListener('click', function (event) {
		if (!event.target.closest('[data-archive-reset]')) {
			return;
		}

		Array.prototype.forEach.call(form.querySelectorAll('input'), function (input) {
			if ('checkbox' === input.type) {
				input.checked = false;
			} else {
				input.value = '';
			}
		});

		reload();
	});

	// The filter drawer on narrow screens.
	archive.addEventListener('click', function (event) {
		if (event.target.closest('[data-archive-filters-open]')) {
			archive.classList.add('is-filtering');
			document.body.classList.add('gueta-locked');
			return;
		}

		if (event.target.closest('[data-archive-filters-close]')) {
			archive.classList.remove('is-filtering');
			document.body.classList.remove('gueta-locked');
		}
	});

	if (compareToggle) {
		compareToggle.addEventListener('change', function () {
			archive.classList.toggle('is-comparing', compareToggle.checked);

			try {
				window.localStorage.setItem('guetaCompareMode', compareToggle.checked ? '1' : '');
			} catch (error) {
				// Storage can be refused; the toggle still works for this view.
			}

			/*
			 * The comparison bar lives in the footer and is driven by its own
			 * script, which has no way to see this switch move. The storage event
			 * only reaches other tabs, so say it out loud for this one.
			 */
			document.dispatchEvent(new CustomEvent('gueta:compare-mode', {
				detail: { on: compareToggle.checked }
			}));
		});

		try {
			if (window.localStorage.getItem('guetaCompareMode')) {
				compareToggle.checked = true;
				archive.classList.add('is-comparing');
			}
		} catch (error) {
			// Ignore.
		}
	}

	/* ---------------------------------------------------------------------
	 * Quick view
	 * ------------------------------------------------------------------ */

	(function quickView() {
		var modal = document.querySelector('[data-quickview-modal]');

		if (!modal) {
			return;
		}

		var body = modal.querySelector('[data-quickview-body]');
		var opener = null;

		function open(id) {
			opener = document.activeElement;
			modal.hidden = false;
			body.innerHTML = '<p class="gueta-quickview__loading">טוענים…</p>';

			window.requestAnimationFrame(function () {
				modal.classList.add('is-open');
			});

			document.body.classList.add('gueta-locked');

			post('gueta_quickview', { product: id })
				.then(function (data) {
					if (data && data.success) {
						body.innerHTML = data.data.html;
						// The gallery it carries needs its own lightbox wiring.
						document.dispatchEvent(new CustomEvent('gueta:quickview-rendered'));
						return;
					}

					body.innerHTML = '<p class="gueta-quickview__loading">לא הצלחנו לטעון את המוצר.</p>';
				})
				.catch(function () {
					body.innerHTML = '<p class="gueta-quickview__loading">לא הצלחנו לטעון את המוצר.</p>';
				});
		}

		function close() {
			if (!modal.classList.contains('is-open')) {
				return;
			}

			modal.classList.remove('is-open');
			document.body.classList.remove('gueta-locked');

			window.setTimeout(function () {
				if (!modal.classList.contains('is-open')) {
					modal.hidden = true;
					body.innerHTML = '';
				}
			}, 320);

			if (opener && opener.focus) {
				opener.focus();
				opener = null;
			}
		}

		document.addEventListener('click', function (event) {
			var trigger = event.target.closest('[data-quickview]');

			if (trigger) {
				event.preventDefault();
				open(Number(trigger.getAttribute('data-quickview')));
				return;
			}

			if (event.target.closest('[data-quickview-close]')) {
				close();
			}
		});

		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key) {
				close();
			}
		});

		// Adding from quick view should show the cart, then get out of the way.
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart', function () {
				close();
			});
		}
	}());
}());
