/**
 * Админка конструктора шаблонов.
 * Медиапикер превью/шрифта + визуальный редактор раскладок.
 *
 * @package Podval Print
 */
(function ($) {
	'use strict';

	/* ───────── Превью основ ───────── */

	$(document).on('click', '.mtpl-pick', function () {
		var target = $(this).data('target');
		var frame = wp.media({
			title: 'Превью основы',
			library: { type: 'image' },
			button: { text: 'Выбрать' },
			multiple: false
		});

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
			$('#mtpl-prev-' + target).val(att.id);
			var $media = $('.mtpl-base-media[data-target="' + target + '"]');
			$media.find('img').remove();
			$media.find('.mtpl-noimg').remove();
			$media.prepend('<img src="' + url + '" alt="">');
		});

		frame.open();
	});

	$(document).on('click', '.mtpl-clear', function () {
		var target = $(this).data('target');
		$('#mtpl-prev-' + target).val(0);
		var $media = $('.mtpl-base-media[data-target="' + target + '"]');
		$media.find('img').remove();
		$media.prepend('<span class="mtpl-noimg">нет превью</span>');
	});

	/* ───────── Шрифт ───────── */

	/* Слотов несколько — обработчики работают по data-slot, а не по id.
	   Раньше шрифт был один, и селекторы были жёсткие; с тремя слотами
	   это означало бы три копии одного кода. */
	$(document).on('click', '.mtpl-font-pick', function () {
		var slot = $(this).data('slot');
		var frame = wp.media({
			title: 'Рукописный шрифт (woff2)',
			button: { text: 'Выбрать' },
			multiple: false
		});

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			if (!/\.woff2?$/i.test(att.url)) {
				window.alert('Нужен файл .woff2 или .woff');
				return;
			}
			$('#mtpl-font-id-' + slot).val(att.id);
			$('#mtpl-font-url-' + slot).val(att.url);
			$('#mtpl-font-name-' + slot).text(att.filename + ' — сохраните, чтобы применить');

			// Подпись по умолчанию — имя файла без расширения: заполнять её
			// руками ради «Caveat» лишний шаг, а пустая подпись означала бы
			// безымянную кнопку в конструкторе.
			var $label = $('#mtpl-font-label-' + slot);
			if ($label.length && !$label.val()) {
				$label.val(att.filename.replace(/\.[^.]+$/, ''));
			}
		});

		frame.open();
	});

	$(document).on('click', '.mtpl-font-clear', function () {
		var slot = $(this).data('slot');
		$('#mtpl-font-id-' + slot).val(0);
		$('#mtpl-font-url-' + slot).val('');
		$('#mtpl-font-label-' + slot).val('');
		$('#mtpl-font-name-' + slot).text('Не выбран');
	});

	/* ───────── Редактор раскладок ───────── */

	var MIN = 0.05; // минимальная сторона слота в долях

	function canvasOf(node) { return $(node).closest('.mtpl-layout').find('.mtpl-canvas'); }

	function serialize($canvas) {
		var slots = [];
		$canvas.find('.mtpl-rect').each(function () {
			var $r = $(this);
			slots.push({
				x: round(parseFloat($r.css('left')) / $canvas.width()),
				y: round(parseFloat($r.css('top')) / $canvas.height()),
				w: round($r.outerWidth() / $canvas.width()),
				h: round($r.outerHeight() / $canvas.height())
			});
		});
		$canvas.closest('.mtpl-layout').find('.mtpl-json').val(JSON.stringify(slots));
		renumber($canvas);
	}

	function round(v) { return Math.max(0, Math.min(1, Math.round(v * 1000) / 1000)); }

	function renumber($canvas) {
		$canvas.find('.mtpl-rect').each(function (i) {
			$(this).attr('data-i', i).find('.mtpl-rect-n').text(i + 1);
		});
	}

	function startDrag(e, $rect, mode) {
		e.preventDefault();
		var $canvas = $rect.parent();
		var cw = $canvas.width();
		var ch = $canvas.height();
		var startX = e.pageX;
		var startY = e.pageY;
		var oL = parseFloat($rect.css('left'));
		var oT = parseFloat($rect.css('top'));
		var oW = $rect.outerWidth();
		var oH = $rect.outerHeight();

		$rect.addClass('is-active');

		function move(ev) {
			var dx = ev.pageX - startX;
			var dy = ev.pageY - startY;

			if (mode === 'move') {
				var nl = Math.max(0, Math.min(cw - oW, oL + dx));
				var nt = Math.max(0, Math.min(ch - oH, oT + dy));
				$rect.css({ left: (nl / cw * 100) + '%', top: (nt / ch * 100) + '%' });
			} else {
				var nw = Math.max(cw * MIN, Math.min(cw - oL, oW + dx));
				var nh = Math.max(ch * MIN, Math.min(ch - oT, oH + dy));
				$rect.css({ width: (nw / cw * 100) + '%', height: (nh / ch * 100) + '%' });
			}
		}

		function up() {
			$(document).off('mousemove.mtpl mouseup.mtpl');
			$rect.removeClass('is-active');
			serialize($canvas);
		}

		$(document).on('mousemove.mtpl', move).on('mouseup.mtpl', up);
	}

	$(document).on('mousedown', '.mtpl-rect', function (e) {
		if ($(e.target).hasClass('mtpl-rect-grip') || $(e.target).hasClass('mtpl-rect-del')) return;
		startDrag(e, $(this), 'move');
	});

	$(document).on('mousedown', '.mtpl-rect-grip', function (e) {
		e.stopPropagation();
		startDrag(e, $(this).parent(), 'resize');
	});

	$(document).on('click', '.mtpl-rect-del', function (e) {
		e.stopPropagation();
		var $canvas = $(this).closest('.mtpl-canvas');
		if ($canvas.find('.mtpl-rect').length <= 1) {
			window.alert('В раскладке должен остаться хотя бы один слот.');
			return;
		}
		$(this).parent().remove();
		serialize($canvas);
	});

	$(document).on('click', '.mtpl-add-slot', function () {
		var $canvas = canvasOf(this);
		$canvas.append(
			'<div class="mtpl-rect" style="left:10%;top:10%;width:35%;height:30%">' +
			'<span class="mtpl-rect-n"></span>' +
			'<span class="mtpl-rect-del" title="Удалить">×</span>' +
			'<span class="mtpl-rect-grip"></span></div>'
		);
		serialize($canvas);
	});

	$(document).on('click', '.mtpl-reset', function () {
		var slots;
		try { slots = JSON.parse($(this).attr('data-default')); }
		catch (err) { return; }

		var $canvas = canvasOf(this);
		$canvas.empty();
		slots.forEach(function (s) {
			$canvas.append(
				'<div class="mtpl-rect" style="left:' + (s.x * 100) + '%;top:' + (s.y * 100) +
				'%;width:' + (s.w * 100) + '%;height:' + (s.h * 100) + '%">' +
				'<span class="mtpl-rect-n"></span>' +
				'<span class="mtpl-rect-del" title="Удалить">×</span>' +
				'<span class="mtpl-rect-grip"></span></div>'
			);
		});
		serialize($canvas);
	});

	$(function () {
		$('.mtpl-canvas').each(function () { renumber($(this)); });
	});
})(jQuery);
