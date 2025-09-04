<?php
if (!defined('ABSPATH')) exit;

/**
 * Шаблон вывода карточек GLPI
 * Ожидает переменные в $GLOBALS:
 *  - gexe_tickets          : array
 *  - gexe_executors_map    : array (имя => md5)
 *  - gexe_status_counts    : array (status => count)
 *  - gexe_total_count      : int
 *  - gexe_show_all         : bool
 *  - gexe_category_counts  : array ('Ремонт' => 12)
 *  - gexe_category_slugs   : array ('Ремонт' => 'remont')
 */
$tickets          = isset($GLOBALS['gexe_tickets'])         ? $GLOBALS['gexe_tickets']         : [];
$executors_map    = isset($GLOBALS['gexe_executors_map'])    ? $GLOBALS['gexe_executors_map']    : [];
$status_counts    = isset($GLOBALS['gexe_status_counts'])    ? $GLOBALS['gexe_status_counts']    : [1=>0,2=>0,3=>0,4=>0];
$total_count      = isset($GLOBALS['gexe_total_count'])      ? $GLOBALS['gexe_total_count']      : 0;
$show_all         = isset($GLOBALS['gexe_show_all'])         ? (bool)$GLOBALS['gexe_show_all']   : false;
$category_counts  = isset($GLOBALS['gexe_category_counts'])  ? $GLOBALS['gexe_category_counts']  : [];
$category_slugs   = isset($GLOBALS['gexe_category_slugs'])   ? $GLOBALS['gexe_category_slugs']   : [];

if (!function_exists('esc_html')) {
    function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('esc_url')) {
    function esc_url($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Очистка описания: убираем HTML и схлопываем пробелы.
 */
function gexe_clean_html_text($html) {
    $s = (string)$html;
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $s);
    $s = preg_replace('~</\s*p\s*>~i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}
function gexe_trim_words($text, $words = 40, $suffix = '…') {
    $text = trim((string)$text);
    if ($text === '') return '';
    $parts = preg_split('/\s+/u', $text);
    if (count($parts) <= $words) return $text;
    $parts = array_slice($parts, 0, $words);
    return implode(' ', $parts) . $suffix;
}

/** Вытаскиваем «лист» из полного имени категории */
function gexe_leaf_category($full) {
    $full  = (string)$full;
    $full  = html_entity_decode($full, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = preg_split('/\s*>\s*/u', $full);
    $leaf  = trim((string)end($parts));
    return ($leaf !== '') ? $leaf : $full;
}

/** Помощник: обрезать подпись категории до N символов */
function gexe_truncate_label($text, $limit = 12) {
    $text = (string)$text;
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $limit, '…', 'UTF-8');
    }
    return (strlen($text) > $limit) ? substr($text, 0, $limit - 2) . '…' : $text;
}

/** Slug для категории (совпадает со стороной PHP) */
function gexe_cat_slug($leaf) {
    if (function_exists('transliterator_transliterate')) {
        $leaf = transliterator_transliterate('Any-Latin; Latin-ASCII', $leaf);
    }
    $leaf = strtolower($leaf);
    $leaf = preg_replace('/[^a-z0-9]+/u', '-', $leaf);
    $leaf = trim($leaf, '-');
    if ($leaf === '') $leaf = substr(md5((string)$leaf), 0, 8);
    return $leaf;
}
?>
<div class="glpi-container">

  <!-- Панель фильтрации -->
  <div class="glpi-filtering-panel">
    <div class="glpi-header-row">
      <div class="glpi-search-row">
        <input type="text" id="glpi-unified-search" class="glpi-search-input" placeholder="Поиск...">
      </div>

      <!-- Ряд статусов и экшена -->
      <div class="glpi-status-row">
      <!-- Блоки статусов -->
      <div class="glpi-status-blocks">
        <div class="glpi-status-block status-filter-btn active" data-status="all" data-label="Все задачи">
          <span class="status-count"><?php echo intval($total_count); ?></span>
          <span class="status-label">Все задачи</span>
        </div>
        <div class="glpi-status-block status-filter-btn" data-status="2" data-label="В работе">
          <span class="status-count"><?php echo intval($status_counts[2] ?? 0); ?></span>
          <span class="status-label">В работе</span>
        </div>
        <div class="glpi-status-block status-filter-btn" data-status="3" data-label="В плане">
          <span class="status-count"><?php echo intval($status_counts[3] ?? 0); ?></span>
          <span class="status-label">В плане</span>
        </div>
        <div class="glpi-status-block status-filter-btn" data-status="4" data-label="В стопе">
          <span class="status-count"><?php echo intval($status_counts[4] ?? 0); ?></span>
          <span class="status-label">В стопе</span>
        </div>
        <div class="glpi-status-block status-filter-btn" data-status="1" data-label="Новые">
          <span class="status-count"><?php echo intval($status_counts[1] ?? 0); ?></span>
          <span class="status-label">Новые</span>
        </div>
      </div>

      <div class="glpi-status-block glpi-late-block glpi-filter-btn" data-filter="late"><span class="status-count">&nbsp;</span><span class="status-label">Надо тушить</span></div>      </div>

    </div>

    <!-- Блоки категорий (Сегодня в программе) -->
    <div class="glpi-category-tags">
      <?php foreach ($category_counts as $leaf => $count): 
          $slug = isset($category_slugs[$leaf]) ? $category_slugs[$leaf] : gexe_cat_slug($leaf);
          $icon = function_exists('glpi_get_icon_by_category') ? glpi_get_icon_by_category(mb_strtolower($leaf)) : '<i class="fa-solid fa-tag"></i>';
          $label = gexe_truncate_label($leaf, 12);
      ?>
        <span class="glpi-category-tag category-filter-btn" data-cat="<?php echo esc_attr(strtolower($slug)); ?>">
          <?php echo $icon; ?> <?php echo esc_html($label); ?> (<?php echo intval($count); ?>)
        </span>
      <?php endforeach; ?>
    </div>

  </div>

  <!-- Карточки -->
  <div class="glpi-wrapper">
    <?php foreach ($tickets as $t): 
      $slug_list     = array_map('md5', $t['executors']);
      $slug_str      = implode(',', $slug_list);
      $assignees     = array_map('intval', $t['assignee_ids'] ?? []);
      $assignees     = array_values(array_unique($assignees, SORT_NUMERIC));
      $assignees_str = implode(',', $assignees);

      $is_late       = !empty($t['late']); 
      $is_unassigned = empty($t['executors']);

      $name_raw      = trim((string)$t['name']);
      $name          = esc_html(mb_strimwidth($name_raw, 0, 120, '…'));

      $clean_desc    = gexe_clean_html_text($t['content']);
      $desc_short    = esc_html(gexe_trim_words($clean_desc, 40, '…'));

      // Дочерняя категория
      $leaf_cat      = gexe_leaf_category($t['category']);
      $cat_slug      = gexe_cat_slug($leaf_cat);
      $icon          = function_exists('glpi_get_icon_by_category') ? glpi_get_icon_by_category(mb_strtolower($leaf_cat)) : '';

      // Прямая ссылка в GLPI
      $link = 'http://192.168.100.12/glpi/front/ticket.form.php?id=' . intval($t['id']);

      $executors_html = '';
      if (!empty($t['executors'])) {
        $executors_html = implode(', ', array_map(function($e){
          return '<i class="fa-solid fa-user-tie glpi-executor"></i> ' . esc_html($e);
        }, $t['executors']));
      }
    ?>
      <div class="glpi-card"
           data-ticket-id="<?php echo intval($t['id']); ?>"
           data-executors="<?php echo esc_attr($slug_str); ?>"
           data-assignees="<?php echo esc_attr($assignees_str); ?>"
           data-category="<?php echo esc_attr(strtolower($cat_slug)); ?>"
           data-late="<?php echo $is_late ? '1':'0'; ?>"
           data-status="<?php echo esc_attr((string)$t['status']); ?>"
           data-unassigned="<?php echo $is_unassigned ? '1':'0'; ?>"
           data-author="<?php echo intval($t['author_id'] ?? 0); ?>">
        <div class="glpi-badge <?php echo esc_attr($cat_slug); ?>"><?php echo $icon; ?> <?php echo esc_html($leaf_cat); ?></div>
        <div class="glpi-card-header<?php echo $is_late ? ' late':''; ?>">
          <a href="<?php echo esc_url($link); ?>" class="glpi-topic" target="_blank" rel="noopener noreferrer"><?php echo $name; ?></a>
          <div class="glpi-ticket-id">#<?php echo intval($t['id']); ?></div>
        </div>
        <div class="glpi-card-body">
          <p class="glpi-desc"><?php echo $desc_short; ?></p>
        </div>
        <div class="glpi-executor-footer"><?php echo $executors_html; ?></div>
        <div class="glpi-date-footer" data-date="<?php echo esc_attr((string)$t['date']); ?>"></div>
      </div>
    <?php endforeach; ?>
  </div>

</div>
