<?php
/**
 * Возвращает HTML-иконку для категории с учётом:
 * 1) Точного совпадения по имени категории или пути "Родитель > Дочерняя"
 * 2) Наследования от предка, если у подкатегории нет своей иконки
 * 3) Фолбэка по ключевым словам (подстрочные совпадения)
 *
 * Используйте значение из столбца «Полное имя» (с иерархией через ">") в $CATEGORY_ICON.
 * Если у подкатегории иконка не задана, вернётся иконка ближайшего предка.
 */
function glpi_get_icon_by_category($cat_text) {
    // --- Справочник HTML-иконок по ключам ---
    $ICON = [
        'video'         => '<i class="fa-solid fa-video icon-video"></i>',
        'recorder'      => '<i class="fa-solid fa-hard-drive icon-recorder"></i>',
        'file-sign'     => '<i class="fa-solid fa-file-signature icon-file-signature"></i>',
        'cartridge'     => '<i class="fa-solid fa-box icon-cartridge"></i>',
        'meds'          => '<i class="fa-solid fa-capsules icon-meds"></i>',
        'gov'           => '<i class="fa-solid fa-landmark icon-gov"></i>',
        'hospital'      => '<i class="fa-solid fa-hospital icon-hospital"></i>',
        'doctor'        => '<i class="fa-solid fa-user-doctor icon-doctor"></i>',
        'web'           => '<i class="fa-solid fa-globe icon-web"></i>',
        'infosec'       => '<i class="fa-solid fa-shield-halved icon-infosec"></i>',
        'alert'         => '<i class="fa-solid fa-triangle-exclamation icon-alert"></i>',
        'print'         => '<i class="fa-solid fa-print icon-print"></i>',
        'desktop'       => '<i class="fa-solid fa-desktop icon-desktop"></i>',
        'phone'         => '<i class="fa-solid fa-phone icon-phone"></i>',
        'network'       => '<i class="fa-solid fa-network-wired icon-network"></i>',
        'switch'        => '<i class="fa-solid fa-ethernet icon-switch"></i>',
        'key'           => '<i class="fa-solid fa-key icon-key"></i>',
        'docs'          => '<i class="fa-solid fa-file-lines icon-docs"></i>',
        'docflow'       => '<i class="fa-solid fa-folder-open icon-docflow"></i>',
        '1c'            => '<i class="fa-solid fa-calculator icon-1c"></i>',
        'redmine'       => '<i class="fa-brands fa-git-alt icon-redmine"></i>',
        'videocall'     => '<i class="fa-solid fa-video icon-videocall"></i>',
        'orders'        => '<i class="fa-solid fa-scroll icon-orders"></i>',
        'memo'          => '<i class="fa-solid fa-note-sticky icon-memo"></i>',
        'report'        => '<i class="fa-solid fa-chart-line icon-report"></i>',
        'law'           => '<i class="fa-solid fa-scale-balanced icon-law"></i>',
        'glpi'          => '<i class="fa-solid fa-database icon-glpi"></i>',
        'parus'         => '<i class="fa-solid fa-ship icon-parus"></i>',
        'rfid'          => '<i class="fa-solid fa-id-card icon-rfid"></i>',
        'skzi'          => '<i class="fa-solid fa-shield-halved icon-skzi"></i>',
        'kii'           => '<i class="fa-solid fa-bolt icon-kii"></i>',
        'teach'         => '<i class="fa-solid fa-chalkboard-user icon-teach"></i>',
        'control'       => '<i class="fa-solid fa-clipboard-check icon-control"></i>',
        'error'         => '<i class="fa-solid fa-triangle-exclamation icon-error"></i>',
        'watch'         => '<i class="fa-solid fa-eye icon-watch"></i>',
        'form'          => '<i class="fa-solid fa-file-pen icon-form"></i>',
        'software'      => '<i class="fa-solid fa-floppy-disk icon-software"></i>',
        'buy'           => '<i class="fa-solid fa-coins icon-buy"></i>',
        '_default'      => '<i class="fa-solid fa-tags icon-default"></i>',
    ];

    // --- ЯВНЫЕ СООТВЕТСТВИЯ: «Полное имя» категории => ключ иконки из $ICON (или готовый HTML) ---
    // ВАЖНО: используем именно поле "Полное имя" из вашей таблицы.
    $CATEGORY_ICON = [
        // Уровень 1
        'Внутренняя работа отдела' => 'default',

        'Документальное сопровождение' => 'docflow',
        'Документальное сопровождение > Redmine ФМБА' => 'redmine',
        'Документальное сопровождение > ВКС, вебинары, телемедицина' => 'videocall',
        'Документальное сопровождение > Входящие' => 'docflow',
        'Документальное сопровождение > Входящие > На контроле' => 'watch',
        'Документальное сопровождение > Договорная деятельность' => 'docs',
        'Документальное сопровождение > Заявки' => 'docs',
        'Документальное сопровождение > Заявки > Приобретение' => 'buy',
        'Документальное сопровождение > Заявки > Приобретение > Оформление и учёт' => 'form',
        'Документальное сопровождение > Информационная безопасность' => 'infosec',
        'Документальное сопровождение > Информационная безопасность > КИИ' => 'kii',
        'Документальное сопровождение > Информационная безопасность > СКЗИ' => 'skzi',
        'Документальное сопровождение > Исходящие' => 'docflow',
        'Документальное сопровождение > Отчеты' => 'report',
        'Документальное сопровождение > Приказы' => 'orders',
        'Документальное сопровождение > Служебные записки' => 'memo',
        'Документальное сопровождение > Указания' => 'orders',
        'Документальное сопровождение > Учёт и контроль активов в GLPI' => 'glpi',

        'Информационные системы' => 'default',
        'Информационные системы > 1С Аптека' => '1c',
        'Информационные системы > 1С: БГУ' => '1c',
        'Информационные системы > Active Directory' => 'network',
        'Информационные системы > Active Directory > Учетные записи' => 'network',
        'Информационные системы > Active Directory > Учетные записи > Создание' => 'network',
        'Информационные системы > DigiPAX (ПАКС)' => '_default',
        'Информационные системы > DigiPAX (ПАКС) > Orthank' => '_default',
        'Информационные системы > LMS ЦМСЧ № 120' => 'teach',
        'Информационные системы > LMS ЦМСЧ № 120 > Персонифицированный учёт' => 'control',
        'Информационные системы > zabbix' => 'watch',
        'Информационные системы > АОКЗ, СОБИ (Казначейство)' => 'gov',
        'Информационные системы > АСТ Сбербанк' => 'buy',
        'Информационные системы > Видеоконференции' => 'videocall',
        'Информационные системы > Гарант' => 'law',
        'Информационные системы > Госзакупки' => 'buy',
        'Информационные системы > Госфинансы' => 'buy',
        'Информационные системы > ГРАНД Смета' => 'report',
        'Информационные системы > ГЦГиЭ ФМБА России' => 'doctor',
        'Информационные системы > ЕВМИАС' => 'doctor',
        'Информационные системы > ЕИС Закупки' => 'buy',
        'Информационные системы > еФарма2-Льгота Web' => 'meds',
        'Информационные системы > ИР ФМБА (socfmba.ru)' => 'web',
        'Информационные системы > МДЛП (Тимофеев)' => 'meds',
        'Информационные системы > Мед.статистика (Зотова)' => 'report',
        'Информационные системы > Мед.статистика (Маликов)' => 'report',
        'Информационные системы > Непрерывное медицинское образование (НМО)' => 'teach',
        'Информационные системы > Оф. сайт ЦМСЧ № 120' => 'web',
        'Информационные системы > ПАКС' => '_default',
        'Информационные системы > Парус' => 'parus',
        'Информационные системы > Полис ОМС' => 'hospital',
        'Информационные системы > РСЗЛ ТФОМС' => 'hospital',
        'Информационные системы > Сервисы ЕГИСЗ' => 'hospital',
        'Информационные системы > СМЭВ' => 'gov',
        'Информационные системы > Телемед. консультации' => 'videocall',
        'Информационные системы > ФМБА Кадры (ПО, VIpNet, сайт)' => 'doctor',
        'Информационные системы > ФРМО/ФРМР' => 'doctor',
        'Информационные системы > ФСС Родовые' => 'gov',
        'Информационные системы > ФСС Социальные выплаты' => 'gov',
        'Информационные системы > ФСС ЭЛН' => 'gov',
        'Информационные системы > Честный знак' => 'control',
        'Информационные системы > Эконом-Эксперт онлайн' => 'buy',
        'Информационные системы > Электронный бюджет' => 'buy',
        'Информационные системы > ЭМК ЦМСЧ 120' => 'hospital',

        'Инфраструктура' => 'network',
        'Инфраструктура > Кассовое обслуживание' => 'buy',
        'Инфраструктура > Кодовые замки (СКУД)' => 'rfid',
        'Инфраструктура > Локальная сеть' => 'network',
        'Инфраструктура > Локальная сеть > Прокладка кабеля' => 'network',
        'Инфраструктура > Локальная сеть > Прокладка сети и подключение ТС' => 'network',
        'Инфраструктура > Связь с подразделениями (в т.ч. VPN)' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Видеонаблюдение' => 'video',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-db3' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-node' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-print' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-terminal' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-virtual' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Серверная > srv-web (почтовый сервер)' => 'network',
        'Инфраструктура > Сервисы ЦМСЧ №120 > Шлагбаумы' => 'rfid',
        'Инфраструктура > Сетевое оборудование' => 'switch',
        'Инфраструктура > Сетевое оборудование > Видеонаблюдение' => 'video',
        'Инфраструктура > Сетевое оборудование > Коммутатор (хаб)' => 'switch',
        'Инфраструктура > Сетевое оборудование > Регистратор выбытия' => 'recorder',
        'Инфраструктура > Телефония' => 'phone',
        'Инфраструктура > Телефония > SIP телефония' => 'phone',
        'Инфраструктура > Телефония > Перенос' => 'phone',

        'Ключ-карты' => 'rfid',

        'Оргтехника' => 'desktop',
        'Оргтехника > Картридж' => 'cartridge',
        'Оргтехника > Комплектующие' => 'desktop',
        'Оргтехника > Компьютер' => 'desktop',
        'Оргтехника > Компьютер > Перенос' => 'desktop',
        'Оргтехника > Компьютер > Ремонт' => 'desktop',
        'Оргтехника > Компьютер > Установка пользователю' => 'desktop',
        'Оргтехника > Монитор' => 'desktop',
        'Оргтехника > Принтер' => 'print',
        'Оргтехника > Принтер > Картридж' => 'cartridge',
        'Оргтехника > Принтер > Картридж > Замена' => 'cartridge',
        'Оргтехника > Принтер > Картридж > Заправка' => 'cartridge',
        'Оргтехника > Принтер > Подключение' => 'print',
        'Оргтехника > Принтер > Ремонт' => 'print',
        'Оргтехника > Принтер > Сканирование' => 'print',

        'Программное обеспечение' => 'software',
        'Программное обеспечение > Настройка ПО' => 'software',
        'Программное обеспечение > Обучение пользователей' => 'teach',
        'Программное обеспечение > Сбой / отказ' => 'error',
        'Программное обеспечение > СКЗИ' => 'skzi',
        'Программное обеспечение > Электронные подписи' => 'key',
        'Программное обеспечение > Электронные подписи > Изготовление' => 'key',
        'Программное обеспечение > Электронные подписи > Установка пользователю' => 'key',

        'СУЭО QuickQ' => 'software',
    ];

    // --- Фолбэк по ключевым словам (подстроки) ---
    $KEYWORD_ICON_MAP = [
        ['keywords' => ['видеонаблюдение'],                     'icon' => $ICON['video']],
        ['keywords' => ['регистр'],                              'icon' => $ICON['recorder']],
        ['keywords' => ['электрон', 'изгот', 'лечебн'],          'icon' => $ICON['file-sign']],
        ['keywords' => ['картридж'],                             'icon' => $ICON['cartridge']],
        ['keywords' => ['лекарств', 'мдлп', 'аптек'],            'icon' => $ICON['meds']],
        ['keywords' => ['госусл', 'госуслуги', 'гос'],           'icon' => $ICON['gov']],
        ['keywords' => ['больниц', 'госпиталь', 'егисз'],        'icon' => $ICON['hospital']],
        ['keywords' => ['фмба', 'фрмо', 'евмиас'],               'icon' => $ICON['doctor']],
        ['keywords' => ['сайт', 'веб'],                          'icon' => $ICON['web']],
        ['keywords' => ['информационн', 'иб'],                   'icon' => $ICON['infosec']],
        ['keywords' => ['срочно', 'важно', 'авария'],            'icon' => $ICON['alert']],
        ['keywords' => ['принтер', 'печать'],                    'icon' => $ICON['print']],
        ['keywords' => ['оргтех', 'компьютер'],                  'icon' => $ICON['desktop']],
        ['keywords' => ['телефон', 'sip'],                       'icon' => $ICON['phone']],
        ['keywords' => ['инфраструктура', 'сервер', 'сеть'],     'icon' => $ICON['network']],
        ['keywords' => ['сетевое оборудовани', 'коммутат'],      'icon' => $ICON['switch']],
        ['keywords' => ['подпис'],                               'icon' => $ICON['key']],
        ['keywords' => ['заявки', 'документооборот'],            'icon' => $ICON['docs']],
        ['keywords' => ['документ'],                             'icon' => $ICON['docflow']], // docflow для "документ"
        ['keywords' => ['1с', 'бгу'],                            'icon' => $ICON['1c']],
        ['keywords' => ['redmine'],                              'icon' => $ICON['redmine']],
        ['keywords' => ['вкс', 'вебинар', 'телемед'],            'icon' => $ICON['videocall']],
        ['keywords' => ['приказ', 'указани'],                    'icon' => $ICON['orders']],
        ['keywords' => ['служебн'],                              'icon' => $ICON['memo']],
        ['keywords' => ['отчет', 'смета'],                       'icon' => $ICON['report']],
        ['keywords' => ['гарант'],                               'icon' => $ICON['law']],
        ['keywords' => ['glpi'],                                 'icon' => $ICON['glpi']],
        ['keywords' => ['парус'],                                'icon' => $ICON['parus']],
        ['keywords' => ['ключ-карт', 'скуд'],                    'icon' => $ICON['rfid']],
        ['keywords' => ['скзи'],                                 'icon' => $ICON['skzi']],
        ['keywords' => ['кии'],                                  'icon' => $ICON['kii']],
        ['keywords' => ['обучение', 'нмо', 'lms'],               'icon' => $ICON['teach']],
        ['keywords' => ['учет', 'контроль', 'персонифиц'],       'icon' => $ICON['control']],
        ['keywords' => ['ошибка', 'сбой', 'отказ'],              'icon' => $ICON['error']],
        ['keywords' => ['контрол', 'zabbix'],                    'icon' => $ICON['watch']],
        ['keywords' => ['оформление'],                           'icon' => $ICON['form']],
        ['keywords' => ['по', 'программное', 'software'],        'icon' => $ICON['software']],
        ['keywords' => ['приобретен', 'аукцион', 'закупк', 'финанс', 'бюджет'], 'icon' => $ICON['buy']],
        ['keywords' => ['омс', 'егисз', 'эмк', 'кадры', 'фрмо', 'фрмр'], 'icon' => $ICON['hospital']],
    ];

    // 1) Поиск по иерархии (включая наследование)
    $icon = _find_icon_by_hierarchy($cat_text, $CATEGORY_ICON, $ICON);
    if ($icon !== null) {
        return $icon;
    }

    // 2) Фолбэк по ключевым словам (подстроки)
    $low = mb_strtolower((string)$cat_text);
    foreach ($KEYWORD_ICON_MAP as $map) {
        foreach ($map['keywords'] as $kw) {
            $kw = (string)$kw;
            if ($kw !== '' && mb_strpos($low, mb_strtolower($kw)) !== false) {
                return $map['icon'];
            }
        }
    }

    // 3) Ничего не нашли — дефолт
    return $ICON['_default'];
}

/**
 * Поиск иконки по точному имени/пути с наследованием предков.
 * Поддерживаем разделители: > / → — - –
 */
function _find_icon_by_hierarchy($cat_text, array $CATEGORY_ICON, array $ICON) {
    if (!is_string($cat_text) || $cat_text === '') return null;

    $parts = _split_category_path($cat_text);
    if (empty($parts)) return null;

    // case-insensitive карта «нормализованный путь» => значение
    $map_ci = [];
    foreach ($CATEGORY_ICON as $name => $icon_key_or_html) {
        $map_ci[mb_strtolower(_normalize_spaces($name))] = $icon_key_or_html;
    }

    // Пробуем от самого глубокого пути к корню — это и есть наследование
    for ($len = count($parts); $len >= 1; $len--) {
        $candidate = implode(' > ', array_slice($parts, 0, $len));
        $ci = mb_strtolower($candidate);
        if (array_key_exists($ci, $map_ci)) {
            $val = $map_ci[$ci];
            // Если это ключ из $ICON — вернём соответствующий HTML, иначе предполагаем непосредственно HTML-иконку
            if (isset($ICON[$val])) return $ICON[$val];
            return $val;
        }
    }
    return null;
}

/** Делит строку пути на части по нескольким разделителям */
function _split_category_path($text) {
    $norm = _normalize_spaces($text);
    $parts = preg_split('/\s*(?:>|\/|→|—|-|–)\s*/u', $norm);
    $parts = array_values(array_filter(array_map('_trim_mb', $parts), fn($x) => $x !== ''));
    return $parts;
}

/** Нормализует пробелы (в т.ч. NBSP) */
function _normalize_spaces($s) {
    $s = preg_replace('/\s+/u', ' ', (string)$s);
    return _trim_mb($s);
}

/** Трим с учётом NBSP */
function _trim_mb($s) {
    return trim((string)$s, " \t\n\r\0\x0B\xC2\xA0");
}
?>