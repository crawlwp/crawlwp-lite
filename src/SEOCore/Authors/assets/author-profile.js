/* CrawlWP: profile photo chooser on the user profile screen. */
(function ($) {
	'use strict';

	var frame;
	var l10n = window.crawlwpAuthorProfile || {};

	function setPhoto(id, url) {
		var preview = $('#cwp_author_photo_preview');

		$('#cwp_author_photo').val(id || '');
		$('#cwp_author_photo_remove').toggle(!!id);

		/* No photo: back to the Gravatar. */
		url = id ? url : preview.attr('data-gravatar');

		if (url) {
			var img = $('<img width="64" height="64" alt="">').attr('src', url).css({ borderRadius: '50%', objectFit: 'cover' });
			preview.empty().append(img);
		}
	}

	$(function () {
		$('#cwp_author_photo_choose').on('click', function (e) {
			e.preventDefault();

			if (!frame) {
				frame = wp.media({
					title: l10n.title,
					button: { text: l10n.button },
					library: { type: 'image' },
					multiple: false
				});

				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					var url = a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url;
					setPhoto(a.id, url);
				});
			}

			frame.open();
		});

		$('#cwp_author_photo_remove').on('click', function (e) {
			e.preventDefault();
			setPhoto('', '');
		});
	});
})(jQuery);
