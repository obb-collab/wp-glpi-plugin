<?php
// Защита от прямого вызова
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Шаблон вывода карточек GLPI
 *
 * Ожидаемые внешние переменные:
 *   - $tickets (array) — подготовленные записи заявок
 *   - $executors_map (array) — map исполнителей (имя => slug)
 *
 * Шаблон использует функции:
 *   - glpi_get_icon_by_category($cat_text)
 *   - esc_html(), esc_attr(), esc_url() и пр. (WP-контекст)
 *
 * Важно: здесь только представление — вся логика должна быть в основном файле плагина.
 */
?>

<div class="glpi-container">

    <!-- === Панель фильтрации: единый ряд со всеми фильтрами === -->
    <div class="glpi-filtering-panel">
        <div class="glpi-header-row">

            <!-- === СНАЧАЛА: Выпадающее меню исполнителей === -->
            <div class="glpi-filter-dropdown">
                <div class="glpi-filter-toggle">Сегодня в программе <i class="fa-solid fa-angle-down"></i>
                    <div class="glpi-filter-menu">
                        <button class="glpi-filter-btn active" data-filter="all" id="glpi-counter">Всего: <?php echo intval(count($tickets)); ?></button>
                        <?php foreach ($executors_map as $name => $slug): ?>
                            <button class="glpi-filter-btn" data-filter="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></button>
                        <?php endforeach; ?>
                        <button class="glpi-filter-btn" data-filter="late"><i class="fa-solid fa-bomb"></i> Пора тушить</button>
                    </div>
                </div>
            </div>

            <!-- === ЗАТЕМ: Статусы === -->
            <div class="glpi-filter-dropdown">
                <div class="glpi-filter-toggle">Статусы <i class="fa-solid fa-angle-down"></i>
                    <div class="glpi-filter-menu">

                        <button class="glpi-filter-btn status-filter-btn active" data-status="2">
                            <span class="glpi-status-dot status-2"></span> Назначенные
                        </button>

                        <button class="glpi-filter-btn status-filter-btn" data-status="1">
                            <span class="glpi-status-dot status-1"></span> Новые (ЭП)
                        </button>

                        <button class="glpi-filter-btn status-filter-btn" data-status="3">
                            <span class="glpi-status-dot status-3"></span> Запланированы
                        </button>

                        <button class="glpi-filter-btn status-filter-btn" data-status="4">
                            <span class="glpi-status-dot status-4"></span> В стопе
                        </button>

                        <button class="glpi-filter-btn status-filter-btn" data-unassigned="1">
                            <span class="glpi-status-dot status-late"></span> Без исполнителя
                        </button>

                        <!-- Кнопка "Показать все" -->
                        <button class="glpi-filter-btn status-filter-btn" data-status="all">
                            <span class="glpi-status-dot" style="background:#facc15;"></span> Показать все
                        </button>

                    </div>
                </div>
            </div>

            <!-- === В КОНЦЕ: Поиск === -->
            <div class="glpi-search-block">
                <input type="text" id="glpi-unified-search" class="glpi-search-input" placeholder="Поиск...">
            </div>

        </div> <!-- .glpi-header-row -->
    </div> <!-- .glpi-filtering-panel -->

    <!-- === Сетка карточек === -->
    <div class="glpi-wrapper">
        <?php foreach ($tickets as $t): ?>
            <?php
            $slug_list = array_map('md5', $t['executors']);
            $slug_str = implode(',', $slug_list);
            $is_late = $t['late'];
            $is_unassigned = empty($t['executors']);

            $desc = wp_trim_words(strip_tags(html_entity_decode($t['content'])), 40, '...');
            $name = esc_html(mb_strimwidth(trim($t['name']), 0, 100, '…'));
            $category = $t['category'] ?: '—';
            $cat_parts = explode('>', $category);
            $cat_trimmed = trim(end($cat_parts));
            $cat_text = mb_strtolower($cat_trimmed);
            $icon = function_exists('glpi_get_icon_by_category') ? glpi_get_icon_by_category($cat_text) : '';
            $cat_slug = preg_replace('/[^a-z0-9]+/u', '', strtolower(transliterator_transliterate('Any-Latin; Latin-ASCII', $cat_trimmed)));

            $link = 'http://192.168.100.12/glpi/front/ticket.form.php?id=' . intval($t['id']);
            $executors_html = implode(', ', array_map(function ($e) {
                return '<i class="fa-solid fa-user"></i> ' . esc_html($e);
            }, $t['executors']));
            ?>
            <div class="glpi-card" data-executors="<?php echo esc_attr($slug_str); ?>" data-late="<?php echo $is_late ? '1' : '0'; ?>" data-status="<?php echo esc_attr($t['status']); ?>" data-unassigned="<?php echo $is_unassigned ? '1' : '0'; ?>">
                <div class="glpi-badge <?php echo esc_attr($cat_slug); ?>"><?php echo $icon; ?> <?php echo esc_html($cat_trimmed); ?></div>
                <div class="glpi-card-header<?php echo $is_late ? ' late' : ''; ?>">
                    <a href="<?php echo esc_url($link); ?>" class="glpi-topic" target="_blank" style="text-decoration: none;"><?php echo $is_late ? '<span style="color:#d1242f;">' . $name . '</span>' : $name; ?></a>
                    <div class="glpi-ticket-id">#<?php echo intval($t['id']); ?></div>
                </div>
                <div class="glpi-card-body"><p class="glpi-desc"><?php echo esc_html($desc); ?></p></div>
                <div class="glpi-executor-footer"><?php echo $executors_html; ?></div>
                <div class="glpi-date-footer" data-date="<?php echo esc_attr($t['date']); ?>"></div>
            </div>
        <?php endforeach; ?>
    </div> <!-- .glpi-wrapper -->

</div> <!-- .glpi-container -->
