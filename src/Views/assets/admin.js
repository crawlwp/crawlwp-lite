/**
 * CrawlWP admin settings page JS.
 *
 * Handles tab switching, collapsible nav groups, color picker, media picker,
 * and the help-tab toggle. Dynamic values (prefix, Yandex OAuth URL, nonce)
 * are passed via wposaAdmin localized object.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {

		var cfg = window.wposaAdmin || {};

		var prefix      = cfg.prefix      || '';
		var redirectUrl = cfg.redirectUrl  || '';
		var yandexState = cfg.yandexState  || '';
		var codeEndpoint = 'https://oauth.yandex.com/authorize?state=' + yandexState + '&response_type=code&force_confirm=yes&redirect_uri=' + redirectUrl + '&client_id=';

		var $showSettingsToggler = $('.show-settings');
		var $help = $('.wpsa-help-tab-toggle').not('.is-url');

		$help.on('click', function () {
			var $this = $(this);
			var tab = '#tab-link-' + prefix + '_' + $this.data('tab');

			if ($showSettingsToggler.attr('aria-expanded') === 'false') {
				$showSettingsToggler.trigger('click');
			}

			$(tab).find('a').trigger('click');
		});

		// Initiate Color Picker.
		$('.color-picker').iris();

		// Tab switching — hide all groups and show the active one.
		$('.group').hide();
		var activetab = '';
		if (window.location.hash && /^#[a-zA-Z0-9_\-]+$/.test(window.location.hash) && $(window.location.hash).length) {
			activetab = window.location.hash;
		} else if (typeof localStorage !== 'undefined') {
			activetab = localStorage.getItem('activetab') || '';
		}
		if (activetab && $(activetab).length) {
			$(activetab).fadeIn();
		} else {
			$('.group:first').fadeIn();
		}

		$('.group .collapsed').each(function () {
			$(this)
				.find('input:checked')
				.parent()
				.parent()
				.parent()
				.nextAll()
				.each(function () {
					if ($(this).hasClass('last')) {
						$(this).removeClass('hidden');
						return false;
					}
					$(this).filter('.hidden').removeClass('hidden');
				});
		});

		if (activetab && $(activetab + '-tab').length) {
			$(activetab + '-tab').addClass('wposa-nav-tab-active');
		} else {
			$('.wposa-nav-tab-wrapper a:first').addClass('wposa-nav-tab-active');
		}

		$('.wposa-nav-tab-wrapper a').on('click', function (evt) {
			$('.wposa-nav-tab-wrapper a').removeClass('wposa-nav-tab-active');
			$(this).addClass('wposa-nav-tab-active').blur();
			var clickedGroup = $(this).attr('href');
			if (typeof localStorage !== 'undefined') {
				localStorage.setItem('activetab', clickedGroup);
			}
			$('.group').hide();
			$(clickedGroup).fadeIn();
			evt.preventDefault();
		});

		$(window).on('hashchange', function () {
			if (window.location.hash && /^#[a-zA-Z0-9_\-]+$/.test(window.location.hash)) {
				var hash = window.location.hash;
				if ($(hash).length && $(hash + '-tab').length) {
					$('.wposa-nav-tab-wrapper a').removeClass('wposa-nav-tab-active');
					$(hash + '-tab').addClass('wposa-nav-tab-active');
					var $activeGroup = $(hash + '-tab').closest('.wposa-nav-group');
					if ($activeGroup.hasClass('wposa-nav-group--collapsed')) {
						setNavGroupCollapsed($activeGroup, false, true);
					}
					$('.group').hide();
					$(hash).fadeIn();
					if (typeof localStorage !== 'undefined') {
						localStorage.setItem('activetab', hash);
					}
				}
			}
		});

		// Collapsible navigation groups.
		var navGroupKey = function ($group) {
			return 'wposa-navgroup-' + $group.data('group');
		};

		var setNavGroupCollapsed = function ($group, collapsed, store) {
			var $items = $group.children('.wposa-nav-group__items');

			$group.toggleClass('wposa-nav-group--collapsed', collapsed);
			$group.children('.wposa-nav-group__toggle').attr('aria-expanded', collapsed ? 'false' : 'true');
			$items.stop(true, true).css('display', '');

			if (store && typeof localStorage !== 'undefined') {
				localStorage.setItem(navGroupKey($group), collapsed ? '1' : '0');
			}
		};

		// Restore each group's saved state (default: expanded).
		$('.wposa-nav-group').each(function () {
			var $group = $(this);
			if (typeof localStorage !== 'undefined' && '1' === localStorage.getItem(navGroupKey($group))) {
				setNavGroupCollapsed($group, true, false);
			}
		});

		// Expand the group containing the active tab if it was collapsed.
		if (activetab && $(activetab + '-tab').length) {
			var $activeGroup = $(activetab + '-tab').closest('.wposa-nav-group');
			if ($activeGroup.hasClass('wposa-nav-group--collapsed')) {
				setNavGroupCollapsed($activeGroup, false, true);
			}
		}

		$(document).on('click', '.wposa-nav-group__toggle', function (evt) {
			evt.preventDefault();

			var $toggle = $(this);
			var $group  = $toggle.closest('.wposa-nav-group');
			var $items  = $group.children('.wposa-nav-group__items');
			var collapse = !$group.hasClass('wposa-nav-group--collapsed');

			$toggle.attr('aria-expanded', collapse ? 'false' : 'true');

			if (typeof localStorage !== 'undefined') {
				localStorage.setItem(navGroupKey($group), collapse ? '1' : '0');
			}

			if (collapse) {
				$items.stop(true, true).slideUp(150, function () {
					$group.addClass('wposa-nav-group--collapsed');
					$items.css('display', '');
				});
			} else {
				$group.removeClass('wposa-nav-group--collapsed');
				$items.hide().stop(true, true).slideDown(150, function () {
					$items.css('display', '');
				});
			}
		});

		// WordPress media library picker.
		$('.wpsa-browse').on('click', function (event) {
			event.preventDefault();

			var self = $(this);
			var file_frame = (window.wp.media.frames.file_frame = window.wp.media({
				title: self.data('uploader_title'),
				button: { text: self.data('uploader_button_text') },
				multiple: false
			}));

			file_frame.on('select', function () {
				var attachment = file_frame.state().get('selection').first().toJSON();
				self.prev('.wpsa-url').val(attachment.url).change();
			});

			file_frame.open();
		});

		$('input.wpsa-url').on('change keyup paste input', function () {
			var self = $(this);
			self.next().parent().children('.wpsa-image-preview').children('img').attr('src', self.val());
		}).change();

		// Yandex Webmaster OAuth button.
		$('#button_get_token').on('click', function () {
			var clientId = document.getElementById('crawlwp_yandex_webmaster[client_id]').value;
			window.location.href = codeEndpoint + clientId;
		});
	});

})(jQuery);
