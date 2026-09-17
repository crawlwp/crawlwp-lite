/* global jQuery, wp, crawlwpWizard */
(function ($) {
	'use strict';

	var stages = ['settings', 'posts', 'terms', 'users', 'redirects'];
	var stageWeights = { settings: 10, posts: 50, terms: 20, users: 10, redirects: 10 };
	var currentStageIndex = 0;
	var totals = { imported: 0, skipped: 0 };
	var isImporting = false;

	function goToStep(stepId) {
		// Update panels
		$('.cwp-wizard-panel').removeClass('is-active');
		$('#cwp-step-' + stepId).addClass('is-active');

		// Update stepper
		var found = false;
		$('.cwp-wizard-step').each(function () {
			var $this = $(this);
			var thisStep = $this.data('step');

			if (thisStep === stepId) {
				$this.addClass('is-active').removeClass('is-completed');
				found = true;
			} else if (!found) {
				$this.addClass('is-completed').removeClass('is-active');
			} else {
				$this.removeClass('is-active is-completed');
			}
		});

		// Scroll to top of card smoothly
		$('html, body').animate({
			scrollTop: $('.cwp-wizard-wrap').offset().top - 30
		}, 200);
	}

	function log(msg) {
		var $log = $('#cwpImportLog').show();
		$log.append($('<div/>').text(msg));
		$log.scrollTop($log[0].scrollHeight);
	}

	function stageLabel(stage) {
		var labels = crawlwpWizard.stageLabels || {};
		return labels[stage] || stage;
	}

	function calculateProgress(stage, isDone) {
		var stageIdx = stages.indexOf(stage);
		if (stageIdx === -1) {
			stageIdx = currentStageIndex;
		} else {
			currentStageIndex = stageIdx;
		}

		if (isDone) {
			return 100;
		}

		var progress = 0;
		for (var i = 0; i < stageIdx; i++) {
			progress += stageWeights[stages[i]] || 20;
		}

		// Halfway through current stage
		progress += (stageWeights[stages[stageIdx]] || 20) / 2;

		return Math.min(Math.round(progress), 95);
	}

	function runImportStep(source, stage, offset) {
		return $.post(crawlwpWizard.ajaxUrl, {
			action: 'crawlwp_import_step',
			nonce: crawlwpWizard.importerNonce,
			source: source,
			stage: stage,
			offset: offset,
			overwrite: $('#cwpWizardOverwrite').is(':checked') ? 1 : 0
		});
	}

	function pumpImport(source, stage, offset) {
		runImportStep(source, stage, offset).done(function (res) {
			if (!res || !res.success) {
				log(crawlwpWizard.i18n.error);
				$('#cwpImportStatusText').text(crawlwpWizard.i18n.error).css('color', '#d63638');
				$('#cwpStartImportBtn').prop('disabled', false).find('.cwp-spin-icon').hide();
				isImporting = false;
				return;
			}

			var d = res.data;
			totals.imported += parseInt(d.imported, 10) || 0;
			totals.skipped += parseInt(d.skipped, 10) || 0;

			var pct = calculateProgress(d.stage, d.done);
			$('#cwpImportProgressFill').css('width', pct + '%');

			var currentLabel = stageLabel(d.stage);
			$('#cwpImportStatusText').text(
				currentLabel + ': +' + d.imported + ' ' + crawlwpWizard.i18n.imported +
				(d.skipped > 0 ? ', ' + d.skipped + ' ' + crawlwpWizard.i18n.skipped : '')
			);

			log(currentLabel + ': +' + d.imported + ' ' + crawlwpWizard.i18n.imported + ', ' + d.skipped + ' ' + crawlwpWizard.i18n.skipped);

			if (d.done) {
				$('#cwpImportProgressFill').css('width', '100%');
				var doneMsg = crawlwpWizard.i18n.done + ' (' + totals.imported + ' ' + crawlwpWizard.i18n.imported + ')';
				$('#cwpImportStatusText').text(doneMsg).css('color', '#00a32a');
				log(doneMsg);

				$('#cwpStartImportBtn').hide();
				$('#cwpImportContinueBtn').show().addClass('button-primary').removeClass('button-secondary');
				isImporting = false;
				return;
			}

			pumpImport(source, d.next_stage, d.next_offset);
		}).fail(function () {
			log(crawlwpWizard.i18n.error);
			$('#cwpImportStatusText').text(crawlwpWizard.i18n.error).css('color', '#d63638');
			$('#cwpStartImportBtn').prop('disabled', false).find('.cwp-spin-icon').hide();
			isImporting = false;
		});
	}

	$(function () {
		if (!$('.cwp-wizard-wrap').length) {
			return;
		}

		// Next / Prev Step Navigation
		$(document).on('click', '.cwp-btn-next[data-goto]', function () {
			var $btn = $(this);
			var target = $btn.data('goto');
			goToStep(target);
		});

		$(document).on('click', '.cwp-btn-prev[data-goto]', function () {
			var $btn = $(this);
			var target = $btn.data('goto');
			goToStep(target);
		});

		// Save Step via AJAX
		$(document).on('click', '.cwp-btn-save-step', function (e) {
			e.preventDefault();
			var $btn = $(this);
			var stepName = $btn.data('step');
			var target = $btn.data('goto');
			var origText = $btn.text();

			$btn.prop('disabled', true).text(crawlwpWizard.i18n.saving);

			var formData = {
				action: 'crawlwp_wizard_save_step',
				nonce: crawlwpWizard.nonce,
				wizard_step: stepName
			};

			if (stepName === 'site_info') {
				var $form = $('#cwpSiteInfoForm');
				formData.site_type = $form.find('input[name="site_type"]:checked').val() || 'organization';
				formData.site_name = $form.find('#cwp_site_name').val();
				formData.site_description = $form.find('#cwp_site_desc').val();
				formData.logo = $form.find('#cwp_site_logo').val();
			} else if (stepName === 'search_appearance') {
				var $form2 = $('#cwpAppearanceForm');
				formData.separator = $form2.find('input[name="separator"]:checked').val() || '-';
				formData.home_title = $form2.find('#cwp_home_title').val();
				formData.home_description = $form2.find('#cwp_home_description').val();
				formData.indexed_post_types = [];
				$form2.find('input[name="indexed_post_types[]"]:checked').each(function () {
					formData.indexed_post_types.push($(this).val());
				});
			} else if (stepName === 'index_now') {
				var $form3 = $('#cwpIndexNowForm');
				formData.index_now_enable = $form3.find('#cwp_index_now_enable').is(':checked') ? 1 : 0;
				formData.api_key = $form3.find('#cwp_indexnow_api_key').val();
				formData.search_engine = $form3.find('input[name="search_engine"]:checked').val() || 'bing-index-now';
				formData.submission_post_types = [];
				$form3.find('input[name="submission_post_types[]"]:checked').each(function () {
					formData.submission_post_types.push($(this).val());
				});
				formData.ping_on_post = $form3.find('input[name="ping_on_post"]').is(':checked') ? 1 : 0;
				formData.ping_on_post_updated = $form3.find('input[name="ping_on_post_updated"]').is(':checked') ? 1 : 0;
			}

			$.post(crawlwpWizard.ajaxUrl, formData).always(function () {
				$btn.prop('disabled', false).text(origText);
				goToStep(target);
			});
		});

		// Media Picker for Logo
		var mediaFrame;
		$(document).on('click', '.cwp-media-upload-btn', function (e) {
			e.preventDefault();
			var $input = $(this).siblings('.cwp-media-url');
			var $preview = $('#cwpLogoPreview');

			if (mediaFrame) {
				mediaFrame.open();
				return;
			}

			mediaFrame = wp.media({
				title: crawlwpWizard.i18n.chooseLogo,
				button: { text: crawlwpWizard.i18n.useLogo },
				multiple: false
			});

			mediaFrame.on('select', function () {
				var attachment = mediaFrame.state().get('selection').first().toJSON();
				$input.val(attachment.url);
				$preview.show().find('img').attr('src', attachment.url);
			});

			mediaFrame.open();
		});

		// Snippet Preview Dynamic Updates
		$('input[name="separator"]').on('change', function () {
			var sep = $(this).val();
			$('.cwp-sep-pill').removeClass('is-selected');
			$(this).closest('.cwp-sep-pill').addClass('is-selected');
			$('.cwp-preview-sep').text(sep);
		});

		$('#cwp_site_name').on('input', function () {
			var name = $(this).val() || $(this).attr('placeholder');
			$('.cwp-preview-site').text(name);
		});

		// Import Handler
		$('#cwpStartImportBtn').on('click', function () {
			if (isImporting) {
				return;
			}

			var source = $('#cwpWizardSource').val() || $(this).data('source');
			if (!source) {
				return;
			}

			isImporting = true;
			totals = { imported: 0, skipped: 0 };
			currentStageIndex = 0;

			var $btn = $(this);
			$btn.prop('disabled', true);
			$btn.find('.cwp-spin-icon').show();
			$btn.find('.cwp-btn-text').text(crawlwpWizard.i18n.running);

			$('#cwpImportProgressWrap').show();
			$('#cwpImportProgressFill').css('width', '5%');
			$('#cwpImportStatusText').text(crawlwpWizard.i18n.running).css('color', '#50575e');
			$('#cwpImportLog').empty().show();

			pumpImport(source, crawlwpWizard.firstStage || 'settings', 0);
		});

		// Toggle Other Sources
		$('#cwpToggleOtherSources').on('click', function () {
			$('#cwpOtherSourcesList').slideToggle(150);
		});

		$('.cwp-select-source-btn').on('click', function () {
			var $btn = $(this);
			var srcId = $btn.data('source');
			var label = $btn.data('label');
			var posts = $btn.data('posts');
			var terms = $btn.data('terms');
			var users = $btn.data('users');
			var redirects = $btn.data('redirects');

			$('#cwpWizardSource').val(srcId);
			$('.cwp-import-alert__title').text(label + ' selected for import');
			$('.cwp-import-stats').html(
				'<span class="cwp-import-stat"><strong>' + posts + '</strong> Posts/Pages</span>' +
				'<span class="cwp-import-stat"><strong>' + terms + '</strong> Categories/Terms</span>' +
				'<span class="cwp-import-stat"><strong>' + users + '</strong> Authors</span>' +
				'<span class="cwp-import-stat"><strong>' + redirects + '</strong> Redirects</span>'
			);

			$('#cwpStartImportBtn .cwp-btn-text').text('Import from ' + label);
			$('#cwpOtherSourcesList').slideUp(150);
		});

		// Deactivate Old Plugin
		$('.cwp-deactivate-btn').on('click', function () {
			var $btn = $(this);
			var sourceId = $btn.data('source');
			var $card = $btn.closest('.cwp-deactivate-card');

			if (!window.confirm(crawlwpWizard.i18n.confirmDeact)) {
				return;
			}

			$btn.prop('disabled', true).find('.cwp-btn-text').text(crawlwpWizard.i18n.deactivating);

			$.post(crawlwpWizard.ajaxUrl, {
				action: 'crawlwp_wizard_deactivate_plugin',
				nonce: crawlwpWizard.nonce,
				source_id: sourceId
			}).done(function (res) {
				if (res && res.success) {
					$card.addClass('is-deactivated');
					$card.find('.cwp-warn-icon').removeClass('dashicons-warning').addClass('dashicons-yes');
					$card.find('strong').text(crawlwpWizard.i18n.deactivated);
					$card.find('p').text('CrawlWP is now managing all on-page SEO without plugin conflicts.');
					$btn.remove();
				} else {
					alert((res && res.data && res.data.message) || crawlwpWizard.i18n.error);
					$btn.prop('disabled', false).find('.cwp-btn-text').text('Retry Deactivation');
				}
			}).fail(function () {
				alert(crawlwpWizard.i18n.error);
				$btn.prop('disabled', false).find('.cwp-btn-text').text('Retry Deactivation');
			});
		});

		// IndexNow Enable Toggle
		$('#cwp_index_now_enable').on('change', function () {
			var isEnabled = $(this).is(':checked');
			if (isEnabled) {
				$('#cwpIndexNowDetails').css({ opacity: 1, pointerEvents: 'auto' });
			} else {
				$('#cwpIndexNowDetails').css({ opacity: 0.5, pointerEvents: 'none' });
			}
		});

		// Engine card selection
		$(document).on('change', 'input[name="search_engine"]', function () {
			$('.cwp-engine-card').removeClass('is-selected');
			$(this).closest('.cwp-engine-card').addClass('is-selected');
		});

		// Generate API Key
		$('#cwpGenerateKeyBtn').on('click', function (e) {
			e.preventDefault();
			var $btn = $(this);
			$btn.prop('disabled', true);
			$btn.find('.dashicons').addClass('cwp-spin-icon');
			$btn.find('.cwp-btn-label').text(crawlwpWizard.i18n.generatingKey || 'Generating…');

			$.post(crawlwpWizard.ajaxUrl, {
				action: 'crawlwp_wizard_generate_key',
				nonce: crawlwpWizard.nonce
			}).done(function (res) {
				if (res && res.success && res.data) {
					$('#cwp_indexnow_api_key').val(res.data.api_key);
					$('#cwpKeyVerificationUrl').attr('href', res.data.key_url).find('code').text(res.data.key_url);
				}
			}).always(function () {
				$btn.prop('disabled', false);
				$btn.find('.dashicons').removeClass('cwp-spin-icon');
				$btn.find('.cwp-btn-label').text('Generate New Key');
			});
		});

		// Copy API Key
		$('#cwpCopyKeyBtn').on('click', function (e) {
			e.preventDefault();
			var key = $('#cwp_indexnow_api_key').val();
			if (!key) {
				return;
			}

			var $btn = $(this);
			var originalText = $btn.find('.cwp-btn-label').text();

			var copySuccess = function () {
				$btn.find('.cwp-btn-label').text(crawlwpWizard.i18n.copied || 'Copied!');
				setTimeout(function () {
					$btn.find('.cwp-btn-label').text(originalText);
				}, 2000);
			};

			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(key).then(copySuccess, function () {
					copyFallback(key, copySuccess);
				});
			} else {
				copyFallback(key, copySuccess);
			}
		});

		function copyFallback(text, cb) {
			var $temp = $('<input>');
			$('body').append($temp);
			$temp.val(text).select();
			try {
				document.execCommand('copy');
				cb();
			} catch (err) {}
			$temp.remove();
		}

		// Finish Wizard
		$('#cwpFinishWizardBtn').on('click', function () {
			var $btn = $(this);
			$btn.prop('disabled', true).text(crawlwpWizard.i18n.saving);

			$.post(crawlwpWizard.ajaxUrl, {
				action: 'crawlwp_wizard_finish',
				nonce: crawlwpWizard.nonce
			}).always(function () {
				window.location.href = crawlwpWizard.dashboardUrl;
			});
		});
	});
}(jQuery));
