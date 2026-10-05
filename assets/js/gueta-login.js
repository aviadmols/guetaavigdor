/**
 * The login step before the checkout: a code by email, Google, or going on as
 * a guest with just an address. Without SMTP set up there is no code to send,
 * and the email form takes the guest way by itself.
 *
 * On most pages the step is a popup. A guest who clicks through to the
 * checkout, from the cart drawer, the cart page or anywhere else, stays where
 * they are and the popup opens; once they are through, the browser goes on to
 * the checkout. The checkout reached directly shows the same step as its page.
 *
 * Settings arrive in guetaLogin from gueta-login.php.
 */
(function () {
	'use strict';

	var settings = window.guetaLogin;
	var root = document.querySelector('[data-gueta-login]');

	if (!settings || !root) {
		return;
	}

	var modal = document.querySelector('[data-login-modal]');
	var emailStep = root.querySelector('[data-login-step="email"]');
	var codeStep = root.querySelector('[data-login-step="code"]');
	var emailInput = emailStep.querySelector('input[name="email"]');
	var codeInput = codeStep.querySelector('input[name="code"]');
	var address = root.querySelector('[data-login-address]');
	var message = root.querySelector('[data-login-message]');
	var email = '';
	var busy = false;

	function say(html, isError) {
		// The server's messages are its own fixed strings; a staff notice carries a link.
		message.innerHTML = html || '';
		message.classList.toggle('is-error', !!isError);
	}

	function post(action, data) {
		var body = new FormData();

		body.append('nonce', settings.nonce);
		Object.keys(data).forEach(function (key) {
			body.append(key, data[key]);
		});

		return fetch(settings.endpoint.replace('%%endpoint%%', action), {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		}).then(function (response) {
			return response.json().catch(function () {
				return { success: false, data: { message: settings.strings.error } };
			});
		});
	}

	function setBusy(form, value) {
		busy = value;
		form.querySelectorAll('button').forEach(function (button) {
			button.disabled = value;
		});
		root.classList.toggle('is-busy', value);
	}

	function finish(result) {
		if (result && result.success) {
			window.location.href = (result.data && result.data.redirect) || settings.redirect;
			return true;
		}

		say((result && result.data && result.data.message) || settings.strings.error, true);
		return false;
	}

	function sendCode(form, onSent) {
		setBusy(form, true);
		say(settings.strings.sending);

		post('gueta_login_send_code', { email: email })
			.then(function (result) {
				setBusy(form, false);

				if (!result.success) {
					say((result.data && result.data.message) || settings.strings.error, true);
					return;
				}

				onSent();
			})
			.catch(function () {
				setBusy(form, false);
				say(settings.strings.error, true);
			});
	}

	/*
	 * On to the checkout unverified. "המשך" needs the address, which goes on
	 * the order the server opens for the cart; "המשך כאורח" goes on without
	 * one, though an address already typed in is sent along.
	 */
	function continueAsGuest(needEmail) {
		email = emailInput.value.trim();

		var valid = !!email && emailInput.checkValidity();

		if (needEmail && !valid) {
			say(settings.strings.email, true);
			emailInput.focus();
			return;
		}

		if (!valid) {
			email = '';
		}

		setBusy(emailStep, true);
		say(settings.strings.continuing);

		post('gueta_login_guest', { email: email })
			.then(function (result) {
				if (!finish(result)) {
					setBusy(emailStep, false);
				}
			})
			.catch(function () {
				setBusy(emailStep, false);
				say(settings.strings.error, true);
			});
	}

	function showCodeStep() {
		address.textContent = email;
		emailStep.hidden = true;
		codeStep.hidden = false;
		codeInput.value = '';
		say('');
		codeInput.focus();
	}

	emailStep.addEventListener('submit', function (event) {
		event.preventDefault();

		if (busy) {
			return;
		}

		if ('guest' === emailStep.getAttribute('data-login-mode')) {
			continueAsGuest(true);
			return;
		}

		email = emailInput.value.trim();

		if (!emailInput.checkValidity() || !email) {
			say(settings.strings.email, true);
			emailInput.focus();
			return;
		}

		sendCode(emailStep, showCodeStep);
	});

	codeStep.addEventListener('submit', function (event) {
		event.preventDefault();

		if (busy) {
			return;
		}

		var code = codeInput.value.replace(/\D/g, '');

		if (code.length !== 6) {
			say(settings.strings.code, true);
			codeInput.focus();
			return;
		}

		setBusy(codeStep, true);

		post('gueta_login_verify_code', { email: email, code: code })
			.then(function (result) {
				if (!finish(result)) {
					setBusy(codeStep, false);
					codeInput.select();
				}
			})
			.catch(function () {
				setBusy(codeStep, false);
				say(settings.strings.error, true);
			});
	});

	// Six digits pasted or typed send themselves; nobody needs to find the button.
	codeInput.addEventListener('input', function () {
		var digits = codeInput.value.replace(/\D/g, '').slice(0, 6);

		if (digits !== codeInput.value) {
			codeInput.value = digits;
		}

		if (digits.length === 6 && !busy) {
			codeStep.requestSubmit();
		}
	});

	var guestButton = emailStep.querySelector('[data-login-guest]');

	if (guestButton) {
		guestButton.addEventListener('click', function () {
			if (!busy) {
				continueAsGuest(false);
			}
		});
	}

	root.querySelector('[data-login-resend]').addEventListener('click', function () {
		if (!busy) {
			sendCode(codeStep, function () {
				say(settings.strings.resent);
				codeInput.focus();
			});
		}
	});

	root.querySelector('[data-login-change]').addEventListener('click', function () {
		codeStep.hidden = true;
		emailStep.hidden = false;
		say('');
		emailInput.focus();
	});

	/* ---------------------------------------------------------------------
	 * Google
	 * ------------------------------------------------------------------ */

	var googleSlot = root.querySelector('[data-login-google]');
	var googleStarted = false;

	function hideGoogle() {
		googleSlot.hidden = true;
		root.querySelector('[data-login-or]').hidden = true;
	}

	function startGoogle() {
		if (!window.google || !google.accounts || !google.accounts.id) {
			return false;
		}

		google.accounts.id.initialize({
			client_id: settings.google,
			ux_mode: 'popup',
			callback: function (response) {
				say(settings.strings.sending);
				root.classList.add('is-busy');

				post('gueta_login_google', { credential: response.credential })
					.then(function (result) {
						if (!finish(result)) {
							root.classList.remove('is-busy');
						}
					})
					.catch(function () {
						root.classList.remove('is-busy');
						say(settings.strings.error, true);
					});
			}
		});

		google.accounts.id.renderButton(googleSlot, {
			type: 'standard',
			theme: 'outline',
			size: 'large',
			text: 'continue_with',
			shape: 'rectangular',
			locale: 'he',
			width: Math.min(400, googleSlot.clientWidth || 320)
		});

		return true;
	}

	// Google's library is fetched only once the step is on screen. If it never
	// comes, say a blocker ate it, the email way stands alone.
	function loadGoogle() {
		if (googleStarted || !settings.google || !googleSlot) {
			return;
		}

		googleStarted = true;

		if (!window.google || !google.accounts) {
			var script = document.createElement('script');

			script.src = 'https://accounts.google.com/gsi/client';
			script.async = true;
			script.onerror = hideGoogle;
			document.head.appendChild(script);
		}

		var tries = 0;
		var timer = setInterval(function () {
			if (startGoogle()) {
				clearInterval(timer);
			} else if (++tries > 80) {
				clearInterval(timer);
				hideGoogle();
			}
		}, 100);
	}

	/* ---------------------------------------------------------------------
	 * The popup
	 * ------------------------------------------------------------------ */

	if (!modal) {
		// The checkout's own page: the step is already in view.
		loadGoogle();
		return;
	}

	var checkoutPath = new URL(settings.redirect, window.location.href).pathname.replace(/\/+$/, '');
	var returnFocus = null;

	function isCheckoutLink(link) {
		if (!link || !link.href || '_blank' === link.target) {
			return false;
		}

		try {
			var url = new URL(link.href, window.location.href);

			return url.origin === window.location.origin && url.pathname.replace(/\/+$/, '') === checkoutPath;
		} catch (error) {
			return false;
		}
	}

	function openModal() {
		returnFocus = document.activeElement;
		modal.hidden = false;
		document.documentElement.classList.add('gueta-login-open');

		// One frame later, so the opening transition has a start to run from.
		window.requestAnimationFrame(function () {
			modal.classList.add('is-open');
			emailInput.focus();
		});

		loadGoogle();
	}

	function closeModal() {
		if (modal.hidden || busy) {
			return;
		}

		modal.classList.remove('is-open');
		document.documentElement.classList.remove('gueta-login-open');

		window.setTimeout(function () {
			if (!modal.classList.contains('is-open')) {
				modal.hidden = true;
			}
		}, 300);

		if (returnFocus && returnFocus.focus) {
			returnFocus.focus();
		}
	}

	/*
	 * The page may be a stored copy, or older than the cart, and its nonce no
	 * longer good: WooCommerce ties a guest's nonce to the cart session. Ask
	 * for a fresh one, and learn whether the step is still needed at all;
	 * someone who logged in in another tab just goes on.
	 */
	function refresh() {
		return fetch(settings.endpoint.replace('%%endpoint%%', 'gueta_login_state'), {
			credentials: 'same-origin',
			cache: 'no-store'
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (result) {
				if (result && result.success && result.data) {
					settings.nonce = result.data.nonce || settings.nonce;
					return false !== result.data.needed;
				}

				return true;
			})
			.catch(function () {
				return true;
			});
	}

	// Capture, so the popup opens before anything else acts on the click.
	document.addEventListener('click', function (event) {
		if (event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
			return;
		}

		var link = event.target.closest('a[href]');

		if (!isCheckoutLink(link)) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();
		settings.redirect = link.href;
		openModal();

		refresh().then(function (needed) {
			if (!needed) {
				window.location.href = settings.redirect;
			}
		});
	}, true);

	modal.addEventListener('click', function (event) {
		if (event.target.closest('[data-login-close]')) {
			closeModal();
		}
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && !modal.hidden) {
			closeModal();
		}
	});
})();
