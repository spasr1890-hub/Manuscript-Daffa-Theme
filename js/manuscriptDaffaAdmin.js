/**
 * @file plugins/themes/manuscriptDaffa/js/manuscriptDaffaAdmin.js
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Back end helpers of the Manuscript-Daffa theme. Loaded only in the back end
 * for logged-in users; does nothing on pages without the lists below.
 *
 * - "Select all / Clear selection" above the Crossref export list
 *   (#submissionsListGridContainer) and the Tasks/notifications table
 *   (table[id*="tasknotification"]).
 *
 * File sizes in the back end file lists moved to the Docx Font Stripper
 * plugin (OJS 3.3), so they no longer depend on the theme.
 */
(function () {
	'use strict';

	var config = window.manuscriptDaffaAdmin || {};
	var labels = config.labels || {};

	/*
	 * Select all / Clear selection
	 */
	var SELECT_TARGETS = [
		{selector: '#submissionsListGridContainer', legacyButton: '#btnSelectAllCrossref', key: 'crossref'},
		{selector: 'table[id*="tasknotification"]', legacyButton: '#btnSelectAllTasks', key: 'tasks'}
	];

	function checkboxes(target) {
		return Array.prototype.filter.call(
			target.querySelectorAll('input[type="checkbox"]'),
			function (box) {
				return !box.disabled;
			}
		);
	}

	function setChecked(box, checked) {
		if (box.checked === checked) {
			return;
		}
		// A click runs the handlers of Vue and of the legacy grids
		box.click();
		if (box.checked !== checked) {
			box.checked = checked;
			box.dispatchEvent(new Event('change', {bubbles: true}));
		}
	}

	function updateLabel(button, target) {
		var boxes = checkboxes(target);
		var allChecked = boxes.length > 0 && boxes.every(function (box) {
			return box.checked;
		});
		var text = allChecked ? (labels.clearSelection || 'Clear selection') : (labels.selectAll || 'Select all');
		// Change the DOM only when needed, so the observer below settles
		if (button.textContent !== text) {
			button.textContent = text;
		}
		if (button.getAttribute('data-msd-all') !== (allChecked ? '1' : '0')) {
			button.setAttribute('data-msd-all', allChecked ? '1' : '0');
		}
	}

	function addSelectButtons() {
		SELECT_TARGETS.forEach(function (def) {
			if (document.querySelector(def.legacyButton)) {
				return;
			}
			Array.prototype.forEach.call(document.querySelectorAll(def.selector), function (target) {
				var button = target.msdSelectButton;
				if (!button || !button.isConnected) {
					button = document.createElement('button');
					button.type = 'button';
					button.className = 'pkp_button msd-select-all';
					button.setAttribute('data-msd-target', def.key);
					button.style.margin = '0 0 .5rem';
					button.addEventListener('click', function () {
						var select = button.getAttribute('data-msd-all') !== '1';
						checkboxes(target).forEach(function (box) {
							setChecked(box, select);
						});
						updateLabel(button, target);
					});
					target.parentNode.insertBefore(button, target);
					target.msdSelectButton = button;
					target.addEventListener('change', function () {
						updateLabel(button, target);
					});
				}
				updateLabel(button, target);
			});
		});
	}

	/*
	 * Run now and when the page changes (lists loaded with AJAX)
	 */
	var timer = null;
	function run() {
		timer = null;
		try {
			var features = config.features || {};
			if (features.selectAll !== false) {
				addSelectButtons();
			}
		} catch (e) {
			if (window.console) {
				window.console.warn('manuscriptDaffa admin:', e);
			}
		}
	}

	function schedule() {
		if (timer === null) {
			timer = window.setTimeout(run, 150);
		}
	}

	function start() {
		run();
		if (window.MutationObserver) {
			new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					var nodes = mutations[i].addedNodes;
					var own = nodes.length > 0;
					for (var j = 0; j < nodes.length; j++) {
						var cls = nodes[j].classList;
						if (!cls || !cls.contains('msd-select-all')) {
							own = false;
						}
					}
					if (!own) {
						schedule();
						return;
					}
				}
			}).observe(document.body, {childList: true, subtree: true});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
