/**
 * WooCommerce's notices as toasts that slide in from the side.
 *
 * Whatever WooCommerce prints as a notice, on page load or into the page
 * later, such as a checkout error, is lifted out of the page into a card at
 * the edge of the screen: a coloured rule, an icon, a title, the message
 * with its links, a close button and, for anything that is not an error, a
 * bar that runs down before it leaves by itself. An error stays until it is
 * closed. The same message twice shows once. Without this script the notices
 * stay where WooCommerce put them.
 *
 * window.guetaNotices.show(type, html) raises one, and fromServer() collects
 * the notices an AJAX request left in the session and raises those.
 */
(function () {
	'use strict';

	var settings = window.guetaNoticesSettings || {};
	var titles = settings.titles || { error: 'שגיאה', success: 'בוצע', info: 'לתשומת לבך' };
	var lasting = { success: 5000, info: 7000, error: 0 };
	var maxShown = 4;

	/* Where WooCommerce puts the notices that are messages, rather than page content. */
	var scopes = '.woocommerce-notices-wrapper, .woocommerce-NoticeGroup';
	var notices = '.woocommerce-error, .woocommerce-message, .woocommerce-info';

	var icons = {
		error: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7.5v5.5M12 16.6v.1"/></svg>',
		success: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 12.5 3.2 3.2L17 9"/></svg>',
		info: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 11v5.5M12 7.4v.1"/></svg>'
	};

	var region = null;
	var open = {};

	function stack() {
		if (!region) {
			region = document.createElement('div');
			region.className = 'gueta-toasts';
			region.setAttribute('aria-live', 'polite');
			document.body.appendChild(region);
		}

		return region;
	}

	function plain(html) {
		var holder = document.createElement('div');

		holder.innerHTML = html;

		return (holder.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function escapeText(text) {
		var holder = document.createElement('div');

		holder.textContent = text;

		return holder.innerHTML;
	}

	function show(type, html) {
		type = lasting.hasOwnProperty(type) ? type : 'info';

		var text = plain(html);

		if (!text) {
			return;
		}

		var key = type + '|' + text;

		// Said already and still on screen: draw the eye to that one again.
		if (open[key]) {
			open[key].again();
			return;
		}

		var toast = document.createElement('div');

		toast.className = 'gueta-toast gueta-toast--' + type;
		toast.setAttribute('role', 'error' === type ? 'alert' : 'status');
		toast.innerHTML = '<span class="gueta-toast__icon">' + icons[type] + '</span>'
			+ '<div class="gueta-toast__body"><p class="gueta-toast__title"></p><div class="gueta-toast__text"></div></div>'
			+ '<button type="button" class="gueta-toast__close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>';

		toast.querySelector('.gueta-toast__title').textContent = titles[type] || '';
		toast.querySelector('.gueta-toast__text').innerHTML = html;
		toast.querySelector('.gueta-toast__close').setAttribute('aria-label', settings.close || 'סגירת ההודעה');

		var duration = lasting[type];
		var timer = null;
		var endsAt = 0;
		var remaining = duration;

		function leave() {
			window.clearTimeout(timer);
			delete open[key];
			toast.classList.remove('is-in');
			toast.classList.add('is-leaving');
			window.setTimeout(function () {
				toast.remove();
			}, 400);
		}

		function run(ms) {
			if (!duration) {
				return;
			}

			window.clearTimeout(timer);
			endsAt = Date.now() + ms;
			timer = window.setTimeout(leave, ms);
		}

		function restartBar() {
			var bar = toast.querySelector('.gueta-toast__timer');

			if (bar) {
				bar.remove();
			}

			if (!duration) {
				return;
			}

			bar = document.createElement('span');
			bar.className = 'gueta-toast__timer';
			bar.style.animationDuration = duration + 'ms';
			toast.appendChild(bar);
		}

		toast.querySelector('.gueta-toast__close').addEventListener('click', leave);

		// A hand on the card holds it, the way a reader would expect.
		toast.addEventListener('mouseenter', function () {
			if (duration) {
				window.clearTimeout(timer);
				remaining = Math.max(800, endsAt - Date.now());
				toast.classList.add('is-held');
			}
		});

		toast.addEventListener('mouseleave', function () {
			if (duration) {
				toast.classList.remove('is-held');
				run(remaining);
			}
		});

		open[key] = {
			again: function () {
				toast.classList.remove('is-again');
				void toast.offsetWidth;
				toast.classList.add('is-again');
				restartBar();
				run(duration);
			}
		};

		var holder = stack();

		holder.appendChild(toast);

		// The oldest steps out when the stack is full.
		var cards = holder.querySelectorAll('.gueta-toast:not(.is-leaving)');

		if (cards.length > maxShown) {
			cards[0].querySelector('.gueta-toast__close').click();
		}

		restartBar();
		run(duration);

		window.requestAnimationFrame(function () {
			window.requestAnimationFrame(function () {
				toast.classList.add('is-in');
			});
		});
	}

	function typeOf(notice) {
		if (notice.classList.contains('woocommerce-error')) {
			return 'error';
		}

		return notice.classList.contains('woocommerce-message') ? 'success' : 'info';
	}

	/*
	 * Lift every notice out of the places messages go. A list of errors is one
	 * card per error; a message or an info block, with its buttons, is one card.
	 */
	function collect() {
		Array.prototype.forEach.call(document.querySelectorAll(scopes), function (scope) {
			Array.prototype.forEach.call(scope.querySelectorAll(notices), function (notice) {
				if (notice.classList.contains('gueta-notice-moved')) {
					return;
				}

				notice.classList.add('gueta-notice-moved');

				var type = typeOf(notice);
				var items = 'UL' === notice.tagName ? notice.querySelectorAll(':scope > li') : [];

				if (items.length) {
					Array.prototype.forEach.call(items, function (item) {
						show(type, item.innerHTML);
					});
				} else {
					show(type, notice.innerHTML);
				}
			});
		});
	}

	function fromServer(fallback) {
		var done = function (list) {
			if (list && list.length) {
				list.forEach(function (item) {
					show(item.type, item.html);
				});
			} else if (fallback) {
				show('error', escapeText(fallback));
			}
		};

		if (!settings.endpoint || !window.fetch) {
			done([]);
			return;
		}

		window.fetch(settings.endpoint, { method: 'POST', credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (result) {
				done(result && result.success ? result.data : []);
			})
			.catch(function () {
				done([]);
			});
	}

	window.guetaNotices = {
		show: show,
		fromServer: fromServer
	};

	function start() {
		collect();

		if (!window.MutationObserver) {
			return;
		}

		// Checkout errors, coupon results and the like arrive after the page.
		var pending = false;

		new MutationObserver(function () {
			if (pending) {
				return;
			}

			pending = true;
			window.requestAnimationFrame(function () {
				pending = false;
				collect();
			});
		}).observe(document.body, { childList: true, subtree: true });
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
}());
