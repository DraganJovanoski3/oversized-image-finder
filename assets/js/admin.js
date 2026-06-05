(function () {
	'use strict';

	if (typeof oifAdmin === 'undefined') {
		return;
	}

	var currentPage = 1;
	var totalPages = 0;
	var meta = {};
	var isScanning = false;
	var abortScan = false;

	var els = {
		startScan: document.getElementById('oif-start-scan'),
		rescan: document.getElementById('oif-rescan'),
		finishScan: document.getElementById('oif-finish-scan'),
		progress: document.getElementById('oif-progress'),
		progressFill: document.getElementById('oif-progress-fill'),
		progressText: document.getElementById('oif-progress-text'),
		filterMode: document.getElementById('oif-filter-mode'),
		resultsBody: document.getElementById('oif-results-body'),
		summaryStats: document.getElementById('oif-summary-stats'),
		scopeMedia: document.getElementById('oif-scope-media'),
		scopeUploads: document.getElementById('oif-scope-uploads'),
		scopeTheme: document.getElementById('oif-scope-theme'),
		pagination: document.getElementById('oif-pagination'),
		paginationBottom: document.getElementById('oif-pagination-bottom'),
		pageInfo: document.getElementById('oif-page-info'),
		pageInfoBottom: document.getElementById('oif-page-info-bottom'),
		prevPage: document.getElementById('oif-prev-page'),
		nextPage: document.getElementById('oif-next-page'),
		prevPageBottom: document.getElementById('oif-prev-page-bottom'),
		nextPageBottom: document.getElementById('oif-next-page-bottom'),
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
		return els.filterMode ? els.filterMode.value : 'largest_slow';
	}

	function getSettings() {
		return oifAdmin.settings || {};
	}

	function slowRiskLabel(item) {
		return item.slow_risk === 'slow' ? oifAdmin.i18n.slowRisk : oifAdmin.i18n.likelyOk;
	}

	function slowRiskClass(item) {
		return item.slow_risk === 'slow' ? 'high' : 'ok';
	}

	function renderRows(items, safeLineAt, isLive) {
		if (!els.resultsBody) {
			return;
		}

		if (!items.length) {
			els.resultsBody.innerHTML =
				'<tr class="oif-empty-row"><td colspan="11">' +
				escapeHtml(isLive ? oifAdmin.i18n.livePreview : oifAdmin.i18n.noResults) +
				'</td></tr>';
			return;
		}

		var rows = [];
		var insertedSafeLine = false;
		var slowBytes = (parseInt(getSettings().slow_threshold_kb, 10) || 200) * 1024;

		items.forEach(function (item) {
			if (
				!insertedSafeLine &&
				safeLineAt > 0 &&
				item.rank === safeLineAt &&
				getFilterMode() !== 'largest_slow'
			) {
				rows.push(
					'<tr class="oif-safe-line"><td colspan="11">' +
						escapeHtml(oifAdmin.i18n.safeLine) +
					'</td></tr>'
				);
				insertedSafeLine = true;
			}

			if (
				!insertedSafeLine &&
				safeLineAt > 0 &&
				item.rank === safeLineAt &&
				getFilterMode() === 'all' &&
				(item.filesize || 0) < slowBytes
			) {
				rows.push(
					'<tr class="oif-safe-line"><td colspan="11">' +
						escapeHtml(oifAdmin.i18n.safeLine) +
					'</td></tr>'
				);
				insertedSafeLine = true;
			}

			var thumb = item.url
				? '<img class="oif-thumb" src="' + escapeHtml(item.url) + '" alt="" loading="lazy" />'
				: '<span class="oif-thumb"></span>';

			var libraryCell = item.in_library
				? '<a href="' +
				  escapeHtml('post.php?post=' + item.attachment_id + '&action=edit') +
				  '">' +
				  escapeHtml(oifAdmin.i18n.yes) +
				  ' (#' + escapeHtml(item.attachment_id) + ')</a>'
				: escapeHtml(oifAdmin.i18n.notInLibrary);

			var usageCell =
				item.in_library && item.usage_count > 0
					? escapeHtml(String(item.usage_count))
					: '—';

			var actions = '';
			if (item.in_library && item.attachment_id) {
				actions +=
					'<a href="' +
					escapeHtml('post.php?post=' + item.attachment_id + '&action=edit') +
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

			var rank = item.rank ? item.rank : '—';

			rows.push(
				'<tr class="' + (item.slow_risk === 'slow' ? 'oif-row-slow' : 'oif-row-ok') + '">' +
				'<td class="oif-rank"><strong>#' + escapeHtml(String(rank)) + '</strong></td>' +
				'<td>' + thumb + '</td>' +
				'<td><strong>' + escapeHtml(item.filename) + '</strong></td>' +
				'<td title="' + escapeHtml(String(item.filesize)) + ' bytes">' + escapeHtml(item.filesize_h) + '</td>' +
				'<td>' + escapeHtml(item.dimensions) + '</td>' +
				'<td>' + escapeHtml(item.extension || item.mime) + '</td>' +
				'<td class="oif-location">' + escapeHtml(item.location) + '</td>' +
				'<td>' + libraryCell + '</td>' +
				'<td>' + usageCell + '</td>' +
				'<td><span class="oif-badge oif-badge-' + escapeHtml(slowRiskClass(item)) + '">' +
					escapeHtml(slowRiskLabel(item)) +
				'</span></td>' +
				'<td class="oif-actions-cell">' + actions + '</td>' +
				'</tr>'
			);
		});

		els.resultsBody.innerHTML = rows.join('');
	}

	function updatePagination() {
		var show = !isScanning && totalPages > 1;
		var pageText =
			oifAdmin.i18n.page +
			' ' +
			currentPage +
			' ' +
			oifAdmin.i18n.of +
			' ' +
			totalPages;

		if (els.pagination) {
			els.pagination.hidden = !show;
		}
		if (els.paginationBottom) {
			els.paginationBottom.hidden = !show;
		}
		if (els.pageInfo) {
			els.pageInfo.textContent = pageText;
		}
		if (els.pageInfoBottom) {
			els.pageInfoBottom.textContent = pageText;
		}

		var disablePrev = currentPage <= 1;
		var disableNext = currentPage >= totalPages;

		[els.prevPage, els.prevPageBottom].forEach(function (btn) {
			if (btn) btn.disabled = disablePrev;
		});
		[els.nextPage, els.nextPageBottom].forEach(function (btn) {
			if (btn) btn.disabled = disableNext;
		});
	}

	function updateSummary(data) {
		if (!els.summaryStats) {
			return;
		}

		var slowCount = data.slow_count || 0;
		var totalItems = data.total_items || 0;
		var allCount = data.all_count || 0;
		var largestKb = data.largest_kb || 0;

		var html =
			'<strong>#' +
			'1</strong> = ' +
			oifAdmin.i18n.largestFile +
			' <strong>' +
			largestKb +
			' KB</strong> &middot; ' +
			'<strong>' +
			totalItems +
			'</strong> in current filter &middot; ' +
			'<strong>' +
			slowCount +
			'</strong> slow-risk files &middot; ' +
			'<strong>' +
			allCount +
			'</strong> total scanned';

		if (isScanning) {
			html = oifAdmin.i18n.livePreview + ' ' + html;
		}

		if (data.partial && data.processed && data.total) {
			html =
				'<span class="oif-partial-badge">' +
				escapeHtml(oifAdmin.i18n.partialScan) +
				'</span> ' +
				'<strong>' +
				data.processed +
				'</strong> ' +
				escapeHtml(oifAdmin.i18n.scannedOf) +
				' <strong>' +
				data.total +
				'</strong> &middot; ' +
				html;
		}

		els.summaryStats.innerHTML = html;
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

	function setScanningUi(scanning) {
		isScanning = scanning;
		if (els.finishScan) {
			els.finishScan.hidden = !scanning;
			els.finishScan.disabled = false;
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

	function loadResults(page) {
		currentPage = page || 1;

		return post('oif_get_results', {
			page: String(currentPage),
			filter: getFilterMode(),
		}).then(function (response) {
			if (!response.success) {
				throw new Error(oifAdmin.i18n.scanError);
			}

			var data = response.data;
			if (!data.has_results) {
				renderRows([], 0, false);
				updateSummary({});
				updatePagination();
				return;
			}

			meta = data;
			totalPages = data.total_pages || 1;
			renderRows(data.items || [], data.safe_line_at || 0, false);
			updateSummary(data);
			updatePagination();
		});
	}

	function showLivePreview(preview, responseData) {
		var items = (preview || []).map(function (item, index) {
			item.rank = index + 1;
			return item;
		});

		renderRows(items, 0, true);
		updateSummary({
			slow_count: responseData.slow_count || 0,
			total_items: items.length,
			all_count: responseData.processed || 0,
			largest_kb: items.length ? Math.round((items[0].filesize || 0) / 1024) : 0,
		});
		updatePagination();
	}

	function completeScanUi() {
		setScanningUi(false);
		hideProgress();
		setButtonsDisabled(false);
		abortScan = false;
	}

	function runBatch() {
		if (abortScan) {
			return Promise.resolve();
		}

		return post('oif_scan_batch').then(function (response) {
			if (abortScan) {
				return;
			}

			if (!response.success) {
				throw new Error(
					response.data && response.data.message
						? response.data.message
						: oifAdmin.i18n.scanError
				);
			}

			setProgress(response.data.processed, response.data.total);

			if (response.data.preview && response.data.preview.length) {
				showLivePreview(response.data.preview, response.data);
			}

			if (response.data.done) {
				completeScanUi();
				currentPage = 1;
				return loadResults(1);
			}

			if (abortScan) {
				return;
			}

			return runBatch();
		});
	}

	function finishScanEarly() {
		if (!window.confirm(oifAdmin.i18n.confirmFinish)) {
			return;
		}

		abortScan = true;
		if (els.finishScan) {
			els.finishScan.disabled = true;
		}

		post('oif_finish_scan')
			.then(function (response) {
				if (!response.success) {
					throw new Error(
						response.data && response.data.message
							? response.data.message
							: oifAdmin.i18n.scanError
					);
				}

				completeScanUi();
				currentPage = 1;
				return loadResults(1);
			})
			.catch(function (error) {
				abortScan = false;
				if (els.finishScan) {
					els.finishScan.disabled = false;
				}
				window.alert(error.message || oifAdmin.i18n.scanError);
			});
	}

	function startScan() {
		setButtonsDisabled(true);
		setScanningUi(true);
		abortScan = false;
		currentPage = 1;
		totalPages = 0;

		post('oif_start_scan', getScopeData())
			.then(function (response) {
				if (!response.success) {
					throw new Error(
						response.data && response.data.message
							? response.data.message
							: oifAdmin.i18n.scanError
					);
				}

				if (response.data.total === 0) {
					completeScanUi();
					renderRows([], 0, false);
					return;
				}

				setProgress(0, response.data.total);
				return runBatch();
			})
			.catch(function (error) {
				completeScanUi();
				window.alert(error.message || oifAdmin.i18n.scanError);
			});
	}

	function changePage(delta) {
		var next = currentPage + delta;
		if (next < 1 || next > totalPages) {
			return;
		}
		loadResults(next);
	}

	function initPagination() {
		[
			{ prev: els.prevPage, next: els.nextPage },
			{ prev: els.prevPageBottom, next: els.nextPageBottom },
		].forEach(function (group) {
			if (group.prev) {
				group.prev.addEventListener('click', function () {
					changePage(-1);
				});
			}
			if (group.next) {
				group.next.addEventListener('click', function () {
					changePage(1);
				});
			}
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

		if (els.finishScan) {
			els.finishScan.addEventListener('click', finishScanEarly);
		}

		if (els.filterMode) {
			els.filterMode.addEventListener('change', function () {
				if (!isScanning) {
					loadResults(1);
				}
			});
		}

		initPagination();

		if (oifAdmin.cached && oifAdmin.cached.scanned_at) {
			loadResults(1);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
