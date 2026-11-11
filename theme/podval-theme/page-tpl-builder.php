<?php
/**
 * Template Name: Конструктор шаблона (Tpl Builder)
 *
 * Страница товара с метой `_podval_tpl_group` роутится сюда через
 * template_include (см. inc/tpl-builder.php). Шаблон полноэкранный —
 * с header/footer, как page-cart.php.
 *
 * Структура собирается на клиенте (assets/js/tpl-builder.js) из конфига
 * PodvalTpl. Здесь — только каркас, SEO-контент и no-JS фолбэк.
 *
 * @package Podval Print
 *
 */

defined( 'ABSPATH' ) || exit;

$product_id = get_queried_object_id();
$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

if ( ! $product ) {
	wp_safe_redirect( home_url( '/' ) );
	exit;
}

$group      = podval_tpl_product_group( $product_id );
$templates  = podval_tpl_by_group( $group );
$base_price = (float) $product->get_price();

get_header();
?>

<main class="tplb-page" id="tplb-page">

	<div class="tplb-wrap">

		<?php
		/* Шапка убрана из вида: название, цена и вводный текст человек уже
		   прочитал на карточке товара, откуда сюда и пришёл. Повтор только
		   отодвигал первый шаг вниз.

		   Заголовок остаётся в разметке скрытым: страница без единственного
		   h1 — дыра в структуре документа и для скринридеров, и для
		   поисковиков. Цена живёт в блоке «Итого», она там пересчитывается. */
		?>
		<h1 class="tplb-title screen-reader-text"><?php echo esc_html( $product->get_name() ); ?></h1>

		<noscript>
			<div class="tplb-noscript">
				Конструктор требует JavaScript. Напишите нам в Telegram — соберём макет вместе.
			</div>
		</noscript>

		<div class="tplb-layout">

			<!-- ═══ ЛЕВАЯ КОЛОНКА: шаги ═══ -->
			<div class="tplb-steps" id="tplb-steps">

				<?php
				/* Заглушка на время сборки шагов. Лежит первым ребёнком и
				   позиционируется абсолютно, чтобы не влиять на раскладку:
				   высоту колонке держит min-height из style-tpl-builder.css.
				   aria-hidden — читать скринридеру тут нечего, настоящие
				   заголовки шагов уже в разметке ниже. */
				?>
				<div class="tplb-steps__boot" aria-hidden="true">
					<span></span><span></span><span></span><span></span>
				</div>


				<!-- Шаг 1: изделие. Идёт первым: цвет футболки задаёт фон превью,
				     и собирать макет поверх незаданного цвета — лишний шаг назад. -->
				<section class="tplb-step is-open" data-step="garment">
					<button type="button" class="tplb-step__head" aria-expanded="true">
						<span class="tplb-step__num">1</span>
						<span class="tplb-step__title">Футболка</span>
						<span class="tplb-step__pick" data-pick="garment"></span>
						<span class="tplb-step__chevron" aria-hidden="true"></span>
					</button>
					<div class="tplb-step__body">
					<div class="tplb-garment" id="tplb-garment"></div>
					<div class="tplb-addons" id="tplb-addons"></div>
						<div class="tplb-step__nav"><button type="button" class="tplb-step__next" hidden></button></div>
					</div>
				</section>

				<!-- Шаг 1: основа -->
				<section class="tplb-step" data-step="base">
					<button type="button" class="tplb-step__head" aria-expanded="false">
						<span class="tplb-step__num">2</span>
						<span class="tplb-step__title">Выберите основу</span>
						<span class="tplb-step__pick" data-pick="base"></span>
						<span class="tplb-step__chevron" aria-hidden="true"></span>
					</button>
					<div class="tplb-step__body">
					<div class="tplb-bases" id="tplb-bases">
						<?php foreach ( $templates as $tpl_id => $tpl ) :
							$preview = podval_tpl_preview_url( $tpl );
							?>
							<button type="button" class="tplb-base" data-tpl="<?php echo esc_attr( $tpl_id ); ?>">
								<span class="tplb-base__img">
									<?php if ( $preview ) : ?>
										<img src="<?php echo esc_url( $preview ); ?>" alt="<?php echo esc_attr( $tpl['title'] ); ?>" loading="lazy" width="300" height="400">
									<?php else : ?>
										<span class="tplb-base__ph" aria-hidden="true"><?php echo esc_html( mb_substr( $tpl['title'], 0, 1 ) ); ?></span>
									<?php endif; ?>
								</span>
								<span class="tplb-base__body">
									<span class="tplb-base__title"><?php echo esc_html( $tpl['title'] ); ?></span>
									<?php if ( ! empty( $tpl['subtitle'] ) ) : ?>
										<span class="tplb-base__sub"><?php echo esc_html( $tpl['subtitle'] ); ?></span>
									<?php endif; ?>
									<span class="tplb-base__photos">
										<?php
										$ph = $tpl['photos'];
										echo esc_html(
											( $ph['min'] === $ph['max'] )
												? $ph['min'] . ' фото'
												: $ph['min'] . '–' . $ph['max'] . ' фото'
										);
										?>
									</span>
								</span>
							</button>
						<?php endforeach; ?>
					</div>

						<div class="tplb-step__nav"><button type="button" class="tplb-step__next" hidden></button></div>
					</div>
				</section>

				<!-- Шаг 3: раскладка + фото.
				     Раньше это были два отдельных шага, и между ними стояли
				     «Надписи»: человек выбирал раскладку, а следующим экраном
				     ему предлагали писать текст — по замерам поведения там и
				     возникал ступор. Фото стоят сразу за раскладкой: выбор
				     сетки логично продолжается её заполнением, и макет с
				     собственными кадрами человек видит до всякого текста.

				     Шаг показывается ВСЕГДА, в отличие от прежнего
				     `step('layout', keys.length > 1)`. У движков letters и
				     single раскладок нет вовсе, шаг скрывался целиком — и
				     вместе с ним исчезла бы загрузка фото. Прячется теперь
				     только сетка плиток внутри (renderLayouts), а заголовок
				     переключается на «Ваши фото», чтобы не обещать выбор,
				     которого в шаге не будет. -->
				<section class="tplb-step tplb-step--media" data-step="layout" hidden>
					<button type="button" class="tplb-step__head" aria-expanded="false">
						<span class="tplb-step__num">3</span>
						<span class="tplb-step__title" id="tplb-media-title">Раскладка и фото</span>
						<span class="tplb-step__pick" data-pick="layout"></span>
						<span class="tplb-step__thumbs" id="tplb-head-thumbs" aria-hidden="true"></span>
						<span class="tplb-step__counter" id="tplb-photo-counter"></span>
						<span class="tplb-step__chevron" aria-hidden="true"></span>
					</button>
					<div class="tplb-step__body">
						<?php
						/* Поля надписей у шаблонов с `merge_texts` (слово из фото):
						   слово задаёт число и подписи слотов, поэтому стоит прямо
						   над ними. Остальные шаблоны рендерят поля в «Надписях». */
						?>
						<div class="tplb-fields tplb-fields--media" id="tplb-media-texts" hidden></div>

						<div class="tplb-layouts" id="tplb-layouts"></div>

						<div class="tplb-drop" id="tplb-drop">
							<input type="file" id="tplb-file" accept="image/jpeg,image/png,image/webp" multiple hidden>
							<button type="button" class="tplb-drop__btn" id="tplb-drop-btn">
								<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18" aria-hidden="true"><path d="M12 16V4m0 0L8 8m4-4 4 4"/><path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>
								Загрузить фото
							</button>
							<span class="tplb-drop__hint">JPG, PNG или WEBP, до 25 МБ. Можно сразу несколько.</span>
						</div>

						<div class="tplb-slots" id="tplb-slots"></div>

						<label class="tplb-later" id="tplb-later">
							<input type="checkbox" id="tplb-later-input">
							<span class="tplb-later__body">
								<span class="tplb-later__title">Пришлю фото позже</span>
								<span class="tplb-later__hint">Свяжемся в Telegram, чтобы подтвердить заказ — фото можно прислать туда.</span>
							</span>
						</label>

						<div class="tplb-styles tplb-styles--tone" id="tplb-tone"></div>

						<p class="tplb-crop-note">
							Нажмите на фото в макете или на значок кадра, чтобы приблизить его
							и выбрать, какая часть попадёт в рамку. Перед печатью дизайнер
							проверит макет и пришлёт его на согласование.
						</p>

						<div class="tplb-step__nav"><button type="button" class="tplb-step__next" hidden></button></div>
					</div>
				</section>

				<!-- Шаг 4: тексты -->
				<section class="tplb-step" data-step="texts" hidden>
					<button type="button" class="tplb-step__head" aria-expanded="false">
						<span class="tplb-step__num">4</span>
						<span class="tplb-step__title">Надписи</span>
						<span class="tplb-step__pick" data-pick="texts"></span>
						<span class="tplb-step__chevron" aria-hidden="true"></span>
					</button>
					<div class="tplb-step__body">
						<div class="tplb-fields" id="tplb-texts"></div>
						<div class="tplb-styles" id="tplb-styles"></div>

						<div class="tplb-step__nav"><button type="button" class="tplb-step__next" hidden></button></div>
					</div>
				</section>

				<?php
				/* Комментарий менеджеру. Жил внутри шага «Футболка», под размерами
				   и надбавками — и его не замечали: к моменту, когда человек
				   доходит до макета, шаг уже свёрнут, а само поле читалось как ещё
				   одна настройка изделия, а не как способ что-то сказать дизайнеру.
				
				   Стоит отдельной карточкой в конце колонки и НЕ является
				   `.tplb-step`: нумерацию, галочки «готово» и цепочку «Далее» JS
				   строит перебором `.tplb-step`, и необязательное поле получило бы
				   там свой номер — флоу выглядел бы длиннее, чем он есть.
				   Карточка всегда раскрыта, поэтому после сворачивания последнего
				   шага остаётся единственным открытым блоком внизу. */
				?>
				<section class="tplb-comment" aria-labelledby="tplb-comment-title">
					<h2 class="tplb-comment__title" id="tplb-comment-title">
						Комментарий менеджеру
						<span>необязательно</span>
					</h2>
					<p class="tplb-comment__hint">
						Что важно учесть по макету: пожелания по цветам, что оставить, что убрать.
						Прочитаем до того, как отрисуем финальный вариант.
					</p>
					<label class="screen-reader-text" for="tplb-comment-field">Комментарий менеджеру</label>
					<textarea id="tplb-comment-field" rows="4" maxlength="1000" placeholder="Например: Катя слева, фон оставьте как есть, дату не печатайте"></textarea>
				</section>

			</div><!-- /.tplb-steps -->

			<!-- ═══ ПРАВАЯ КОЛОНКА: превью ═══ -->
			<aside class="tplb-side" id="tplb-side">
				<div class="tplb-side__inner">

					<!-- Обёртка-масштабатор. На мобильных превью верстается в полную
					     ширину колонки и уменьшается transform-ом: все кегли в макете
					     заданы через vw и откалиброваны под эту ширину, поэтому сжимать
					     сам контейнер нельзя — текст поехал бы относительно кадра.
					     Высоту обёртки держит padding-top, доля включает масштаб. -->
					<div class="tplb-previewfit" id="tplb-previewfit">
						<div class="tplb-preview" id="tplb-preview" data-color="black">
							<div class="tplb-preview__tee">
								<div class="tplb-preview__area">
								<div class="tplb-preview__art" id="tplb-art">
									<div class="tplb-preview__empty" id="tplb-empty">
										Выберите основу — здесь появится макет
									</div>
								</div>
								</div>
							</div>
						</div>
					</div>

					<?php /* подсказка про кадрирование — пока человек ни разу не открыл редактор */ ?>
					<button type="button" class="tplb-crophint" id="tplb-crophint" hidden>
						<span class="tplb-crophint__ico" aria-hidden="true"></span>
						<span>Нажмите на фото в макете — можно приблизить, уменьшить и сдвинуть</span>
					</button>

					<button type="button" class="tplb-zoom" id="tplb-zoom" aria-expanded="false">
						<span class="tplb-zoom__open">Развернуть макет</span>
						<span class="tplb-zoom__close">Свернуть</span>
					</button>



					<div class="tplb-summary" id="tplb-summary" hidden></div>

					<?php
					/* Оговорка переехала сюда из-под превью. Там она читалась
					   как подпись к картинке и терялась; здесь стоит ровно
					   перед решением «в корзину» — в момент, когда важно
					   понимать, что уедет в заказ.

					   Соседом, а не внутри «Итого»: на мобильных тот блок
					   скрыт (цену и кнопку показывает нижняя панель), и
					   вложенная оговорка исчезла бы вместе с ним. */
					?>
					<div class="tplb-side__note" id="tplb-note">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="16" height="16" aria-hidden="true">
							<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>
						</svg>
						<span>Превью — схематичное. Финальный макет пришлём на согласование до печати.</span>
					</div>

					<div class="tplb-total">
						<div class="tplb-total__row">
							<span>Итого</span>
							<strong id="tplb-total-price"><?php echo esc_html( number_format( $base_price, 2, ',', ' ' ) ); ?> Br</strong>
						</div>
						<button type="button" class="tplb-submit" id="tplb-submit" disabled>
							В корзину
						</button>
						<div class="tplb-error" id="tplb-error" hidden></div>
					</div>

				</div>
			</aside>

		</div><!-- /.tplb-layout -->

		<!-- Мобильная нижняя панель -->
		<div class="tplb-sticky" id="tplb-sticky">
			<div class="tplb-sticky__price">
				<span>Итого</span>
				<strong id="tplb-sticky-price"><?php echo esc_html( number_format( $base_price, 2, ',', ' ' ) ); ?> Br</strong>
			</div>
			<button type="button" class="tplb-sticky__btn" id="tplb-sticky-btn" disabled>В корзину</button>
		</div>

		<?php
		/* Описание товара здесь больше не выводится. Конструктор перестал
		   быть страницей товара: с версии 2.9 человек сначала видит обычную
		   карточку с описанием, характеристиками и галереей, и только потом
		   нажимает «Создать принт». Повтор описания под формой сбивал —
		   выглядел так, будто ниже есть ещё один товар. */
		?>

	</div><!-- /.tplb-wrap -->

	<script>
	/* Страховка. Колонка шагов скрыта до тех пор, пока init() в
	   tpl-builder.js не поставит is-ready. Если скрипт не выполнится —
	   ошибка, блокировщик, оборванная загрузка — человек остался бы перед
	   вечным скелетоном. Через 5 секунд показываем что есть: конструктор
	   без JS всё равно нерабочий, но пустой экран хуже видимой поломки. */
	setTimeout(function () {
		var box = document.getElementById('tplb-steps');
		if (box) box.classList.add('is-ready');
	}, 5000);
	</script>

	<?php
	// Попап таблицы размеров. Разметка статична: обработчики закрытия
	// навешиваются в main.js на DOMContentLoaded, динамический узел они
	// бы не поймали. Открытие вешает сам конструктор — ссылка рисуется
	// вместе с опциями изделия.
	$sc_type = get_post_meta( $product_id, '_podval_size_chart', true );
	if ( $sc_type ) {
		get_template_part( 'template-parts/size-chart-popup', null, array(
			'sc_type' => $sc_type,
			'sc_id'   => 'tplb-size-chart',
		) );
	}
	?>

</main>

<?php
get_footer();
