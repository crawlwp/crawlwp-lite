/* CrawlWP Local SEO: opening hours editor, and menu/cuisine only for places that serve food. */
(function ($) {
	'use strict';

	var foodTypes = (window.crawlwpLocalSeo || {}).foodTypes || [];

	function toggleFood($select) {
		/* "Same as the main business" on a Premium location uses the main business's type. */
		var type = $select.val() || $select.attr('data-cwp-inherit') || '';
		var isFood = $.inArray(type, foodTypes) !== -1;
		$select.closest('form').find('.cwp-local-food').toggle(isFood);
	}

	$(function () {
		$('select[name$="[local_type]"]').each(function () {
			toggleFood($(this));
		}).on('change', function () {
			toggleFood($(this));
		});

		$(document).on('change', '.cwp-hours__open', function () {
			$(this).closest('.cwp-hours__day').toggleClass('is-closed', !this.checked);
		});

		$(document).on('click', '.cwp-hours__add', function (e) {
			e.preventDefault();
			var $day = $(this).closest('.cwp-hours__day');
			$day.find('.cwp-hours__range[hidden]').first().prop('hidden', false).find('input').first().trigger('focus');
			$(this).prop('hidden', $day.find('.cwp-hours__range[hidden]').length === 0);
		});

		$(document).on('click', '.cwp-hours__remove', function (e) {
			e.preventDefault();
			var $range = $(this).closest('.cwp-hours__range');
			$range.prop('hidden', true).find('input').val('');
			$range.closest('.cwp-hours__day').find('.cwp-hours__add').prop('hidden', false);
		});
	});
})(jQuery);
