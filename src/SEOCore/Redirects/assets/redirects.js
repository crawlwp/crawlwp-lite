/* global crawlwpRedirects, jQuery */
(function ($) {
	'use strict';

	if (typeof crawlwpRedirects === 'undefined') {
		return;
	}

	var cfg   = crawlwpRedirects;
	var i18n  = cfg.i18n || {};

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	var state = {
		page:     1,
		per_page: 20,
		search:   '',
		status:   'all',
		type:     '',
		orderby:  'id',
		order:    'DESC'
	};

	// -------------------------------------------------------------------------
	// DOM refs (resolved after DOMContentLoaded)
	// -------------------------------------------------------------------------

	var $wrap, $tbody, $totalInfo, $pageLinks, $modal, $backdrop;
	var searchTimer = null;

	// -------------------------------------------------------------------------
	// Init
	// -------------------------------------------------------------------------

	$(function () {
		$wrap      = $('#cwp-redirect-wrap');
		$tbody     = $('#cwp-redirect-tbody');
		$totalInfo = $('#cwp-redirect-total-info');
		$pageLinks = $('#cwp-redirect-page-links');
		$modal     = $('#cwp-redirect-modal');
		$backdrop  = $('#cwp-redirect-backdrop');

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

		// Add redirect button.
		$wrap.on('click', '#cwp-redirect-add-btn', function () {
			openModal(null);
		});

		// Modal close.
		$wrap.on('click', '#cwp-redirect-modal-close', closeModal);
		$wrap.on('click', '#cwp-redirect-backdrop', closeModal);

		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				closeModal();
			}
		});

		// Modal save.
		$wrap.on('click', '#cwp-redirect-modal-save', saveRedirect);

		// Table row edit button.
		$wrap.on('click', '.cwp-redirect-edit-btn', function () {
			var $row     = $(this).closest('tr');
			var redirect = getRowData($row);
			if (redirect) {
				openModal(redirect);
			}
		});

		// Table row delete button.
		$wrap.on('click', '.cwp-redirect-delete-btn', function () {
			var id = $(this).data('id');
			if (!id) return;

			if (!window.confirm(i18n.confirmDelete || 'Delete this redirect?')) {
				return;
			}

			deleteRedirect(id);
		});

		// Row enable toggle.
		$wrap.on('change', '.cwp-redirect-row-toggle', function () {
			var id   = $(this).data('id');
			toggleRedirect(id);
		});

		// Check all.
		$wrap.on('change', '#cwp-redirect-check-all', function () {
			var checked = this.checked;
			$tbody.find('.cwp-redirect-row-cb').prop('checked', checked);
		});

		// Bulk apply.
		$wrap.on('click', '#cwp-redirect-bulk-apply', function () {
			var action = $('#cwp-redirect-bulk-action').val();
			if (!action) return;

			var ids = [];
			$tbody.find('.cwp-redirect-row-cb:checked').each(function () {
				ids.push($(this).val());
			});

			if (!ids.length) return;

			if (!window.confirm(i18n.confirmBulk || 'Apply this action?')) {
				return;
			}

			bulkAction(action, ids);
		});

		// Type filter.
		$wrap.on('change', '#cwp-redirect-type-filter', function () {
			state.type = $(this).val();
			state.page = 1;
			loadTable();
		});

		// Search (debounced).
		$wrap.on('input', '#cwp-redirect-search', function () {
			var val = $(this).val();
			clearTimeout(searchTimer);
			searchTimer = setTimeout(function () {
				state.search = val;
				state.page   = 1;
				loadTable();
			}, 300);
		});

		// Per-page.
		$wrap.on('change', '#cwp-redirect-per-page', function () {
			state.per_page = parseInt($(this).val(), 10) || 20;
			state.page     = 1;
			loadTable();
		});

		// Pagination links (delegated, injected dynamically).
		$wrap.on('click', '.cwp-redirect-page-btn', function () {
			var page = parseInt($(this).data('page'), 10);
			if (page && page !== state.page) {
				state.page = page;
				loadTable();
			}
		});

		// Hide To URL field for 410 / 451 types.
		$wrap.on('change', '#cwp-redirect-type', function () {
			toggleToUrlField($(this).val());
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: load table
	// -------------------------------------------------------------------------

	function loadTable() {
		$tbody.html('<tr class="cwp-redirect-loading-row"><td colspan="8">Loading…</td></tr>');

		$.post(cfg.ajaxUrl, {
			action:    'crawlwp_redirect_list',
			nonce:     cfg.nonce,
			search:    state.search,
			status:    state.status,
			type:      state.type,
			per_page:  state.per_page,
			page:      state.page,
			orderby:   state.orderby,
			order:     state.order
		}, function (resp) {
			if (!resp.success) {
				// Server error text is untrusted — insert it as text, never as HTML.
				var $errCell = $('<td colspan="8"></td>').text(resp.data && resp.data.message ? resp.data.message : 'Error');
				$tbody.empty().append($('<tr></tr>').append($errCell));
				return;
			}

			var data = resp.data;
			$tbody.html(data.html);

			// Update total info.
			$totalInfo.text('Total ' + data.total + ' item' + (data.total === 1 ? '' : 's') + '.');

			// Build pagination.
			renderPagination(data.page, data.pages, data.total);
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: save (insert / update)
	// -------------------------------------------------------------------------

	function saveRedirect() {
		var id       = $('#cwp-redirect-id').val();
		var fromUrl  = $('#cwp-redirect-from-url').val().trim();
		var toUrl    = $('#cwp-redirect-to-url').val().trim();
		var type     = parseInt($('#cwp-redirect-type').val(), 10);
		var needsTo  = (type !== 410 && type !== 451);

		if (!fromUrl) {
			alert('From URL is required.');
			return;
		}

		if (needsTo && !toUrl) {
			alert('To URL is required for this redirect type.');
			return;
		}

		var $btn = $('#cwp-redirect-modal-save');
		$btn.prop('disabled', true).text(i18n.saving || 'Saving…');

		var payload = {
			action:               'crawlwp_redirect_save',
			nonce:                cfg.nonce,
			id:                   id || 0,
			redirect_type:        type,
			match_type:           $('#cwp-redirect-match-type').val(),
			from_url:             fromUrl,
			to_url:               toUrl,
			note:                 $('#cwp-redirect-note').val(),
			ignore_query_string:  $('#cwp-redirect-ignore-qs').is(':checked') ? 1 : 0,
			enabled:              $('#cwp-redirect-enabled').is(':checked') ? 1 : 0
		};

		// Priority / external flag only exist once the DB columns are present.
		if ($('#cwp-redirect-priority').length) {
			payload.priority       = parseInt($('#cwp-redirect-priority').val(), 10);
			payload.allow_external = $('#cwp-redirect-allow-external').is(':checked') ? 1 : 0;

			if (isNaN(payload.priority)) {
				payload.priority = 10;
			}
		}

		$.post(cfg.ajaxUrl, payload, function (resp) {
			$btn.prop('disabled', false).text(id ? (i18n.updateBtn || 'Update Redirect') : (i18n.addBtn || 'Add Redirect'));

			if (!resp.success) {
				alert(resp.data && resp.data.message ? resp.data.message : 'Save failed.');
				return;
			}

			closeModal();
			loadTable();
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: delete
	// -------------------------------------------------------------------------

	function deleteRedirect(id) {
		$.post(cfg.ajaxUrl, {
			action: 'crawlwp_redirect_delete',
			nonce:  cfg.nonce,
			id:     id
		}, function (resp) {
			if (resp.success) {
				loadTable();
			} else {
				alert(resp.data && resp.data.message ? resp.data.message : 'Delete failed.');
			}
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: toggle
	// -------------------------------------------------------------------------

	function toggleRedirect(id) {
		$.post(cfg.ajaxUrl, {
			action: 'crawlwp_redirect_toggle',
			nonce:  cfg.nonce,
			id:     id
		}, function (resp) {
			if (!resp.success) {
				// Revert the checkbox state on failure.
				var $toggle = $tbody.find('.cwp-redirect-row-toggle[data-id="' + id + '"]');
				$toggle.prop('checked', !$toggle.prop('checked'));
			}
		});
	}

	// -------------------------------------------------------------------------
	// AJAX: bulk action
	// -------------------------------------------------------------------------

	function bulkAction(action, ids) {
		$.post(cfg.ajaxUrl, {
			action:      'crawlwp_redirect_bulk',
			nonce:       cfg.nonce,
			bulk_action: action,
			ids:         ids
		}, function (resp) {
			loadTable();

			if (!resp.success && resp.data && resp.data.message) {
				alert(resp.data.message);
			}
		});
	}

	// -------------------------------------------------------------------------
	// Modal helpers
	// -------------------------------------------------------------------------

	/**
	 * Open the add/edit modal.
	 *
	 * @param {object|null} redirect  Row data object, or null for a new redirect.
	 */
	function openModal(redirect) {
		var isEdit = (redirect !== null);

		// Reset the form.
		$('#cwp-redirect-id').val(isEdit ? redirect.id : '');
		$('#cwp-redirect-type').val(isEdit ? redirect.redirect_type : '301');
		$('#cwp-redirect-match-type').val(isEdit ? redirect.match_type : 'exact');
		$('#cwp-redirect-from-url').val(isEdit ? redirect.from_url : '');
		$('#cwp-redirect-to-url').val(isEdit ? redirect.to_url : '');
		$('#cwp-redirect-note').val(isEdit ? redirect.note : '');
		$('#cwp-redirect-ignore-qs').prop('checked', isEdit ? !!parseInt(redirect.ignore_query_string, 10) : true);
		$('#cwp-redirect-enabled').prop('checked', isEdit ? !!parseInt(redirect.enabled, 10) : true);

		if ($('#cwp-redirect-priority').length) {
			$('#cwp-redirect-priority').val(isEdit && redirect.priority !== undefined ? redirect.priority : 10);
			$('#cwp-redirect-allow-external').prop('checked', isEdit ? !!parseInt(redirect.allow_external, 10) : false);
		}

		// Set modal title and button label.
		$('#cwp-redirect-modal-title').text(isEdit ? (i18n.editTitle || 'Edit Redirect') : (i18n.addTitle || 'Add Redirect'));
		$('#cwp-redirect-modal-save').text(isEdit ? (i18n.updateBtn || 'Update Redirect') : (i18n.addBtn || 'Add Redirect'));

		// Show / hide To URL field based on current type.
		toggleToUrlField($('#cwp-redirect-type').val());

		$modal.prop('hidden', false);
		$backdrop.prop('hidden', false);

		// Focus the first input.
		setTimeout(function () {
			$('#cwp-redirect-from-url').focus();
		}, 50);
	}

	function closeModal() {
		$modal.prop('hidden', true);
		$backdrop.prop('hidden', true);
	}

	/**
	 * Show / hide the To URL field based on redirect type.
	 *
	 * @param {string} type  Redirect type value.
	 */
	function toggleToUrlField(type) {
		var $field = $('#cwp-redirect-to-field');
		var typeInt = parseInt(type, 10);

		if (typeInt === 410 || typeInt === 451) {
			$field.hide();
		} else {
			$field.show();
		}
	}

	// -------------------------------------------------------------------------
	// Row data helper
	// -------------------------------------------------------------------------

	/**
	 * Read the JSON data attribute from a table row.
	 *
	 * @param {jQuery} $row  The <tr> element.
	 * @return {object|null}
	 */
	function getRowData($row) {
		try {
			return JSON.parse($row.attr('data-redirect') || '');
		} catch (e) {
			return null;
		}
	}

	// -------------------------------------------------------------------------
	// Pagination
	// -------------------------------------------------------------------------

	/**
	 * Render numbered pagination buttons.
	 *
	 * @param {number} currentPage
	 * @param {number} totalPages
	 * @param {number} totalItems
	 */
	function renderPagination(currentPage, totalPages, totalItems) {
		if (totalPages <= 1) {
			$pageLinks.html('');
			return;
		}

		var html = '';

		// Prev.
		if (currentPage > 1) {
			html += '<button type="button" class="cwp-redirect-page-btn button" data-page="' + (currentPage - 1) + '">&laquo;</button> ';
		}

		// Page buttons (show up to 7, window around current).
		var start = Math.max(1, currentPage - 3);
		var end   = Math.min(totalPages, currentPage + 3);

		for (var p = start; p <= end; p++) {
			var active = (p === currentPage) ? ' button-primary' : '';
			html += '<button type="button" class="cwp-redirect-page-btn button' + active + '" data-page="' + p + '">' + p + '</button> ';
		}

		// Next.
		if (currentPage < totalPages) {
			html += '<button type="button" class="cwp-redirect-page-btn button" data-page="' + (currentPage + 1) + '">&raquo;</button>';
		}

		$pageLinks.html(html);
	}

}(jQuery));
