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

	// ── Progress meter (shared by Sync Now + initial-load running state) ────

	var progressPanel    = $('asae-cae-progress-panel');
	var progressBar      = $('asae-cae-progress-bar');
	var progressBarWrap  = $('asae-cae-progress-bar-wrap');
	var progressTextEl   = $('asae-cae-progress-text');
	var progressDetailEl = $('asae-cae-progress-detail');
	var pollTimer = null;
	var pollIntervalMs = (asaeCaeAdmin.pollIntervalMs > 0) ? asaeCaeAdmin.pollIntervalMs : 3000;

	function showProgressPanel() {
		if (progressPanel) { progressPanel.hidden = false; }
	}
	function hideProgressPanel() {
		if (progressPanel) { progressPanel.hidden = true; }
	}

	function paintProgress(progress) {
		if (!progress) { return; }
		var current = parseInt(progress.current, 10) || 0;
		var total   = parseInt(progress.total, 10) || 0;
		var phase   = progress.phase || '';
		var detail  = progress.detail || '';

		var pct = (total > 0 && current > 0)
			? Math.min(100, Math.floor((current / total) * 100))
			: 0;

		if (progressBar)     { progressBar.style.width = pct + '%'; }
		if (progressBarWrap) { progressBarWrap.setAttribute('aria-valuenow', String(pct)); }

		var text;
		if (total > 0 && current > 0) {
			text = S.progressOf
				.replace('%1$d', String(current))
				.replace('%2$d', String(total))
				.replace('%3$s', phase);
		} else {
			text = phase || S.progressRunning;
		}
		if (progressTextEl) { progressTextEl.textContent = text; }

		if (progressDetailEl) {
			progressDetailEl.textContent = detail;
			progressDetailEl.hidden = (detail === '');
		}
	}

	function fetchProgressOnce() {
		return postAjax('asae_cae_get_progress')
			.then(function (data) {
				if (!data || !data.success || !data.data) { return null; }
				var d = data.data;

				if (d.is_running && d.progress) {
					showProgressPanel();
					paintProgress(d.progress);
				} else {
					// Sync ended (success, fail, or aborted) — reflect final state
					// in the live record count and hide the panel.
					var countEl = $('asae-cae-record-count');
					if (countEl && typeof d.count !== 'undefined') {
						countEl.textContent = String(d.count);
					}
					hideProgressPanel();
				}
				return d;
			})
			.catch(function () { return null; });
	}

	function startProgressPolling() {
		if (pollTimer) { return; }
		// Immediate first tick so the panel updates without waiting one interval.
		fetchProgressOnce();
		pollTimer = setInterval(function () {
			fetchProgressOnce().then(function (d) {
				if (d && !d.is_running) { stopProgressPolling(); }
			});
		}, pollIntervalMs);
	}

	function stopProgressPolling() {
		if (pollTimer) {
			clearInterval(pollTimer);
			pollTimer = null;
		}
	}

	// If the page rendered with the progress panel visible (a sync was already
	// running on initial load), kick off polling immediately so the user sees
	// updates without having to refresh.
	if (progressPanel && !progressPanel.hidden) {
		startProgressPolling();
	}

	// ── Sync Now ─────────────────────────────────────────────────────────────

	var syncBtn = $('asae-cae-sync-now');
	if (syncBtn) {
		syncBtn.addEventListener('click', function () {
			var stat = $('asae-cae-sync-status');
			syncBtn.disabled = true;
			setStatus(stat, S.syncing, 'busy');

			// Surface the panel and start polling immediately — the run_sync
			// AJAX is synchronous and won't return for minutes, so polling
			// fetches running in parallel are what drive the live updates.
			showProgressPanel();
			paintProgress({ current: 0, total: 0, phase: S.progressRunning, detail: '' });
			startProgressPolling();

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
				.then(function () {
					syncBtn.disabled = false;
					// Final progress check + panel hide. The polling loop also
					// handles this, but doing it once more synchronously keeps
					// the UI snappy when the sync ends between poll ticks.
					fetchProgressOnce().then(function () { stopProgressPolling(); });
				});
		});
	}

	// ── Dry Run (preview first 50 alphabetically) ────────────────────────────

	var dryRunBtn = $('asae-cae-dry-run');
	if (dryRunBtn) {
		dryRunBtn.addEventListener('click', function () {
			var stat    = $('asae-cae-dry-run-status');
			var results = $('asae-cae-dry-run-results');
			dryRunBtn.disabled = true;
			setStatus(stat, S.dryRunStart, 'busy');

			postAjax('asae_cae_dry_run')
				.then(function (data) {
					if (data && data.success && data.data && Array.isArray(data.data.rows)) {
						setStatus(stat, data.data.message || S.dryRunOk, 'ok');
						renderDryRunResults(results, data.data.rows);
					} else {
						setStatus(stat, (data && data.data && data.data.message) || S.dryRunError, 'err');
					}
				})
				.catch(function () { setStatus(stat, S.dryRunError, 'err'); })
				.then(function () { dryRunBtn.disabled = false; });
		});
	}

	function renderDryRunResults(container, rows) {
		if (!container) { return; }
		if (!rows.length) {
			container.innerHTML = '<p><em>No CAE records returned.</em></p>';
			container.hidden = false;
			return;
		}

		// Build table via DOM (not innerHTML) so we don't have to escape strings
		// manually — textContent handles it.
		container.innerHTML = '';
		var table = document.createElement('table');
		table.className = 'widefat striped asae-cae-dry-run-table';

		var thead = document.createElement('thead');
		thead.innerHTML =
			'<tr>' +
			'<th scope="col">#</th>' +
			'<th scope="col">Name</th>' +
			'<th scope="col">Title</th>' +
			'<th scope="col">Organization</th>' +
			'<th scope="col">Location</th>' +
			'</tr>';
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		rows.forEach(function (r, i) {
			var tr = document.createElement('tr');

			var tdNum = document.createElement('td');
			tdNum.textContent = String(i + 1);
			tr.appendChild(tdNum);

			var tdName = document.createElement('td');
			var name = r.full_name || ((r.given_name || '') + ' ' + (r.family_name || '')).trim();
			tdName.textContent = name;
			if (r.honorific_suffix) {
				var suf = document.createElement('span');
				suf.className = 'asae-cae-dry-run-suffix';
				suf.textContent = ', ' + r.honorific_suffix;
				tdName.appendChild(suf);
			}
			tr.appendChild(tdName);

			var tdTitle = document.createElement('td');
			tdTitle.textContent = r.job_title || '';
			tr.appendChild(tdTitle);

			var tdOrg = document.createElement('td');
			tdOrg.textContent = r.organization_name || '';
			tr.appendChild(tdOrg);

			var loc = [r.city, r.state].filter(function (x) { return x; }).join(', ');
			var tdLoc = document.createElement('td');
			tdLoc.textContent = loc;
			tr.appendChild(tdLoc);

			tbody.appendChild(tr);
		});
		table.appendChild(tbody);

		container.appendChild(table);
		container.hidden = false;
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
