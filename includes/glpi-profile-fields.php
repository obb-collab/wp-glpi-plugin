<?php
if (!defined('ABSPATH')) exit;

/**
 * Adds GLPI mapping fields to WordPress user profiles.
 */
add_action('show_user_profile', 'gexe_show_glpi_profile_fields');
add_action('edit_user_profile', 'gexe_show_glpi_profile_fields');
add_action('personal_options_update', 'gexe_save_glpi_profile_fields');
add_action('edit_user_profile_update', 'gexe_save_glpi_profile_fields');

/**
 * Render GLPI user ID field.
 */
function gexe_show_glpi_profile_fields($user) {
    if (!($user instanceof WP_User)) return;
    $glpi_user_id = get_user_meta($user->ID, 'glpi_user_id', true);
    ?>
    <h2>GLPI ↔ WordPress</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="glpi_user_id">GLPI user ID</label></th>
            <td>
                <input type="number" name="glpi_user_id" id="glpi_user_id" class="regular-text" value="<?php echo esc_attr($glpi_user_id); ?>" />
                <p class="description">Укажите <strong>числовой users.id</strong> из GLPI.</p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Save GLPI user ID field.
 */
function gexe_save_glpi_profile_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;
    if (!isset($_POST['glpi_user_id'])) {
        return;
    }
    $raw = trim((string) wp_unslash($_POST['glpi_user_id']));
    if ($raw === '' || !ctype_digit($raw)) {
        delete_user_meta($user_id, 'glpi_user_id');
    } else {
        update_user_meta($user_id, 'glpi_user_id', (int) $raw);
    }
}
