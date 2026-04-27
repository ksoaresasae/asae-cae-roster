/**
 * ASAE CAE Roster — admin tab UI.
 *
 * Wires the AJAX handlers (save settings, test connection, sync now, check
 * for updates) and the WP media-library default-photo picker.
 *
 * Tab navigation itself is server-rendered (real <a href> links) so browser
 * back/forward, deep-linking, and assistive tech all work without JS.
 */
(function () {
	'use strict';

	if (typeof asaeCaeAdmin === 'undefined') {
		return;
	}

	var S = asaeCaeAdmin.strings;

	function $(id) { return document.getElementById(id); }

	function setStatus(el, msg, kind) {
		if (!el) { return; }
		el.textContent = msg || '';
		el.classList.remove('asae-cae-msg-ok', 'asae-cae-msg-err', 'asae-cae-msg-busy');
		if (kind === 'ok')   { el.classList.add('asae-cae-msg-ok'); }
		if (kind === 'err')  { el.classList.add('asae-cae-msg-err'); }
		if (kind === 'busy') { el.classList.add('asae-cae-msg-busy'); }
	}

	/**
	 * Lightweight AJAX helper. Resolves with the parsed JSON, rejects on
	 * network or HTTP failure. Always sets `action` and `nonce`.
	 */
	function postAjax(action, fields) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', asaeCaeAdmin.nonce);
		if (fields) {
			Object.keys(fields).forEach(function (k) {
				fd.append(k, fields[k]);
			});
		}
		return fetch(asaeCaeAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		}).then(function (resp) {
			if (!resp.ok) {
				throw new Error('HTTP ' + resp.status);
			}
			return resp.json();
		});
	}

	/**
	 * Submit a <form> element with all its fields included as FormData. Used
	 * by Save Settings since the form has many fields with [bracket] names.
	 */
	function postForm(action, formEl) {
		var fd = new FormData(formEl);
		fd.append('action', action);
		fd.append('nonce', asaeCaeAdmin.nonce);
		return fetch(asaeCaeAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		}).then(function (resp) {
			if (!resp.ok) {
				throw new Error('HTTP ' + resp.status);
			}
			return resp.json();
		});
	}

	// ── Save Settings ────────────────────────────────────────────────────────

	var settingsForm = $('asae-cae-settings-form');
	if (settingsForm) {
		settingsForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn  = $('asae-cae-save-settings');
			var stat = $('asae-cae-save-status');
			if (btn) { btn.disabled = true; }
			setStatus(stat, S.saving, 'busy');

			postForm('asae_cae_save_settings', settingsForm)
				.then(function (data) {
					if (data && data.success) {
						setStatus(stat, (data.data && data.data.message) || S.saved, 'ok');
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.saveError, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.saveError, 'err'); })
				.then(function () { if (btn) { btn.disabled = false; } });
		});
	}

	// ── Test Connection ──────────────────────────────────────────────────────

	var testBtn = $('asae-cae-test-connection');
	if (testBtn) {
		testBtn.addEventListener('click', function () {
			var stat = $('asae-cae-test-status');
			testBtn.disabled = true;
			setStatus(stat, S.testing, 'busy');

			// Pull the current (possibly unsaved) values straight from the form.
			postAjax('asae_cae_test_connection', {
				base_url:  ($('asae-cae-base-url')  || {}).value || '',
				secret:    ($('asae-cae-secret')    || {}).value || '',
				person_id: ($('asae-cae-person-id') || {}).value || ''
			})
				.then(function (data) {
					if (data && data.success) {
						setStatus(stat, (data.data && data.data.message) || '', 'ok');
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.testFailed, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.testFailed, 'err'); })
				.then(function () { testBtn.disabled = false; });
		});
	}

	// ── Sync Now ─────────────────────────────────────────────────────────────

	var syncBtn = $('asae-cae-sync-now');
	if (syncBtn) {
		syncBtn.addEventListener('click', function () {
			var stat = $('asae-cae-sync-status');
			syncBtn.disabled = true;
			setStatus(stat, S.syncing, 'busy');

			postAjax('asae_cae_run_sync')
				.then(function (data) {
					if (data && data.success) {
						setStatus(stat, (data.data && data.data.message) || '', 'ok');
						var countEl = $('asae-cae-record-count');
						if (countEl && data.data && typeof data.data.count !== 'undefined') {
							countEl.textContent = String(data.data.count);
						}
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.syncError, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.syncError, 'err'); })
				.then(function () { syncBtn.disabled = false; });
		});
	}

	// ── Stop All Active Jobs ─────────────────────────────────────────────────

	var stopBtn = $('asae-cae-stop-jobs');
	if (stopBtn) {
		stopBtn.addEventListener('click', function () {
			if (!window.confirm(S.stopConfirm)) {
				return;
			}
			var stat = $('asae-cae-stop-status');
			stopBtn.disabled = true;
			setStatus(stat, S.stopping, 'busy');

			postAjax('asae_cae_stop_jobs')
				.then(function (data) {
					if (data && data.success) {
						setStatus(stat, (data.data && data.data.message) || '', 'ok');
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.stopError, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.stopError, 'err'); })
				.then(function () { stopBtn.disabled = false; });
		});
	}

	// ── Check for Updates ───────────────────────────────────────────────────

	var updatesBtn = $('asae-cae-check-updates');
	if (updatesBtn) {
		updatesBtn.addEventListener('click', function () {
			var stat = $('asae-cae-updates-status');
			updatesBtn.disabled = true;
			setStatus(stat, S.checkingUpdates, 'busy');

			postAjax('asae_cae_check_updates')
				.then(function (data) {
					if (data && data.success) {
						setStatus(stat, (data.data && data.data.message) || S.updatesChecked, 'ok');
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.updatesError, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.updatesError, 'err'); })
				.then(function () { updatesBtn.disabled = false; });
		});
	}

	// ── Default-photo media-library picker ───────────────────────────────────

	var pickBtn   = $('asae-cae-photo-pick');
	var clearBtn  = $('asae-cae-photo-clear');
	var photoIdEl = $('asae-cae-photo-id');
	var previewEl = $('asae-cae-photo-preview');

	if (pickBtn && photoIdEl && previewEl && window.wp && wp.media) {
		var frame = null;
		pickBtn.addEventListener('click', function () {
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({
				title: S.pickPhotoTitle,
				button: { text: S.pickPhotoButton },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				photoIdEl.value = attachment.id;
				var url = (attachment.sizes && attachment.sizes.medium && attachment.sizes.medium.url) || attachment.url;
				previewEl.src = url;
				previewEl.classList.remove('is-empty');
				previewEl.alt = attachment.alt || '';
				if (clearBtn) { clearBtn.hidden = false; }
			});
			frame.open();
		});
	}

	if (clearBtn && photoIdEl && previewEl) {
		clearBtn.addEventListener('click', function () {
			photoIdEl.value = '0';
			previewEl.src = previewEl.dataset.emptySrc || '';
			previewEl.classList.add('is-empty');
			previewEl.alt = '';
			clearBtn.hidden = true;
		});
	}
})();
