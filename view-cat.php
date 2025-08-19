<?php
/**
 * Plugin Name: GLPI Categories Shortcode (G-Exe-Copy addon)
 * Description: Шорткод [glpi_categories] — выводит категории GLPI. Берёт подключение ТОЛЬКО из gexe-copy.php ($glpi_db_*).
 * Version: 1.0.0
 * Author: you
 */

if (!defined('ABSPATH')) { exit; } // только из WP

add_shortcode('glpi_categories', function ($atts = []) {

    // === Подключаем gexe-copy.php без побочного вывода ===
    $gexe_path = null;
    foreach ([__DIR__ . '/gexe-copy.php', __DIR__ . '/G-Exe-Copy.php'] as $p) {
        if (is_file($p)) { $gexe_path = $p; break; }
    }
    if (!$gexe_path) {
        return '<div style="color:#ef4444">gexe-copy.php не найден в папке плагина.</div>';
    }

    $ob_lvl = ob_get_level();
    ob_start();
    require_once $gexe_path;
    while (ob_get_level() > $ob_lvl) { ob_end_clean(); }

    // === Подключение ТОЛЬКО из $glpi_db_* ===
    global $glpi_db_host, $glpi_db_name, $glpi_db_user, $glpi_db_pass, $glpi_db_charset;
    if (empty($glpi_db_host) || empty($glpi_db_name) || empty($glpi_db_user)) {
        return '<div style="color:#ef4444">В gexe-copy.php нет $glpi_db_*.</div>';
    }
    $charset = $glpi_db_charset ?: 'utf8mb4';

    try {
        $pdo = new PDO(
            "mysql:host={$glpi_db_host};dbname={$glpi_db_name};charset={$charset}",
            $glpi_db_user,
            $glpi_db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET NAMES '{$charset}'");
    } catch (Throwable $e) {
        return '<div style="color:#ef4444">Ошибка подключения: ' . esc_html($e->getMessage()) . '</div>';
    }

    // === Тянем категории ===
    try {
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
        $rows = $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return '<div style="color:#ef4444">Ошибка запроса: ' . esc_html($e->getMessage()) . '</div>';
    }

    if (!$rows) {
        return '<div>Категории не найдены.</div>';
    }

    // === Рендер таблицы (тёмная тема) ===
    ob_start(); ?>
    <div class="glpi-cats-dump" style="margin-top:8px;">
      <table style="width:100%; border-collapse:collapse; background:#0f172a; color:#e5e7eb; font-size:14px;">
        <thead>
          <tr style="background:#1e293b;">
            <th style="padding:8px; border:1px solid #334155;">ID</th>
            <th style="padding:8px; border:1px solid #334155;">Название</th>
            <th style="padding:8px; border:1px solid #334155;">Полное имя</th>
            <th style="padding:8px; border:1px solid #334155;">Уровень</th>
            <th style="padding:8px; border:1px solid #334155;">Helpdesk</th>
            <th style="padding:8px; border:1px solid #334155;">Recursive</th>
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
