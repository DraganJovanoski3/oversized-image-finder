(function () {
	'use strict';

	if (typeof oifAdmin === 'undefined') {
		return;
	}

	var allItems = [];
	var sortKey = 'filesize';
	var sortDir = 'desc';

	var els = {
		startScan: document.getElementById('oif-start-scan'),
		rescan: document.getElementById('oif-rescan'),
		progress: document.getElementById('oif-progress'),
		progressFill: document.getElementById('oif-progress-fill'),
		progressText: document.getElementById('oif-progress-text'),
		filterMode: document.getElementById('oif-filter-mode'),
		resultsBody: document.getElementById('oif-results-body'),
		summaryStats: document.getElementById('oif-summary-stats'),
		scopeMedia: document.getElementById('oif-scope-media'),
		scopeUploads: document.getElementById('oif-scope-uploads'),
		scopeTheme: document.getElementById('oif-scope-theme'),
	};

	function post(action, data) {
		var form = new FormData();
		form.append('action', action);
		form.append('nonce', oifAdmin.nonce);

		if (data) {
			Object.keys(data).forEach(function (key) {
				form.append(key, data[key]);
			});
		}

		return fetch(oifAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form,
		}).then(function (response) {
			return response.json();
		});
	}

	function escapeHtml(text) {
		var div = document.createElement('div');
		div.textContent = text == null ? '' : String(text);
		return div.innerHTML;
	}

	function getFilterMode() {
		return els.filterMode ? els.filterMode.value : 'oversized';
	}

	function getSettings() {
		return oifAdmin.settings || {};
	}

	function applyFilter(items) {
		var mode = getFilterMode();
		var settings = getSettings();
		var maxBytes = (parseInt(settings.max_file_size_kb, 10) || 500) * 1024;
		var maxWidth = parseInt(settings.max_width, 10) || 2000;
		var maxHeight = parseInt(settings.max_height, 10) || 2000;

		return items.filter(function (item) {
			if (mode === 'all') {
				return true;
			}
			if (mode === 'size') {
				return item.filesize > maxBytes;
			}
			if (mode === 'dimensions') {
				return item.width > maxWidth || item.height > maxHeight;
			}
			return item.oversized;
		});
	}

	function sortItems(items) {
		var sorted = items.slice();

		sorted.sort(function (a, b) {
			var av;
			var bv;

			if (sortKey === 'filename') {
				av = (a.filename || '').toLowerCase();
				bv = (b.filename || '').toLowerCase();
				if (av < bv) return sortDir === 'asc' ? -1 : 1;
				if (av > bv) return sortDir === 'asc' ? 1 : -1;
				return 0;
			}

			if (sortKey === 'dimensions') {
				av = (a.width || 0) * (a.height || 0);
				bv = (b.width || 0) * (b.height || 0);
			} else {
				av = a[sortKey] || 0;
				bv = b[sortKey] || 0;
			}

			if (sortDir === 'asc') {
				return av - bv;
			}
			return bv - av;
		});

		return sorted;
	}

	function severityLabel(severity) {
		if (severity === 'high') return oifAdmin.i18n.severityHigh;
		if (severity === 'medium') return oifAdmin.i18n.severityMedium;
		return oifAdmin.i18n.severityInfo;
	}

	function renderTable() {
		if (!els.resultsBody) {
			return;
		}

		var filtered = sortItems(applyFilter(allItems));

		if (!filtered.length) {
			els.resultsBody.innerHTML =
				'<tr class="oif-empty-row"><td colspan="10">' +
				escapeHtml(allItems.length ? oifAdmin.i18n.noResults : oifAdmin.i18n.noCached) +
				'</td></tr>';
			updateSummary(filtered);
			return;
		}

		var rows = filtered.map(function (item) {
			var thumb = item.url
				? '<img class="oif-thumb" src="' + escapeHtml(item.url) + '" alt="" loading="lazy" />'
				: '<span class="oif-thumb"></span>';

			var libraryCell = item.in_library
				? '<a href="' +
				  escapeHtml(
						'post.php?post=' + item.attachment_id + '&action=edit'
				  ) +
				  '">' +
				  escapeHtml(oifAdmin.i18n.yes) +
				  ' (#' +
				  escapeHtml(item.attachment_id) +
				  ')</a>'
				: escapeHtml(oifAdmin.i18n.notInLibrary);

			var usageCell =
				item.in_library && item.usage_count > 0
					? escapeHtml(String(item.usage_count))
					: '—';

			var actions = '';
			if (item.in_library && item.attachment_id) {
				actions +=
					'<a href="' +
					escapeHtml(
						'post.php?post=' + item.attachment_id + '&action=edit'
					) +
					'">' +
					escapeHtml(oifAdmin.i18n.edit) +
					'</a>';
			}
			if (item.url) {
				actions +=
					'<a href="' +
					escapeHtml(item.url) +
					'" target="_blank" rel="noopener">' +
					escapeHtml(oifAdmin.i18n.view) +
					'</a>';
			}

			return (
				'<tr>' +
				'<td>' + thumb + '</td>' +
				'<td><strong>' + escapeHtml(item.filename) + '</strong></td>' +
				'<td title="' + escapeHtml(String(item.filesize)) + ' bytes">' +
					escapeHtml(item.filesize_h) +
				'</td>' +
				'<td>' + escapeHtml(item.dimensions) + '</td>' +
				'<td>' + escapeHtml(item.extension || item.mime) + '</td>' +
				'<td class="oif-location">' + escapeHtml(item.location) + '</td>' +
				'<td>' + libraryCell + '</td>' +
				'<td>' + usageCell + '</td>' +
				'<td><span class="oif-badge oif-badge-' + escapeHtml(item.severity) + '">' +
					escapeHtml(severityLabel(item.severity)) +
				'</span></td>' +
				'<td class="oif-actions-cell">' + actions + '</td>' +
				'</tr>'
			);
		});

		els.resultsBody.innerHTML = rows.join('');
		updateSummary(filtered);
		updateSortHeaders();
	}

	function updateSummary(filtered) {
		if (!els.summaryStats) {
			return;
		}

		var totalBytes = filtered.reduce(function (sum, item) {
			return sum + (item.filesize || 0);
		}, 0);
		var totalMB = (totalBytes / 1048576).toFixed(2);
		var oversizedCount = allItems.filter(function (item) {
			return item.oversized;
		}).length;

		els.summaryStats.innerHTML =
			'<strong>' +
			filtered.length +
			'</strong> shown &middot; ' +
			'<strong>' +
			allItems.length +
			'</strong> total scanned &middot; ' +
			'<strong>' +
			oversizedCount +
			'</strong> oversized &middot; ' +
			'<strong>' +
			totalMB +
			' MB</strong> combined size (filtered)';
	}

	function updateSortHeaders() {
		document.querySelectorAll('.oif-col-sortable').forEach(function (th) {
			th.classList.remove('is-sorted-asc', 'is-sorted-desc');
			if (th.getAttribute('data-sort') === sortKey) {
				th.classList.add(sortDir === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
			}
		});
	}

	function setProgress(processed, total) {
		if (!els.progress || !els.progressFill || !els.progressText) {
			return;
		}

		els.progress.hidden = false;
		var pct = total > 0 ? Math.round((processed / total) * 100) : 0;
		els.progressFill.style.width = pct + '%';
		els.progressText.textContent =
			oifAdmin.i18n.scanning + ' ' + processed + ' / ' + total + ' (' + pct + '%)';
	}

	function hideProgress() {
		if (els.progress) {
			els.progress.hidden = true;
		}
	}

	function setButtonsDisabled(disabled) {
		if (els.startScan) els.startScan.disabled = disabled;
		if (els.rescan) els.rescan.disabled = disabled;
	}

	function getScopeData() {
		return {
			scope_media_library: els.scopeMedia && els.scopeMedia.checked ? '1' : '',
			scope_uploads: els.scopeUploads && els.scopeUploads.checked ? '1' : '',
			scope_theme_plugins: els.scopeTheme && els.scopeTheme.checked ? '1' : '',
		};
	}

	function runBatch() {
		return post('oif_scan_batch').then(function (response) {
			if (!response.success) {
				throw new Error(response.data && response.data.message ? response.data.message : oifAdmin.i18n.scanError);
			}

			setProgress(response.data.processed, response.data.total);

			if (response.data.done) {
				return loadResults();
			}

			return runBatch();
		});
	}

	function loadResults() {
		return post('oif_get_results').then(function (response) {
			if (!response.success) {
				throw new Error(oifAdmin.i18n.scanError);
			}

			allItems = response.data.items || [];
			if (getFilterMode() === 'all') {
				sortKey = 'filesize';
				sortDir = 'desc';
			}
			renderTable();
			hideProgress();
			setButtonsDisabled(false);
		});
	}

	function startScan() {
		setButtonsDisabled(true);
		allItems = [];

		post('oif_start_scan', getScopeData())
			.then(function (response) {
				if (!response.success) {
					throw new Error(response.data && response.data.message ? response.data.message : oifAdmin.i18n.scanError);
				}

				if (response.data.total === 0) {
					allItems = [];
					renderTable();
					hideProgress();
					setButtonsDisabled(false);
					return;
				}

				setProgress(0, response.data.total);
				return runBatch();
			})
			.catch(function (error) {
				hideProgress();
				setButtonsDisabled(false);
				window.alert(error.message || oifAdmin.i18n.scanError);
			});
	}

	function initSortableHeaders() {
		document.querySelectorAll('.oif-col-sortable').forEach(function (th) {
			th.addEventListener('click', function () {
				var key = th.getAttribute('data-sort');
				if (sortKey === key) {
					sortDir = sortDir === 'asc' ? 'desc' : 'asc';
				} else {
					sortKey = key;
					sortDir = key === 'filename' ? 'asc' : 'desc';
				}
				renderTable();
			});
		});
	}

	function init() {
		if (els.startScan) {
			els.startScan.addEventListener('click', startScan);
		}

		if (els.rescan) {
			els.rescan.addEventListener('click', function () {
				if (window.confirm(oifAdmin.i18n.confirmRescan)) {
					post('oif_clear_cache').then(function () {
						startScan();
					});
				}
			});
		}

		if (els.filterMode) {
			els.filterMode.addEventListener('change', function () {
				if (els.filterMode.value === 'all') {
					sortKey = 'filesize';
					sortDir = 'desc';
				}
				renderTable();
			});
		}

		initSortableHeaders();

		if (oifAdmin.cached && oifAdmin.cached.items && oifAdmin.cached.items.length) {
			allItems = oifAdmin.cached.items;
			renderTable();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
