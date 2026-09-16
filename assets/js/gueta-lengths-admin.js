/**
 * Product screen, lengths tab: add, remove and reorder lengths, work out a
 * percentage from the length itself, and show what each length costs.
 */
(function ($) {
	'use strict';

	$(function () {
		var $panel = $('[data-length-admin]');

		if (!$panel.length) {
			return;
		}

		var $rows = $panel.find('[data-length-rows]');
		var decimals = parseInt($panel.attr('data-decimals') || '2', 10);

		/* The price on the General tab: the sale price while one is set. */
		function basePrice() {
			var sale = parseFloat(String($('#_sale_price').val() || '').replace(',', '.'));
			var regular = parseFloat(String($('#_regular_price').val() || '').replace(',', '.'));

			return !isNaN(sale) && sale > 0 ? sale : regular;
		}

		function refreshPrices() {
			var base = basePrice();

			$rows.find('tr').each(function () {
				var percent = parseFloat(String($(this).find('[data-length-percent]').val() || '').replace(',', '.'));
				var $price = $(this).find('[data-length-price]');

				$price.text(isNaN(base) || isNaN(percent) ? '—' : (base * (1 + percent / 100)).toFixed(decimals));
			});
		}

		/*
		 * A length in metres as a percentage on a price per metre: 1.50 is 50%,
		 * 4.20 is 320%. A size written as 2X3 counts as its area, 6.
		 */
		function percentFromLabel(label) {
			var text = String(label || '').replace(/,/g, '.');
			var size = /(\d+(?:\.\d+)?)\s*[xX×]\s*(\d+(?:\.\d+)?)/.exec(text);
			var single = /(\d+(?:\.\d+)?)/.exec(text);
			var metres = size ? parseFloat(size[1]) * parseFloat(size[2]) : (single ? parseFloat(single[1]) : NaN);

			return isNaN(metres) ? NaN : Math.round((metres - 1) * 100 * 100) / 100;
		}

		$panel.on('click', '[data-length-add]', function () {
			$rows.append($('#tmpl-gueta-length-row').html());
			$rows.find('tr:last input:first').trigger('focus');
			refreshPrices();
		});

		$panel.on('click', '[data-length-remove]', function () {
			$(this).closest('tr').remove();
		});

		$panel.on('click', '[data-length-fill]', function () {
			$rows.find('tr').each(function () {
				var percent = percentFromLabel($(this).find('input[type="text"]').val());

				if (!isNaN(percent)) {
					$(this).find('[data-length-percent]').val(Math.max(0, percent));
				}
			});

			refreshPrices();
		});

		$panel.on('input', '[data-length-percent]', refreshPrices);
		$(document).on('input change', '#_regular_price, #_sale_price', refreshPrices);

		$rows.sortable({
			handle: '.gueta-lengths-admin__handle',
			axis: 'y',
			helper: function (event, $row) {
				// Keep the cells their width while the row is being dragged.
				$row.children().each(function () {
					$(this).width($(this).width());
				});

				return $row;
			}
		});

		refreshPrices();
	});
}(jQuery));
