/**
 * Product comparison.
 *
 * The selection lives in localStorage so it survives navigation between
 * category pages, which is the whole point of comparing.
 */
(function () {
	'use strict';

	var settings = window.guetaCompare || {};
	var bar = document.querySelector('[data-compare-bar]');
	var drawer = document.querySelector('[data-compare-drawer]');

	if (!bar || !drawer) {
		return;
	}

	var STORAGE_KEY = 'guetaCompare';
	var max = settings.max || 5;
	var list = bar.querySelector('[data-compare-list]');
	var counter = bar.querySelector('[data-compare-count]');
	var openButton = bar.querySelector('[data-compare-open]');
	var body = drawer.querySelector('[data-compare-body]');
	var selection = read();
	var meta = {};

	function read() {
		try {
			var raw = window.localStorage.getItem(STORAGE_KEY);
			var parsed = raw ? JSON.parse(raw) : [];

			return Array.isArray(parsed) ? parsed.map(Number).filter(Boolean).slice(0, max) : [];
		} catch (error) {
			return [];
		}
	}

	function write() {
		try {
			window.localStorage.setItem(STORAGE_KEY, JSON.stringify(selection));
		} catch (error) {
			// A private window can refuse to store; the session still works.
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

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	function syncSwitches() {
		var toggles = document.querySelectorAll('[data-compare-toggle]');

		Array.prototype.forEach.call(toggles, function (toggle) {
			var id = Number(toggle.value);
			var checked = selection.indexOf(id) !== -1;

			toggle.checked = checked;
			// Block adding a sixth, but never block removing one.
			toggle.disabled = !checked && selection.length >= max;
		});
	}

	function renderBar() {
		counter.textContent = String(selection.length);
		openButton.disabled = selection.length < 2;

		var html = '';

		selection.forEach(function (id) {
			var item = meta[id];
			var image = item && item.image
				? '<img src="' + item.image + '" alt="" loading="lazy">'
				: '';

			html += '<li class="gueta-compare-bar__item" data-compare-item="' + id + '">'
				+ image
				+ '<button type="button" class="gueta-compare-bar__remove" data-compare-remove="' + id + '" aria-label="הסרה מההשוואה">'
				+ '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>'
				+ '</button></li>';
		});

		for (var i = selection.length; i < max; i++) {
			html += '<li class="gueta-compare-bar__item gueta-compare-bar__item--empty"></li>';
		}

		list.innerHTML = html;

		if (selection.length) {
			bar.hidden = false;
			window.requestAnimationFrame(function () {
				bar.classList.add('is-visible');
			});
		} else {
			bar.classList.remove('is-visible');
			closeDrawer();
			window.setTimeout(function () {
				if (!selection.length) {
					bar.hidden = true;
				}
			}, 320);
		}
	}

	function refresh(withTable) {
		syncSwitches();

		if (!selection.length) {
			meta = {};
			renderBar();
			return;
		}

		post('gueta_compare', { ids: selection.join(',') })
			.then(function (data) {
				if (!data || !data.success) {
					return;
				}

				// Drop anything the server no longer considers visible.
				var returned = (data.data.items || []).map(function (item) {
					return Number(item.id);
				});

				if (returned.length !== selection.length) {
					selection = selection.filter(function (id) {
						return returned.indexOf(id) !== -1;
					});
					write();
					syncSwitches();
				}

				meta = {};
				(data.data.items || []).forEach(function (item) {
					meta[item.id] = item;
				});

				renderBar();

				if (withTable) {
					body.innerHTML = data.data.html || '';
				}
			})
			.catch(function () {
				renderBar();
			});
	}

	/* ---------------------------------------------------------------------
	 * Selection
	 * ------------------------------------------------------------------ */

	function add(id) {
		if (selection.indexOf(id) !== -1 || selection.length >= max) {
			return;
		}

		selection.push(id);
		write();
		refresh(isDrawerOpen());
	}

	function remove(id) {
		var at = selection.indexOf(id);

		if (at === -1) {
			return;
		}

		selection.splice(at, 1);
		write();
		refresh(isDrawerOpen());
	}

	function clear() {
		selection = [];
		write();
		refresh(false);
	}

	/* ---------------------------------------------------------------------
	 * Drawer
	 * ------------------------------------------------------------------ */

	function isDrawerOpen() {
		return drawer.classList.contains('is-open');
	}

	function openDrawer() {
		drawer.hidden = false;
		body.innerHTML = '<p class="gueta-compare-loading">טוענים…</p>';

		window.requestAnimationFrame(function () {
			drawer.classList.add('is-open');
		});

		document.body.classList.add('gueta-locked');
		refresh(true);
	}

	function closeDrawer() {
		if (!isDrawerOpen()) {
			return;
		}

		drawer.classList.remove('is-open');
		document.body.classList.remove('gueta-locked');

		window.setTimeout(function () {
			if (!isDrawerOpen()) {
				drawer.hidden = true;
			}
		}, 380);
	}

	/* ---------------------------------------------------------------------
	 * Events
	 * ------------------------------------------------------------------ */

	document.addEventListener('change', function (event) {
		var toggle = event.target.closest('[data-compare-toggle]');

		if (!toggle) {
			return;
		}

		var id = Number(toggle.value);

		if (toggle.checked) {
			add(id);
		} else {
			remove(id);
		}
	});

	// The shop's cards wrap everything in one link; keep the switch from following it.
	document.addEventListener('click', function (event) {
		if (event.target.closest('[data-compare-switch]')) {
			event.stopPropagation();
			event.preventDefault();

			var input = event.target.closest('[data-compare-switch]').querySelector('[data-compare-toggle]');

			if (input && !input.disabled) {
				input.checked = !input.checked;
				input.dispatchEvent(new Event('change', { bubbles: true }));
			}

			return;
		}

		var removeButton = event.target.closest('[data-compare-remove]');

		if (removeButton) {
			remove(Number(removeButton.getAttribute('data-compare-remove')));
			return;
		}

		if (event.target.closest('[data-compare-clear]')) {
			clear();
			return;
		}

		if (event.target.closest('[data-compare-open]')) {
			openDrawer();
			return;
		}

		if (event.target.closest('[data-compare-close]')) {
			closeDrawer();
		}
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && isDrawerOpen()) {
			closeDrawer();
		}
	});

	// Another tab changing the selection keeps this one in step.
	window.addEventListener('storage', function (event) {
		if (STORAGE_KEY === event.key) {
			selection = read();
			refresh(isDrawerOpen());
		}
	});

	refresh(false);
}());
