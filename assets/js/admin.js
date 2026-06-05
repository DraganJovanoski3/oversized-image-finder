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
	var rememberedCount = oifAdmin.remembered || 0;
	var scaleTarget = null;

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
		scanLimit: document.getElementById('oif-scan-limit'),
		scanLimitCustom: document.getElementById('oif-scan-limit-custom'),
		skipScanned: document.getElementById('oif-skip-scanned'),
		rememberedCount: document.getElementById('oif-remembered-count'),
		clearScannedHistory: document.getElementById('oif-clear-scanned-history'),
		scaleModal: document.getElementById('oif-scale-modal'),
		scaleBackdrop: document.getElementById('oif-scale-backdrop'),
		scaleFilename: document.getElementById('oif-scale-filename'),
		scaleCurrent: document.getElementById('oif-scale-current'),
		scalePercentWrap: document.getElementById('oif-scale-percent-wrap'),
		scaleDimensionsWrap: document.getElementById('oif-scale-dimensions-wrap'),
		scalePercent: document.getElementById('oif-scale-percent'),
		scaleMaxWidth: document.getElementById('oif-scale-max-width'),
		scaleMaxHeight: document.getElementById('oif-scale-max-height'),
		scaleQuality: document.getElementById('oif-scale-quality'),
		scaleApply: document.getElementById('oif-scale-apply'),
		scaleCancel: document.getElementById('oif-scale-cancel'),
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
			if (item.path && item.width && item.height) {
				actions +=
					'<button type="button" class="button-link oif-scale-btn" data-item="' +
					encodeURIComponent(
						JSON.stringify({
							path: item.path,
							filename: item.filename,
							width: item.width,
							height: item.height,
							dimensions: item.dimensions,
							filesize_h: item.filesize_h,
							attachment_id: item.attachment_id || 0,
							url: item.url || '',
						})
					) +
					'">' +
					escapeHtml(oifAdmin.i18n.scale) +
					'</button>';
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

		if (data.limited && data.found && data.total) {
			html =
				'<strong>' +
				data.found +
				'</strong> ' +
				escapeHtml(oifAdmin.i18n.scannedOf) +
				' <strong>' +
				data.total +
				'</strong> ' +
				escapeHtml(oifAdmin.i18n.largestScanned || 'largest scanned') +
				' &middot; ' +
				html;
		}

		if (data.already_skipped) {
			html +=
				' &middot; <strong>' +
				data.already_skipped +
				'</strong> ' +
				escapeHtml(oifAdmin.i18n.alreadySkipped);
		}

		if (typeof data.remembered === 'number') {
			rememberedCount = data.remembered;
			updateRememberedCount();
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
		setScanLimitDisabled(disabled);
	}

	function getScanLimit() {
		if (!els.scanLimit) {
			return '0';
		}

		if (els.scanLimit.value === 'custom') {
			return els.scanLimitCustom ? els.scanLimitCustom.value : '0';
		}

		return els.scanLimit.value;
	}

	function validateScanLimit() {
		if (!els.scanLimit || els.scanLimit.value !== 'custom') {
			return true;
		}

		var value = parseInt(els.scanLimitCustom ? els.scanLimitCustom.value : '', 10);
		if (!value || value < 1) {
			window.alert(oifAdmin.i18n.invalidLimit);
			return false;
		}

		return true;
	}

	function updateRememberedCount() {
		if (!els.rememberedCount) {
			return;
		}

		els.rememberedCount.textContent = (
			oifAdmin.i18n.rememberedNames || '%d scanned filenames remembered'
		).replace('%d', String(rememberedCount));
	}

	function setScanLimitDisabled(disabled) {
		if (els.scanLimit) {
			els.scanLimit.disabled = disabled;
		}
		if (els.scanLimitCustom) {
			els.scanLimitCustom.disabled = disabled;
		}
	}

	function toggleCustomLimitField() {
		if (!els.scanLimit || !els.scanLimitCustom) {
			return;
		}

		var isCustom = els.scanLimit.value === 'custom';
		els.scanLimitCustom.hidden = !isCustom;
		if (isCustom) {
			els.scanLimitCustom.focus();
		}
	}

	function getScopeData() {
		return {
			scope_media_library: els.scopeMedia && els.scopeMedia.checked ? '1' : '',
			scope_uploads: els.scopeUploads && els.scopeUploads.checked ? '1' : '',
			scope_theme_plugins: els.scopeTheme && els.scopeTheme.checked ? '1' : '',
			scan_limit: getScanLimit(),
			skip_scanned: els.skipScanned && els.skipScanned.checked ? '1' : '',
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
		if (!validateScanLimit()) {
			return;
		}

		setButtonsDisabled(true);
		setScanningUi(true);
		abortScan = false;
		currentPage = 1;
		totalPages = 0;

		if (els.progressText && els.scanLimit && els.scanLimit.value !== '0') {
			els.progress.hidden = false;
			els.progressText.textContent = oifAdmin.i18n.preparing;
		}

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

				if (response.data.message && els.progressText) {
					els.progressText.textContent = response.data.message;
				}

				setProgress(0, response.data.total);
				return runBatch();
			})
			.catch(function (error) {
				completeScanUi();
				window.alert(error.message || oifAdmin.i18n.scanError);
			});
	}

	function getScaleMode() {
		var checked = document.querySelector('input[name="oif_scale_mode"]:checked');
		return checked ? checked.value : 'percent';
	}

	function toggleScaleModeFields() {
		var mode = getScaleMode();
		if (els.scalePercentWrap) {
			els.scalePercentWrap.hidden = mode !== 'percent';
		}
		if (els.scaleDimensionsWrap) {
			els.scaleDimensionsWrap.hidden = mode !== 'dimensions';
		}
	}

	function openScaleModal(item) {
		scaleTarget = item;
		if (!els.scaleModal) {
			return;
		}

		if (els.scaleFilename) {
			els.scaleFilename.textContent = item.filename || '';
		}
		if (els.scaleCurrent) {
			els.scaleCurrent.textContent =
				(oifAdmin.i18n.currentSize || 'Current') +
				': ' +
				(item.dimensions || item.width + ' × ' + item.height) +
				' · ' +
				(item.filesize_h || '');
		}

		var settings = getSettings();
		if (els.scaleMaxWidth) {
			els.scaleMaxWidth.value = settings.max_width || 2000;
		}
		if (els.scaleMaxHeight) {
			els.scaleMaxHeight.value = settings.max_height || 2000;
		}
		if (els.scaleQuality) {
			els.scaleQuality.value = settings.scale_quality || 82;
		}

		toggleScaleModeFields();
		els.scaleModal.hidden = false;
	}

	function closeScaleModal() {
		scaleTarget = null;
		if (els.scaleModal) {
			els.scaleModal.hidden = true;
		}
	}

	function applyScale() {
		if (!scaleTarget) {
			return;
		}

		if (!window.confirm(oifAdmin.i18n.confirmScale)) {
			return;
		}

		var mode = getScaleMode();
		var payload = {
			path: scaleTarget.path,
			attachment_id: String(scaleTarget.attachment_id || 0),
			quality: els.scaleQuality ? els.scaleQuality.value : '82',
		};

		if (mode === 'percent') {
			payload.scale_percent = els.scalePercent ? els.scalePercent.value : '50';
		} else {
			payload.max_width = els.scaleMaxWidth ? els.scaleMaxWidth.value : '';
			payload.max_height = els.scaleMaxHeight ? els.scaleMaxHeight.value : '';
		}

		if (els.scaleApply) {
			els.scaleApply.disabled = true;
			els.scaleApply.textContent = oifAdmin.i18n.scaling;
		}

		post('oif_scale_image', payload)
			.then(function (response) {
				if (!response.success) {
					throw new Error(
						response.data && response.data.message
							? response.data.message
							: oifAdmin.i18n.scanError
					);
				}

				closeScaleModal();
				window.alert(response.data.message);
				return loadResults(currentPage);
			})
			.catch(function (error) {
				window.alert(error.message || oifAdmin.i18n.scanError);
			})
			.finally(function () {
				if (els.scaleApply) {
					els.scaleApply.disabled = false;
					els.scaleApply.textContent = oifAdmin.i18n.applyScale;
				}
			});
	}

	function initScaleModal() {
		document.querySelectorAll('input[name="oif_scale_mode"]').forEach(function (input) {
			input.addEventListener('change', toggleScaleModeFields);
		});

		if (els.scaleCancel) {
			els.scaleCancel.addEventListener('click', closeScaleModal);
		}
		if (els.scaleBackdrop) {
			els.scaleBackdrop.addEventListener('click', closeScaleModal);
		}
		if (els.scaleApply) {
			els.scaleApply.addEventListener('click', applyScale);
		}

		if (els.resultsBody) {
			els.resultsBody.addEventListener('click', function (event) {
				var btn = event.target.closest('.oif-scale-btn');
				if (!btn) {
					return;
				}

				try {
					openScaleModal(
						JSON.parse(decodeURIComponent(btn.getAttribute('data-item')))
					);
				} catch (e) {
					window.alert(oifAdmin.i18n.scanError);
				}
			});
		}
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

		if (els.scanLimit) {
			els.scanLimit.addEventListener('change', toggleCustomLimitField);
			toggleCustomLimitField();
		}

		if (els.clearScannedHistory) {
			els.clearScannedHistory.addEventListener('click', function () {
				if (!window.confirm(oifAdmin.i18n.confirmClearHistory)) {
					return;
				}

				post('oif_clear_scanned_history').then(function (response) {
					if (!response.success) {
						throw new Error(oifAdmin.i18n.scanError);
					}

					rememberedCount = 0;
					updateRememberedCount();
				}).catch(function (error) {
					window.alert(error.message || oifAdmin.i18n.scanError);
				});
			});
		}

		updateRememberedCount();

		if (els.filterMode) {
			els.filterMode.addEventListener('change', function () {
				if (!isScanning) {
					loadResults(1);
				}
			});
		}

		initPagination();
		initScaleModal();

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
