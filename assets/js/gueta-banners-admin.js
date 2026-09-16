/**
 * Banners screen: add, remove, reorder, and pick pictures from the media library.
 */
(function ($) {
	'use strict';

	$(function () {
		var $rows = $('[data-banner-rows]');

		if (!$rows.length) {
			return;
		}

		/* Field names carry the row's index; the template holds __i__ in its place. */
		function nextIndex() {
			var highest = -1;

			$rows.find('input[name^="gueta_banner["]').each(function () {
				var match = /^gueta_banner\[(\d+)\]/.exec(this.name);

				if (match) {
					highest = Math.max(highest, parseInt(match[1], 10));
				}
			});

			return highest + 1;
		}

		$('[data-banner-add]').on('click', function () {
			$rows.append($('#tmpl-gueta-banner-row').html().split('__i__').join(String(nextIndex())));
		});

		$rows.on('click', '.gueta-banner-row__remove', function () {
			$(this).closest('[data-banner-row]').remove();
		});

		$rows.on('click', '.gueta-banner-pick__choose', function () {
			var $pick = $(this).closest('.gueta-banner-pick');

			var frame = window.wp.media({
				title: 'בחירת באנר',
				button: { text: 'שימוש בתמונה' },
				library: { type: 'image' },
				multiple: false
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var sizes = attachment.sizes || {};
				var preview = (sizes.medium_large || sizes.large || sizes.full || {}).url || attachment.url;

				$pick.find('input[type="hidden"]').val(attachment.id);
				$pick.find('.gueta-banner-pick__preview').removeClass('is-empty').empty().append($('<img alt="">').attr('src', preview));
				$pick.find('.gueta-banner-pick__clear').prop('hidden', false);
			});

			frame.open();
		});

		$rows.on('click', '.gueta-banner-pick__clear', function () {
			var $pick = $(this).closest('.gueta-banner-pick');

			$pick.find('input[type="hidden"]').val('');
			$pick.find('.gueta-banner-pick__preview').addClass('is-empty').empty();
			$(this).prop('hidden', true);
		});

		if ($.fn.sortable) {
			$rows.sortable({
				axis: 'y',
				handle: '.gueta-banner-row__handle',
				items: '> [data-banner-row]',
				tolerance: 'pointer'
			});
		}
	});
}(jQuery));
