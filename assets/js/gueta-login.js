/**
 * The login step before the checkout: a code by email, or Google.
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

	if (settings.google && googleSlot) {
		// Google's library loads async, so wait for it rather than assume it is
		// there. If it never comes, say a blocker ate it, the email way stands alone.
		var tries = 0;
		var timer = setInterval(function () {
			if (startGoogle()) {
				clearInterval(timer);
			} else if (++tries > 80) {
				clearInterval(timer);
				googleSlot.hidden = true;
				root.querySelector('[data-login-or]').hidden = true;
			}
		}, 100);
	}
})();
