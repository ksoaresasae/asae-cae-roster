(function () {
	'use strict';

	var btn = document.getElementById('asae-cae-check-updates');
	var statusEl = document.getElementById('asae-cae-check-updates-status');

	if (!btn || !statusEl) {
		return;
	}

	btn.addEventListener('click', function () {
		if (typeof asaeCae === 'undefined' || !asaeCae.ajaxUrl) {
			return;
		}

		btn.disabled = true;
		statusEl.textContent = asaeCae.strings.checking;

		var formData = new FormData();
		formData.append('action', 'asae_cae_check_updates');
		formData.append('nonce', asaeCae.nonce);

		fetch(asaeCae.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: formData
		})
			.then(function (resp) { return resp.json(); })
			.then(function (data) {
				btn.disabled = false;
				if (data && data.success) {
					statusEl.textContent = (data.data && data.data.message) || asaeCae.strings.checked;
				} else {
					statusEl.textContent = (data && data.data && data.data.message) || asaeCae.strings.error;
				}
			})
			.catch(function () {
				btn.disabled = false;
				statusEl.textContent = asaeCae.strings.error;
			});
	});
})();
