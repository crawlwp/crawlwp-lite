/* global jQuery, crawlwpImporter */
(function ($) {
	'use strict';

	var totals = { imported: 0, skipped: 0 };

	function loadInventory() {
		$.post(crawlwpImporter.ajaxUrl, {
			action: 'crawlwp_import_inventory',
			nonce: crawlwpImporter.nonce
		}).done(function (res) {
			if (!res || !res.success) {
				return;
			}
			renderList(res.data.sources || []);
		});
	}

	function renderList(sources) {
		var $list = $('#cwpImporterList');
		$list.empty();

		if (!sources.length) {
			$list.append($('<p/>').text(crawlwpImporter.i18n.none));
			return;
		}

		sources.forEach(function (src) {
			var $row = $('<div class="cwp-importer-row"/>');
			var meta = [
				src.posts + ' ' + crawlwpImporter.i18n.posts,
				src.terms + ' ' + crawlwpImporter.i18n.terms,
				(src.users || 0) + ' ' + crawlwpImporter.i18n.users,
				src.redirects + ' ' + crawlwpImporter.i18n.redirects
			].join(' · ');
			var $btn = $('<button type="button" class="button button-primary"/>')
				.text(src.available ? 'Import' : 'No data')
				.prop('disabled', !src.available)
				.attr('data-source', src.id);

			$row.append($('<div class="cwp-importer-info"/>')
				.append($('<strong/>').text(src.label))
				.append($('<span class="cwp-importer-meta"/>').text(meta)));
			$row.append($btn);
			$list.append($row);
		});
	}

	function run(source, stage, offset) {
		return $.post(crawlwpImporter.ajaxUrl, {
			action: 'crawlwp_import_step',
			nonce: crawlwpImporter.nonce,
			source: source,
			stage: stage,
			offset: offset,
			overwrite: $('#cwpImporterOverwrite').is(':checked') ? 1 : 0
		});
	}

	function log(msg) {
		var $log = $('#cwpImporterLog').show();
		$log.append($('<div/>').text(msg));
	}

	function stageLabel(stage) {
		var labels = crawlwpImporter.stageLabels || {};

		return labels[stage] || stage;
	}

	function pump(source, stage, offset) {
		run(source, stage, offset).done(function (res) {
			if (!res || !res.success) {
				log(crawlwpImporter.i18n.error);
				$('#cwpImporter').removeClass('is-running');
				return;
			}

			var d = res.data;
			totals.imported += parseInt(d.imported, 10) || 0;
			totals.skipped += parseInt(d.skipped, 10) || 0;

			log(stageLabel(d.stage) + ': +' + d.imported + ' ' + crawlwpImporter.i18n.imported + ', ' + d.skipped + ' ' + crawlwpImporter.i18n.skipped);

			if (d.done) {
				log(crawlwpImporter.i18n.done + ' (' + totals.imported + ' / ' + totals.skipped + ')');
				$('#cwpImporter').removeClass('is-running');
				return;
			}

			pump(source, d.next_stage, d.next_offset);
		}).fail(function () {
			log(crawlwpImporter.i18n.error);
			$('#cwpImporter').removeClass('is-running');
		});
	}

	$(function () {
		if (!$('#cwpImporter').length) {
			return;
		}

		loadInventory();

		$('#cwpImporter').on('click', '[data-source]', function () {
			var source = $(this).attr('data-source');
			if (!window.confirm(crawlwpImporter.i18n.confirm)) {
				return;
			}
			totals = { imported: 0, skipped: 0 };
			$('#cwpImporterLog').empty().show();
			$('#cwpImporter').addClass('is-running');
			log(crawlwpImporter.i18n.running);
			pump(source, crawlwpImporter.firstStage || 'posts', 0);
		});
	});
}(jQuery));
