<?php
/**
 * Template for the "chief" standalone page.
 *
 * This is a simplified skeleton clone of the main plugin page. It renders
 * a container for tickets and an assignee selector. Real logic is expected to
 * be implemented later.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Restrict access to the specific user only.
$u = wp_get_current_user();
$login   = isset($u->user_login) ? (string) $u->user_login : '';
$glpi_id = (int) get_user_meta($u->ID, 'glpi_user_id', true);
if ($login !== 'vks_m5_local' && $glpi_id !== 2) {
    status_header(403);
    echo 'Доступ ограничен';
    exit;
}

?>
<div class="glpi-chief-page">
    <div class="glpi-chief-filters"><!-- TODO: filters placeholder --></div>
    <div class="glpi-chief-list"><!-- TODO: ticket cards placeholder --></div>
    <div class="glpi-chief-assignee">
        <label for="glpi-chief-assignee-select">Исполнитель:</label>
        <select id="glpi-chief-assignee-select" class="glpi-chief-assignee-select">
            <option value="all" selected>Все заявки</option>
        </select>
    </div>
</div>
