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
 * - File size under the file type in the workflow, author dashboard and
 *   reviewer file lists. Sizes come from the server in one request per
 *   submission and per page load.
 */
(function () {
	'use strict';

	var config = window.manuscriptDaffaAdmin || {};
	var labels = config.labels || {};
	var LARGE = config.largeBytes || 5 * 1024 * 1024;

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
	 * File sizes
	 */
	var sizeRequests = {};

	function formatSize(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		if (bytes < 1024 * 1024) {
			return Math.round(bytes / 1024) + ' KB';
		}
		if (bytes < 1024 * 1024 * 1024) {
			return (bytes / 1024 / 1024).toFixed(1) + ' MB';
		}
		return (bytes / 1024 / 1024 / 1024).toFixed(1) + ' GB';
	}

	function param(url, name) {
		var match = new RegExp('[?&]' + name + '=(\\d+)').exec(String(url || '').replace(/&amp;/g, '&'));
		return match ? parseInt(match[1], 10) : null;
	}

	function pageSubmissionId() {
		var href = window.location.href;
		var match = /\/reviewer\/submission\/(\d+)/.exec(window.location.pathname)
			|| /\/(?:workflow|authorDashboard)\/[a-zA-Z]+\/(\d+)/.exec(window.location.pathname);
		if (match) {
			return parseInt(match[1], 10);
		}
		return param(href, 'workflowSubmissionId') || param(href, 'submissionId');
	}

	function loadSizes(submissionId) {
		if (!config.sizeUrl || !submissionId) {
			return Promise.resolve({});
		}
		if (!sizeRequests[submissionId]) {
			var url = config.sizeUrl + (config.sizeUrl.indexOf('?') === -1 ? '?' : '&') + 'submissionId=' + submissionId;
			sizeRequests[submissionId] = fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
				.then(function (response) {
					return response.ok ? response.json() : {sizes: {}};
				})
				.then(function (data) {
					return (data && data.sizes) || {};
				})
				.catch(function () {
					return {};
				});
		}
		return sizeRequests[submissionId];
	}

	/**
	 * The cell that gets the size: the file type, otherwise the last
	 * text cell (the date in the reviewer list).
	 */
	function targetCell(row) {
		var cells = Array.prototype.slice.call(row.children);
		var best = null;
		cells.forEach(function (cell) {
			var text = (cell.textContent || '').replace(/\s+/g, '');
			if (!text || cell.querySelector('button, input, a[href*="submissionFileId"]')) {
				return;
			}
			if (cell.querySelector('a') && !cell.querySelector('span')) {
				return;
			}
			best = cell;
		});
		return best || cells[cells.length > 1 ? 1 : 0] || null;
	}

	function showSize(row, bytes) {
		if (row.querySelector('.msd-file-size')) {
			return;
		}
		var cell = targetCell(row);
		if (!cell) {
			return;
		}
		var size = document.createElement('div');
		size.className = 'msd-file-size';
		size.textContent = formatSize(bytes);
		size.style.fontSize = '0.8em';
		size.style.lineHeight = '1.3';
		size.style.marginTop = '2px';
		size.style.opacity = '0.8';
		if (bytes >= LARGE) {
			size.style.color = '#d00';
			size.style.fontWeight = '700';
			size.style.opacity = '1';
			if (labels.large) {
				size.title = labels.large;
			}
		}
		cell.appendChild(size);
		centerUnder(size, cell);
	}

	/**
	 * Center the size under the first visible element of the cell (the file
	 * type badge, or the date in the reviewer list).
	 */
	function centerUnder(size, cell) {
		for (var el = cell.firstElementChild; el; el = el.nextElementSibling) {
			if (el === size || !el.getClientRects().length) {
				continue;
			}
			var width = el.getBoundingClientRect().width;
			if (width > 0) {
				// Block-level but only as wide as needed: at least the badge
				// width, wider when the size text is longer than the badge
				size.style.display = 'table';
				size.style.minWidth = Math.round(width) + 'px';
				size.style.textAlign = 'center';
			}
			return;
		}
	}

	function fileRows() {
		var rows = [];
		// New file lists (workflow, author dashboard): links with submissionFileId
		Array.prototype.forEach.call(document.querySelectorAll('tr a[href*="submissionFileId="]'), function (link) {
			var row = link.closest('tr');
			var fileId = param(link.getAttribute('href'), 'submissionFileId');
			if (row && fileId) {
				rows.push({row: row, fileId: fileId, submissionId: param(link.getAttribute('href'), 'submissionId')});
			}
		});
		// Legacy file grids (reviewer): row ids end with -row-<submissionFileId>
		Array.prototype.forEach.call(document.querySelectorAll('[id^="component-grid-files-"] tr[id*="-row-"]'), function (row) {
			var match = /-row-(\d+)$/.exec(row.id);
			if (match) {
				rows.push({row: row, fileId: parseInt(match[1], 10), submissionId: null});
			}
		});
		return rows;
	}

	function addFileSizes() {
		var pageId = pageSubmissionId();
		fileRows().forEach(function (item) {
			if (item.row.getAttribute('data-msd-size') === String(item.fileId)) {
				return;
			}
			var submissionId = item.submissionId || pageId;
			if (!submissionId) {
				return;
			}
			item.row.setAttribute('data-msd-size', String(item.fileId));
			loadSizes(submissionId).then(function (sizes) {
				if (Object.prototype.hasOwnProperty.call(sizes, item.fileId) && item.row.isConnected) {
					showSize(item.row, sizes[item.fileId]);
				}
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
			if (features.fileSizes !== false) {
				addFileSizes();
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
						if (!cls || !(cls.contains('msd-file-size') || cls.contains('msd-select-all'))) {
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
