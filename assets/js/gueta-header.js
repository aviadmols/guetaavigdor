(function () {
	'use strict';

	const header = document.querySelector('[data-gueta-header]');
	if (!header) return;

	const searchPanel = header.querySelector('[data-search-panel]');
	const searchInput = header.querySelector('[data-search-input]');
	const searchToggle = header.querySelector('[data-search-toggle]');
	const results = header.querySelector('[data-search-results]');
	const mobileMenu = document.querySelector('[data-mobile-menu]');
	let searchTimer;

	function setMenu(open) {
		document.body.classList.toggle('gueta-menu-open', open);
		mobileMenu.setAttribute('aria-hidden', String(!open));
		header.querySelector('[data-menu-toggle]').setAttribute('aria-expanded', String(open));
	}

	header.querySelector('[data-menu-toggle]').addEventListener('click', () => setMenu(true));
	document.querySelectorAll('[data-menu-close]').forEach((element) => element.addEventListener('click', () => setMenu(false)));
	searchToggle.addEventListener('click', () => {
		const open = searchPanel.classList.toggle('is-open');
		searchToggle.setAttribute('aria-expanded', String(open));
		if (open) searchInput.focus();
	});

	searchInput.addEventListener('input', () => {
		clearTimeout(searchTimer);
		const term = searchInput.value.trim();
		if (term.length < 2) {
			results.innerHTML = '';
			return;
		}
		results.innerHTML = '<p class="gueta-search-loading">מחפש...</p>';
		searchTimer = setTimeout(() => {
			const body = new URLSearchParams({ action: 'gueta_header_search', nonce: guetaHeader.nonce, term });
			fetch(guetaHeader.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body })
				.then((response) => response.json())
				.then((data) => { results.innerHTML = data.success ? data.data.html : ''; })
				.catch(() => { results.innerHTML = ''; });
		}, 250);
	});
})();