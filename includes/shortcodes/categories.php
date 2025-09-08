<?php
/**
 * Plugin Name: GLPI Categories Shortcode
 * Description: [glpi_categories] — выводит категории GLPI, используя $glpi_db (wpdb) из glpi-db-setup.php.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) { exit; } // только из WP

add_shortcode('glpi_categories', function () {

    // 1) Подключаем модуль инициализации БД GLPI
    require_once dirname(__DIR__) . '/bootstrap/db-setup.php';

    // 2) Проверяем готовый $glpi_db (wpdb)
    global $glpi_db;
    if (!($glpi_db instanceof wpdb)) {
        return '<div style="color:#ef4444">$glpi_db (wpdb) не найден.</div>';
    }

    // 3) Тянем категории из GLPI
    $sql = "
        SELECT
            id,
            name,
            completename,
            level,
            is_helpdeskvisible,
            is_recursive
        FROM glpi_itilcategories
        ORDER BY completename
    ";
    $rows = $glpi_db->get_results($sql, ARRAY_A);
    if ($glpi_db->last_error) {
        return '<div style="color:#ef4444">SQL error: ' . esc_html($glpi_db->last_error) . '</div>';
    }
    if (!$rows) {
        return '<div>Категории не найдены.</div>';
    }

    // 4) Рендер таблицы (тёмная тема, без конфликтов)
    ob_start(); ?>
    <div class="glpi-cats-dump" style="margin-top:8px;">
      <table style="width:100%; border-collapse:collapse; background:#0f172a; color:#e5e7eb; font-size:14px;">
        <thead>
          <tr style="background:#1e293b;">
            <th style="padding:8px; border:1px solid #334155; text-align:left;">ID</th>
            <th style="padding:8px; border:1px solid #334155; text-align:left;">Название</th>
            <th style="padding:8px; border:1px solid #334155; text-align:left;">Полное имя</th>
            <th style="padding:8px; border:1px solid #334155; text-align:left;">Уровень</th>
            <th style="padding:8px; border:1px solid #334155; text-align:left;">Helpdesk</th>
            <th style="padding:8px; border:1px solid #334155; text-align:left;">Recursive</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="padding:6px; border:1px solid #334155;"><?php echo (int)$r['id']; ?></td>
            <td style="padding:6px; border:1px solid #334155;"><?php echo esc_html($r['name']); ?></td>
            <td style="padding:6px; border:1px solid #334155;"><?php echo esc_html($r['completename']); ?></td>
            <td style="padding:6px; border:1px solid #334155;"><?php echo (int)$r['level']; ?></td>
            <td style="padding:6px; border:1px solid #334155;"><?php echo ((int)$r['is_helpdeskvisible'] ? 'да' : 'нет'); ?></td>
            <td style="padding:6px; border:1px solid #334155;"><?php echo ((int)$r['is_recursive'] ? 'да' : 'нет'); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:8px; font-size:12px; color:#9ca3af;">
        Всего категорий: <?php echo count($rows); ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
});
