/* global jQuery, swi */
(function ($) {
	'use strict';

	function ajax(action, data) {
		return $.post(swi.ajaxUrl, $.extend({ action: action, nonce: swi.nonce }, data));
	}

	function setStatus(el, text, isError) {
		$(el).text(text);
		$(el).toggleClass('swi-status--error', !!isError);
	}

	function esc(s) {
		return $('<div/>').text(s == null ? '' : s).html();
	}

	// ---------------------------------------------------------------------
	// In-memory state only. Nothing about the discovered products is ever
	// written to the server — leaving or refreshing this screen discards it.
	// ---------------------------------------------------------------------
	var products = [];   // [{ source_url, title, thumbnail, price, currency }]
	var selected = {};   // index -> boolean
	var platform = '';

	// --- Detect platform -------------------------------------------------
	$('#swi-detect').on('click', function () {
		var btn = $(this);
		var url = $('#swi_target_url').val();

		btn.prop('disabled', true);
		setStatus('#swi-discover-status', 'Detecting…');

		ajax('swi_detect', { url: url })
			.done(function (res) {
				if (res.success) {
					var badge = $('#swi-platform-badge');
					badge
						.removeClass('swi-badge--shopify swi-badge--woocommerce')
						.addClass('swi-badge--' + res.data.platform)
						.text(res.data.platform.charAt(0).toUpperCase() + res.data.platform.slice(1));
					setStatus('#swi-discover-status', 'Detected: ' + res.data.platform);
					$('#swi-discover').show();
				} else {
					setStatus('#swi-discover-status', res.data.message || 'Detection failed.', true);
					$('#swi-discover').hide();
				}
			})
			.fail(function () {
				setStatus('#swi-discover-status', 'Detection failed.', true);
			})
			.always(function () {
				btn.prop('disabled', false);
			});
	});

	// --- Discover products ----------------------------------------------
	$('#swi-discover').on('click', function () {
		var btn = $(this);
		var url = $('#swi_target_url').val();

		btn.prop('disabled', true);
		setStatus('#swi-discover-status', 'Discovering products…');

		ajax('swi_discover', { url: url })
			.done(function (res) {
				if (res.success) {
					products = res.data.products || [];
					platform = res.data.platform || '';
					selected = {};
					renderTable();

					if (res.data.previews) {
						setStatus('#swi-discover-status', 'Found ' + res.data.count + ' products.');
					} else {
						setStatus('#swi-discover-status', 'Found ' + res.data.count + ' products. Loading previews…');
						loadPreviews(0);
					}
				} else {
					setStatus('#swi-discover-status', res.data.message || 'Discovery failed.', true);
					btn.prop('disabled', false);
				}
			})
			.fail(function () {
				setStatus('#swi-discover-status', 'Discovery failed.', true);
				btn.prop('disabled', false);
			});
	});

	// --- Render the product table from memory ---------------------------
	function renderTable() {
		if (!products.length) {
			$('#swi-results').hide();
			$('#swi-empty').show();
			return;
		}

		$('#swi-empty').hide();

		var rows = '';
		$.each(products, function (i, p) {
			var title = p.title || p.source_url || '';
			var img = p.thumbnail ? '<img src="' + esc(p.thumbnail) + '" alt="" />' : '';
			var price = p.price ? esc(p.price + (p.currency ? ' ' + p.currency : '')) : '—';
			rows += '<tr data-idx="' + i + '">' +
				'<td class="swi-col-check"><input type="checkbox" class="swi-select-row" data-idx="' + i + '" /></td>' +
				'<td class="swi-col-image">' + img + '</td>' +
				'<td><span class="swi-title">' + esc(title) + '</span>' +
				'<br /><a href="' + esc(p.source_url) + '" target="_blank" rel="noopener" class="swi-url">' + esc(p.source_url) + '</a></td>' +
				'<td class="swi-price">' + price + '</td>' +
				'<td class="swi-row-status"></td>' +
				'</tr>';
		});
		$('#swi-tbody').html(rows);
		$('#swi-count-text').text('Discovered ' + products.length + ' products.');
		$('#swi-import-all').text('Import all (' + products.length + ')').show();
		updateSelectedButton();
		$('#swi-results').show();
	}

	// --- Load previews one-by-one (sitemap fallback only) ---------------
	function loadPreviews(i) {
		if (i >= products.length) {
			setStatus('#swi-discover-status', 'Previews loaded.');
			return;
		}

		var p = products[i];
		setStatus('#swi-discover-status', 'Loading previews… ' + (i + 1) + ' / ' + products.length);

		ajax('swi_preview', { url: p.source_url })
			.done(function (res) {
				if (res.success) {
					var d = res.data;
					if (d.title) products[i].title = d.title;
					if (d.image) products[i].thumbnail = d.image;
					if (d.price) products[i].price = d.price;
					if (d.currency) products[i].currency = d.currency;

					var $row = $('#swi-tbody tr[data-idx="' + i + '"]');
					if ($row.length) {
						$row.find('.swi-title').text(d.title || products[i].source_url);
						if (d.image) {
							$row.find('.swi-col-image').html('<img src="' + esc(d.image) + '" alt="" />');
						}
						$row.find('.swi-price').text(d.price ? esc(d.price + (d.currency ? ' ' + d.currency : '')) : '—');
					}
				}
				loadPreviews(i + 1);
			})
			.fail(function () {
				loadPreviews(i + 1);
			});
	}

	// --- Selection ------------------------------------------------------
	function updateSelectedButton() {
		var n = 0;
		$.each(selected, function (i, v) {
			if (v) n++;
		});
		$('#swi-start').text('Import selected (' + n + ')');
	}

	$(document).on('change', '.swi-select-row', function () {
		selected[$(this).data('idx')] = $(this).is(':checked');
		updateSelectedButton();
	});

	$('#swi-select-all').on('change', function () {
		var checked = $(this).is(':checked');
		$('.swi-select-row').each(function () {
			var idx = $(this).data('idx');
			selected[idx] = checked;
			$(this).prop('checked', checked);
		});
		updateSelectedButton();
	});

	// --- Import loop (one product per request, driven by the browser) ---
	var running = false;
	var importQueue = [];
	var importIndex = 0;
	var importedTotal = 0;
	var errorTotal = 0;

	function resetImportUI() {
		importedTotal = 0;
		errorTotal = 0;
		$('#swi-log').empty().show();
		$('#swi-progress').show();
		$('#swi-progress-fill').css('width', '0%');
		$('#swi-progress-text').text('0 / 0');
		$('#swi-progress-summary').text('');
		$('#swi-complete').hide();
		$('#swi-cancel').show();
		$('#swi-start').prop('disabled', true);
		$('#swi-import-all').prop('disabled', true);
	}

	function startImport(scope) {
		importQueue = [];
		$.each(products, function (i) {
			if (scope === 'all' || selected[i]) {
				importQueue.push(i);
			}
		});

		if (!importQueue.length) {
			alert(scope === 'all'
				? 'No products discovered. Click "Discover products" first.'
				: 'No products selected. Check the products you want to import.');
			return;
		}

		resetImportUI();
		importIndex = 0;
		running = true;
		runOneImport();
	}

	function runOneImport() {
		if (!running || importIndex >= importQueue.length) {
			running = false;
			finishImport();
			return;
		}

		var idx = importQueue[importIndex];
		var p = products[idx];
		var title = p.title || p.source_url;

		$('#swi-progress-summary').text('Now importing: ' + title + ' …');

		ajax('swi_import_one', { url: p.source_url, platform: platform })
			.done(function (res) {
				var d = res.success ? res.data : null;
				var status = d && d.status ? d.status : 'error';

			if (status === 'imported' || status === 'updated') {
				importedTotal++;
				appendLog('ok', (status === 'updated' ? 'UPDATED: ' : 'OK: ') + (d.title || title));
			} else {
				errorTotal++;
				appendLog('error', 'ERROR: ' + (d && d.title ? d.title : title) + (d && d.message ? ' — ' + d.message : ''));
			}
			setRowStatus(idx, status);

				advanceImport();
			})
			.fail(function (xhr) {
				var detail = 'A request failed (HTTP ' + (xhr.status || '?') + ').';
				var body = '';
				try {
					var raw = xhr.responseText || '';
					if (raw) {
						body = raw.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
						if (body.length > 300) {
							body = body.substring(0, 300) + '…';
						}
					}
				} catch (e) {}
				errorTotal++;
				appendLog('error', 'ERROR: ' + title + ' — ' + detail + (body ? ' ' + body : ''));
				setRowStatus(idx, 'error');

				advanceImport();
			});
	}

	function advanceImport() {
		importIndex++;
		var pct = importQueue.length > 0 ? Math.round((importIndex / importQueue.length) * 100) : 100;
		$('#swi-progress-fill').css('width', pct + '%');
		$('#swi-progress-text').text('Imported ' + importIndex + ' / ' + importQueue.length);
		$('#swi-progress-summary').text('Imported: ' + importedTotal + ' · Errors: ' + errorTotal);
		scheduleNext();
	}

	// The polite pause between products happens here (client-side) so the
	// server never blocks a PHP-FPM worker on sleep().
	function scheduleNext() {
		var delaySec = (typeof swi.delay === 'number' && swi.delay > 0) ? swi.delay : 0;
		if (delaySec) {
			setTimeout(runOneImport, delaySec * 1000);
		} else {
			runOneImport();
		}
	}

	function setRowStatus(idx, status) {
		var $row = $('#swi-tbody tr[data-idx="' + idx + '"]');
		if ($row.length) {
			var label = status === 'updated' ? 'Updated' : (status === 'imported' ? 'Imported' : 'Error');
			$row.find('.swi-row-status').text(label);
		}
	}

	function finishImport() {
		appendLog('imported', 'Import complete.');
		$('#swi-cancel').hide();
		$('#swi-start').prop('disabled', false);
		$('#swi-import-all').prop('disabled', false);
		$('#swi-progress-summary').text('Imported: ' + importedTotal + ' · Errors: ' + errorTotal);
		$('#swi-complete').show();
		$('#swi-complete-text').text(
			'Import complete: ' + importedTotal + ' product(s) imported, ' + errorTotal + ' error(s).'
		);
	}

	function appendLog(status, text) {
		var cls = status === 'error' ? 'swi-log__error' : 'swi-log__ok';
		$('#swi-log').append('<li class="' + cls + '">' + esc(text) + '</li>');
		var log = document.getElementById('swi-log');
		if (log) {
			log.scrollTop = log.scrollHeight;
		}
	}

	$('#swi-start').on('click', function () {
		startImport('selected');
	});

	$('#swi-import-all').on('click', function () {
		startImport('all');
	});

	$('#swi-reset-import').on('click', function () {
		$('#swi-complete').hide();
		$('#swi-log').empty().hide();
		$('#swi-progress').hide();
	});

	$('#swi-cancel').on('click', function () {
		running = false;
		appendLog('imported', 'Cancelled by user.');
		finishImport();
	});

	// --- Clear products (discards the in-memory list only) --------------
	$('#swi-clear-products').on('click', function () {
		if (!confirm('Clear the discovered products from this screen? Imported products are NOT affected.')) {
			return;
		}
		products = [];
		selected = {};
		renderTable();
		setStatus('#swi-discover-status', 'Products cleared.');
	});

	// --- Train UI -------------------------------------------------------
	var $trainAnalyze = $('#swi-train-analyze');
	var $trainSave = $('#swi-train-save');

	// Read a sample's HTML source: pasted text > file > URL (server handles it).
	function trainPayload() {
		var fd = new FormData();
		fd.append('action', 'swi_train_analyze');
		fd.append('nonce', swi.nonce);

		var simpleUrl = $('#swi-train-simple-url').val().trim();
		var variableUrl = $('#swi-train-variable-url').val().trim();
		var domain = $('#swi-train-domain').val().trim();

		if (simpleUrl) fd.append('simple_url', simpleUrl);
		if (variableUrl) fd.append('variable_url', variableUrl);
		if (domain) fd.append('domain', domain);

		// Pasted HTML takes precedence over URL (mirrors server precedence).
		var simpleHtml = $('#swi-train-simple-html').val();
		var variableHtml = $('#swi-train-variable-html').val();
		if (simpleHtml && simpleHtml.trim()) fd.append('simple_html', simpleHtml);
		if (variableHtml && variableHtml.trim()) fd.append('variable_html', variableHtml);

		// Uploaded files.
		var simpleFile = document.getElementById('swi-train-simple-file').files[0];
		var variableFile = document.getElementById('swi-train-variable-file').files[0];
		if (simpleFile) fd.append('simple_file', simpleFile);
		if (variableFile) fd.append('variable_file', variableFile);

		return fd;
	}

	function renderPreview(p) {
		if (!p) return '<p class="description">' + esc('No preview available.') + '</p>';

		var rows = '';
		function row(label, val) {
			if (val === '' || val === null || val === undefined) return '';
			var display = Array.isArray(val) ? val.join(', ') : String(val);
			if (display.length > 120) display = display.substring(0, 120) + '…';
			return '<tr><th>' + esc(label) + '</th><td>' + esc(display) + '</td></tr>';
		}

		rows += row('Type', p.type);
		rows += row('Title', p.title);
		rows += row('Short description', p.short_description);
		rows += row('Description', p.description);
		rows += row('Price', p.price + (p.currency ? ' ' + p.currency : ''));
		rows += row('Sale price', p.sale_price);
		rows += row('SKU', p.sku);
		rows += row('Images', p.images_count);
		rows += row('Categories', p.categories);

		if (p.variants_count) {
			rows += '<tr><th>Variants</th><td>' + esc(p.variants_count) + '</td></tr>';
			$.each(p.variants, function (i, v) {
				var attrs = $.map(v.attributes, function (val, key) { return key + '=' + val; }).join(', ');
				rows += '<tr><th style="padding-left:16px;">↳ variant ' + (i + 1) + '</th><td>' +
					esc((attrs ? attrs + ' · ' : '') + (v.price ? v.price : '') + (v.sku ? ' · ' + v.sku : '')) + '</td></tr>';
			});
		}

		return '<table class="widefat striped swi-preview-table"><tbody>' + rows + '</tbody></table>';
	}

	$trainAnalyze.on('click', function () {
		var btn = $(this);
		btn.prop('disabled', true);
		setStatus('#swi-train-status', 'Analyzing… (this can take up to a minute)');

		$.ajax({
			url: swi.ajaxUrl,
			method: 'POST',
			data: trainPayload(),
			processData: false,
			contentType: false,
			dataType: 'json'
		}).done(function (res) {
			if (res.success) {
				setStatus('#swi-train-status', 'Analysis complete — review below.');
				if (res.data.domain) {
					$('#swi-train-domain').val(res.data.domain);
				}
				$('#swi-train-profile').val(JSON.stringify(res.data.profile, null, 2));
				$('#swi-train-preview').html(renderPreview(res.data.preview));
				$('#swi-train-result').show();
				$('html, body').animate({ scrollTop: $('#swi-train-result').offset().top }, 300);
			} else {
				setStatus('#swi-train-status', res.data.message || 'Analysis failed.', true);
			}
		}).fail(function () {
			setStatus('#swi-train-status', 'Analysis request failed.', true);
		}).always(function () {
			btn.prop('disabled', false);
		});
	});

	$trainSave.on('click', function () {
		var btn = $(this);
		var domain = $('#swi-train-domain').val().trim();
		var profileText = $('#swi-train-profile').val();

		if (!domain) {
			setStatus('#swi-train-save-status', 'Enter a site domain first.', true);
			return;
		}

		try {
			JSON.parse(profileText);
		} catch (e) {
			setStatus('#swi-train-save-status', 'The profile is not valid JSON.', true);
			return;
		}

		btn.prop('disabled', true);
		setStatus('#swi-train-save-status', 'Saving…');

		ajax('swi_train_save', { domain: domain, profile: profileText })
			.done(function (res) {
				if (res.success) {
					setStatus('#swi-train-save-status', 'Profile saved for ' + res.data.domain + '. You can now scrape this site from the Products tab.');
				} else {
					setStatus('#swi-train-save-status', res.data.message || 'Save failed.', true);
				}
			})
			.fail(function () {
				setStatus('#swi-train-save-status', 'Save request failed.', true);
			})
			.always(function () {
				btn.prop('disabled', false);
			});
	});

	// --- Debug log viewer ----------------------------------------------
	var $debugLog = $('#swi-debug-log');

	function refreshDebugLog() {
		if (!$debugLog.length) {
			return;
		}
		ajax('swi_debug_log', {})
			.done(function (res) {
				if (res.success) {
					$debugLog.val(res.data.log || '(log is empty — enable "Debug logging" above and run an import)');
				}
			})
			.fail(function () {
				$debugLog.val('(could not load log)');
			});
	}

	$('#swi-debug-refresh').on('click', refreshDebugLog);

	$('#swi-debug-clear').on('click', function () {
		ajax('swi_clear_log', {}).done(function () {
			$debugLog.val('');
		});
	});

	if ($debugLog.length) {
		refreshDebugLog();
	}
})(jQuery);
