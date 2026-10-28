/* global crawlwpBulkEditor, jQuery */
(function ($) {
	'use strict';

	if (typeof crawlwpBulkEditor === 'undefined') {
		return;
	}

	var cfg  = crawlwpBulkEditor;
	var i18n = cfg.i18n || {};

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	var state = {
		view:         'posts',
		page:         1,
		per_page:     20,
		search:       '',
		post_type:    '',
		filter:       'all',
		image_filter: 'missing_alt'
	};

	// Dirty rows keyed by post/attachment id: { field: value, ... } for every field in the row.
	var dirty = {};

	// The running "Write with AI" job, if any.
	var aiJob = null;

	// -------------------------------------------------------------------------
	// DOM refs (resolved after DOMContentLoaded)
	// -------------------------------------------------------------------------

	var $wrap, $tbody, $totalInfo, $pageLinks, $saveBtn, $notice;
	var searchTimer = null;

	// -------------------------------------------------------------------------
	// "Insert variable" dropdown — one shared/reused panel for every
	// SEO Title / Meta Description textarea in the table.
	// -------------------------------------------------------------------------

	var VARS = cfg.variables || [];
	var $varsPanel   = null;
	var $varsSearch  = null;
	var $varsList    = null;
	var $varsTarget  = null; // textarea currently receiving the insert.

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$(function () {
		$wrap      = $('#cwp-bulk-wrap');
		$tbody     = $('#cwp-bulk-tbody');
		$totalInfo = $('#cwp-bulk-total-info');
		$pageLinks = $('#cwp-bulk-page-links');
		$saveBtn   = $('#cwp-bulk-save-btn');
		$notice    = $('#cwp-bulk-notice');

		if (!$wrap.length) {
			return;
		}

		bindEvents();
		bindAI();
		loadTable();

		if ($('#cwp-bulk-missing-alt').length) {
			refreshMissingCount();
		}
	});

	// -------------------------------------------------------------------------
	// Event bindings
	// -------------------------------------------------------------------------

	function bindEvents() {

		// Post type filter.
		$wrap.on('change', '#cwp-bulk-post-type', function () {
			if (!confirmDiscard()) {
				$(this).val(state.post_type);
				return;
			}
			state.post_type = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Posts / Image alt text views.
		$wrap.on('click', '.cwp-bulk-view', function () {
			var view = $(this).data('view');

			if (view === state.view || aiJob || !confirmDiscard()) {
				return;
			}

			state.view   = view;
			state.page   = 1;
			state.search = '';
			$('#cwp-bulk-search').val('');

			$wrap.find('.cwp-bulk-view').each(function () {
				var on = $(this).data('view') === view;
				$(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
			});

			$('#cwp-bulk-post-type, #cwp-bulk-filter').toggle(view === 'posts');
			$('#cwp-bulk-image-filter').toggle(view === 'images');

			renderHead();
			loadTable();
		});

		$wrap.on('change', '#cwp-bulk-image-filter', function () {
			if (!confirmDiscard()) {
				$(this).val(state.image_filter);
				return;
			}
			state.image_filter = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Select all rows on this page.
		$wrap.on('change', '.cwp-bulk-check-all', function () {
			$tbody.find('.cwp-bulk-check').prop('checked', this.checked);
		});

		$wrap.on('change', '.cwp-bulk-check', function () {
			var $checks = $tbody.find('.cwp-bulk-check');
			$wrap.find('.cwp-bulk-check-all').prop('checked', $checks.length > 0 && $checks.filter(':checked').length === $checks.length);
		});

		// Missing title/description filter.
		$wrap.on('change', '#cwp-bulk-filter', function () {
			if (!confirmDiscard()) {
				$(this).val(state.filter);
				return;
			}
			state.filter = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Search (debounced).
		$wrap.on('input', '#cwp-bulk-search', function () {
			var $search = $(this);
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				runSearch($search);
			}, 300);
		});

		// The table sits inside the settings form: Enter must search, not submit it.
		$wrap.on('keydown', '#cwp-bulk-search', function (e) {
			if (e.key === 'Enter' || e.which === 13) {
				e.preventDefault();
				clearTimeout(searchTimer);
				runSearch($(this));
			}
		});

		// Per-page.
		$wrap.on('change', '#cwp-bulk-per-page', function () {
			if (!confirmDiscard()) {
				$(this).val(String(state.per_page));
				return;
			}
			state.per_page = parseInt($(this).val(), 10) || 20;
			state.page     = 1;
			loadTable();
		});

		// Pagination links (delegated, injected dynamically).
		$wrap.on('click', '.cwp-bulk-page-btn', function () {
			var page = parseInt($(this).data('page'), 10);
			if (page && page !== state.page && confirmDiscard()) {
				state.page = page;
				loadTable();
			}
		});

		// Track edits + char counts on any inline field.
		$wrap.on('input', '.cwp-bulk-input', function () {
			updateCount($(this));
			markDirty($(this));
		});

		// "Insert variable" trigger — delegated since rows are added/removed
		// dynamically by loadTable().
		$wrap.on('click', '.cwp-bulk-vars', function (e) {
			e.stopPropagation();
			toggleVarsPanel($(this));
		});

		// Save all changes.
		$wrap.on('click', '#cwp-bulk-save-btn', saveChanges);

		// Warn on navigating away with unsaved changes.
		$(window).on('beforeunload', function (e) {
			if (hasDirty()) {
				var message = i18n.confirmLeave || 'You have unsaved changes.';
				e.preventDefault();
				if (e.originalEvent) {
					e.originalEvent.returnValue = message;
				}
				return message;
			}
		});
	}

	/**
	 * Apply the search box value, unless the user keeps their unsaved edits.
	 */
	function runSearch($search) {
		var val = $search.val();

		if (val === state.search) {
			return;
		}

		if (!confirmDiscard()) {
			$search.val(state.search);
			return;
		}

		state.search = val;
		state.page   = 1;
		loadTable();
	}

	/**
	 * Reloading the table drops unsaved edits — ask first.
	 *
	 * @return {boolean} True when it is fine to reload.
	 */
	function confirmDiscard() {
		// Writing with AI fills fields on this page: finish or stop it first.
		if (aiJob) {
			return false;
		}

		return !hasDirty() || window.confirm(i18n.confirmDiscard || 'You have unsaved changes in the table. Discard them?');
	}

	// -------------------------------------------------------------------------
	// AJAX: load table
	// -------------------------------------------------------------------------

	function loadTable() {
		closeVarsPanel();
		$tbody.html('<tr class="cwp-bulk-loading-row"><td colspan="4">' + escapeHtml(i18n.loading || 'Loading…') + '</td></tr>');
		$wrap.find('.cwp-bulk-check-all').prop('checked', false);
		dirty = {};
		toggleSaveButton();

		var images = state.view === 'images';

		$.post(cfg.ajaxUrl, {
			action:    images ? 'crawlwp_bulk_editor_images' : 'crawlwp_bulk_editor_list',
			nonce:     cfg.nonce,
			search:    state.search,
			post_type: state.post_type,
			filter:    images ? state.image_filter : state.filter,
			per_page:  state.per_page,
			page:      state.page
		}, function (resp) {
			if (!resp.success) {
				$tbody.html('<tr><td colspan="4">' + escapeHtml((resp.data && resp.data.message) ? resp.data.message : (i18n.error || 'Error')) + '</td></tr>');
				return;
			}

			var data = resp.data;
			$tbody.html(data.html);

			if (typeof data.missing !== 'undefined') {
				updateMissingBadge(data.missing);
			}

			$tbody.find('.cwp-bulk-input').each(function () {
				// The server-rendered length belongs to the stored value.
				$(this).closest('td').find('.cwp-bulk-count').data('resolvedFor', $(this).val());
				updateCount($(this));
			});

			$totalInfo.text(data.total_label || String(data.total));

			renderPagination(data.page, data.pages);
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: save changes
	// -------------------------------------------------------------------------

	function saveChanges() {
		var ids = Object.keys(dirty);

		if (!ids.length) {
			return;
		}

		var rows = ids.map(function (id) {
			return $.extend({ id: id }, dirty[id]);
		});

		var images = state.view === 'images';

		$saveBtn.prop('disabled', true).text(i18n.saving || 'Saving…');
		hideNotice();

		$.post(cfg.ajaxUrl, {
			action: 'crawlwp_bulk_editor_save',
			nonce:  cfg.nonce,
			type:   images ? 'images' : 'posts',
			rows:   rows
		}, function (resp) {
			$saveBtn.text(i18n.saveChanges || 'Save Changes');

			if (!resp.success) {
				markFailedRows((resp.data && resp.data.failed) ? resp.data.failed : []);
				showNotice((resp.data && resp.data.message) ? resp.data.message : (i18n.saveError || 'Save failed.'), true);
				$saveBtn.prop('disabled', false);
				return;
			}

			// Mark saved rows as clean.
			var savedIds = (resp.data && resp.data.saved) ? resp.data.saved : [];
			savedIds.forEach(function (id) {
				delete dirty[String(id)];

				var $row = $tbody.find('tr[data-id="' + id + '"]');
				$row.find('.cwp-bulk-input').each(function () {
					$(this).data('original', $(this).val()).attr('data-original', $(this).val());
				});
				$row.removeClass('is-dirty is-failed');
				$row.find('.cwp-bulk-row-error').remove();
				$row.find('.is-ai').removeClass('is-ai').find('.cwp-bulk-ai-tag').remove();
			});

			if (images) {
				refreshMissingCount();
			}

			// Rows the server refused stay dirty and are called out in the table.
			var failedIds = (resp.data && resp.data.failed) ? resp.data.failed : [];
			markFailedRows(failedIds);

			toggleSaveButton();

			var message = (resp.data && resp.data.message) ? resp.data.message : (i18n.saved || 'Saved.');

			showNotice(message, failedIds.length > 0);
		}).fail(function () {
			$saveBtn.prop('disabled', false).text(i18n.saveChanges || 'Save Changes');
			showNotice(i18n.saveError || 'Save failed.', true);
		});
	}

	/**
	 * Flag the rows the server could not save, so a partial failure is visible
	 * in the table instead of being swallowed.
	 *
	 * @param {Array} ids Post IDs that failed.
	 */
	function markFailedRows(ids) {
		if (!ids || !ids.length) {
			return;
		}

		var label = (i18n.rowsFailed || '%d row(s) could not be saved.').replace('%d', ids.length);

		ids.forEach(function (id) {
			var $row = $tbody.find('tr[data-id="' + id + '"]');

			if (!$row.length) {
				return;
			}

			$row.addClass('is-failed');

			var $cell = $row.find('.cwp-bulk-col-post');

			if (!$cell.find('.cwp-bulk-row-error').length) {
				$cell.append($('<span class="cwp-bulk-row-error"></span>').text(label));
			}
		});
	}

	// -------------------------------------------------------------------------
	// Dirty-state tracking
	// -------------------------------------------------------------------------

	function markDirty($input) {
		var $row  = $input.closest('tr');
		var id    = $row.data('id');
		var field = $input.data('field');

		if (!id || !field) {
			return;
		}

		// Editing the row clears a previous failure marker.
		$row.removeClass('is-failed').find('.cwp-bulk-row-error').remove();

		var values  = {};
		var changed = false;

		$row.find('.cwp-bulk-input').each(function () {
			var $field = $(this);
			values[$field.data('field')] = $field.val();
			changed = changed || $field.val() !== ($field.attr('data-original') || '');
		});

		if (changed) {
			dirty[String(id)] = values;
			$row.addClass('is-dirty');
		} else {
			delete dirty[String(id)];
			$row.removeClass('is-dirty');
		}

		toggleSaveButton();
	}

	function hasDirty() {
		return Object.keys(dirty).length > 0;
	}

	function toggleSaveButton() {
		var count = Object.keys(dirty).length;

		$saveBtn.prop('disabled', count === 0);

		var label = i18n.saveChanges || 'Save Changes';
		$saveBtn.text(count > 0 ? label + ' (' + count + ')' : label);
	}

	// -------------------------------------------------------------------------
	// Character count
	// -------------------------------------------------------------------------

	/**
	 * Values with variables are counted as they will be output: the length is
	 * rendered by the server for stored values and fetched for edited ones.
	 */
	function updateCount($input) {
		var $count = $input.closest('td').find('.cwp-bulk-count');
		var val    = $input.val();

		clearTimeout($input.data('cwpPreviewTimer'));

		if (val.indexOf('{{') === -1) {
			setCount($count, val.length, false);
			return;
		}

		if ($count.data('resolvedFor') === val) {
			setCount($count, parseInt($count.attr('data-length'), 10) || 0, true);
			return;
		}

		$input.data('cwpPreviewTimer', setTimeout(function () {
			$.post(cfg.ajaxUrl, {
				action: 'crawlwp_bulk_editor_preview',
				nonce:  cfg.nonce,
				id:     $input.closest('tr').data('id'),
				text:   val
			}, function (resp) {
				if (!resp || !resp.success || $input.val() !== val) {
					return;
				}

				$count.attr('data-length', resp.data.length).data('resolvedFor', val);
				setCount($count, parseInt(resp.data.length, 10) || 0, true);
			});
		}, 400));
	}

	function setCount($count, length, resolved) {
		var max = parseInt($count.data('max'), 10) || 0;

		$count.text(length + ' / ' + max);
		$count.toggleClass('is-over', max > 0 && length > max);
		$count.attr('title', resolved ? (i18n.resolvedLength || '') : '');
	}

	// -------------------------------------------------------------------------
	// Views
	// -------------------------------------------------------------------------

	function renderHead() {
		var check = '<th class="cwp-bulk-col-check"><input type="checkbox" class="cwp-bulk-check-all"></th>';
		var cols  = state.view === 'images'
			? [['cwp-bulk-col-image', i18n.image], ['cwp-bulk-col-alt', i18n.altText, 2]]
			: [['cwp-bulk-col-post', i18n.post], ['cwp-bulk-col-title', i18n.seoTitle], ['cwp-bulk-col-desc', i18n.metaDescription]];
		var $tr = $('<tr></tr>').append(check);

		cols.forEach(function (c) {
			var $th = $('<th></th>').addClass(c[0]).text(c[1] || '');
			if (c[2]) {
				$th.attr('colspan', c[2]);
			}
			$tr.append($th);
		});

		$('#cwp-bulk-thead').empty().append($tr);
	}

	function updateMissingBadge(count) {
		var $badge = $('#cwp-bulk-missing-alt');
		$badge.text(fmt(i18n.missingAlt || '%s missing', [Number(count).toLocaleString()])).toggle(count > 0);
	}

	function refreshMissingCount() {
		$.post(cfg.ajaxUrl, {
			action: 'crawlwp_bulk_editor_images', nonce: cfg.nonce, count_only: 1
		}, function (resp) {
			if (resp && resp.success) {
				updateMissingBadge(resp.data.missing);
			}
		});
	}

	// -------------------------------------------------------------------------
	// AI
	// -------------------------------------------------------------------------

	function bindAI() {
		// One field.
		$wrap.on('click', '.cwp-bulk-ai', function (e) {
			e.preventDefault();
			var $input = $(this).closest('.cwp-bulk-field').find('.cwp-bulk-input');

			if (aiJob || $input.closest('td').hasClass('is-ai-busy')) {
				return;
			}

			writeField($input, false, function (result) {
				if (result.error) {
					showNotice(result.error, true, result.connectUrl);
				}
			});
		});

		// Many fields.
		$wrap.on('click', '#cwp-bulk-ai-btn', function () {
			if (!cfg.batchAI) {
				showNotice(i18n.aiPremium, true, cfg.upgradeUrl, i18n.aiUpgrade);
				return;
			}

			if (!cfg.aiReady) {
				showNotice(i18n.aiNotReady, true, cfg.connectUrl, i18n.aiConnect);
				return;
			}

			if (!aiJob) {
				openDialog();
			}
		});

		$wrap.on('click', '.cwp-bulk-progress__stop', function () {
			if (aiJob) {
				aiJob.stopped = true;
			}
		});
	}

	/**
	 * Ask the server to write one field and put the result in it, marked
	 * "AI · not saved" until the table is saved.
	 *
	 * @param {jQuery}   $input
	 * @param {boolean}  batch
	 * @param {Function} done   Called with {ok, error, connectUrl, rateLimited}.
	 */
	function writeField($input, batch, done) {
		var $td    = $input.closest('td');
		var field  = $input.data('field');
		var $count = $td.find('.cwp-bulk-count');
		var label  = field === 'alt' ? i18n.aiLookingImage : i18n.aiWriting;

		$td.addClass('is-ai-busy');
		$td.find('.cwp-bulk-ai-wait').remove();
		$td.find('.cwp-bulk-field').append($('<span class="cwp-bulk-ai-wait"></span>').text(label || ''));
		$td.find('.cwp-bulk-ai-error').remove();

		$.ajax({
			url:  cfg.ajaxUrl,
			type: 'POST',
			data: {
				action:   'crawlwp_bulk_editor_ai',
				nonce:    cfg.nonce,
				id:       $input.closest('tr').data('id'),
				field:    field,
				previous: $input.val(),
				batch:    batch ? 1 : 0
			}
		}).done(function (resp) {
			if (resp && resp.success && resp.data && typeof resp.data.text === 'string') {
				$input.val(resp.data.text).trigger('input');
				$td.addClass('is-ai');

				if (!$count.find('.cwp-bulk-ai-tag').length) {
					$count.before($('<span class="cwp-bulk-ai-tag"></span>').text(i18n.aiNotSaved || ''));
				}

				done({ ok: true });
				return;
			}

			var data = resp && resp.data ? resp.data : {};
			fieldError($td, data.message || i18n.aiError);
			done({ error: data.message || i18n.aiError, connectUrl: data.connectUrl, stop: !!data.stop });
		}).fail(function (xhr) {
			var data = xhr && xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : {};
			fieldError($td, data.message || i18n.aiError);
			done({ error: data.message || i18n.aiError, connectUrl: data.connectUrl, rateLimited: xhr && xhr.status === 429, dailyLimit: !!data.dailyLimit });
		}).always(function () {
			$td.removeClass('is-ai-busy').find('.cwp-bulk-ai-wait').remove();
		});
	}

	function fieldError($td, message) {
		$td.find('.cwp-bulk-ai-error').remove();
		$td.append($('<span class="cwp-bulk-ai-error"></span>').text(message));
	}

	/**
	 * Fields of the rows "Write with AI" would fill.
	 *
	 * @param {Object} opts {fields: [], scope: 'selected'|'page', overwrite: bool}
	 * @return {jQuery[]}
	 */
	function batchTargets(opts) {
		var $rows = $tbody.find('tr[data-id]');

		if (opts.scope === 'selected') {
			$rows = $rows.filter(function () {
				return $(this).find('.cwp-bulk-check').is(':checked');
			});
		}

		var out = [];

		$rows.each(function () {
			var $row = $(this);
			opts.fields.forEach(function (f) {
				var $input = $row.find('.cwp-bulk-input[data-field="' + f + '"]');
				if ($input.length && (opts.overwrite || $.trim($input.val()) === '')) {
					out.push($input);
				}
			});
		});

		return out;
	}

	function openDialog() {
		var images   = state.view === 'images';
		var selected = $tbody.find('.cwp-bulk-check:checked').length;
		var onPage   = $tbody.find('tr[data-id]').length;

		var fields = images
			? [['alt', i18n.aiAlts]]
			: [['seo_title', i18n.aiTitles], ['seo_description', i18n.aiDescriptions]];

		var $dialog = $('<div class="cwp-bulk-dialog" role="dialog" aria-modal="true"></div>');
		var $box    = $('<div class="cwp-bulk-dialog__box"></div>').appendTo($dialog);

		$box.append($('<h2 class="cwp-bulk-dialog__title"></h2>').html(cfg.aiIcon || '').append($('<span></span>').text(i18n.aiDialogTitle)));

		var $body = $('<div class="cwp-bulk-dialog__body"></div>').appendTo($box);

		if (fields.length > 1) {
			var $write = $('<fieldset></fieldset>').append($('<legend></legend>').text(i18n.aiWrite));
			fields.forEach(function (f) {
				$write.append($('<label></label>').append($('<input type="checkbox" name="cwp-ai-field" checked>').val(f[0])).append(document.createTextNode(' ' + f[1])));
			});
			$body.append($write);
		}

		var $for = $('<fieldset></fieldset>').append($('<legend></legend>').text(i18n.aiFor));
		if (selected) {
			$for.append($('<label></label>').append('<input type="radio" name="cwp-ai-scope" value="selected" checked>').append(document.createTextNode(' ' + fmt(i18n.aiSelected, [selected]))));
		}
		$for.append($('<label></label>').append($('<input type="radio" name="cwp-ai-scope" value="page">').prop('checked', !selected)).append(document.createTextNode(' ' + fmt(i18n.aiAllOnPage, [onPage]))));
		$for.append($('<p class="cwp-bulk-dialog__hint"></p>').text(i18n.aiPageHint));
		$body.append($for);

		var $existing = $('<fieldset></fieldset>').append($('<legend></legend>').text(i18n.aiExisting));
		$existing.append($('<label></label>').append('<input type="radio" name="cwp-ai-overwrite" value="0" checked>').append(document.createTextNode(' ' + i18n.aiKeep)));
		$existing.append($('<label></label>').append('<input type="radio" name="cwp-ai-overwrite" value="1">').append(document.createTextNode(' ' + i18n.aiRewrite)));
		$body.append($existing);

		var $note = $('<p class="cwp-bulk-dialog__note"></p>').appendTo($body);

		var $foot   = $('<div class="cwp-bulk-dialog__foot"></div>').appendTo($box);
		var $cancel = $('<button type="button" class="cwp-bulk-btn"></button>').text(i18n.cancel).appendTo($foot);
		var $start  = $('<button type="button" class="cwp-bulk-btn cwp-bulk-btn--ai"></button>').appendTo($foot);

		function options() {
			var chosen = fields.length > 1
				? $body.find('input[name="cwp-ai-field"]:checked').map(function () { return this.value; }).get()
				: [fields[0][0]];

			return {
				fields:    chosen,
				scope:     $body.find('input[name="cwp-ai-scope"]:checked').val(),
				overwrite: $body.find('input[name="cwp-ai-overwrite"]:checked').val() === '1'
			};
		}

		function update() {
			var count = batchTargets(options()).length;
			$note.text(count ? fmt(i18n.aiNote, [count]) : i18n.aiNothing);
			$start.text(fmt(i18n.aiStart, [count])).prop('disabled', count === 0);
		}

		function close() {
			$dialog.remove();
			$(document).off('keydown.cwpAiDialog');
			$('#cwp-bulk-ai-btn').trigger('focus');
		}

		$body.on('change', 'input', update);
		$cancel.on('click', close);
		$dialog.on('click', function (e) {
			if (e.target === $dialog.get(0)) {
				close();
			}
		});
		$(document).on('keydown.cwpAiDialog', function (e) {
			if (e.key === 'Escape') {
				close();
			}
		});
		$start.on('click', function () {
			var targets = batchTargets(options());
			close();
			runBatch(targets);
		});

		update();
		$('body').append($dialog);
		$start.trigger('focus');
	}

	/**
	 * Write the fields one after another, so the provider and the rate limit
	 * are not flooded, showing progress with a Stop button.
	 *
	 * @param {jQuery[]} targets
	 */
	function runBatch(targets) {
		var $progress = $('#cwp-bulk-progress');
		var label     = state.view === 'images' ? i18n.aiProgressAlt : i18n.aiProgressPosts;
		var job       = { stopped: false, done: 0, ok: 0, failed: 0 };

		aiJob = job;
		hideNotice();
		$wrap.addClass('is-ai-running');
		$progress.css('display', 'flex');

		function render() {
			$progress.find('.cwp-bulk-progress__label').empty()
				.append($('<b></b>').text(label + ' '))
				.append(document.createTextNode(fmt(i18n.aiProgressCount, [job.done, targets.length])));
			$progress.find('.cwp-bulk-progress__bar i').css('width', (targets.length ? job.done / targets.length * 100 : 0) + '%');
		}

		function finish(message, isError, linkUrl) {
			aiJob = null;
			$wrap.removeClass('is-ai-running');
			$progress.hide();
			showNotice(message, isError, linkUrl);
		}

		function next() {
			if (job.stopped) {
				finish(i18n.aiStopped, false);
				return;
			}

			if (job.done >= targets.length) {
				finish(fmt(i18n.aiDone, [job.ok, job.failed]), job.failed > 0);
				return;
			}

			var $input = targets[job.done];

			writeField($input, true, function (result) {
				job.done++;

				if (result.ok) {
					job.ok++;
				} else {
					job.failed++;
				}

				render();

				if (result.rateLimited) {
					/* The daily cap lasts until midnight: the server's message says so, "wait a few minutes" would not. */
					finish(result.dailyLimit ? result.error : i18n.aiRateLimited, true);
					return;
				}

				// A connection problem (or a model that can't read images) fails every field the same way: stop.
				if (result.connectUrl || result.stop) {
					finish(result.error, true, result.connectUrl);
					return;
				}

				next();
			});
		}

		render();
		next();
	}

	/**
	 * PHP-style placeholders (%s, %d, %1$s) in translated strings.
	 */
	function fmt(str, args) {
		var i = 0;
		return String(str || '').replace(/%(\d+\$)?[sd]/g, function (m, pos) {
			var index = pos ? parseInt(pos, 10) - 1 : i++;
			return typeof args[index] !== 'undefined' ? args[index] : m;
		});
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	var noticeTimer = null;

	function showNotice(message, isError, linkUrl, linkText) {
		$notice
			.text(message)
			.toggleClass('cwp-bulk-notice--error', !!isError)
			.toggleClass('cwp-bulk-notice--success', !isError)
			.prop('hidden', false);

		if (linkUrl) {
			$notice.append(' ').append($('<a target="_blank" rel="noopener"></a>').attr('href', linkUrl).text(linkText || i18n.aiConnect || linkUrl));
		}

		clearTimeout(noticeTimer);

		// Messages with a link or about AI results stay until the next action.
		if (!linkUrl && !isError) {
			noticeTimer = setTimeout(hideNotice, 6000);
		}
	}

	function hideNotice() {
		$notice.prop('hidden', true);
	}

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------

	/**
	 * Render numbered pagination buttons.
	 *
	 * @param {number} currentPage
	 * @param {number} totalPages
	 */
	function renderPagination(currentPage, totalPages) {
		if (totalPages <= 1) {
			$pageLinks.html('');
			return;
		}

		var html = '';

		if (currentPage > 1) {
			html += '<button type="button" class="cwp-bulk-page-btn button" data-page="' + (currentPage - 1) + '">&laquo;</button> ';
		}

		var start = Math.max(1, currentPage - 3);
		var end   = Math.min(totalPages, currentPage + 3);

		for (var p = start; p <= end; p++) {
			var active = (p === currentPage) ? ' button-primary' : '';
			html += '<button type="button" class="cwp-bulk-page-btn button' + active + '" data-page="' + p + '">' + p + '</button> ';
		}

		if (currentPage < totalPages) {
			html += '<button type="button" class="cwp-bulk-page-btn button" data-page="' + (currentPage + 1) + '">&raquo;</button>';
		}

		$pageLinks.html(html);
	}

	// -------------------------------------------------------------------------
	// "Insert variable" dropdown
	// -------------------------------------------------------------------------

	/**
	 * Build the shared panel once (lazy) and append it to the wrap so it can
	 * be repositioned/reused for whichever textarea's trigger was clicked.
	 */
	function buildVarsPanel() {
		if ($varsPanel) {
			return;
		}

		$varsPanel = $('<div class="cwp-bulk-vars-panel" hidden></div>');
		$varsSearch = $('<input type="search" class="cwp-bulk-vars-panel__search" />')
			.attr('placeholder', i18n.searchVariables || 'Search variables…');
		$varsList = $('<div class="cwp-bulk-vars-panel__list"></div>');

		$varsPanel.append($varsSearch).append($varsList);
		$('body').append($varsPanel);

		$varsSearch.on('input', function () {
			renderVarsList($(this).val());
		});

		renderVarsList('');
	}

	/**
	 * (Re)render the variable list, optionally filtered by a search term.
	 */
	function renderVarsList(filter) {
		$varsList.empty();

		var f = (filter || '').toLowerCase();
		var shown = 0;

		VARS.forEach(function (v) {
			var token = v.token || '';
			var desc  = v.desc || '';

			if (f && token.toLowerCase().indexOf(f) === -1 && desc.toLowerCase().indexOf(f) === -1) {
				return;
			}

			shown++;

			var $item = $('<button type="button" class="cwp-bulk-vars-panel__item"></button>')
				.append($('<code></code>').text('{{ ' + token + ' }}'))
				.append($('<span></span>').text(desc));

			$item.on('click', function () {
				insertVariable('{{ ' + token + ' }}');
			});

			$varsList.append($item);
		});

		if (!shown) {
			$varsList.append($('<p class="cwp-bulk-vars-panel__empty"></p>').text(i18n.noVariables || 'No matching variables.'));
		}
	}

	/**
	 * Insert the chosen token at the caret of the currently active textarea,
	 * then trigger the usual dirty/char-count handling via a real input event.
	 */
	function insertVariable(text) {
		if (!$varsTarget || !$varsTarget.length) {
			closeVarsPanel();
			return;
		}

		var el = $varsTarget.get(0);
		var start = (typeof el.selectionStart === 'number') ? el.selectionStart : el.value.length;
		var end   = (typeof el.selectionEnd === 'number') ? el.selectionEnd : el.value.length;
		var val   = el.value;

		el.value = val.slice(0, start) + text + val.slice(end);
		el.selectionStart = el.selectionEnd = start + text.length;
		el.focus();

		$varsTarget.trigger('input');

		closeVarsPanel();
	}

	/**
	 * Toggle the shared panel for the textarea associated with the clicked
	 * trigger button. Positions the panel with `position: fixed` (based on
	 * the trigger's bounding rect) so it renders correctly regardless of the
	 * table's own scroll/pagination state.
	 */
	function toggleVarsPanel($trigger) {
		buildVarsPanel();

		var $field    = $trigger.closest('.cwp-bulk-field');
		var $textarea = $field.find('.cwp-bulk-input');

		// Clicking the same trigger again closes it.
		if (!$varsPanel.prop('hidden') && $varsTarget && $varsTarget.get(0) === $textarea.get(0)) {
			closeVarsPanel();
			return;
		}

		closeVarsPanel();

		$varsTarget = $textarea;

		var rect = $trigger.get(0).getBoundingClientRect();

		$varsPanel.css({
			position: 'fixed',
			top:  (rect.bottom + 4) + 'px',
			left: Math.max(8, rect.right - 340) + 'px'
		});

		$varsPanel.prop('hidden', false);
		$trigger.attr('aria-expanded', 'true');
		$varsSearch.val('');
		renderVarsList('');
		$varsSearch.trigger('focus');

		$trigger.data('cwpBulkVarsOpen', true);
	}

	function closeVarsPanel() {
		if (!$varsPanel) {
			return;
		}

		$varsPanel.prop('hidden', true);
		$wrap.find('.cwp-bulk-vars[aria-expanded="true"]').attr('aria-expanded', 'false');
		$varsTarget = null;
	}

	// Close on outside click or Escape.
	$(document).on('click', function (e) {
		if ($varsPanel && !$varsPanel.prop('hidden') && !$(e.target).closest('.cwp-bulk-vars-panel, .cwp-bulk-vars').length) {
			closeVarsPanel();
		}
	});

	$(document).on('keydown', function (e) {
		if (e.key === 'Escape') {
			closeVarsPanel();
		}
	});

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function escapeHtml(str) {
		return $('<div>').text(str).html();
	}

}(jQuery));
