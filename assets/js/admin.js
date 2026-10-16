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
	var products = [];   // [{ source_url, title }]
	var selected = {};   // url -> boolean
	var platform = '';
	var categories = []; // category tree

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
					categories = res.data.categories || [];
					platform = res.data.platform || '';
					selected = {};
					renderCategories();

					setStatus('#swi-discover-status', 'Found ' + res.data.count + ' products.');
					if (!res.data.previews) {
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

	// --- Render the category accordion ----------------------------------
	function renderCategories() {
		if (!products.length) {
			$('#swi-results').hide();
			$('#swi-empty').show();
			return;
		}

		$('#swi-empty').hide();
		$('#swi-categories').html(renderCategoryNodes(categories));

		$('#swi-count-text').text('Discovered ' + products.length + ' products.');
		$('#swi-import-all').text('Import all (' + products.length + ')').show();
		updateSelectedButton();
		$('#swi-results').show();
	}

	// Recursively render a category tree. A node with `name` is a category
	// (foldable); a node with `source_url` is a product leaf (checkbox).
	function renderCategoryNodes(nodes) {
		var html = '';
		$.each(nodes, function (i, node) {
			if (node && node.source_url) {
				// Product leaf.
				var title = node.title || node.source_url || '';
				var checked = selected[node.source_url] ? ' checked' : '';
				html += '<label class="swi-prod-leaf">' +
					'<input type="checkbox" class="swi-select-row" data-url="' + esc(node.source_url) + '"' + checked + ' /> ' +
					'<span class="swi-prod-leaf__title">' + esc(title) + '</span>' +
					'</label>';
			} else if (node && node.name) {
				// Category / subcategory.
				var kids = node.children || [];
				var count = node.count != null ? node.count : kids.length;
				html += '<div class="swi-cat">' +
					'<div class="swi-cat__head">' +
						'<span class="swi-cat__toggle">▸</span>' +
						'<input type="checkbox" class="swi-cat-check" />' +
						'<span class="swi-cat__name">' + esc(node.name) + '</span>' +
						'<span class="swi-cat__count">(' + count + ')</span>' +
					'</div>' +
					'<div class="swi-cat__body" style="display:none;">' +
						(kids.length ? renderCategoryNodes(kids) : '<p class="description" style="margin:0;">No products.</p>') +
					'</div>' +
				'</div>';
			}
		});
		return html;
	}

	// --- Load previews one-by-one (sitemap fallback only) ---------------
	function loadPreviews(i) {
		if (i >= products.length) {
			setStatus('#swi-discover-status', 'Previews loaded.');
			return;
		}

		var p = products[i];

		ajax('swi_preview', { url: p.source_url })
			.done(function (res) {
				if (res.success) {
					var d = res.data;
					if (d.title) products[i].title = d.title;

					var $leaf = $('#swi-categories .swi-prod-leaf input[data-url="' + p.source_url + '"]');
					if ($leaf.length) {
						$leaf.closest('.swi-prod-leaf').find('.swi-prod-leaf__title').text(d.title || products[i].source_url);
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
		$.each(selected, function (url, v) {
			if (v) n++;
		});
		$('#swi-start').text('Import selected (' + n + ')');
	}

	$(document).on('change', '.swi-select-row', function () {
		selected[$(this).data('url')] = $(this).is(':checked');
		updateSelectedButton();
	});

	// Category checkbox: select/deselect every product under it (recursively,
	// incl. nested subcategories) and reflect on its parent(s).
	$(document).on('change', '.swi-cat-check', function () {
		var $cat = $(this).closest('.swi-cat');
		var checked = $(this).is(':checked');

		// Set every product leaf inside this category.
		$cat.find('.swi-select-row').each(function () {
			$(this).prop('checked', checked);
			selected[$(this).data('url')] = checked;
		});
		// Cascade to nested subcategory checkboxes too (and clear any
		// indeterminate state on them).
		$cat.find('.swi-cat-check').each(function () {
			$(this).prop('checked', checked).prop('indeterminate', false);
		});

		updateCategoryChecks();
		updateSelectedButton();
	});

	// Product leaf change: refresh ancestor category checkboxes to indeterminate.
	$(document).on('change', '.swi-select-row', function () {
		updateCategoryChecks();
	});

	// After any change, set each category checkbox to checked / unchecked /
	// indeterminate based on its products.
	function updateCategoryChecks() {
		$('.swi-cat').each(function () {
			var $cat = $(this);
			var $box = $cat.children('.swi-cat__head').find('.swi-cat-check');
			var $leaves = $cat.find('.swi-select-row');
			var total = $leaves.length;
			if (!total) return;
			var checkedN = $leaves.filter(':checked').length;
			$box.prop('checked', checkedN === total);
			$box.prop('indeterminate', checkedN > 0 && checkedN < total);
		});
	}

	// Fold/unfold a category on head click (but not when clicking the checkbox).
	$(document).on('click', '.swi-cat__head', function (e) {
		if ($(e.target).is('.swi-cat-check')) return;
		var $cat = $(this).closest('.swi-cat');
		var $body = $cat.find('.swi-cat__body');
		var $toggle = $cat.find('.swi-cat__toggle');
		$body.slideToggle(120);
		$toggle.text($body.is(':visible') ? '▾' : '▸');
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
			if (scope === 'all' || selected[products[i].source_url]) {
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
		var url = products[idx] && products[idx].source_url;
		var $leaf = $('#swi-categories .swi-prod-leaf input[data-url="' + url + '"]');
		if ($leaf.length) {
			var label = status === 'updated' ? 'Updated' : (status === 'imported' ? 'Imported' : 'Error');
			var $status = $leaf.closest('.swi-prod-leaf').find('.swi-prod-leaf__status');
			if (!$status.length) {
				$status = $('<span class="swi-prod-leaf__status"></span>');
				$leaf.closest('.swi-prod-leaf').append($status);
			}
			$status.text(label);
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
		categories = [];
		selected = {};
		renderCategories();
		setStatus('#swi-discover-status', 'Products cleared.');
	});

	// --- Universal Website UI -------------------------------------------
	var $universalList = $('#swi-universal-list');
	var $universalAdd = $('#swi-universal-add');
	var $universalAddToggle = $('#swi-universal-add-toggle');
	var $trainAnalyze = $('#swi-train-analyze');
	var $trainSave = $('#swi-train-save');

	// Load and render the saved websites list.
	function loadProfiles() {
		ajax('swi_profiles_list', {})
			.done(function (res) {
				if (res.success) {
					renderProfiles(res.data.profiles || []);
				} else {
					$universalList.html('<p class="description">' + esc(res.data.message || 'Could not load websites.') + '</p>');
				}
			})
			.fail(function () {
				$universalList.html('<p class="description">Could not load websites.</p>');
			});
	}

	function formatTrainedAt(iso) {
		if (!iso) return '';
		var d = new Date(iso);
		if (isNaN(d.getTime())) return iso;
		return d.toLocaleString();
	}

	function renderProfiles(list) {
		if (!list.length) {
			$universalList.html(
				'<div class="notice notice-info" style="margin:0;"><p style="margin:0;">' +
				esc('No websites yet. Click "Add new website" to teach the AI a site.') +
				'</p></div>'
			);
			return;
		}

		var html = '<div class="swi-universal-list">';
		$.each(list, function (i, p) {
			html +=
				'<div class="swi-universal-item" data-domain="' + esc(p.domain) + '">' +
					'<div class="swi-universal-item__head">' +
						'<div>' +
							'<div class="swi-universal-item__domain">' + esc(p.domain) + '</div>' +
							'<div class="swi-universal-item__meta">Trained ' + esc(formatTrainedAt(p.trained_at)) + '</div>' +
						'</div>' +
						'<div class="swi-universal-item__actions">' +
							'<button type="button" class="button swi-universal-toggle">Scrape product</button>' +
							'<button type="button" class="button swi-universal-products-toggle">Show Products</button>' +
							'<button type="button" class="button button-link-delete swi-universal-delete">Remove</button>' +
						'</div>' +
					'</div>' +
					'<div class="swi-universal-item__body" style="display:none;">' +
						'<label class="swi-field-label" for="swi-universal-url-' + i + '">Product URL</label>' +
						'<input type="text" id="swi-universal-url-' + i + '" class="regular-text swi-universal-url" placeholder="https://' + esc(p.domain) + '/product/…" style="width:100%;" />' +
						'<div class="swi-imgopts" style="margin-top:10px;">' +
							'<div class="swi-imgopts__row">' +
								'<span class="swi-imgopts__label">White background</span>' +
								'<label><input type="checkbox" class="swi-whitebg" data-size="800" /> 800 × 800</label>' +
								'<label><input type="checkbox" class="swi-whitebg" data-size="1000" /> 1000 × 1000</label>' +
							'</div>' +
							'<div class="swi-imgopts__row">' +
								'<span class="swi-imgopts__label">Padding</span>' +
								'<label><input type="checkbox" class="swi-padding" data-pad="50" /> 50px</label>' +
								'<label><input type="checkbox" class="swi-padding" data-pad="60" /> 60px</label>' +
								'<label><input type="checkbox" class="swi-padding" data-pad="70" /> 70px</label>' +
								'<label><input type="checkbox" class="swi-padding" data-pad="80" /> 80px</label>' +
								'<label><input type="checkbox" class="swi-padding" data-pad="90" /> 90px</label>' +
							'</div>' +
						'</div>' +
						'<p class="submit" style="margin:8px 0 0;">' +
							'<button type="button" class="button button-primary swi-universal-scrape">Scrape &amp; import</button>' +
							'<span class="swi-status swi-universal-scrape-status"></span>' +
						'</p>' +
						'<div class="swi-universal-catalog" style="display:none; margin-top:14px; border-top:1px solid #e2e4e7; padding-top:12px;">' +
							'<div class="swi-catalog-toolbar" style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">' +
								'<button type="button" class="button swi-catalog-select-all">Select all</button>' +
								'<button type="button" class="button button-primary swi-catalog-import">Import selected (0)</button>' +
								'<span class="swi-status swi-catalog-status"></span>' +
							'</div>' +
							'<div class="swi-catalog-list" style="max-height:420px; overflow-y:auto;"></div>' +
						'</div>' +
						'<div class="swi-fix-chat" style="display:none; margin-top:14px; border-top:1px solid #e2e4e7; padding-top:12px;">' +
							'<div class="swi-fix-chat__messages" style="max-height:260px; overflow-y:auto; margin-bottom:10px;"></div>' +
							'<div class="swi-fix-chat__input-row" style="display:flex; gap:8px;">' +
								'<input type="text" class="swi-fix-chat__input regular-text" placeholder="Tell the AI what to fix (e.g. the shade names are wrong)…" style="flex:1;" />' +
								'<button type="button" class="button swi-fix-chat__send">Send</button>' +
							'</div>' +
							'<p style="margin:8px 0 0;">' +
								'<button type="button" class="button button-primary swi-fix-chat__apply" style="display:none;">Apply fixed profile</button>' +
								'<span class="swi-status swi-fix-chat__status"></span>' +
							'</p>' +
						'</div>' +
					'</div>' +
				'</div>';
		});
		html += '</div>';
		$universalList.html(html);
	}

	// Toggle the product-URL field for a website. The "Scrape product" button
	// lives INSIDE the header, so its click bubbles up here — one handler is
	// enough (a second handler on the button would double-toggle and leave the
	// body closed). "Remove" and "Show Products" must NOT toggle the body.
	$universalList.on('click', '.swi-universal-item__head', function (e) {
		if ($(e.target).closest('.swi-universal-delete').length) return;
		if ($(e.target).closest('.swi-universal-scrape').length) return;
		if ($(e.target).closest('.swi-universal-products-toggle').length) return;
		$(this).closest('.swi-universal-item').find('.swi-universal-item__body').slideToggle(150);
	});

	// Scrape + import one product using the site's saved profile.
	$universalList.on('click', '.swi-universal-scrape', function () {
		var $item = $(this).closest('.swi-universal-item');
		var domain = $item.data('domain');
		var url = $item.find('.swi-universal-url').val().trim();
		var $btn = $(this);
		var $status = $item.find('.swi-universal-scrape-status');

		if (!url) {
			setStatusEl($status, 'Enter a product URL first.', true);
			return;
		}

		// Read image options (white background size + padding).
		var whiteBg = 0;
		$item.find('.swi-whitebg:checked').each(function () { whiteBg = parseInt($(this).data('size'), 10); });
		var padding = 0;
		$item.find('.swi-padding:checked').each(function () { padding = parseInt($(this).data('pad'), 10); });

		$btn.prop('disabled', true);
		setStatusEl($status, 'Scraping & importing…');

		ajax('swi_universal_scrape', { url: url, domain: domain, white_bg: whiteBg, padding: padding })
			.done(function (res) {
				if (res.success) {
					setStatusEl($status, (res.data.status === 'updated' ? 'Updated: ' : 'Imported: ') + res.data.title);
					// Reveal the AI-fix chat now that a product is scraped, and
					// remember the URL + clear any previous conversation.
					openFixChat($item, url);
				} else {
					setStatusEl($status, res.data.message || 'Scrape failed.', true);
				}
			})
			.fail(function () {
				setStatusEl($status, 'Scrape request failed.', true);
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	// Radio behavior for white-background sizes + padding (single-select each).
	$universalList.on('change', '.swi-whitebg', function () {
		var $item = $(this).closest('.swi-universal-item');
		if ($(this).is(':checked')) {
			$item.find('.swi-whitebg').not(this).prop('checked', false);
		}
	});
	$universalList.on('change', '.swi-padding', function () {
		var $item = $(this).closest('.swi-universal-item');
		if ($(this).is(':checked')) {
			$item.find('.swi-padding').not(this).prop('checked', false);
		}
	});

	// --- Show Products (catalog accordion + select + bulk import) --------
	function catalogState($item) {
		var domain = $item.data('domain');
		if (!catalogStateMap[domain]) {
			catalogStateMap[domain] = { categories: [], selected: {}, importing: false };
		}
		return catalogStateMap[domain];
	}
	var catalogStateMap = {};

	function renderCatalog($item) {
		var state = catalogState($item);
		var $list = $item.find('.swi-catalog-list');
		if (!state.categories.length) {
			$list.html('<p class="description" style="margin:0;">No products discovered.</p>');
			return;
		}
		var html = '';
		$.each(state.categories, function (ci, cat) {
			html += '<div class="swi-catalog-cat">' +
				'<div class="swi-catalog-cat__head">' +
					'<span class="swi-catalog-cat__toggle">▸</span>' +
					'<span class="swi-catalog-cat__name">' + esc(cat.name) + '</span>' +
					'<span class="swi-catalog-cat__count">(' + cat.products.length + ')</span>' +
				'</div>' +
				'<div class="swi-catalog-cat__body" style="display:none;">';
			$.each(cat.products, function (pi, prod) {
				var checked = state.selected[prod.url] ? ' checked' : '';
				html += '<label class="swi-catalog-prod">' +
					'<input type="checkbox" class="swi-catalog-check" data-url="' + esc(prod.url) + '"' + checked + ' /> ' +
					'<span class="swi-catalog-prod__title">' + esc(prod.title) + '</span>' +
					'</label>';
			});
			html += '</div></div>';
		});
		$list.html(html);
		updateCatalogButton($item);
	}

	function updateCatalogButton($item) {
		var state = catalogState($item);
		var n = Object.keys(state.selected).filter(function (k) { return state.selected[k]; }).length;
		$item.find('.swi-catalog-import').text('Import selected (' + n + ')');
	}

	$universalList.on('click', '.swi-universal-products-toggle', function () {
		var $item = $(this).closest('.swi-universal-item');
		var $catalog = $item.find('.swi-universal-catalog');
		var $body = $item.find('.swi-universal-item__body');

		// Ensure the body is open, then toggle the catalog panel.
		if ($body.is(':hidden')) $body.show();
		$catalog.slideToggle(150);

		if ($catalog.is(':visible') && !catalogState($item).categories.length) {
			loadCatalog($item);
		}
	});

	function loadCatalog($item) {
		var domain = $item.data('domain');
		var $status = $item.find('.swi-catalog-status');
		setStatusEl($status, 'Loading products…');
		ajax('swi_universal_catalog', { domain: domain })
			.done(function (res) {
				if (res.success) {
					catalogState($item).categories = res.data.categories || [];
					renderCatalog($item);
					setStatusEl($status, res.data.count + ' products found.');
				} else {
					setStatusEl($status, res.data.message || 'Could not load products.', true);
				}
			})
			.fail(function () {
				setStatusEl($status, 'Could not load products.', true);
			});
	}

	$universalList.on('click', '.swi-catalog-cat__head', function () {
		var $cat = $(this).closest('.swi-catalog-cat');
		var $body = $cat.find('.swi-catalog-cat__body');
		var $toggle = $cat.find('.swi-catalog-cat__toggle');
		$body.slideToggle(120);
		$toggle.text($body.is(':visible') ? '▾' : '▸');
	});

	$universalList.on('change', '.swi-catalog-check', function () {
		var $item = $(this).closest('.swi-universal-item');
		var state = catalogState($item);
		state.selected[$(this).data('url')] = $(this).is(':checked');
		updateCatalogButton($item);
	});

	$universalList.on('click', '.swi-catalog-select-all', function () {
		var $item = $(this).closest('.swi-universal-item');
		var state = catalogState($item);
		var allChecked = true;
		$item.find('.swi-catalog-check').each(function () {
			if (!$(this).is(':checked')) allChecked = false;
		});
		var newVal = !allChecked;
		$item.find('.swi-catalog-check').each(function () {
			$(this).prop('checked', newVal);
			state.selected[$(this).data('url')] = newVal;
		});
		updateCatalogButton($item);
	});

	$universalList.on('click', '.swi-catalog-import', function () {
		var $item = $(this).closest('.swi-universal-item');
		var state = catalogState($item);
		var domain = $item.data('domain');
		var urls = Object.keys(state.selected).filter(function (k) { return state.selected[k]; });

		if (!urls.length) {
			alert('Select at least one product to import.');
			return;
		}
		if (state.importing) return;
		state.importing = true;

		var $status = $item.find('.swi-catalog-status');
		var total = urls.length;
		var done = 0;
		var imported = 0;
		var errors = 0;
		var $btn = $(this);
		$btn.prop('disabled', true);

		function next() {
			if (done >= total) {
				state.importing = false;
				$btn.prop('disabled', false);
				setStatusEl($status, 'Done: ' + imported + ' imported, ' + errors + ' errors.');
				return;
			}
			var url = urls[done];
			done++;
			setStatusEl($status, 'Importing ' + done + '/' + total + '…');
			ajax('swi_universal_scrape', { url: url, domain: domain })
				.done(function (res) {
					if (res.success) imported++;
					else errors++;
					next();
				})
				.fail(function () {
					errors++;
					next();
				});
		}
		next();
	});

	// --- AI fix chat ----------------------------------------------------
	// Per-item state keyed by domain: the last scraped URL, pending fixed
	// profile, and conversation history.
	var fixState = {};

	function fixStateFor($item) {
		var domain = $item.data('domain');
		if (!fixState[domain]) {
			fixState[domain] = { url: '', pendingProfile: null, history: [] };
		}
		return fixState[domain];
	}

	function openFixChat($item, url) {
		var state = fixStateFor($item);
		state.url = url;
		state.pendingProfile = null;
		state.history = [];
		$item.find('.swi-fix-chat').show();
		$item.find('.swi-fix-chat__messages').html('');
		$item.find('.swi-fix-chat__apply').hide();
		$item.find('.swi-fix-chat__status').text('');
	}

	function chatMessage($messages, role, text) {
		var cls = role === 'user' ? 'swi-fix-chat__user' : 'swi-fix-chat__ai';
		var $m = $('<div class="swi-fix-chat__msg ' + cls + '"></div>');
		// Preserve line breaks in the AI explanation.
		$m.html(role === 'ai' ? text.replace(/\n/g, '<br/>') : esc(text));
		$messages.append($m);
		$messages.scrollTop($messages[0].scrollHeight);
	}

	function sendFix($item) {
		var state = fixStateFor($item);
		var domain = $item.data('domain');
		var $input = $item.find('.swi-fix-chat__input');
		var $send = $item.find('.swi-fix-chat__send');
		var $status = $item.find('.swi-fix-chat__status');
		var $messages = $item.find('.swi-fix-chat__messages');
		var instruction = $input.val().trim();

		if (!instruction) return;
		if (!state.url) {
			setStatusEl($status, 'Scrape the product first.', true);
			return;
		}

		$input.val('');
		$send.prop('disabled', true);
		chatMessage($messages, 'user', instruction);
		setStatusEl($status, 'Thinking…');

		ajax('swi_ai_fix', {
			domain: domain,
			url: state.url,
			instruction: instruction,
			history: JSON.stringify(state.history)
		})
			.done(function (res) {
				if (res.success) {
					state.history.push({ role: 'user', content: instruction });
					state.history.push({ role: 'assistant', content: res.data.explanation || '' });
					state.pendingProfile = res.data.profile;

					var html = (res.data.explanation || 'No explanation.');
					if (res.data.changes && res.data.changes.length) {
						html += '\n\n' + res.data.changes.map(function (c) { return '• ' + c; }).join('\n');
					}
					chatMessage($messages, 'ai', html);
					$item.find('.swi-fix-chat__apply').show();
					setStatusEl($status, 'Fixed — review then apply.');
				} else {
					chatMessage($messages, 'ai', '⚠ ' + (res.data.message || 'Fix failed.'));
					setStatusEl($status, res.data.message || 'Fix failed.', true);
				}
			})
			.fail(function () {
				chatMessage($messages, 'ai', '⚠ Fix request failed.');
				setStatusEl($status, 'Fix request failed.', true);
			})
			.always(function () {
				$send.prop('disabled', false);
			});
	}

	$universalList.on('click', '.swi-fix-chat__send', function () {
		sendFix($(this).closest('.swi-universal-item'));
	});

	$universalList.on('keydown', '.swi-fix-chat__input', function (e) {
		if (e.which === 13) {
			e.preventDefault();
			sendFix($(this).closest('.swi-universal-item'));
		}
	});

	$universalList.on('click', '.swi-fix-chat__apply', function () {
		var $item = $(this).closest('.swi-universal-item');
		var domain = $item.data('domain');
		var state = fixStateFor($item);
		var $btn = $(this);
		var $status = $item.find('.swi-fix-chat__status');

		if (!state.pendingProfile) return;

		$btn.prop('disabled', true);
		ajax('swi_train_save', { domain: domain, profile: JSON.stringify(state.pendingProfile) })
			.done(function (res) {
				if (res.success) {
					setStatusEl($status, 'Profile saved. Scrape the product again to verify.');
					$btn.hide();
					state.pendingProfile = null;
				} else {
					setStatusEl($status, res.data.message || 'Save failed.', true);
				}
			})
			.fail(function () {
				setStatusEl($status, 'Save failed.', true);
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	// Delete a website's profile.
	$universalList.on('click', '.swi-universal-delete', function (e) {
		e.stopPropagation();
		var $item = $(this).closest('.swi-universal-item');
		var domain = $item.data('domain');
		if (!confirm('Remove the learned profile for "' + domain + '"?')) return;
		ajax('swi_profile_delete', { domain: domain })
			.done(function () {
				loadProfiles();
			})
			.fail(function () {
				alert('Could not remove the website.');
			});
	});

	// Helper: set a status element's text + error class (local span).
	function setStatusEl($el, text, isError) {
		$el.text(text).toggleClass('swi-status--error', !!isError);
	}

	// Show / hide the "Add new website" form.
	$universalAddToggle.on('click', function () {
		$universalAdd.slideToggle(150);
	});

	// Reset the "Add new website" form back to its empty state.
	function resetUniversalForm() {
		$('#swi-train-simple-url').val('');
		$('#swi-train-variable-url').val('');
		$('#swi-train-domain').val('');
		$('#swi-train-profile').val('');
		$('#swi-train-scraped').html('');
		trainData.scraped = null;
		$('#swi-train-profile-wrap').hide();
		$('#swi-train-profile-toggle').text('Show profile JSON (advanced)');
		$('#swi-train-result').hide();
		resetTrainChat();
		setStatus('#swi-train-status', '');
		setStatus('#swi-train-save-status', '');
	}

	// Read the sample URLs (the only inputs now).
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

	// Current analyze result state.
	var trainData = { scraped: null };

	// Read-only dump of what the scraper produced, incl. swatches + per-variation color codes.
	function renderScraped(data) {
		if (!data || typeof data !== 'object') return '<p class="description">No scraped data.</p>';

		// Build value→color and value→image maps from the swatches (by term name AND slug).
		var colorMap = {};
		var imageMap = {};
		var swatchSections = [];
		if (data.swatches && typeof data.swatches === 'object') {
			$.each(data.swatches, function (tax, sw) {
				var label = (sw && sw.name) ? sw.name : tax;
				var type = (sw && sw.type) ? sw.type : 'select';
				var terms = (sw && sw.terms) ? sw.terms : {};
				var chips = [];
				$.each(terms, function (slug, term) {
					var name = (term && term.name) ? term.name : slug;
					var color = (term && term.color) ? term.color : '';
					var image = (term && term.image) ? term.image : '';
					if (name) {
						if (color) colorMap[name] = color;
						if (image) imageMap[name] = image;
					}
					if (slug) {
						if (color) colorMap[slug] = color;
						if (image) imageMap[slug] = image;
					}
					var dot;
					if (color) {
						dot = '<span class="swi-swatch-dot" style="background:' + esc(color) + ';"></span>';
					} else if (image) {
						dot = '<img class="swi-swatch-dot swi-swatch-dot--img" src="' + esc(image) + '" alt="" />';
					} else {
						dot = '<span class="swi-swatch-dot swi-swatch-dot--none"></span>';
					}
					var meta = color ? ' <code class="swi-swatch-hex">' + esc(color) + '</code>' : (image ? ' <code class="swi-swatch-hex">img</code>' : '');
					chips.push('<span class="swi-swatch-chip">' + dot + ' <span class="swi-swatch-name">' + esc(name) + '</span>' + meta + '</span>');
				});
				swatchSections.push(
					'<div class="swi-swatch-attr"><strong>' + esc(label) + '</strong> <span class="swi-swatch-type">(' + esc(type) + ')</span>' +
					'<div class="swi-swatch-chips">' + (chips.length ? chips.join('') : '<span class="description">none</span>') + '</div></div>'
				);
			});
		}

		// Render the swatch visual for a variant value: image swatch first, then color.
		function swatchVisual(val) {
			var img = imageMap[val];
			if (img) {
				return '<img class="swi-swatch-dot swi-swatch-dot--img" src="' + esc(img) + '" alt="" />';
			}
			var color = colorMap[val];
			if (color) {
				return '<span class="swi-swatch-dot" style="background:' + esc(color) + ';"></span>';
			}
			return '';
		}

		var rows = '';
		function row(label, val) {
			if (val === '' || val === null || val === undefined) return '';
			if (Array.isArray(val) && !val.length) return '';
			var display = Array.isArray(val) ? val.join(', ') : String(val);
			if (display.length > 160) display = display.substring(0, 160) + '…';
			return '<tr><th>' + esc(label) + '</th><td>' + esc(display) + '</td></tr>';
		}
		rows += row('Type', data.type);
		rows += row('Name', data.title);
		rows += row('Description', data.description);
		rows += row('Short description', data.short_description);
		rows += row('Price', (data.price || '') + (data.currency ? ' ' + data.currency : ''));
		rows += row('Sale price', data.sale_price);
		rows += row('SKU', data.sku);
		rows += row('Categories', data.categories);
		if (data.images && data.images.length) {
			var imgRows = data.images.map(function (u) { return '<div class="swi-img-row">' + esc(u) + '</div>'; }).join('');
			rows += '<tr><th>Images (' + data.images.length + ')</th><td>' + imgRows + '</td></tr>';
		}
		if (data.variants && data.variants.length) {
			rows += '<tr><th>Variants (' + data.variants.length + ')</th><td>';
			rows += data.variants.map(function (v) {
				var parts = [];
				$.each(v.attributes || {}, function (key, val) {
					var visual = swatchVisual(val);
					var color = colorMap[val] || '';
					parts.push(visual + ' ' + esc(key + '=' + val) + (color ? ' <code class="swi-swatch-hex">' + esc(color) + '</code>' : ''));
				});
				var attrStr = parts.join(' · ');
				var priceStr = v.price ? '$' + v.price : '';
				return '<div class="swi-var-row">↳ ' + (attrStr || '(no attributes)') + (priceStr ? ' · ' + esc(priceStr) : '') + '</div>';
			}).join('');
			rows += '</td></tr>';
		}
		var html = '<table class="widefat striped swi-preview-table"><tbody>' + rows + '</tbody></table>';
		if (swatchSections.length) {
			html += '<div class="swi-swatches"><h4>Swatches</h4>' + swatchSections.join('') + '</div>';
		}
		return html;
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
				trainData.scraped = res.data.scraped || null;
				$('#swi-train-scraped').html(renderScraped(res.data.scraped));
				$('#swi-train-result').show();
				resetTrainChat();
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
					setStatus('#swi-train-save-status', 'Saved "' + res.data.domain + '".');
					loadProfiles();
					$universalAdd.slideUp(150);
					resetUniversalForm();
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

	// --- Train chat (after "Analyze samples") ---------------------------
	var trainChatState = { history: [] };

	function resetTrainChat() {
		trainChatState.history = [];
		$('#swi-train-result .swi-fix-chat__messages').html('');
		$('#swi-train-result .swi-fix-chat__input').val('');
		$('#swi-train-result .swi-fix-chat__status').text('');
	}

	function trainChatPayload(instruction) {
		var fd = new FormData();
		fd.append('action', 'swi_ai_fix_train');
		fd.append('nonce', swi.nonce);
		fd.append('instruction', instruction);
		fd.append('profile', $('#swi-train-profile').val());
		fd.append('history', JSON.stringify(trainChatState.history));
		// Re-send the sample URLs so the server can re-fetch the HTML.
		var simpleUrl = $('#swi-train-simple-url').val().trim();
		var variableUrl = $('#swi-train-variable-url').val().trim();
		if (simpleUrl) fd.append('simple_url', simpleUrl);
		if (variableUrl) fd.append('variable_url', variableUrl);
		return fd;
	}

	function sendTrainFix() {
		var $input = $('#swi-train-result .swi-fix-chat__input');
		var instruction = $input.val().trim();
		if (!instruction) return;

		var $send = $('#swi-train-result .swi-fix-chat__send');
		var $messages = $('#swi-train-result .swi-fix-chat__messages');
		var $status = $('#swi-train-result .swi-fix-chat__status');

		$send.prop('disabled', true);
		chatMessage($messages, 'user', instruction);
		setStatusEl($status, 'Thinking…');

		// Build the payload BEFORE clearing the input (the instruction is
		// passed explicitly so clearing the field can't blank it out).
		var payload = trainChatPayload(instruction);
		$input.val('');

		$.ajax({
			url: swi.ajaxUrl,
			method: 'POST',
			data: payload,
			processData: false,
			contentType: false,
			dataType: 'json'
		}).done(function (res) {
			if (res.success) {
				trainChatState.history.push({ role: 'user', content: instruction });
				trainChatState.history.push({ role: 'assistant', content: res.data.explanation || '' });

				var html = (res.data.explanation || 'No explanation.');
				if (res.data.changes && res.data.changes.length) {
					html += '\n\n' + res.data.changes.map(function (c) { return '• ' + c; }).join('\n');
				}
				chatMessage($messages, 'ai', html);

				// Update the profile in place and re-render the scraped data.
				$('#swi-train-profile').val(JSON.stringify(res.data.profile, null, 2));
				if (res.data.scraped) {
					trainData.scraped = res.data.scraped;
					$('#swi-train-scraped').html(renderScraped(res.data.scraped));
				}
				setStatusEl($status, 'Profile updated — review and save.');
			} else {
				chatMessage($messages, 'ai', '⚠ ' + (res.data.message || 'Fix failed.'));
				setStatusEl($status, res.data.message || 'Fix failed.', true);
			}
		}).fail(function () {
			chatMessage($messages, 'ai', '⚠ Fix request failed.');
			setStatusEl($status, 'Fix request failed.', true);
		}).always(function () {
			$send.prop('disabled', false);
		});
	}

	$('#swi-train-result').on('click', '.swi-fix-chat__send', function () {
		sendTrainFix();
	});

	$('#swi-train-result').on('keydown', '.swi-fix-chat__input', function (e) {
		if (e.which === 13) {
			e.preventDefault();
			sendTrainFix();
		}
	});

	// Toggle the advanced profile JSON editor.
	$('#swi-train-profile-toggle').on('click', function () {
		var $wrap = $('#swi-train-profile-wrap');
		var showing = $wrap.is(':visible');
		$wrap.toggle(150);
		$(this).text(showing ? 'Show profile JSON (advanced)' : 'Hide profile JSON');
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

	// Load the saved websites list when the Universal Website tab is active.
	if ($universalList.length) {
		loadProfiles();
	}
})(jQuery);
