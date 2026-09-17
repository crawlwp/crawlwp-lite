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
		page:      1,
		per_page:  20,
		search:    '',
		post_type: '',
		filter:    'all'
	};

	// Dirty rows keyed by post id: { seo_title: '...', seo_description: '...' }.
	var dirty = {};

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
		loadTable();
	});

	// -------------------------------------------------------------------------
	// Event bindings
	// -------------------------------------------------------------------------

	function bindEvents() {

		// Post type filter.
		$wrap.on('change', '#cwp-bulk-post-type', function () {
			state.post_type = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Missing title/description filter.
		$wrap.on('change', '#cwp-bulk-filter', function () {
			state.filter = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Search (debounced).
		$wrap.on('input', '#cwp-bulk-search', function () {
			var val = $(this).val();
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				state.search = val;
				state.page   = 1;
				loadTable();
			}, 300);
		});

		// Per-page.
		$wrap.on('change', '#cwp-bulk-per-page', function () {
			state.per_page = parseInt($(this).val(), 10) || 20;
			state.page     = 1;
			loadTable();
		});

		// Pagination links (delegated, injected dynamically).
		$wrap.on('click', '.cwp-bulk-page-btn', function () {
			var page = parseInt($(this).data('page'), 10);
			if (page && page !== state.page) {
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
				e.preventDefault();
				return i18n.confirmLeave || 'You have unsaved changes.';
			}
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: load table
	// -------------------------------------------------------------------------

	function loadTable() {
		closeVarsPanel();
		$tbody.html('<tr class="cwp-bulk-loading-row"><td colspan="3">' + escapeHtml('Loading…') + '</td></tr>');
		dirty = {};
		toggleSaveButton();

		$.post(cfg.ajaxUrl, {
			action:    'crawlwp_bulk_editor_list',
			nonce:     cfg.nonce,
			search:    state.search,
			post_type: state.post_type,
			filter:    state.filter,
			per_page:  state.per_page,
			page:      state.page
		}, function (resp) {
			if (!resp.success) {
				$tbody.html('<tr><td colspan="3">' + escapeHtml((resp.data && resp.data.message) ? resp.data.message : 'Error') + '</td></tr>');
				return;
			}

			var data = resp.data;
			$tbody.html(data.html);

			$tbody.find('.cwp-bulk-input').each(function () {
				updateCount($(this));
			});

			$totalInfo.text('Total ' + data.total + ' item' + (data.total === 1 ? '' : 's') + '.');

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
			return {
				id:              id,
				seo_title:       dirty[id].seo_title,
				seo_description: dirty[id].seo_description
			};
		});

		$saveBtn.prop('disabled', true).text(i18n.saving || 'Saving…');
		hideNotice();

		$.post(cfg.ajaxUrl, {
			action: 'crawlwp_bulk_editor_save',
			nonce:  cfg.nonce,
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
			});

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

		var $title = $row.find('[data-field="seo_title"]');
		var $desc  = $row.find('[data-field="seo_description"]');

		var titleVal = $title.val();
		var descVal  = $desc.val();

		var titleChanged = titleVal !== ($title.attr('data-original') || '');
		var descChanged  = descVal !== ($desc.attr('data-original') || '');

		if (titleChanged || descChanged) {
			dirty[String(id)] = {
				seo_title:       titleVal,
				seo_description: descVal
			};
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

	function updateCount($input) {
		var $count = $input.closest('td').find('.cwp-bulk-count');
		var max    = parseInt($count.data('max'), 10) || 0;
		var length = $input.val().length;

		$count.text(length + ' / ' + max);
		$count.toggleClass('is-over', max > 0 && length > max);
	}

	// -------------------------------------------------------------------------
	// Notices
	// -------------------------------------------------------------------------

	function showNotice(message, isError) {
		$notice
			.text(message)
			.toggleClass('cwp-bulk-notice--error', !!isError)
			.toggleClass('cwp-bulk-notice--success', !isError)
			.prop('hidden', false);

		setTimeout(hideNotice, 4000);
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
