/**
 * @file plugins/themes/manuscriptDaffa/js/manuscriptDaffa.js
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Front end behaviour of the Manuscript Daffa theme:
 * - "Show Abstract" opens and closes the abstract in an article list;
 * - "Read more" / "Show less" unfolds the current issue on the homepage.
 * Without JavaScript both links open the article or the issue page.
 */
(function () {
	'use strict';

	function onClick(selector, handler) {
		document.addEventListener('click', function (event) {
			var link = event.target.closest ? event.target.closest(selector) : null;
			if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
				return;
			}
			handler(event, link);
		});
	}

	// Show / hide abstract
	onClick('.msd-act-abstract', function (event, link) {
		var box = document.getElementById(link.getAttribute('aria-controls'));
		if (!box) {
			return;
		}
		event.preventDefault();
		var open = box.hasAttribute('hidden');
		if (open) {
			box.removeAttribute('hidden');
		} else {
			box.setAttribute('hidden', '');
		}
		link.setAttribute('aria-expanded', open ? 'true' : 'false');
		link.classList.toggle('is-open', open);
		link.textContent = link.getAttribute(open ? 'data-label-hide' : 'data-label-show');
	});

	// Read more / Show less on the homepage
	onClick('.msd-readmore', function (event, link) {
		var issue = link.closest('.current_issue') || document;
		var items = issue.querySelectorAll('.msd-more, .msd-more-section');
		if (!items.length) {
			return;
		}
		event.preventDefault();
		var expand = link.getAttribute('aria-expanded') !== 'true';
		Array.prototype.forEach.call(items, function (item) {
			if (expand) {
				item.removeAttribute('hidden');
			} else {
				item.setAttribute('hidden', '');
			}
		});
		link.setAttribute('aria-expanded', expand ? 'true' : 'false');
		link.textContent = link.getAttribute(expand ? 'data-label-less' : 'data-label-more');
		if (!expand && link.getBoundingClientRect().top < 0) {
			link.scrollIntoView({block: 'center'});
		}
	});
})();
