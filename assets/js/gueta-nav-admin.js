/**
 * The menu editor in the theme settings.
 *
 * The page holds the tree in guetaNavEditor (from gueta-nav-editor.php) and
 * draws it as three nested lists: the bar, each item's columns, each column's
 * links. jQuery UI's sortable moves rows within a list and between lists of
 * the same level. The lists on the page are the truth while editing; on save
 * they are read back into the tree and posted as JSON in one hidden field.
 */
(function ($) {
	'use strict';

	var settings = window.guetaNavEditor;
	var root = $('[data-nav-editor]');

	if (!settings || !root.length) {
		return;
	}

	var tree = root.find('[data-nav-tree]');
	var field = root.find('[data-nav-field]');
	var categories = {};
	var children = {};

	(settings.categories || []).forEach(function (cat) {
		categories[cat.id] = cat;
		(children[cat.parent] = children[cat.parent] || []).push(cat);
	});

	/* ---------------------------------------------------------------------
	 * Small pieces
	 * ------------------------------------------------------------------ */

	function catName(id) {
		return categories[id] ? categories[id].name : '(קטגוריה שנמחקה)';
	}

	function copy(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function handle() {
		return $('<span class="gnav-handle dashicons dashicons-move" title="גרירה"></span>');
	}

	function badge(type) {
		var text = { cat: 'קטגוריה', link: 'קישור', none: 'כותרת' }[type] || '';

		return $('<span class="gnav-badge"></span>').addClass('is-' + type).text(text);
	}

	function button(label, className) {
		return $('<button type="button" class="button-link"></button>').addClass(className || '').text(label);
	}

	/**
	 * A select of every category, as a tree, for picking one.
	 */
	function categorySelect() {
		var select = $('<select class="gnav-picker"><option value="">בחירת קטגוריה…</option></select>');

		function add(parent, depth) {
			(children[parent] || []).forEach(function (cat) {
				var label = new Array(depth + 1).join('— ') + cat.name + ' (' + cat.count + ')';

				select.append($('<option></option>').val(cat.id).text(label));
				add(cat.id, depth + 1);
			});
		}

		add(0, 0);

		return select;
	}

	/**
	 * Ask for a category in place, beside the button that asked, and hand it
	 * to the callback once chosen.
	 */
	function pickCategory(after, callback) {
		var select = categorySelect();
		var wrap = $('<span class="gnav-pick"></span>').append(select, button('ביטול', 'gnav-pick-cancel'));

		after.after(wrap);
		select.trigger('focus');

		select.on('change', function () {
			var id = Number(select.val());

			wrap.remove();

			if (id) {
				callback(id);
			}
		});

		wrap.find('.gnav-pick-cancel').on('click', function () {
			wrap.remove();
		});
	}

	/**
	 * The title box and, for a typed in link, the address box.
	 */
	function fields(node) {
		var out = $('<span class="gnav-fields"></span>');
		var title = $('<input type="text" class="gnav-title">').val(node.title || '');

		title.attr('placeholder', 'cat' === node.type ? catName(node.term) : 'link' === node.type ? 'שם הקישור' : 'כותרת העמודה (לא חובה)');
		out.append(title);

		if ('link' === node.type) {
			out.append($('<input type="url" class="gnav-url" dir="ltr" placeholder="https://">').val(node.url || ''));
		}

		return out;
	}

	/* ---------------------------------------------------------------------
	 * Drawing
	 * ------------------------------------------------------------------ */

	function drawItem(node) {
		var li = $('<li class="gnav-item"></li>').data('node', { type: node.type, term: node.term || 0 });

		li.append(
			$('<div class="gnav-row"></div>').append(
				handle(),
				badge(node.type),
				fields(node),
				button('הסרה', 'gnav-remove')
			)
		);

		return li;
	}

	function drawColumn(node) {
		var li = $('<li class="gnav-col"></li>').data('node', { type: node.type, term: node.term || 0 });
		var items = $('<ul class="gnav-items"></ul>');

		(node.items || []).forEach(function (item) {
			items.append(drawItem(item));
		});

		li.append(
			$('<div class="gnav-row gnav-row--col"></div>').append(
				handle(),
				badge(node.type),
				fields(node),
				button('הסרת העמודה', 'gnav-remove')
			),
			items,
			$('<div class="gnav-add"></div>').append(
				button('+ קטגוריה', 'gnav-add-cat'),
				button('+ קישור', 'gnav-add-link')
			)
		);

		return li;
	}

	function drawColumns(top, columns) {
		var list = top.children('.gnav-body').children('.gnav-columns').empty();

		columns.forEach(function (column) {
			list.append(drawColumn(column));
		});

		connect();
		warnColumns(top);
	}

	function drawTop(node) {
		var data = { type: node.type, term: node.term || 0, mode: 'cat' === node.type ? (node.mode || 'auto') : 'custom' };
		var li = $('<li class="gnav-top"></li>').data('node', data);
		var head = $('<div class="gnav-row gnav-row--top"></div>');
		var body = $('<div class="gnav-body" hidden></div>');

		head.append(
			handle(),
			$('<button type="button" class="gnav-toggle" aria-expanded="false" title="פתיחת התפריט הנפתח"><span class="dashicons dashicons-arrow-left-alt2"></span></button>'),
			badge(node.type),
			fields(node),
			$('<label class="gnav-check"><input type="checkbox" class="gnav-hidden"> הסתרה</label>'),
			$('<label class="gnav-check"><input type="checkbox" class="gnav-highlight"> הדגשה</label>'),
			button('הסרה', 'gnav-remove')
		);

		head.find('.gnav-hidden').prop('checked', !!node.hidden);
		head.find('.gnav-highlight').prop('checked', !!node.highlight);

		body.append(
			$('<p class="gnav-mode"></p>'),
			$('<ul class="gnav-columns"></ul>'),
			$('<p class="gnav-warn" hidden></p>'),
			$('<div class="gnav-add"></div>').append(
				button('+ עמודה מקטגוריה', 'gnav-add-col-cat'),
				button('+ עמודה ריקה', 'gnav-add-col')
			)
		);

		li.append(head, body);
		li.toggleClass('is-hidden', !!node.hidden);

		var columns = 'auto' === data.mode ? autoColumns(node.term) : (node.columns || []);

		li.data('columns', columns);
		drawColumns(li, columns);
		showMode(li);

		return li;
	}

	function draw(nodes) {
		var list = $('<ul class="gnav-tops"></ul>');

		nodes.forEach(function (node) {
			list.append(drawTop(node));
		});

		tree.empty().append(list);
		connect();
	}

	/* ---------------------------------------------------------------------
	 * Automatic panels
	 * ------------------------------------------------------------------ */

	function autoColumns(term) {
		return copy((settings.panels && settings.panels[term]) || []);
	}

	function showMode(top) {
		var data = top.data('node');
		var line = top.children('.gnav-body').children('.gnav-mode').empty();

		top.toggleClass('is-auto', 'auto' === data.mode);

		if ('cat' !== data.type) {
			line.text('סידור ידני: העמודות והקישורים כאן הם מה שיוצג.');
			return;
		}

		if ('auto' === data.mode) {
			line.text('אוטומטי: נבנה מתתי הקטגוריות של "' + catName(data.term) + '". שינוי של משהו כאן יעביר לסידור ידני.');
			return;
		}

		line.text('סידור ידני. ').append(button('חזרה לאוטומטי', 'gnav-auto'));
	}

	/**
	 * Anything changed inside an automatic panel makes the panel the item's own.
	 */
	function makeCustom(top) {
		var data = top.data('node');

		if ('auto' === data.mode) {
			data.mode = 'custom';
			showMode(top);
		}
	}

	function warnColumns(top) {
		var count = top.find('> .gnav-body > .gnav-columns > .gnav-col').length;
		var warn = top.children('.gnav-body').children('.gnav-warn');

		warn.prop('hidden', count <= settings.maxColumns)
			.text('בתפריט הנפתח יוצגו רק ' + settings.maxColumns + ' העמודות הראשונות.');
	}

	/* ---------------------------------------------------------------------
	 * Dragging
	 * ------------------------------------------------------------------ */

	function connect() {
		var common = {
			handle: '> .gnav-row > .gnav-handle',
			placeholder: 'gnav-placeholder',
			forcePlaceholderSize: true,
			tolerance: 'pointer'
		};

		tree.find('.gnav-tops').sortable($.extend({}, common, { axis: 'y' }));

		tree.find('.gnav-columns').sortable($.extend({}, common, {
			connectWith: '.gnav-columns',
			update: function () {
				var top = $(this).closest('.gnav-top');

				makeCustom(top);
				warnColumns(top);
			}
		}));

		tree.find('.gnav-items').sortable($.extend({}, common, {
			connectWith: '.gnav-items',
			update: function () {
				makeCustom($(this).closest('.gnav-top'));
			}
		}));
	}

	/* ---------------------------------------------------------------------
	 * Editing
	 * ------------------------------------------------------------------ */

	tree.on('click', '.gnav-toggle', function () {
		var toggle = $(this);
		var body = toggle.closest('.gnav-top').children('.gnav-body');
		var open = body.prop('hidden');

		body.prop('hidden', !open);
		toggle.attr('aria-expanded', String(open)).closest('.gnav-top').toggleClass('is-open', open);
	});

	tree.on('input change', 'input', function () {
		var input = $(this);
		var top = input.closest('.gnav-top');

		if (input.hasClass('gnav-hidden')) {
			top.toggleClass('is-hidden', input.prop('checked'));
			return;
		}

		// The bar item's own title or switches leave its panel alone.
		if (!input.closest('.gnav-row--top').length) {
			makeCustom(top);
		}
	});

	tree.on('click', '.gnav-remove', function () {
		var row = $(this).closest('li');
		var top = row.closest('.gnav-top');
		var isTop = row.is('.gnav-top');

		if (isTop && !window.confirm('להסיר את הפריט מהתפריט?')) {
			return;
		}

		row.remove();

		if (!isTop) {
			makeCustom(top);
			warnColumns(top);
		}
	});

	tree.on('click', '.gnav-auto', function () {
		var top = $(this).closest('.gnav-top');
		var data = top.data('node');

		if (!window.confirm('לבנות את התפריט הנפתח מחדש מתתי הקטגוריות? הסידור הידני יימחק.')) {
			return;
		}

		data.mode = 'auto';
		drawColumns(top, autoColumns(data.term));
		showMode(top);
	});

	tree.on('click', '.gnav-add-cat', function () {
		var col = $(this).closest('.gnav-col');

		pickCategory($(this).parent(), function (id) {
			col.children('.gnav-items').append(drawItem({ type: 'cat', term: id }));
			makeCustom(col.closest('.gnav-top'));
		});
	});

	tree.on('click', '.gnav-add-link', function () {
		var col = $(this).closest('.gnav-col');
		var item = drawItem({ type: 'link', title: '', url: '' });

		col.children('.gnav-items').append(item);
		item.find('.gnav-title').trigger('focus');
		makeCustom(col.closest('.gnav-top'));
	});

	tree.on('click', '.gnav-add-col', function () {
		var top = $(this).closest('.gnav-top');

		top.find('> .gnav-body > .gnav-columns').append(drawColumn({ type: 'none', title: '', items: [] }));
		connect();
		makeCustom(top);
		warnColumns(top);
	});

	// A column headed by a category, filled with its sub categories.
	tree.on('click', '.gnav-add-col-cat', function () {
		var top = $(this).closest('.gnav-top');

		pickCategory($(this).parent(), function (id) {
			var items = (children[id] || []).filter(function (cat) {
				return cat.count > 0;
			}).map(function (cat) {
				return { type: 'cat', term: cat.id };
			});

			top.find('> .gnav-body > .gnav-columns').append(drawColumn({ type: 'cat', term: id, items: items }));
			connect();
			makeCustom(top);
			warnColumns(top);
		});
	});

	root.on('click', '[data-nav-add-top]', function () {
		var type = $(this).attr('data-nav-add-top');
		var list = tree.find('.gnav-tops');

		if ('link' === type) {
			var top = drawTop({ type: 'link', title: '', url: '', columns: [] });

			list.append(top);
			connect();
			top.find('.gnav-title').trigger('focus');
			return;
		}

		pickCategory($(this), function (id) {
			list.append(drawTop({ type: 'cat', term: id, mode: 'auto' }));
			connect();
		});
	});

	root.on('click', '[data-nav-reset]', function () {
		if (window.confirm('לבטל את כל השינויים שלא נשמרו?')) {
			draw(copy(settings.tree || []));
		}
	});

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------ */

	function read(li) {
		var data = $.extend({}, li.data('node'));
		var row = li.children('.gnav-row');

		data.title = $.trim(row.find('.gnav-title').val() || '');
		data.url = $.trim(row.find('.gnav-url').val() || '');

		return data;
	}

	function serialize() {
		return tree.find('.gnav-tops > .gnav-top').map(function () {
			var li = $(this);
			var top = read(li);
			var row = li.children('.gnav-row');

			top.hidden = row.find('.gnav-hidden').prop('checked') ? 1 : 0;
			top.highlight = row.find('.gnav-highlight').prop('checked') ? 1 : 0;
			top.columns = 'auto' === top.mode ? [] : li.find('> .gnav-body > .gnav-columns > .gnav-col').map(function () {
				var col = read($(this));

				col.items = $(this).find('> .gnav-items > .gnav-item').map(function () {
					return read($(this));
				}).get();

				return col;
			}).get();

			return top;
		}).get();
	}

	root.closest('form').on('submit', function () {
		field.val(JSON.stringify(serialize()));
	});

	draw(copy(settings.tree || []));
}(jQuery));
