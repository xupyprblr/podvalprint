<?php
/**
 * Реестр шаблонов принтов (конструктор-лайт, прежней версии).
 *
 * Три независимых измерения:
 *   tpl_id     — структура шаблона (набор слотов и текстовых полей)
 *   layout_id  — раскладка фото внутри шаблона
 *   style      — стилевые опции (тон фото, футер, декор, рамка)
 *
 * Плюс отдельно — параметры изделия (цвет / тип / размер) и надбавки.
 *
 * Координаты слотов — доли 0..1 от области коллажа (совместимо с awTemplate).
 *
 * @package Podval Print
 *
 */

defined( 'ABSPATH' ) || exit;

/* ═══════════════════════════════════════════
   1. РЕЕСТР ШАБЛОНОВ
   ═══════════════════════════════════════════ */

/**
 * Полный реестр шаблонов принтов.
 *
 * @return array tpl_id => definition
 */
/**
 * Дефолтный реестр «из кода» — без наложения оверрайдов из админки.
 * Нужен странице настроек, чтобы показывать исходные значения.
 *
 * @return array tpl_id => definition
 */
function podval_tpl_registry_defaults() {
	$registry = array(

		/* ---------------------------------------------------------------
		 * ПАПА из фото — буквы-маска (реф. 1)
		 * ------------------------------------------------------------- */
		'dad_letters' => array(
			'group'      => 'dad',
			'title'      => 'Слово из фото',
			'subtitle'   => 'ПАПА, PAPA, ОТЕЦ — буквы заполняются вашими фото',
			'preview'    => 'assets/img/tpl/dad-letters.jpg',
			'engine'     => 'letters',
			'colors'     => array( 'white', 'black' ),
			'photos'     => array(
				'mode' => 'by_word', // количество слотов = длине слова
				'min'  => 3,
				'max'  => 6,
			),
			'slot_ratio' => 0.46,   // ширина/высота одного глифа
			// слово выбирается в одном шаге с фото. Число и подписи
			// слотов диктует именно слово, и в отдельном шаге «Надписи»
			// связь «буква → своё фото» не читалась: человек грузил фото,
			// не зная, сколько их нужно и под какие буквы.
			'merge_texts' => true,
			'texts'      => array(
				'word' => array(
					'label'    => 'Слово',
					'type'     => 'word',
					'min'      => 3,
					'max'      => 6,
					'required' => true,
					'default'  => 'PAPA',
					'pattern'  => '/^[A-ZА-ЯЁ]{3,6}$/u',
					'presets'  => array( 'PAPA', 'ПАПА', 'DAD', 'ОТЕЦ', 'БАТЯ' ),
					// Свободный ввод: 3–6 букв, латиница или кириллица.
					// Границы держит pattern — и здесь, и в валидации.
					'locked'   => false,
					'hint'     => 'Выберите или впишите своё — 3–6 букв. Под каждую букву своё фото.',
				),
			),
			'layouts'    => array(), // раскладка диктуется словом
			'styles'     => array(
				// Сплошные буквы — внутренние просветы (P, A, O, Б…) залиты
				// фото, как на референсе. «С просветами» — обычный глиф.
				'letter_fill' => array(
					'label'   => 'Буквы',
					'options' => array( 'solid' => 'Сплошные', 'classic' => 'С просветами' ),
					'default' => 'solid',
					'after'   => 'word',
				),
				'photo_tone' => array(
					'label'   => 'Тон фото',
					'options' => array( 'color' => 'Цветные', 'mono' => 'Ч/Б' ),
					'default' => 'color',
				),
			),
		),

		/* ---------------------------------------------------------------
		 * BEST DAD — постер с коллажем (реф. 2 и 4)
		 * ------------------------------------------------------------- */
		'dad_poster' => array(
			'group'    => 'dad',
			'title'    => 'Постер с коллажем',
			'subtitle' => 'Крупный заголовок + 3–7 фото + подписи снизу',
			'preview'  => 'assets/img/tpl/dad-poster.jpg',
			'engine'   => 'poster',
			'colors'   => array( 'black', 'white' ),
			'photos'   => array(
				'mode' => 'by_layout',
				'min'  => 3,
				'max'  => 9,
			),
			'texts'    => array(
				'headline' => array(
					'label'    => 'Заголовок',
					'type'     => 'text',
					'max'      => 12,
					'required' => true,
					'default'  => 'BEST DAD',
					'presets'  => array( 'BEST DAD', 'FAMILY', 'ЛУЧШИЙ ПАПА', 'MY DAD', 'ПАПА' ),
					'locked'   => true, // только выбор из списка, без свободного ввода
					'hint'     => 'Повторяется 2–3 строками, как на референсе.',
				),
				// имя только в блочном низе. В «Подписях по центру»
				// под коллажем дата и подпись — имя там лишнее.
				'name'     => array(
					'label'    => 'Имя',
					'type'     => 'text',
					'max'      => 18,
					'required' => false,
					'default'  => 'ВАШЕ ИМЯ',
					'sample'   => true,
					'when'     => array( 'footer_kind' => array( 'block' ) ),
				),
				'caption'  => array(
					'label'    => 'Подпись',
					'type'     => 'text',
					'max'      => 28,
					'required' => false,
					'default'  => 'BEST DAD IN THE WORLD!',
					// В блочном низе правую колонку занимает «Фраза».
					'when'     => array( 'footer_kind' => array( 'simple' ) ),
				),
				// Дата подписывает коллаж в простом низе и штрихкод в блочном.
				'date'     => array(
					'label'    => 'Дата',
					'type'     => 'text',
					'max'      => 20,
					'required' => false,
					'default'  => 'SINCE 00.00.0000',
					'sample'   => true,
					'hint'     => 'Например: SINCE 12.03.2000',
					'when'     => array(
						array( 'footer_kind' => array( 'simple' ) ),
						array( 'footer_left' => array( 'barcode' ) ),
					),
				),
			) + podval_tpl_footer_block_texts( false, false ),
			'layouts'  => array(
				'p3' => array(
					'title' => '3 фото',
					'slots' => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.56, 'h' => 0.52 ),
						array( 'key' => 's2', 'x' => 0.58, 'y' => 0.00, 'w' => 0.42, 'h' => 0.52 ),
						array( 'key' => 's3', 'x' => 0.18, 'y' => 0.54, 'w' => 0.82, 'h' => 0.46 ),
					),
				),
				'p4' => array(
					'title' => '4 фото',
					'slots' => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.52, 'h' => 0.48 ),
						array( 'key' => 's2', 'x' => 0.54, 'y' => 0.06, 'w' => 0.46, 'h' => 0.48 ),
						array( 'key' => 's3', 'x' => 0.00, 'y' => 0.50, 'w' => 0.52, 'h' => 0.50 ),
						array( 'key' => 's4', 'x' => 0.54, 'y' => 0.56, 'w' => 0.46, 'h' => 0.44 ),
					),
				),
				// 6 ровных квадратов 3×2. Пропорция области коллажа подобрана так,
				// чтобы ячейки были именно квадратными: 3 колонки по 0.32
				// при aspect 0.66 дают cell_h ≈ cell_w.
				'p6' => array(
					'title'  => '6 фото',
					'aspect' => 0.66,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.49 ),
						array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.49 ),
						array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.49 ),
						array( 'key' => 's4', 'x' => 0.00, 'y' => 0.51, 'w' => 0.32, 'h' => 0.49 ),
						array( 'key' => 's5', 'x' => 0.34, 'y' => 0.51, 'w' => 0.32, 'h' => 0.49 ),
						array( 'key' => 's6', 'x' => 0.68, 'y' => 0.51, 'w' => 0.32, 'h' => 0.49 ),
					),
				),
				'p7' => array(
					'title' => '7 фото',
					'slots' => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.30 ),
						array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.30 ),
						array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.30 ),
						array( 'key' => 's4', 'x' => 0.00, 'y' => 0.32, 'w' => 0.49, 'h' => 0.36 ),
						array( 'key' => 's5', 'x' => 0.51, 'y' => 0.32, 'w' => 0.49, 'h' => 0.36 ),
						array( 'key' => 's6', 'x' => 0.00, 'y' => 0.70, 'w' => 0.49, 'h' => 0.30 ),
						array( 'key' => 's7', 'x' => 0.51, 'y' => 0.70, 'w' => 0.49, 'h' => 0.30 ),
					),
				),
				'p9' => array(
					'title'  => '9 фото',
					'aspect' => 1.0,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's4', 'x' => 0.00, 'y' => 0.34, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's5', 'x' => 0.34, 'y' => 0.34, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's6', 'x' => 0.68, 'y' => 0.34, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's7', 'x' => 0.00, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's8', 'x' => 0.34, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
						array( 'key' => 's9', 'x' => 0.68, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
					),
				),
			),
			'styles'   => array(
				'photo_tone'   => array(
					'label'   => 'Тон фото',
					'options' => array( 'mono' => 'Ч/Б', 'color' => 'Цветные' ),
					'default' => 'mono',
				),
				'title_repeat' => array(
					// `after` — к какому полю прижать строку опций при выводе.
					// Настройка, стоящая вплотную к тому, что она меняет,
					// не требует объяснений; в общей куче внизу — требует.
					'after'   => 'headline',
					'label'   => 'Повтор заголовка',
					'options' => array( '2' => '2 строки', '3' => '3 строки' ),
					'default' => '3',
				),
				// переключатель «Подписи снизу: по краям / по центру»
				// убран — простой низ всегда по центру. В старых заказах
				// значение `footer` просто не попадёт в сводку.
				'footer_kind'  => podval_tpl_footer_kind_style(),
				// BEST DAD без плеера и QR — сознательно: трек и ссылка
				// на плейлист в отцовском постере не по адресу.
			) + podval_tpl_footer_block_styles( false, false ),
		),

		/* ---------------------------------------------------------------
		 * Одно фото + имя (реф. 3)
		 * ------------------------------------------------------------- */
		'dad_single' => array(
			'group'    => 'dad',
			'title'    => 'Одно фото + имя',
			'subtitle' => 'Винтажный кадр, имя сверху, подпись снизу',
			'preview'  => 'assets/img/tpl/dad-single.jpg',
			'engine'   => 'single',
			'colors'   => array( 'white', 'black' ),
			'photos'   => array(
				'mode' => 'fixed',
				'min'  => 1,
				'max'  => 1,
			),
			// в варианте «Имя и штрихкод» верх — это «Имя / отчество»
			// повтором в 2–3 строки, а в строке над штрихкодом своё поле.
			// Ключи, а не новые поля движка: блочный низ и заголовок берутся
			// готовыми из GIRLFRIEND / BOYFRIEND, им нужно только знать,
			// откуда читать текст.
			'title_key'     => 'name',
			'foot_name_key' => 'foot_name',
			'texts'    => array(
				'name'      => array(
					'label'    => 'Имя / отчество',
					'type'     => 'text',
					'max'      => 16,
					'required' => true,
					// Дефолт не пустой: превью сразу показывает, как ляжет надпись.
					// Поле помечается «примером» в UI, пока пользователь его не тронул.
					'default'  => 'ALEXANDROVICH',
					'sample'   => true,
					'hint'     => 'Это пример — впишите своё имя или отчество',
				),
				'bottom'    => array(
					'label'    => 'Нижняя надпись',
					'type'     => 'script',
					'max'      => 20,
					'required' => false,
					'default'  => 'Since 1994',
					'sample'   => true,
					'presets'  => array( 'Vintage 1994', 'Since 1994' ),
					'hint'     => 'Растягивается на ширину фото.',
					'when'     => array( 'footer_kind' => array( 'script' ) ),
				),
				'foot_name' => array(
					'label'    => 'Имя снизу',
					'type'     => 'text',
					'max'      => 18,
					'required' => false,
					'default'  => 'ВАШЕ ИМЯ',
					'sample'   => true,
					'hint'     => 'Например: MAXIM',
					'when'     => array( 'footer_kind' => array( 'block' ) ),
				),
				'date'      => array(
					'label'    => 'Подпись под штрихкодом',
					'type'     => 'text',
					'max'      => 20,
					'required' => false,
					'default'  => 'SINCE 00.00.0000',
					'sample'   => true,
					'hint'     => 'Например: SINCE 03.11.2008',
					'when'     => array(
						'footer_kind' => array( 'block' ),
						'footer_left' => array( 'barcode' ),
					),
				),
			) + podval_tpl_single_block_texts(),
			'layouts'  => array(
				'single' => array(
					'title' => '1 фото',
					'slots' => array(
						array( 'key' => 's1', 'x' => 0.12, 'y' => 0.10, 'w' => 0.76, 'h' => 0.80 ),
					),
				),
			),
			'styles'   => array(
				'footer_kind' => array(
					'label'   => 'Вид макета',
					'options' => array(
						'script' => 'Рукописная подпись',
						'block'  => 'Имя и штрихкод',
					),
					'default' => 'script',
					'major'   => true,
					'hint'    => 'Меняет надписи вокруг фото и набор полей ниже.',
				),
				'title_repeat' => array(
					'after'   => 'name',
					'label'   => 'Повтор имени',
					'options' => array( '2' => '2 строки', '3' => '3 строки' ),
					'default' => '3',
					'when'    => array( 'footer_kind' => array( 'block' ) ),
				),
				// frame / photo_size / photo_tone относятся к фото — конструктор
				// рендерит их в шаге «Ваши фото» (см. PHOTO_STYLE_KEYS в JS).
				// Рамка и размер — только у рукописного варианта: в блочном
				// кадр всегда на всю ширину, как у ONLY YOU.
				'frame'       => array(
					'label'   => 'Рамка',
					'options' => array( 'none' => 'Без рамки', 'polaroid' => 'Полароид' ),
					'default' => 'none',
					'when'    => array( 'footer_kind' => array( 'script' ) ),
				),
				'photo_size'  => array(
					'label'   => 'Размер фото',
					'options' => array( 'large' => 'Большое', 'medium' => 'Небольшое по центру' ),
					'default' => 'large',
					'when'    => array( 'footer_kind' => array( 'script' ) ),
				),
				'photo_tone'  => array(
					'label'   => 'Тон фото',
					'options' => array( 'mono' => 'Ч/Б', 'color' => 'Цветные' ),
					'default' => 'mono',
				),
				'bottom_tilt' => array(
					'label'   => 'Нижняя надпись',
					'options' => array( 'straight' => 'Ровно', 'tilt' => 'С наклоном' ),
					'default' => 'straight',
					'when'    => array( 'footer_kind' => array( 'script' ) ),
				),
			) + podval_tpl_single_block_styles(),
		),

		/* ---------------------------------------------------------------
		 * PAPA и 4 фото — буквы P·A·P·A между кадрами
		 *
		 * Заменяет в группе «Папа / муж» общую основу «Полароид»
		 * (у мамы и парных она остаётся, см. podval_tpl_shared_map()).
		 *
		 * Две раскладки: «Сетка» 2 × 3 (как рабочий шаблон студии) и
		 * «Лесенка». Геометрия статична и задана в JS (PAPA): область
		 * печати 25 × 40 см, пропорция 1 : 1.6 держится всегда — строка
		 * подписи зарезервирована, даже если подпись пустая. Админка
		 * раскладки не правит (`static`), иначе буквы разошлись бы с кадрами.
		 * ------------------------------------------------------------- */
		'dad_papa' => array(
			'group'       => 'dad',
			'title'       => 'PAPA и фото',
			'subtitle'    => '3–4 фото, буквы P·A·P·A между ними и подпись снизу',
			'preview'     => 'assets/img/tpl/dad-papa.jpg',
			'engine'      => 'papa',
			// Ширина принта для печатного файла 300 ppi. У остальных
			// основ по умолчанию 30 см.
			'print_width_cm' => 25,
			'colors'      => array( 'white', 'black' ),
			// число фото задаёт раскладка (3 у «Сетка · 3 фото»).
			'photos'      => array(
				'mode' => 'by_layout',
				'min'  => 3,
				'max'  => 4,
			),
			// Настраивать тут почти нечего — фото и подпись, поэтому
			// всё в одном шаге, как у «Слова из фото».
			'merge_texts' => true,
			'step_title'  => 'Фото и надпись',
			'texts'       => array(
				'caption' => array(
					'label'    => 'Надпись снизу',
					'type'     => 'text',
					'max'      => 20,
					'required' => false,
					'default'  => 'EST. 2026',
					'sample'   => true,
					'presets'  => array( 'EST. 2026', 'EST. 11.01.2025', 'BEST DAD' ),
					'hint'     => 'Год, дата или пара слов. Длинная надпись уменьшится по ширине.',
				),
			),
			// Геометрия раскладок считается в JS (papaLayout), слоты
			// здесь повторяют её — для счёта фото и мини-плиток выбора.
			// Первая — по умолчанию.
			'layouts'     => array(
				// Сетка 2 × 3, как в рабочем шаблоне студии: буквы в ячейках
				// размером с кадр, подпись по центру во всю ширину.
				'grid'   => array(
					'title'  => 'Сетка · 4 фото',
					'static' => true,
					'aspect' => 1.6,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.0000, 'y' => 0.0000, 'w' => 0.4825, 'h' => 0.3016 ),
						array( 'key' => 's2', 'x' => 0.0000, 'y' => 0.3234, 'w' => 0.4825, 'h' => 0.3016 ),
						array( 'key' => 's3', 'x' => 0.5175, 'y' => 0.3234, 'w' => 0.4825, 'h' => 0.3016 ),
						array( 'key' => 's4', 'x' => 0.5175, 'y' => 0.6469, 'w' => 0.4825, 'h' => 0.3016 ),
					),
				),
				// та же сетка 2 × 3, но левые верхняя и средняя ячейки
				// объединены в один высокий кадр (реф. «PAPA EST. 2024»).
				'grid3'  => array(
					'title'  => 'Сетка · 3 фото',
					'static' => true,
					'aspect' => 1.6,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.0000, 'y' => 0.0000, 'w' => 0.4825, 'h' => 0.6250 ),
						array( 'key' => 's2', 'x' => 0.5175, 'y' => 0.3234, 'w' => 0.4825, 'h' => 0.3016 ),
						array( 'key' => 's3', 'x' => 0.5175, 'y' => 0.6469, 'w' => 0.4825, 'h' => 0.3016 ),
					),
				),
				// Лесенка: колонки со сдвигом, буквы зигзагом по шву.
				'stairs' => array(
					'title'  => 'Лесенка',
					'static' => true,
					'aspect' => 1.6,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.0000, 'y' => 0.0594, 'w' => 0.4875, 'h' => 0.3202 ),
						array( 'key' => 's2', 'x' => 0.5125, 'y' => 0.2846, 'w' => 0.4875, 'h' => 0.3202 ),
						array( 'key' => 's3', 'x' => 0.0000, 'y' => 0.3952, 'w' => 0.4875, 'h' => 0.3202 ),
						array( 'key' => 's4', 'x' => 0.5125, 'y' => 0.6204, 'w' => 0.4875, 'h' => 0.3202 ),
					),
				),
			),
			// пара букв ячейки — латиница PA или кириллица ПА.
			'papa_letters'     => array( 'P', 'A' ),
			'papa_letters_cyr' => array( 'П', 'А' ),
			'styles'      => array(
				// `after` обязателен: в объединённом шаге «Фото и надпись»
				// стили без привязки к полю не выводятся (только фото-стили).
				'papa_script'    => array(
					'after'   => 'caption',
					'label'   => 'Буквы',
					'options' => array( 'lat' => 'PA PA', 'cyr' => 'ПА ПА' ),
					'default' => 'lat',
				),
				'caption_weight' => array(
					'after'   => 'caption',
					'label'   => 'Шрифт надписи',
					'options' => array( 'regular' => 'Обычный', 'bold' => 'Жирный' ),
					'default' => 'regular',
				),
				'photo_tone'     => array(
					'label'   => 'Тон фото',
					'options' => array( 'mono' => 'Ч/Б', 'color' => 'Цветные' ),
					'default' => 'mono',
				),
			),
		),

		/* ---------------------------------------------------------------
		 * ONLY YOU — пары / влюблённые (реф. 6 и 7)
		 *
		 * Одно фото, две раскладки: узкая полоса (кадр глаз) и полный кадр.
		 * Низ макета переключается: «плеер + фраза» либо «инициалы + дата».
		 * ------------------------------------------------------------- */
		'only_you' => array(
			'group'    => 'couple',
			'title'    => 'ONLY YOU',
			'subtitle' => 'Крупный заголовок + одно фото + плеер или дата',
			'preview'  => 'assets/img/tpl/only-you.jpg',
			'engine'   => 'onlyyou',
			'colors'   => array( 'black', 'white' ),
			'photos'   => array(
				'mode' => 'by_layout',
				'min'  => 1,
				'max'  => 1,
			),
			'texts'    => array(
				'headline' => array(
					'label'    => 'Заголовок',
					'type'     => 'text',
					'max'      => 14,
					'required' => true,
					'default'  => 'ONLY YOU',
					'presets'  => array( 'ONLY YOU', 'ONLY US', 'MY LOVE', 'ТОЛЬКО ТЫ', 'LOVE YOU' ),
					'hint'     => 'Повторяется 2–3 строками. Можно вписать своё.',
				),
				'name'     => array(
					'label'    => 'Имя',
					'type'     => 'text',
					'max'      => 18,
					'required' => false,
					'default'  => 'ВАШЕ ИМЯ',
					'sample'   => true,
					'hint'     => 'Например: EKATERINA. Длинное имя конструктор уменьшит по ширине.',
				),
				// Правая колонка низа — постоянная. Текст или дата, без привязки
				// к тому, что выбрано в левом блоке.
				'phrase'   => array(
					'label'    => 'Фраза или дата',
					'type'     => 'text',
					'max'      => 44,
					'required' => false,
					'default'  => 'ВАША ФРАЗА ИЛИ ДАТА',
					'sample'   => true,
					'presets'  => array( "I'm just enjoying every moment.", 'MY FAVOURITE PERSON', 'SINCE 20.08.2022' ),
					'hint'     => 'Переносится на две строки, если не помещается в одну.',
				),
				// Поля ниже привязаны к варианту левого блока: конструктор
				// показывает только то, что относится к выбранному.
				'track'    => array(
					'label'    => 'Трек',
					'type'     => 'text',
					'max'      => 40,
					'required' => false,
					'default'  => 'ИСПОЛНИТЕЛЬ — НАЗВАНИЕ ТРЕКА',
					'sample'   => true,
					'hint'     => 'Например: БАСТА — ЛЮБОВЬ БЕЗ ПАМЯТИ',
					'when'     => array( 'footer_left' => array( 'player' ) ),
				),
				'date'     => array(
					'label'    => 'Подпись под штрихкодом',
					'type'     => 'text',
					'max'      => 20,
					'required' => false,
					'default'  => 'SINCE 00.00.0000',
					'sample'   => true,
					'presets'  => array( 'SINCE 20.08.2022', '20.08.2022' ),
					'when'     => array( 'footer_left' => array( 'barcode' ) ),
				),
				'note'     => array(
					'label'    => 'Текст в блоке слева',
					'type'     => 'text',
					'max'      => 40,
					'required' => false,
					'default'  => 'ВАШ ТЕКСТ',
					'sample'   => true,
					'hint'     => 'Две-три строки максимум — блок узкий.',
					'when'     => array( 'footer_left' => array( 'text' ) ),
				),
				'qr'       => array(
					'label'    => 'Ссылка для QR-кода',
					'type'     => 'text',
					'max'      => 60,
					'required' => false,
					'default'  => '',
					'hint'     => 'Плейлист, альбом с фото, что угодно. В превью QR схематичный — рабочий сгенерирует дизайнер.',
					'when'     => array( 'footer_left' => array( 'qr' ) ),
				),
			),
			'layouts'  => array(
				'strip' => array(
					'title'  => 'Полоса (глаза)',
					'aspect' => 0.34,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 1.00, 'h' => 1.00 ),
					),
				),
				// 2/3 от полного кадра. Промежуточный вариант: не полоса глаз,
				// но и не портрет во всю грудь. Кадрируется по центру, как полоса.
				'two_thirds' => array(
					'title'  => '2/3 кадра',
					'aspect' => 0.83,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 1.00, 'h' => 1.00 ),
					),
				),
				// исключение из предела печати 30 × 42 (PRINT_MAX_ASPECT
				// в JS) — портретный кадр во всю грудь при сжатии терял смысл
				// и сливался с «2/3 кадра». Печатается по своей высоте.
				'full'  => array(
					'title'        => 'Полный кадр',
					'aspect'       => 1.25,
					'no_print_cap' => true,
					'slots'  => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 1.00, 'h' => 1.00 ),
					),
				),
			),
			'styles'   => array(
				'photo_tone'   => array(
					'label'   => 'Тон фото',
					'options' => array( 'mono' => 'Ч/Б', 'color' => 'Цветные' ),
					'default' => 'mono',
				),
				'title_repeat' => array(
										'after'   => 'headline',
					'label'   => 'Повтор заголовка',
					'options' => array( '2' => '2 строки', '3' => '3 строки' ),
					'default' => '3',
				),
				'name_decor'   => array(
					'after'   => 'name',
					'label'   => 'Декор у имени',
					'options' => array(
						'arrows'   => 'Стрелки',
						'slashes'  => 'Слэши',
						'none'     => 'Без декора',
					),
					'default' => 'arrows',
				),
				'footer_left'  => array(
					'label'   => 'Блок слева',
					'options' => array(
						'player'  => 'Плеер',
						'barcode' => 'Штрихкод',
						'text'    => 'Текст',
						'qr'      => 'QR-код',
						'none'    => 'Пусто',
					),
					'default' => 'player',
				),
			),
		),

		/* ---------------------------------------------------------------
		 * GIRLFRIEND / BOYFRIEND — постер с ровной сеткой фото (реф. 8)
		 *
		 * Тот же движок poster, что и BEST DAD, но:
		 *   • заголовок свободный (не только из списка),
		 *   • раскладки — ровные прямоугольные сетки 4 / 6 / 9,
		 *   • есть «своя компоновка» до 9 фото (сетка считается на лету).
		 * Два одинаковых по механике шаблона — сознательный дубль,
		 * чтобы клиент выбирал по названию, а не гадал.
		 * ------------------------------------------------------------- */
		'girlfriend' => podval_tpl_grid_poster( 'GIRLFRIEND', array( 'GIRLFRIEND', 'МОЯ ДЕВОЧКА', 'MY GIRL', 'WIFEY', 'LOVE' ), 'assets/img/tpl/girlfriend.jpg' ),
		'boyfriend'  => podval_tpl_grid_poster( 'BOYFRIEND', array( 'BOYFRIEND', 'МОЙ МАЛЬЧИК', 'MY BOY', 'HUBBY', 'LOVE' ), 'assets/img/tpl/boyfriend.jpg' ),

		/* ---------------------------------------------------------------
		 * Полароид-пара (реф. 5) — общий, переиспользуется в dad/mom/couple
		 * ------------------------------------------------------------- */
		// ID остаётся `polaroid_pair`, хотя основа теперь и на один кадр.
		// Переименовывать нельзя: в заказах лежит `tpl_id`, по нему сводка
		// достаёт название основы, и старые заказы показали бы прочерк.
		// Плюс на этот id завязаны привязки к группам в админке.
		'polaroid_pair' => array(
			'group'    => 'shared',
			'title'    => 'Полароид',
			'subtitle' => 'Один или два кадра с рукописными подписями',
			'preview'  => 'assets/img/tpl/polaroid-pair.jpg',
			'engine'   => 'polaroid',
			'colors'   => array( 'black', 'white' ),
			'photos'   => array(
				'mode' => 'fixed',
				'min'  => 1,
				'max'  => 2,
			),
			'texts'    => array(
				'cap1' => array(
					'label'    => 'Подпись к первому фото',
					'type'     => 'script',
					'max'      => 60,
					'required' => false,
					'default'  => 'Подпись к фото',
					'sample'   => true,
				),
				'cap2' => array(
					'label'    => 'Подпись к второму фото',
					'type'     => 'script',
					'max'      => 60,
					'required' => false,
					'default'  => 'Подпись к фото',
					'sample'   => true,
					// Второго кадра при раскладке «1 фото» нет — поле прячем,
					// иначе текст уедет в заказ и его будут искать на макете.
					'when'     => array( 'layout' => array( 'pair' ) ),
				),
			),
			// Порядок важен: первая раскладка — по умолчанию. Пара остаётся
			// первой, чтобы у товаров, уже привязанных к этой основе,
			// поведение не изменилось.
			'layouts'  => array(
				'pair'   => array(
					'title' => '2 фото',
					'slots' => array(
						array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.48, 'h' => 1.00 ),
						array( 'key' => 's2', 'x' => 0.52, 'y' => 0.00, 'w' => 0.48, 'h' => 1.00 ),
					),
				),
				'single' => array(
					'title' => '1 фото',
					'slots' => array(
						// Одиночный кадр по центру и крупнее: во всю ширину
						// он выглядел бы плакатом, а не полароидом.
						array( 'key' => 's1', 'x' => 0.22, 'y' => 0.00, 'w' => 0.56, 'h' => 1.00 ),
					),
				),
			),
			'styles'   => array(
				'photo_tone' => array(
					'label'   => 'Тон фото',
					'options' => array( 'color' => 'Цветные', 'mono' => 'Ч/Б' ),
					'default' => 'color',
				),
			),
		),
	);

	return podval_tpl_mirror_mom( $registry );
}

/**
 * «Мама / жена» — зеркало основ «Папа / муж».
 *
 * Не копия в реестре, а производная от dad_*: любая правка папиных
 * основ (раскладки, поля, стили) автоматически приезжает маме. Здесь
 * меняются только тексты: заголовки, пресеты, примеры, буквы MAMA.
 *
 * ID: dad_letters → mom_letters, dad_poster → mom_poster,
 *     dad_single → mom_single, dad_papa → mom_mama.
 * Оверрайды админки (превью, названия, раскладки) у мамы свои — по её ID.
 *
 * @param array $registry Реестр по умолчанию.
 * @return array
 */
function podval_tpl_mirror_mom( $registry ) {
	$map = array(
		'dad_letters' => 'mom_letters',
		'dad_poster'  => 'mom_poster',
		'dad_single'  => 'mom_single',
		'dad_papa'    => 'mom_mama',
	);

	foreach ( $map as $from => $to ) {
		if ( empty( $registry[ $from ] ) || isset( $registry[ $to ] ) ) {
			continue;
		}
		$t          = $registry[ $from ]; // массивы PHP копируются по значению
		$t['group'] = 'mom';
		$t['preview'] = str_replace( 'tpl/dad-', 'tpl/mom-', $t['preview'] ?? '' );

		switch ( $to ) {
			case 'mom_letters':
				$t['subtitle'] = 'МАМА, MAMA, MOM — буквы заполняются вашими фото';
				$t['texts']['word']['default'] = 'MAMA';
				$t['texts']['word']['presets'] = array( 'MAMA', 'МАМА', 'MOM', 'MUM', 'МАМУЛЯ' );
				break;

			case 'mom_poster':
				$t['texts']['headline']['default'] = 'BEST MOM';
				$t['texts']['headline']['presets'] = array( 'BEST MOM', 'FAMILY', 'ЛУЧШАЯ МАМА', 'MY MOM', 'МАМА' );
				$t['texts']['caption']['default']  = 'BEST MOM IN THE WORLD!';
				break;

			case 'mom_single':
				$t['texts']['name']['default'] = 'ALEXANDROVNA';
				break;

			case 'mom_mama':
				$t['title']    = 'MAMA и фото';
				$t['subtitle'] = '3–4 фото, буквы M·A·M·A между ними и подпись снизу';
				$t['preview']  = 'assets/img/tpl/mom-mama.jpg';
				// Пара букв в ячейке сетки: сверху / снизу (движок papa).
				$t['papa_letters']     = array( 'M', 'A' );
				$t['papa_letters_cyr'] = array( 'М', 'А' );
				$t['styles']['papa_script']['options'] = array( 'lat' => 'MA MA', 'cyr' => 'МА МА' );
				$t['texts']['caption']['presets'] = array( 'EST. 2026', 'EST. 11.01.2025', 'BEST MOM' );
				break;
		}

		$registry[ $to ] = $t;
	}

	return $registry;
}

/**
 * Сетки фото: 4 / 6 (два варианта) / 9 (два варианта) плюс своя компоновка.
 * Доли 0..1, зазор 0.02.
 *
 * Два принципа, оба важны:
 *
 * 1. `aspect` = 1.0 у ВСЕХ раскладок. Высота блока коллажа тогда не скачет
 *    при переключении — эталоном взята сетка 3×3, самая уравновешенная.
 *    Пропорции ячеек задаются только долями внутри квадрата.
 *
 * 2. Микросмещения: внешний контур строго прямоугольный (крайние доли
 *    сходятся в 0 и 1), а внутренние горизонтальные границы «гуляют» на
 *    2–4% — колонки делятся не по одной линии. Так коллаж выглядит собранным
 *    вручную, а не сгенерированным, и совпадает с референсами.
 *
 * @return array layout_id => definition
 */
function podval_tpl_grid_layouts() {
	return array(
		'g4'   => array(
			'title'  => '4 фото',
			'aspect' => 1.0,
			'slots'  => array(
				array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.49, 'h' => 0.52 ),
				array( 'key' => 's2', 'x' => 0.51, 'y' => 0.00, 'w' => 0.49, 'h' => 0.46 ),
				array( 'key' => 's3', 'x' => 0.00, 'y' => 0.54, 'w' => 0.49, 'h' => 0.46 ),
				array( 'key' => 's4', 'x' => 0.51, 'y' => 0.48, 'w' => 0.49, 'h' => 0.52 ),
			),
		),
		// 6 фото, вариант A: 3 колонки × 2 ряда.
		'g6a'  => array(
			'title'  => '6 фото · 3×2',
			'aspect' => 1.0,
			'slots'  => array(
				array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.54 ),
				array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.47 ),
				array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.51 ),
				array( 'key' => 's4', 'x' => 0.00, 'y' => 0.56, 'w' => 0.32, 'h' => 0.44 ),
				array( 'key' => 's5', 'x' => 0.34, 'y' => 0.49, 'w' => 0.32, 'h' => 0.51 ),
				array( 'key' => 's6', 'x' => 0.68, 'y' => 0.53, 'w' => 0.32, 'h' => 0.47 ),
			),
		),
		// 6 фото, вариант B: три равных кадра сверху, снизу два узких по краям
		// и широкий горизонтальный посередине. Вертикальные границы рядов
		// намеренно не совпадают — это и даёт «собранный вручную» вид.
		'g6b'  => array(
			'title'  => '6 фото · с широким кадром',
			'aspect' => 1.0,
			'slots'  => array(
				array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.60 ),
				array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.57 ),
				array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.60 ),
				array( 'key' => 's4', 'x' => 0.00, 'y' => 0.62, 'w' => 0.26, 'h' => 0.38 ),
				array( 'key' => 's5', 'x' => 0.28, 'y' => 0.62, 'w' => 0.44, 'h' => 0.38 ),
				array( 'key' => 's6', 'x' => 0.74, 'y' => 0.62, 'w' => 0.26, 'h' => 0.38 ),
			),
		),
		// 9 фото, вариант A: 3×3 с гуляющими внутренними границами.
		'g9a'  => array(
			'title'  => '9 фото · 3×3',
			'aspect' => 1.0,
			'slots'  => array(
				array( 'key' => 's1', 'x' => 0.00, 'y' => 0.00, 'w' => 0.32, 'h' => 0.34 ),
				array( 'key' => 's2', 'x' => 0.34, 'y' => 0.00, 'w' => 0.32, 'h' => 0.30 ),
				array( 'key' => 's3', 'x' => 0.68, 'y' => 0.00, 'w' => 0.32, 'h' => 0.32 ),
				array( 'key' => 's4', 'x' => 0.00, 'y' => 0.36, 'w' => 0.32, 'h' => 0.30 ),
				array( 'key' => 's5', 'x' => 0.34, 'y' => 0.32, 'w' => 0.32, 'h' => 0.34 ),
				array( 'key' => 's6', 'x' => 0.68, 'y' => 0.34, 'w' => 0.32, 'h' => 0.32 ),
				array( 'key' => 's7', 'x' => 0.00, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
				array( 'key' => 's8', 'x' => 0.34, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
				array( 'key' => 's9', 'x' => 0.68, 'y' => 0.68, 'w' => 0.32, 'h' => 0.32 ),
			),
		),
		// 9 фото, вариант B: крупный кадр сверху.
		'g9b'  => array(
			'title'  => '9 фото · с крупным',
			'aspect' => 1.0,
			'slots'  => array(
				array( 'key' => 's1', 'x' => 0.000, 'y' => 0.000, 'w' => 0.660, 'h' => 0.490 ),
				array( 'key' => 's2', 'x' => 0.680, 'y' => 0.000, 'w' => 0.320, 'h' => 0.250 ),
				array( 'key' => 's3', 'x' => 0.680, 'y' => 0.270, 'w' => 0.320, 'h' => 0.220 ),
				array( 'key' => 's4', 'x' => 0.000, 'y' => 0.510, 'w' => 0.235, 'h' => 0.245 ),
				array( 'key' => 's5', 'x' => 0.255, 'y' => 0.510, 'w' => 0.235, 'h' => 0.225 ),
				array( 'key' => 's6', 'x' => 0.510, 'y' => 0.510, 'w' => 0.235, 'h' => 0.245 ),
				array( 'key' => 's7', 'x' => 0.765, 'y' => 0.510, 'w' => 0.235, 'h' => 0.225 ),
				array( 'key' => 's8', 'x' => 0.000, 'y' => 0.775, 'w' => 0.490, 'h' => 0.225 ),
				array( 'key' => 's9', 'x' => 0.510, 'y' => 0.755, 'w' => 0.490, 'h' => 0.245 ),
			),
		),
		// Своя компоновка: сетка считается по числу загруженных фото (1..9).
		// Координаты слотов здесь не заданы — их строит конструктор.
		'free' => array(
			'title'  => 'Своя компоновка',
			'free'   => true,
			'max'    => 9,
			'aspect' => 1.0,
			'slots'  => array(),
		),
	);
}

/**
 * Постер с ровной сеткой (GIRLFRIEND / BOYFRIEND).
 * Отличие от BEST DAD: заголовок свободный, раскладки — ровные сетки.
 *
 * @param string $headline Заголовок по умолчанию.
 * @param array  $presets  Быстрые варианты заголовка.
 * @param string $preview  Путь к превью относительно темы.
 * @return array
 */
/**
 * Тексты блочного низа — перенос механики ONLY YOU в постеры.
 *
 * Блок состоит из строки «декор + имя» и двухколоночного низа: слева
 * переключаемый элемент, справа фраза. У постеров он живёт рядом со старым
 * простым низом, поэтому все поля условные: клиент видит только то, что
 * относится к выбранному варианту.
 *
 * @param bool $with_player Показывать плеер и поле «Трек».
 * @param bool $with_qr     Показывать QR и поле со ссылкой.
 * @return array Определения текстовых полей.
 */
function podval_tpl_footer_block_texts( $with_player = true, $with_qr = true, $always = false ) {
	// Когда блочный низ единственный, условие по footer_kind лишнее:
	// поля должны быть видны всегда, а не при выбранном варианте.
	$kind = $always ? array() : array( 'footer_kind' => array( 'block' ) );

	$texts = array(
		'phrase' => array(
			'label'    => 'Фраза или дата',
			'type'     => 'text',
			'max'      => 44,
			'required' => false,
			'default'  => 'ВАША ФРАЗА ИЛИ ДАТА',
			'sample'   => true,
			'presets'  => array( 'MY FAVOURITE PERSON', 'SINCE 20.08.2022', 'FOREVER AND EVER' ),
			'hint'     => 'Переносится на несколько строк, если не помещается в одну.',
			'when'     => $kind,
		),
		'note'   => array(
			'label'    => 'Текст в блоке',
			'type'     => 'text',
			'max'      => 40,
			'required' => false,
			'default'  => 'ВАШ ТЕКСТ',
			'sample'   => true,
			'hint'     => 'Две-три строки максимум — блок узкий.',
			'when'     => $kind + array( 'footer_left' => array( 'text' ) ),
		),
	);

	if ( $with_player ) {
		$texts['track'] = array(
			'label'    => 'Трек',
			'type'     => 'text',
			'max'      => 40,
			'required' => false,
			'default'  => 'ИСПОЛНИТЕЛЬ — НАЗВАНИЕ ТРЕКА',
			'sample'   => true,
			'hint'     => 'Например: БАСТА — ЛЮБОВЬ БЕЗ ПАМЯТИ',
			'when'     => $kind + array( 'footer_left' => array( 'player' ) ),
		);
	}

	if ( $with_qr ) {
		$texts['qr'] = array(
			'label'    => 'Ссылка для QR-кода',
			'type'     => 'text',
			'max'      => 60,
			'required' => false,
			'default'  => '',
			'hint'     => 'Плейлист, альбом с фото, что угодно. В превью QR схематичный — рабочий сгенерирует дизайнер.',
			'when'     => $kind + array( 'footer_left' => array( 'qr' ) ),
		);
	}

	foreach ( $texts as $key => $def ) {
		if ( isset( $def['when'] ) && ! $def['when'] ) {
			unset( $texts[ $key ]['when'] );
		}
	}

	return $texts;
}

/**
 * Стили блочного низа. Переключатель `footer_kind` виден всегда, остальное
 * привязано к нему: старые «Подписи снизу» и «Декор» относятся к простому
 * варианту, «Декор у имени» и «Блок слева» — к блочному.
 *
 * @param bool $with_player Разрешить вариант «Плеер».
 * @param bool $with_qr     Разрешить вариант «QR-код».
 * @return array Определения стилей.
 */
function podval_tpl_footer_block_styles( $with_player = true, $with_qr = true, $always = false ) {
	$kind = $always ? array() : array( 'footer_kind' => array( 'block' ) );

	$left = array();
	if ( $with_player ) {
		$left['player'] = 'Плеер';
	}
	$left['barcode'] = 'Штрихкод';
	$left['text']    = 'Текст';
	if ( $with_qr ) {
		$left['qr'] = 'QR-код';
	}
	$left['none'] = 'Пусто';

	$styles = array(
		// Сторона имени. Декор всегда занимает противоположную половину
		// строки, поэтому отдельной настройки для него не нужно.
		'name_side'   => array(
			'after'   => 'name',
			'label'   => 'Имя в строке',
			// Порядок кнопок соответствует их смыслу: «Слева» слева,
			// «Справа» справа. При обратном порядке выбор читался наоборот
			// и сбивал. Значение по умолчанию прежнее — right.
			'options' => array(
				'left'  => 'Слева',
				'right' => 'Справа',
			),
			'default' => 'right',
			'when'    => $kind,
		),
		'name_decor'  => array(
			'after'   => 'name',
			'label'   => 'Декор у имени',
			'options' => array(
				'arrows'  => 'Стрелки',
				'slashes' => 'Слэши',
				'none'    => 'Без декора',
			),
			'default' => 'arrows',
			'when'    => $kind,
		),
		// Полоса декора либо добирает всё свободное место, либо ровно
		// половину строки — тогда между ней и именем остаётся воздух.
		'decor_fill'  => array(
			'after'   => 'name',
			'label'   => 'Длина декора',
			'options' => array(
				'full' => 'До имени',
				'half' => 'Половина строки',
			),
			'default' => 'full',
			'when'    => $kind + array( 'name_decor' => array( 'arrows', 'slashes' ) ),
		),
		'footer_left' => array(
			'label'   => 'Блок слева',
			'options' => $left,
			'default' => $with_player ? 'player' : 'barcode',
			'when'    => $kind,
		),
	);

	// Пустое условие — это «условия нет», а не «не подходит никогда».
	// Оставленный пустой массив читался бы как список вариантов, ни один
	// из которых не совпал, и строка опций молча пропадала бы из формы.
	foreach ( $styles as $key => $def ) {
		if ( isset( $def['when'] ) && ! $def['when'] ) {
			unset( $styles[ $key ]['when'] );
		}
	}

	return $styles;
}

/**
 * Блочный низ для «Одно фото + имя»: фраза справа и текст в блоке слева.
 * Поля общие с постерами, меняются только условия (вариант `block` у
 * переключателя «Вид макета») и пресеты фразы — у постеров они парные.
 *
 * @return array Определения текстовых полей.
 */
function podval_tpl_single_block_texts() {
	$texts = podval_tpl_footer_block_texts( false, false );

	$texts['phrase']['default'] = 'A CHILD BORN TO SHINE';
	$texts['phrase']['presets'] = array( 'A CHILD BORN TO SHINE', 'BEST DAD EVER', 'MY HERO' );

	return $texts;
}

/**
 * Стили блочного низа для «Одно фото + имя». Настройки строки имени
 * выводятся под полем «Имя снизу», а не под верхним «Имя / отчество».
 * По умолчанию — как на референсе: имя слева, без декора, штрихкод.
 *
 * @return array Определения стилей.
 */
function podval_tpl_single_block_styles() {
	$styles = podval_tpl_footer_block_styles( false, false );

	foreach ( array( 'name_side', 'name_decor', 'decor_fill' ) as $key ) {
		if ( isset( $styles[ $key ] ) ) {
			$styles[ $key ]['after'] = 'foot_name';
		}
	}
	$styles['name_side']['default']   = 'left';
	$styles['name_decor']['default']  = 'none';
	$styles['footer_left']['default'] = 'barcode';

	return $styles;
}

/**
 * Переключатель между простым и блочным низом.
 *
 * `major` поднимает строку из ряда обычных настроек: выбор меняет и низ
 * макета, и состав полей вокруг — в общем списке опций он читался как
 * мелкая настройка вроде «2 или 3 строки».
 */
function podval_tpl_footer_kind_style() {
	return array(
		// сразу под «Повтор заголовка» (тот же `after`), а не в
		// конце шага: выбор низа определяет, какие поля идут ниже, и
		// стоять он должен до них.
		'after'   => 'headline',
		'label'   => 'Низ макета',
		'options' => array(
			'simple' => 'Подписи по центру',
			'block'  => 'Имя и блок слева',
		),
		'default' => 'simple',
		'major'   => true,
		'hint'    => 'Меняет весь низ макета и набор полей ниже.',
	);
}

function podval_tpl_grid_poster( $headline, $presets, $preview ) {
	return array(
		'group'    => 'couple',
		'title'    => $headline,
		'subtitle' => 'Крупный заголовок + ровная сетка фото + подписи снизу',
		'preview'  => $preview,
		'engine'   => 'poster',
		'colors'   => array( 'black', 'white' ),
		'photos'   => array(
			'mode' => 'by_layout',
			'min'  => 1,
			'max'  => 9,
		),
		'texts'    => array(
			'headline' => array(
				'label'    => 'Заголовок',
				'type'     => 'text',
				'max'      => 14,
				'required' => true,
				'default'  => $headline,
				'presets'  => $presets,
				// locked НЕ ставим: свободный ввод — ключевое отличие от BEST DAD.
				'hint'     => 'Повторяется 2–3 строками. Можно вписать своё.',
			),
			'name'     => array(
				'label'    => 'Имя',
				'type'     => 'text',
				'max'      => 18,
				'required' => false,
				'default'  => 'ВАШЕ ИМЯ',
				'sample'   => true,
			),
			// Простого низа у этих основ больше нет, поэтому «Подпись
			// справа снизу» ушла: её место занимает «Фраза» из блока.
			'date'     => array(
				'label'    => 'Подпись под штрихкодом',
				'type'     => 'text',
				'max'      => 20,
				'required' => false,
				'default'  => 'SINCE 00.00.0000',
				'sample'   => true,
				'hint'     => 'Например: SINCE 12.03.2020',
				'when'     => array( 'footer_left' => array( 'barcode' ) ),
			),
		) + podval_tpl_footer_block_texts( true, true, true ),
		'layouts'  => podval_tpl_grid_layouts(),
		'styles'   => array(
			'photo_tone'   => array(
				'label'   => 'Тон фото',
				'options' => array( 'mono' => 'Ч/Б', 'color' => 'Цветные' ),
				'default' => 'mono',
			),
			'title_repeat' => array(
								'after'   => 'headline',
				'label'   => 'Повтор заголовка',
				'options' => array( '2' => '2 строки', '3' => '3 строки' ),
				'default' => '3',
			),
			// Переключателя «Низ макета» здесь нет: у GIRLFRIEND и BOYFRIEND
			// остаётся только блочный низ. Простые подписи по краям для этих
			// основ не использовались, а лишний выбор путал.
		) + podval_tpl_footer_block_styles( true, true, true ),
	);
}

/**
 * Полный реестр шаблонов — с оверрайдами из админки.
 *
 * @return array tpl_id => definition
 */
function podval_tpl_registry() {
	static $registry = null;
	if ( null !== $registry ) {
		return $registry;
	}
	$registry = apply_filters( 'podval_tpl_registry', podval_tpl_registry_defaults() );
	return $registry;
}

/**
 * Общие шаблоны, подмешиваемые в конкретные группы.
 * Меняется фильтром — не правкой реестра.
 *
 * @return array group => array of tpl_id
 */
function podval_tpl_shared_map() {
	// у «Папа / муж» полароид заменён своей основой dad_papa.
	// Сам шаблон из реестра не убран — по его tpl_id сводка старых
	// заказов достаёт название основы.
	return apply_filters( 'podval_tpl_shared_map', array(
		'dad'    => array(),
		// у мамы полароид заменён её основой mom_mama — зеркально папе.
		'mom'    => array(),
		'couple' => array( 'polaroid_pair' ),
	) );
}

/**
 * Один шаблон по id.
 *
 * @param string $tpl_id ID шаблона.
 * @return array|null
 */
function podval_tpl_get( $tpl_id ) {
	$registry = podval_tpl_registry();
	return isset( $registry[ $tpl_id ] ) ? $registry[ $tpl_id ] : null;
}

/**
 * Шаблоны группы — для шага «Выберите основу».
 *
 * @param string $group Группа: dad, mom, couple…
 * @return array tpl_id => definition
 */
function podval_tpl_by_group( $group ) {
	$group = sanitize_key( $group );
	$out   = array();

	foreach ( podval_tpl_registry() as $id => $tpl ) {
		if ( $tpl['group'] === $group ) {
			$out[ $id ] = $tpl;
		}
	}

	$shared_map = podval_tpl_shared_map();
	if ( ! empty( $shared_map[ $group ] ) ) {
		foreach ( $shared_map[ $group ] as $id ) {
			$tpl = podval_tpl_get( $id );
			if ( $tpl ) {
				$out[ $id ] = $tpl;
			}
		}
	}

	return $out;
}

/**
 * Список доступных групп (для мета-бокса товара).
 *
 * @return array key => label
 */
function podval_tpl_groups() {
	return apply_filters( 'podval_tpl_groups', array(
		''       => '— не шаблонный товар —',
		'dad'    => 'Папа / муж',
		'mom'    => 'Мама / жена',
		'couple' => 'Парные',
		'kids'   => 'Детские',
	) );
}

/* ═══════════════════════════════════════════
   2. ПАРАМЕТРЫ ИЗДЕЛИЯ
   ═══════════════════════════════════════════ */

/**
 * Цвет / тип / размер изделия.
 * Хранятся как мета cart item — НЕ как вариации WC.
 *
 * @return array
 */
function podval_tpl_garment_options( $product_id = 0 ) {
	$opts = apply_filters( 'podval_tpl_garment_options', array(
		'color' => array(
			'label'   => 'Цвет футболки',
			'options' => array(
				'white' => array( 'label' => 'Белая', 'hex' => '#ffffff' ),
				'black' => array( 'label' => 'Чёрная', 'hex' => '#111111' ),
			),
			'default' => 'black',
		),
		'type'  => array(
			'label'   => 'Тип',
			'options' => array(
				'classic'  => array( 'label' => 'Классическая' ),
				'oversize' => array( 'label' => 'Оверсайз' ),
			),
			'default' => 'classic',
		),
		'size'  => array(
			'label'   => 'Размер',
			'options' => array(
				'xs'  => array( 'label' => 'XS' ),
				's'   => array( 'label' => 'S' ),
				'm'   => array( 'label' => 'M' ),
				'l'   => array( 'label' => 'L' ),
				'xl'  => array( 'label' => 'XL' ),
				'2xl' => array( 'label' => '2XL' ),
				'3xl' => array( 'label' => '3XL' ),
			),
			'default' => 'm',
		),
		/* Сторона печати. У обычных карточек это атрибут вариации
		   («Где печатаем?»), но у товаров с конструктором вариаций WC нет
		   — изделие описывается этим массивом. Поле добавлено сюда, а не
		   отдельной веткой: отсюда его подхватывают и карточка товара, и
		   первый шаг конструктора, и валидация payload, и мета в заказе,
		   и уведомление в Telegram — вся цепочка обходит этот список
		   циклом.

		   Дефолт «Сзади», а не «Спереди», как у обычных карточек: у всех
		   шаблонов конструктора принт на макетах и фото товара размещён
		   на спине. Менять — здесь, одним словом.

		   Надбавки нет: цена не зависит от стороны. Отдельная надбавка
		   `second_side` («Печать на второй стороне») остаётся и означает
		   ВТОРОЙ принт, а не выбор из двух. */
		'side'  => array(
			'label'   => 'Печать',
			'options' => array(
				'front' => array( 'label' => 'Спереди' ),
				'back'  => array( 'label' => 'Сзади' ),
			),
			'default' => 'back',
		),
	) );

	/* дефолт стороны печати можно переопределить в товаре —
	   метабокс «Конструктор шаблона» → «Печать по умолчанию». Влияет
	   только на предвыбор на карточке и в конструкторе; валидация
	   payload по-прежнему берёт общий список опций. */
	if ( $product_id && isset( $opts['side'] ) ) {
		$side = get_post_meta( (int) $product_id, '_podval_tpl_default_side', true );
		if ( $side && isset( $opts['side']['options'][ $side ] ) ) {
			$opts['side']['default'] = $side;
		}
	}

	return $opts;
}

/**
 * Надбавки к цене.
 *
 * ⚠️ ЦЕНЫ — ЗАГЛУШКИ. Подставь реальные или переопредели фильтром
 *    podval_tpl_addons в child-теме / functions.php.
 *
 * Каждая надбавка: code => [ title, amount, auto ]
 *   auto = true  → применяется автоматически по условию (тип/размер)
 *   auto = false → чекбокс в конструкторе
 *
 * @return array
 */
function podval_tpl_addons() {
	return apply_filters( 'podval_tpl_addons', array(
		'oversize'    => array(
			'title'  => 'Оверсайз',
			'amount' => 15.0,
			'auto'   => true,
			'when'   => array( 'type' => array( 'oversize' ) ),
		),
		'bigsize'     => array(
			'title'  => 'Размер 2XL/3XL',
			'amount' => 5.0,
			'auto'   => true,
			'when'   => array( 'size' => array( '2xl', '3xl' ) ),
		),
		'second_side' => array(
			'title'  => 'Печать на второй стороне',
			'amount' => 25.0,
			'auto'   => false,
			'hint'   => 'Второй принт согласуем в переписке',
		),
		'rush'        => array(
			'title'  => 'Срочно — готово за 4 часа',
			'amount' => 20.0,
			'auto'   => false,
			'hint'   => 'Только самовывоз, в рабочие часы',
		),
	) );
}

/**
 * Расчёт итоговых надбавок по нормализованному payload.
 *
 * @param array $data Нормализованный payload (после podval_tpl_validate).
 * @return array Список [ code, title, amount ].
 */
function podval_tpl_calc_addons( $data ) {
	$defs   = podval_tpl_addons();
	$result = array();

	foreach ( $defs as $code => $def ) {
		$apply = false;

		if ( ! empty( $def['auto'] ) ) {
			$apply = true;
			foreach ( (array) ( $def['when'] ?? array() ) as $field => $values ) {
				$current = $data['garment'][ $field ] ?? '';
				if ( ! in_array( $current, (array) $values, true ) ) {
					$apply = false;
					break;
				}
			}
		} else {
			$apply = ! empty( $data['addons'] ) && in_array( $code, $data['addons'], true );
		}

		if ( $apply ) {
			$result[] = array(
				'code'   => $code,
				'title'  => $def['title'],
				'amount' => (float) $def['amount'],
			);
		}
	}

	return $result;
}

/* ═══════════════════════════════════════════
   3. ХЕЛПЕРЫ
   ═══════════════════════════════════════════ */

/**
 * Ограничение значения долей 0..1.
 *
 * @param mixed $v Значение.
 * @return float
 */
function podval_tpl_clamp01( $v ) {
	$v = (float) $v;
	return max( 0.0, min( 1.0, round( $v, 4 ) ) );
}

/**
 * Сколько слотов у комбинации шаблон + раскладка + слово.
 *
 * @param array  $tpl       Определение шаблона.
 * @param string $layout_id ID раскладки.
 * @param string $word      Слово (для engine=letters).
 * @return int
 */
function podval_tpl_slot_count( $tpl, $layout_id = '', $word = '' ) {
	if ( 'by_word' === $tpl['photos']['mode'] ) {
		return mb_strlen( $word, 'UTF-8' );
	}
	if ( $layout_id && isset( $tpl['layouts'][ $layout_id ] ) ) {
		// Свободная компоновка: число слотов диктует пользователь (см. валидацию).
		if ( ! empty( $tpl['layouts'][ $layout_id ]['free'] ) ) {
			return (int) ( $tpl['layouts'][ $layout_id ]['max'] ?? $tpl['photos']['max'] );
		}
		return count( $tpl['layouts'][ $layout_id ]['slots'] );
	}
	return (int) $tpl['photos']['min'];
}

/**
 * URL превью шаблона с фолбэком на плейсхолдер.
 *
 * @param array $tpl Определение шаблона.
 * @return string
 */
function podval_tpl_preview_url( $tpl ) {
	if ( ! empty( $tpl['preview_id'] ) ) {
		$url = wp_get_attachment_image_url( (int) $tpl['preview_id'], 'podval-card-sm' );
		if ( $url ) {
			return $url;
		}
	}
	$rel = isset( $tpl['preview'] ) ? ltrim( $tpl['preview'], '/' ) : '';
	if ( $rel && file_exists( get_template_directory() . '/' . $rel ) ) {
		return get_template_directory_uri() . '/' . $rel;
	}
	return '';
}

/* ═══════════════════════════════════════════
   4. СЕРВЕРНАЯ ВАЛИДАЦИЯ
   ═══════════════════════════════════════════ */

/**
 * Валидация и нормализация payload из конструктора.
 * Клиенту не доверяем: состав слотов, лимиты текста, стили и надбавки
 * сверяются с реестром.
 *
 * @param array $payload Сырые данные с фронта.
 * @return array|WP_Error Нормализованный payload либо ошибка.
 */
function podval_tpl_validate( $payload ) {

	if ( ! is_array( $payload ) ) {
		return new WP_Error( 'podval_tpl_bad_payload', 'Некорректные данные конструктора.' );
	}

	$tpl_id = isset( $payload['tpl_id'] ) ? sanitize_key( $payload['tpl_id'] ) : '';
	$tpl    = podval_tpl_get( $tpl_id );

	if ( ! $tpl ) {
		return new WP_Error( 'podval_tpl_unknown', 'Неизвестный шаблон принта.' );
	}

	/* ── Тексты ──────────────────────────────────────────────── */
	$texts = array();
	foreach ( $tpl['texts'] as $key => $def ) {
		$raw = isset( $payload['texts'][ $key ] ) ? (string) $payload['texts'][ $key ] : '';
		$raw = wp_strip_all_tags( wp_unslash( $raw ) );
		$raw = trim( preg_replace( '/\s+/u', ' ', $raw ) );

		if ( 'word' === $def['type'] ) {
			$raw = mb_strtoupper( $raw, 'UTF-8' );
		}

		if ( '' === $raw && ! empty( $def['required'] ) ) {
			return new WP_Error( 'podval_tpl_text_required', sprintf( 'Заполните поле «%s».', $def['label'] ) );
		}
		if ( mb_strlen( $raw, 'UTF-8' ) > (int) $def['max'] ) {
			return new WP_Error( 'podval_tpl_text_long', sprintf( 'Поле «%s»: максимум %d символов.', $def['label'], (int) $def['max'] ) );
		}
		if ( '' !== $raw && ! empty( $def['pattern'] ) && ! preg_match( $def['pattern'], $raw ) ) {
			return new WP_Error( 'podval_tpl_text_pattern', sprintf( 'Поле «%s» заполнено некорректно.', $def['label'] ) );
		}

		// Поле «только из списка»: клиенту не доверяем, сверяем с пресетами.
		if ( ! empty( $def['locked'] ) && ! empty( $def['presets'] ) && '' !== $raw ) {
			if ( ! in_array( $raw, (array) $def['presets'], true ) ) {
				return new WP_Error(
					'podval_tpl_text_not_preset',
					sprintf( 'Для поля «%s» выберите вариант из списка.', $def['label'] )
				);
			}
		}

		$texts[ $key ] = $raw;
	}

	/* ── Раскладка ───────────────────────────────────────────── */
	$layout_id = isset( $payload['layout_id'] ) ? sanitize_key( $payload['layout_id'] ) : '';
	if ( ! empty( $tpl['layouts'] ) ) {
		if ( ! isset( $tpl['layouts'][ $layout_id ] ) ) {
			$keys      = array_keys( $tpl['layouts'] );
			$layout_id = $keys[0];
		}
	} else {
		$layout_id = '';
	}

	/* ── Стили ───────────────────────────────────────────────── */
	$styles = array();
	foreach ( $tpl['styles'] as $key => $def ) {
		$val            = isset( $payload['styles'][ $key ] ) ? sanitize_key( $payload['styles'][ $key ] ) : '';
		$styles[ $key ] = isset( $def['options'][ $val ] ) ? $val : $def['default'];
	}

	/* ── Условные поля ───────────────────────────────────────── */
	// Поле с `when` относится к конкретному варианту стиля. Клиент мог
	// заполнить «Трек», а потом переключиться на QR — в заказ такой текст
	// попадать не должен, иначе менеджер собирает макет по мусору.
	foreach ( $tpl['texts'] as $key => $def ) {
		if ( ! empty( $def['when'] ) && ! podval_tpl_when_matches( $def['when'], $styles, $layout_id ) ) {
			$texts[ $key ] = '';
		}
	}

	// То же для стилей. «Декор у имени» существует только в блочном низе:
	// если клиент вернулся к простым подписям, значение в заказе — шум,
	// и в сводке оно висело бы строкой без смысла.
	foreach ( $tpl['styles'] as $key => $def ) {
		if ( ! empty( $def['when'] ) && ! podval_tpl_when_matches( $def['when'], $styles, $layout_id ) ) {
			unset( $styles[ $key ] );
		}
	}

	/* ── Слоты ───────────────────────────────────────────────── */
	// «Пришлю фото позже»: заказ оформляется без файлов, состав макета
	// добирается в Telegram при подтверждении.
	$photos_later = ! empty( $payload['photos_later'] );

	$word     = isset( $texts['word'] ) ? $texts['word'] : '';
	$expected = podval_tpl_slot_count( $tpl, $layout_id, $word );

	$slots = array();
	$given = ( isset( $payload['slots'] ) && is_array( $payload['slots'] ) ) ? $payload['slots'] : array();

	foreach ( $given as $slot ) {
		if ( ! is_array( $slot ) ) {
			continue;
		}
		$att_id = isset( $slot['attachment_id'] ) ? absint( $slot['attachment_id'] ) : 0;
		if ( ! $att_id || 'attachment' !== get_post_type( $att_id ) ) {
			continue;
		}
		// Принимаем только файлы, загруженные нашим аплоадером.
		if ( '1' !== get_post_meta( $att_id, '_podval_print_upload', true ) ) {
			continue;
		}

		$crop = ( isset( $slot['crop'] ) && is_array( $slot['crop'] ) ) ? $slot['crop'] : array();

		$slots[] = array(
			'key'           => isset( $slot['key'] ) ? sanitize_key( $slot['key'] ) : '',
			'attachment_id' => $att_id,
			'crop'          => array(
				'x' => podval_tpl_clamp01( isset( $crop['x'] ) ? $crop['x'] : 0 ),
				'y' => podval_tpl_clamp01( isset( $crop['y'] ) ? $crop['y'] : 0 ),
				'w' => podval_tpl_clamp01( isset( $crop['w'] ) ? $crop['w'] : 1 ),
				'h' => podval_tpl_clamp01( isset( $crop['h'] ) ? $crop['h'] : 1 ),
			),
		);
	}

	// Свободная компоновка: сетку задаёт число присланных фото, но в границах
	// шаблона. Клиенту здесь тоже не доверяем — режем по максимуму.
	$is_free = $layout_id && ! empty( $tpl['layouts'][ $layout_id ]['free'] );
	if ( $is_free ) {
		$max_free = (int) ( $tpl['layouts'][ $layout_id ]['max'] ?? $tpl['photos']['max'] );
		if ( count( $slots ) > $max_free ) {
			$slots = array_slice( $slots, 0, $max_free );
		}
		if ( ! $photos_later && count( $slots ) < 1 ) {
			return new WP_Error( 'podval_tpl_slots_empty', 'Загрузите хотя бы одно фото.' );
		}
		$expected = max( 1, count( $slots ) );
	}

	if ( $photos_later ) {
		// Частичная загрузка допустима, но не больше, чем есть слотов.
		if ( count( $slots ) > $expected ) {
			$slots = array_slice( $slots, 0, $expected );
		}
	} elseif ( ! $is_free && count( $slots ) !== $expected ) {
		return new WP_Error(
			'podval_tpl_slots_mismatch',
			sprintf( 'Нужно фото: %d, заполнено: %d.', $expected, count( $slots ) )
		);
	}

	/* ── Изделие ─────────────────────────────────────────────── */
	$garment_defs = podval_tpl_garment_options();
	$garment      = array();
	foreach ( $garment_defs as $field => $def ) {
		$val = isset( $payload['garment'][ $field ] ) ? sanitize_key( $payload['garment'][ $field ] ) : '';
		if ( ! isset( $def['options'][ $val ] ) ) {
			$val = $def['default'];
		}
		$garment[ $field ] = $val;
	}
	// Цвет ограничен списком, допустимым для шаблона.
	if ( ! in_array( $garment['color'], $tpl['colors'], true ) ) {
		$garment['color'] = $tpl['colors'][0];
	}

	/* ── Надбавки (только ручные; авто считаются позже) ──────── */
	$addon_defs = podval_tpl_addons();
	$addons     = array();
	$given_add  = ( isset( $payload['addons'] ) && is_array( $payload['addons'] ) ) ? $payload['addons'] : array();
	foreach ( $given_add as $code ) {
		$code = sanitize_key( $code );
		if ( isset( $addon_defs[ $code ] ) && empty( $addon_defs[ $code ]['auto'] ) ) {
			$addons[] = $code;
		}
	}

	/* ── Комментарий ─────────────────────────────────────────── */
	$comment = isset( $payload['comment'] ) ? sanitize_textarea_field( wp_unslash( $payload['comment'] ) ) : '';
	if ( mb_strlen( $comment, 'UTF-8' ) > 1000 ) {
		$comment = mb_substr( $comment, 0, 1000, 'UTF-8' );
	}

	return array(
		'schema_v'     => 2,
		'tpl_id'       => $tpl_id,
		'group'        => $tpl['group'],
		'layout_id'    => $layout_id,
		'texts'        => $texts,
		'styles'       => $styles,
		'slots'        => $slots,
		'slots_needed' => $expected,
		'photos_later' => $photos_later,
		'garment'      => $garment,
		'addons'       => $addons,
		'comment'      => $comment,
	);
}

/**
 * Проверка условия видимости поля или стиля.
 *
 * Две формы записи:
 *   array( 'footer_left' => array( 'player' ) )   — И по всем ключам
 *   array( array( … ), array( … ) )               — ИЛИ по вариантам
 *
 * ИЛИ понадобилось для «Даты»: в постерах она подписывает коллаж при
 * простом низе и штрихкод при блочном, а одним И-условием эти два случая
 * не выражаются.
 *
 * @param array $when   Условие из определения поля.
 * @param array $styles Текущие значения стилей.
 * @return bool
 */
function podval_tpl_when_matches( $when, $styles, $layout_id = '' ) {
	if ( empty( $when ) || ! is_array( $when ) ) {
		return true;
	}

	// Список вариантов: срабатывает, если подходит хотя бы один.
	if ( isset( $when[0] ) && is_array( $when[0] ) ) {
		foreach ( $when as $variant ) {
			if ( podval_tpl_when_matches( $variant, $styles, $layout_id ) ) {
				return true;
			}
		}
		return false;
	}

	foreach ( $when as $key => $allowed ) {
		// Раскладка — не стиль, но условия по ней нужны так же часто:
		// «Подпись ко второму фото» осмысленна только когда кадров два.
		// Зарезервированный ключ `layout` вместо отдельного механизма.
		$current = ( 'layout' === $key )
			? $layout_id
			: ( isset( $styles[ $key ] ) ? $styles[ $key ] : '' );

		if ( ! in_array( $current, (array) $allowed, true ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Человекочитаемая сводка по данным конструктора.
 * Используется в корзине, админке и Telegram-плагине.
 *
 * @param array $data Нормализованный payload.
 * @return array Список [ label, value ].
 */
function podval_tpl_summary( $data ) {
	$tpl = podval_tpl_get( $data['tpl_id'] ?? '' );
	if ( ! $tpl ) {
		return array();
	}

	$rows = array();

	// У строк есть `key`: потребителям сводки (Telegram, админка) нужно
	// уметь отличать параметры изделия от оформления, не сравнивая подписи
	// строками — те переводятся и переименовываются.
	$rows[] = array( 'key' => 'tpl', 'label' => 'Основа', 'value' => $tpl['title'] );

	if ( ! empty( $data['layout_id'] ) && isset( $tpl['layouts'][ $data['layout_id'] ] ) ) {
		$rows[] = array( 'key' => 'layout', 'label' => 'Раскладка', 'value' => $tpl['layouts'][ $data['layout_id'] ]['title'] );
	}

	foreach ( (array) ( $data['texts'] ?? array() ) as $key => $val ) {
		if ( '' === $val || ! isset( $tpl['texts'][ $key ] ) ) {
			continue;
		}
		$def = $tpl['texts'][ $key ];

		// Поля-образцы заполнены по умолчанию, чтобы шаблон при открытии
		// выглядел готовым макетом, а не набором пустых мест. Обратная
		// сторона — клиент может отправить заказ, не тронув их. Помечаем,
		// чтобы менеджер спросил в Telegram, а не напечатал «ВАШЕ ИМЯ».
		$untouched = ! empty( $def['sample'] ) && $val === ( $def['default'] ?? '' );

		$rows[] = array(
			'key'   => 'text:' . $key,
			'label' => $def['label'],
			'value' => '«' . $val . '»' . ( $untouched ? ' — не изменено, уточнить' : '' ),
		);
	}

	foreach ( (array) ( $data['styles'] ?? array() ) as $key => $val ) {
		if ( ! isset( $tpl['styles'][ $key ]['options'][ $val ] ) ) {
			continue;
		}
		$value = $tpl['styles'][ $key ]['options'][ $val ];
		// «Чёрные» чернила под фото на чёрной футболке печатаются белым —
		// в превью так и показано, в заказе пишем то же, что видел клиент.
		if ( 'script_color' === $key && 'black' === $val && 'single' === ( $tpl['engine'] ?? '' )
			&& 'black' === ( $data['garment']['color'] ?? '' ) ) {
			$value = 'Белый';
		}
		$rows[] = array(
			'key'   => 'style:' . $key,
			'label' => $tpl['styles'][ $key ]['label'],
			'value' => $value,
		);
	}

	$garment_defs = podval_tpl_garment_options();
	foreach ( (array) ( $data['garment'] ?? array() ) as $field => $val ) {
		if ( ! isset( $garment_defs[ $field ]['options'][ $val ] ) ) {
			continue;
		}
		$rows[] = array(
			'key'   => 'garment:' . $field,
			'label' => $garment_defs[ $field ]['label'],
			'value' => $garment_defs[ $field ]['options'][ $val ]['label'],
		);
	}

	// Считаем слоты с реальным вложением, а не длину массива: при «пришлю
	// позже» слоты присутствуют все, просто пустые, и строка отчитывалась
	// «4 из 4» по заказу, где не загружено ни одного фото.
	$have = 0;
	foreach ( (array) ( $data['slots'] ?? array() ) as $slot ) {
		if ( ! empty( $slot['attachment_id'] ) ) {
			$have++;
		}
	}

	if ( ! empty( $data['photos_later'] ) ) {
		$need = (int) ( $data['slots_needed'] ?? 0 );
		$rows[] = array(
			'label' => 'Фото',
			'value' => sprintf( '⚠ пришлёт позже в Telegram (%d из %d)', $have, $need ),
		);
	} else {
		$rows[] = array( 'label' => 'Фото', 'value' => $have . ' шт.' );
	}

	if ( ! empty( $data['comment'] ) ) {
		$rows[] = array( 'label' => 'Комментарий', 'value' => $data['comment'] );
	}

	return $rows;
}
