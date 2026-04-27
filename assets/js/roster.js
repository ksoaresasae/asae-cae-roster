/**
 * ASAE CAE Roster — public-side enhancements.
 *
 * The roster works fully without JavaScript: the form submits and the URL
 * carries letter/page/search state. This script adds three small polish
 * touches without breaking the no-JS path:
 *
 *  1. Photo error fallback: any <img> with data-fallback swaps its src to
 *     the fallback URL on the first load error. Lets us lazy-load remote
 *     photos directly while still showing the admin-configured default
 *     when a remote image 404s.
 *  2. After internal navigation (letter/page/search change), scroll the
 *     roster region into view so the user lands on the results, not at the
 *     top of whatever long page hosts the shortcode.
 *  3. Move keyboard focus to the result region so screen-reader users hear
 *     the new context immediately.
 */
(function () {
	'use strict';

	var root = document.querySelector('.asae-cae-roster');
	if (!root) {
		return;
	}

	// ── Photo fallback (1) ──────────────────────────────────────────────────
	// Use event delegation on the root so it covers images added later (e.g.
	// when we add AJAX pagination in a future version).
	root.addEventListener(
		'error',
		function (e) {
			var img = e.target;
			if (!(img instanceof HTMLImageElement)) {
				return;
			}
			var fallback = img.getAttribute('data-fallback');
			if (fallback) {
				// Detach the attribute so a second error (fallback also 404s)
				// falls through to the empty-state branch below.
				img.removeAttribute('data-fallback');
				img.src = fallback;
				return;
			}
			// No fallback (or fallback also failed) — hide the broken image
			// and let the parent's striped empty-state background show.
			img.style.display = 'none';
			var parent = img.parentElement;
			if (parent) {
				parent.classList.add('is-empty');
			}
		},
		true /* capture: image error events don't bubble */
	);

	// Only act if the URL carries one of our state params — otherwise this is
	// a fresh load with default state and there's no reason to scroll.
	var url = new URL(window.location.href);
	var hasState = ['cae_letter', 'cae_page', 'cae_search'].some(function (k) {
		return url.searchParams.has(k);
	});
	if (!hasState) {
		return;
	}

	// Defer one frame so layout has settled.
	requestAnimationFrame(function () {
		try {
			root.scrollIntoView({ behavior: 'smooth', block: 'start' });
		} catch (e) {
			root.scrollIntoView();
		}

		// Focus the region itself so AT picks up the aria-label. tabindex=-1
		// means it can be focused programmatically without joining the tab
		// order; remove it on blur so we don't leave dead focus targets.
		root.setAttribute('tabindex', '-1');
		root.focus({ preventScroll: true });
		root.addEventListener(
			'blur',
			function once() {
				root.removeAttribute('tabindex');
				root.removeEventListener('blur', once);
			}
		);
	});
})();
