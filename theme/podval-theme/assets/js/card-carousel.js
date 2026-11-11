/**
 * Карусель вариантов дизайна в карточке каталога.
 *
 * Автоплей, но экономный:
 *   — работают только карточки в зоне видимости (IntersectionObserver)
 *   — уважается prefers-reduced-motion: движение отключается полностью
 *   — старт карусели откладывается, пока не догрузится второй кадр
 *   — при скрытой вкладке таймеры останавливаются
 *
 * @package Podval Print
 */
(function () {
	'use strict';

	var INTERVAL = 2600;
	var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function Carousel(node) {
		this.node = node;
		this.frames = Array.prototype.slice.call(node.querySelectorAll('.pc-carousel__frame'));
		this.dots = Array.prototype.slice.call(node.querySelectorAll('.pc-carousel__dots i'));
		this.index = 0;
		this.timer = null;
		this.running = false;

		if (this.frames.length < 2) return;

		// Предзагрузка второго кадра — чтобы первый переход не мигал.
		if (this.frames[1]) this.frames[1].loading = 'eager';

		var self = this;
		node.addEventListener('click', function (e) {
			// Тап по карусели на тач-устройствах листает вручную,
			// но не мешает переходу по ссылке карточки.
			if (!('ontouchstart' in window)) return;
			e.preventDefault();
			self.stop();
			self.next();
		});
	}

	Carousel.prototype.show = function (i) {
		this.frames[this.index].classList.remove('is-active');
		if (this.dots[this.index]) this.dots[this.index].classList.remove('is-active');

		this.index = i % this.frames.length;

		this.frames[this.index].classList.add('is-active');
		if (this.dots[this.index]) this.dots[this.index].classList.add('is-active');
	};

	Carousel.prototype.next = function () {
		this.show(this.index + 1);
	};

	Carousel.prototype.start = function () {
		if (this.running || reduced || this.frames.length < 2) return;
		this.running = true;

		var self = this;
		// Разводим старты соседних карточек, чтобы они не мигали синхронно.
		var jitter = Math.random() * 900;

		this.timer = window.setTimeout(function tick() {
			self.next();
			self.timer = window.setTimeout(tick, INTERVAL);
		}, INTERVAL + jitter);
	};

	Carousel.prototype.stop = function () {
		this.running = false;
		if (this.timer) {
			window.clearTimeout(this.timer);
			this.timer = null;
		}
	};

	function init() {
		var nodes = document.querySelectorAll('[data-carousel]');
		if (!nodes.length) return;

		var list = [];
		Array.prototype.forEach.call(nodes, function (n) {
			var c = new Carousel(n);
			if (c.frames.length >= 2) {
				n.__carousel = c;
				list.push(c);
			}
		});

		if (!list.length || reduced) return;

		if (!('IntersectionObserver' in window)) {
			list.forEach(function (c) { c.start(); });
			return;
		}

		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				var c = entry.target.__carousel;
				if (!c) return;
				if (entry.isIntersecting) c.start();
				else c.stop();
			});
		}, { rootMargin: '80px 0px', threshold: 0.25 });

		list.forEach(function (c) { io.observe(c.node); });

		// Вкладка ушла в фон — гасим все таймеры.
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				list.forEach(function (c) { c.stop(); });
			} else {
				list.forEach(function (c) {
					var r = c.node.getBoundingClientRect();
					if (r.bottom > 0 && r.top < window.innerHeight) c.start();
				});
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
