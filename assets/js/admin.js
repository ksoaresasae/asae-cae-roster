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

	// True while a JS-driven chunk loop is in flight. Used by the progress
	// poller (and the visibilitychange handler) to know whether to auto-resume
	// the loop or leave it alone — without this guard we'd double-invoke
	// runChunkUntilDone every poll tick and race chunks on top of each other.
	var chunkLoopActive = false;

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

					// Auto-resume: if a sync is in progress but no JS chunk
					// loop is currently driving it (e.g. the user opened the
					// tab while a sync was already running, or the loop got
					// throttled to a halt by a hidden tab), kick the loop
					// back on. The chunkLoopActive guard prevents duplicate
					// loops from racing.
					if (!chunkLoopActive) {
						var statEl = $('asae-cae-sync-status');
						runChunkUntilDone(statEl);
					}
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

	// Browsers aggressively throttle setTimeout/setInterval in hidden tabs
	// (Chrome clamps to once-per-minute after 5 minutes hidden), which slows
	// the chunk loop to a crawl. When the tab becomes visible again, fire one
	// progress poll immediately — this both refreshes the UI and triggers the
	// auto-resume path inside fetchProgressOnce if the loop has stalled.
	if (typeof document.addEventListener === 'function') {
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible') {
				fetchProgressOnce();
			}
		});
	}

	// ── Sync Now ─────────────────────────────────────────────────────────────
	//
	// JS drives the entire chunk loop client-side. The PHP layer also schedules
	// a wp_schedule_single_event after each chunk (so a non-JS context like
	// WP-Cron can resume the run later), but on local dev / hosts where
	// WP-Cron's loopback POST is unreliable, the JS-side loop is what actually
	// keeps the sync moving. The PHP-side chunk lock prevents races between
	// the two paths if both happen to fire.

	var syncBtn = $('asae-cae-sync-now');
	if (syncBtn) {
		syncBtn.addEventListener('click', function () {
			var stat = $('asae-cae-sync-status');
			syncBtn.disabled = true;
			setStatus(stat, S.syncing, 'busy');

			showProgressPanel();
			paintProgress({ current: 0, total: 0, phase: S.progressRunning, detail: '' });
			startProgressPolling();

			runChunkUntilDone(stat).then(function () {
				syncBtn.disabled = false;
				fetchProgressOnce().then(function () { stopProgressPolling(); });
			});
		});
	}

	/**
	 * Drive the chunked sync to completion via repeated AJAX calls. Each call
	 * to asae_cae_run_sync processes ONE chunk (one or a few Wicket pages).
	 * If the response says `in_progress: true`, schedule the next call after
	 * chunkDelaySeconds + a small buffer; otherwise the sync has finalized.
	 *
	 * Errors stop the loop. The user can click Sync Now again to resume from
	 * wherever the chunk_state currently sits.
	 */
	function runChunkUntilDone(statEl) {
		// Refuse to start a second loop while one is already running. This
		// makes the function safe to call from anywhere — Sync Now click,
		// progress poller auto-resume, visibilitychange — without a
		// chunkLoopActive guard at every call site.
		if (chunkLoopActive) {
			return Promise.resolve();
		}
		chunkLoopActive = true;

		var delayMs = ((asaeCaeAdmin.chunkDelaySeconds || 5) + 1) * 1000;

		function step() {
			return postAjax('asae_cae_run_sync').then(function (data) {
				if (!data || !data.success || !data.data) {
					setStatus(statEl, (data && data.data && data.data.message) || S.syncError, 'err');
					chunkLoopActive = false;
					return;
				}
				var d = data.data;

				// Always reflect the latest live count, even mid-run.
				var countEl = $('asae-cae-record-count');
				if (countEl && typeof d.count !== 'undefined') {
					countEl.textContent = String(d.count);
				}

				if (d.in_progress) {
					// More chunks pending — show "running…" status and schedule next.
					setStatus(statEl, S.syncing, 'busy');
					return new Promise(function (resolve) {
						setTimeout(function () { resolve(step()); }, delayMs);
					});
				}

				// Sync finalized (success / fail / abort). Show whatever the
				// server's final message was.
				setStatus(statEl, d.message || '', 'ok');
				chunkLoopActive = false;
			}).catch(function () {
				setStatus(statEl, S.syncError, 'err');
				chunkLoopActive = false;
			});
		}

		return step();
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
					var d = data && data.data;
					if (data && data.success && d && Array.isArray(d.rows)) {
						setStatus(stat, d.message || S.dryRunOk, 'ok');
						renderDryRunResults(results, d.rows, d);
					} else {
						setStatus(stat, (d && d.message) || S.dryRunError, 'err');
						// Even on the error path, show diagnostics if we have them.
						if (d && (typeof d.raw_count !== 'undefined' || typeof d.requests_made !== 'undefined')) {
							renderDryRunResults(results, [], d);
						}
					}
				})
				.catch(function () { setStatus(stat, S.dryRunError, 'err'); })
				.then(function () { dryRunBtn.disabled = false; });
		});
	}

	function renderDryRunResults(container, rows, meta) {
		if (!container) { return; }
		container.innerHTML = '';

		// Top-line diagnostic — always present so failures are debuggable.
		if (meta) {
			var diag = document.createElement('p');
			diag.className = 'asae-cae-dry-run-diag';
			var bits = [];
			if (typeof meta.raw_count !== 'undefined') {
				bits.push('Wicket returned ' + meta.raw_count + ' record(s)');
			}
			if (typeof meta.accepted_count !== 'undefined') {
				bits.push(meta.accepted_count + ' active');
			}
			if (typeof meta.hidden_count !== 'undefined' && meta.hidden_count > 0) {
				bits.push(meta.hidden_count + ' hidden');
			}
			if (typeof meta.requests_made !== 'undefined') {
				bits.push(meta.requests_made + ' API call(s)');
			}
			diag.textContent = bits.join(' · ');
			container.appendChild(diag);

			// Detailed diagnostics — surface the request body, response shape,
			// and (when zero records) the baseline GET probe. Hidden inside a
			// <details> so it doesn't dominate the UI when things are working.
			var det = document.createElement('details');
			det.className = 'asae-cae-dry-run-detail';
			var summary = document.createElement('summary');
			summary.textContent = 'Diagnostic detail (request + response)';
			det.appendChild(summary);

			function addRow(label, value) {
				var row = document.createElement('p');
				row.className = 'asae-cae-dry-run-detail-row';
				var b = document.createElement('strong');
				b.textContent = label + ': ';
				row.appendChild(b);
				if (typeof value === 'string') {
					row.appendChild(document.createTextNode(value));
				} else {
					var pre = document.createElement('pre');
					pre.textContent = JSON.stringify(value, null, 2);
					row.appendChild(pre);
				}
				det.appendChild(row);
			}

			if (meta.endpoint)        { addRow('Endpoint', meta.endpoint); }
			if (meta.query_body)      { addRow('Request body', meta.query_body); }
			if (meta.response_keys && meta.response_keys.length) {
				addRow('Response top-level keys', meta.response_keys.join(', '));
			}
			if (meta.response_meta)   { addRow('Response meta', meta.response_meta); }
			if (meta.addr_attr_keys && meta.addr_attr_keys.length) {
				addRow('Address attribute keys (first sideloaded address)', meta.addr_attr_keys.join(', '));
			}
			if (typeof meta.baseline_count !== 'undefined' && meta.baseline_count !== null) {
				var baselineMsg;
				if (meta.baseline_count === -1) {
					baselineMsg = 'baseline GET /people also threw an exception (auth or endpoint issue)';
				} else if (meta.baseline_count === 0) {
					baselineMsg = '0 — Wicket /people returned nothing even without filters (empty tenant or auth scoped out)';
				} else {
					baselineMsg = meta.baseline_count + ' — tenant has data; the filter is rejecting everything';
				}
				addRow('Baseline GET /people?page[size]=3', baselineMsg);
			}

			// Filter probes — table form for clarity.
			if (meta.filter_probes && meta.filter_probes.length) {
				var probeWrap = document.createElement('div');
				probeWrap.className = 'asae-cae-probe-wrap';
				var probeHeading = document.createElement('p');
				probeHeading.innerHTML = '<strong>Filter probes:</strong>';
				probeWrap.appendChild(probeHeading);

				var ptable = document.createElement('table');
				ptable.className = 'widefat striped asae-cae-probe-table';
				ptable.innerHTML = '<thead><tr><th>Variant</th><th class="num">total_items</th><th>error</th></tr></thead>';
				var ptbody = document.createElement('tbody');
				meta.filter_probes.forEach(function (p) {
					var tr = document.createElement('tr');
					var t1 = document.createElement('td'); t1.textContent = p.label || '';
					var t2 = document.createElement('td'); t2.className = 'num';
					if (p.total_items === null || typeof p.total_items === 'undefined') {
						t2.textContent = '—';
					} else {
						t2.textContent = String(p.total_items);
						if (p.total_items > 0) { t2.classList.add('asae-cae-probe-hit'); }
					}
					var t3 = document.createElement('td'); t3.textContent = p.error || '';
					tr.appendChild(t1); tr.appendChild(t2); tr.appendChild(t3);
					ptbody.appendChild(tr);
				});
				ptable.appendChild(ptbody);
				probeWrap.appendChild(ptable);
				det.appendChild(probeWrap);
			}

			container.appendChild(det);
		}

		if (!rows.length) {
			var empty = document.createElement('p');
			empty.innerHTML = '<em>No CAE records returned.</em>';
			container.appendChild(empty);
			container.hidden = false;
			return;
		}

		// Build table via DOM (not innerHTML) so we don't have to escape strings
		// manually — textContent handles it. Don't clear the container here:
		// the diagnostic disclosure (and top-line summary) were just appended
		// above and need to stay visible alongside the results table. The
		// initial container.innerHTML = '' at the top of this function is
		// the only clear that should run.
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
			'<th scope="col">Status</th>' +
			'</tr>';
		table.appendChild(thead);

		var tbody = document.createElement('tbody');
		rows.forEach(function (r, i) {
			var tr = document.createElement('tr');
			var skipReason = (r._skip_reason || '').trim();
			if (skipReason) { tr.classList.add('is-skipped'); }

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

			var tdStatus = document.createElement('td');
			tdStatus.className = 'asae-cae-dry-run-status';
			if (skipReason) {
				tdStatus.textContent = 'Hidden — ' + skipReason;
			} else {
				tdStatus.textContent = 'Active';
			}
			tr.appendChild(tdStatus);

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
					if (!data || !data.success || !data.data) {
						setStatus(stat, (data && data.data && data.data.message) || S.updatesError, 'err');
						return;
					}
					var d = data.data;

					if (!d.latest_version) {
						// Differentiate "no releases tagged yet" from "couldn't reach GitHub".
						setStatus(stat, d.no_releases ? S.updateNoReleases : S.updateUnknown, 'err');
						return;
					}

					if (d.update_available) {
						// Build "Update available: vX.Y.Z — Go to Plugins page" with a real link.
						stat.classList.remove('asae-cae-msg-ok', 'asae-cae-msg-err', 'asae-cae-msg-busy');
						stat.classList.add('asae-cae-msg-err'); // red — user attention
						stat.textContent = '';
						var prefix = S.updateAvailable
							.replace('%1$s', d.latest_version)
							.replace('%2$s', '');
						stat.appendChild(document.createTextNode(prefix));
						var link = document.createElement('a');
						link.href = d.plugins_url || '#';
						link.textContent = S.updateLink;
						stat.appendChild(link);
					} else {
						setStatus(stat, S.updateNone.replace('%s', d.current_version), 'ok');
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
