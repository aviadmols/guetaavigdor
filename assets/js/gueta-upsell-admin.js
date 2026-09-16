/**
 * Upsell popups screen: the chosen products can be dragged into order, and
 * only the fields the chosen rule needs are shown.
 */
(function ($) {
	'use strict';

	$(function () {
		var $screen = $('.gueta-upsell-admin');

		if (!$screen.length) {
			return;
		}

		/*
		 * WooCommerce's enhanced select builds the search boxes before this
		 * runs. Initialising them again here would replace its search with a
		 * plain list, so they are only given the drag.
		 *
		 * The form posts the <option> elements in document order, so after a
		 * drop they are moved into the order the chips now show.
		 */
		function optionFor($select, chip) {
			var data = $(chip).data('data');
			var value = data && data.id ? String(data.id) : '';
			var title = $(chip).attr('title');

			return $select.find('option').filter(function () {
				return value ? this.value === value : $(this).text() === title;
			}).first();
		}

		function sortable(select) {
			var $select = $(select);
			var $list = $select.next('.select2-container').find('.select2-selection__rendered');

			if (!$list.length || !$.fn.sortable) {
				return;
			}

			if ($list.hasClass('ui-sortable')) {
				$list.sortable('refresh');
				return;
			}

			$list.sortable({
				items: '> li.select2-selection__choice',
				tolerance: 'pointer',
				update: function () {
					$list.children('li.select2-selection__choice').each(function () {
						var $option = optionFor($select, this);

						if ($option.length) {
							$select.append($option);
						}
					});

					$select.trigger('change');
				}
			});
		}

		$screen.find('select.wc-product-search').each(function () {
			var select = this;

			sortable(select);

			$(select).on('select2:select select2:unselect change', function () {
				window.setTimeout(function () {
					sortable(select);
				}, 0);
			});
		});

		function toggle() {
			var rule = $('#gueta_upsell_cart_rule').val();
			var scope = $('#gueta_upsell_scope').val();

			$screen.find('.gueta-upsell-admin__cart-match').prop('hidden', 'contains' !== rule && 'not_contains' !== rule);
			$screen.find('.gueta-upsell-admin__scope-products').prop('hidden', 'products' !== scope);
			$screen.find('.gueta-upsell-admin__scope-pages').prop('hidden', 'pages' !== scope);
			$screen.find('.gueta-upsell-admin__delay').prop('hidden', 'delay' !== $('#gueta_upsell_trigger').val());
		}

		$('#gueta_upsell_cart_rule, #gueta_upsell_scope, #gueta_upsell_trigger').on('change', toggle);
		toggle();
	});
}(jQuery));
