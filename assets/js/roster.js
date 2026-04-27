/**
 * ASAE CAE Roster — public-side enhancements.
 *
 * The roster works fully without JavaScript: the form submits and the URL
 * carries letter/page/search state. This script adds two small polish
 * touches without breaking the no-JS path:
 *
 *  1. After internal navigation (letter/page/search change), scroll the
 *     roster region into view so the user lands on the results, not at the
 *     top of whatever long page hosts the shortcode.
 *  2. Move keyboard focus to the result region so screen-reader users hear
 *     the new context immediately.
 */
(function () {
	'use strict';

	var root = document.querySelector('.asae-cae-roster');
	if (!root) {
		return;
	}

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
