<?php
// newmodal/new-ticket/ajax.php
if (!defined('ABSPATH')) { exit; }

add_action('wp_ajax_nm_new_ticket_form', 'nm_ajax_new_ticket_form');
add_action('wp_ajax_nm_create_ticket', 'nm_ajax_create_ticket');

function nm_ajax_new_ticket_form() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    ob_start();
    ?>
    <div class="nm-modal">
      <div class="nm-modal-header">
        <div class="nm-modal-title"><?php esc_html_e('New ticket', 'nm'); ?></div>
        <button class="nm-modal-close" onclick="NM_API.closeAllModals()">&times;</button>
      </div>
      <div class="nm-modal-body">
        <form id="nm-new-ticket-form">
          <div class="nm-field">
            <label for="nm-nt-subject"><?php esc_html_e('Subject', 'nm'); ?></label>
            <input type="text" id="nm-nt-subject" name="subject" required>
          </div>
          <div class="nm-field">
            <label for="nm-nt-content"><?php esc_html_e('Description', 'nm'); ?></label>
            <textarea id="nm-nt-content" name="content" required></textarea>
          </div>
          <div class="nm-row">
            <div class="nm-field">
              <label><?php esc_html_e('Category', 'nm'); ?></label>
              <input type="hidden" id="nm-nt-category" name="category_id">
              <input type="text" id="nm-nt-category-input" placeholder="<?php esc_attr_e('Choose category…', 'nm'); ?>">
            </div>
            <div class="nm-field">
              <label><?php esc_html_e('Location', 'nm'); ?></label>
              <input type="hidden" id="nm-nt-location" name="location_id">
              <input type="text" id="nm-nt-location-input" placeholder="<?php esc_attr_e('Choose location…', 'nm'); ?>">
            </div>
          </div>
          <div class="nm-row">
            <div class="nm-field">
              <label class="nm-inline">
                <input type="checkbox" id="nm-nt-iamexec" name="i_am_executor" checked>
                <span><?php esc_html_e('I am the executor', 'nm'); ?></span>
              </label>
              <div class="nm-help"><?php esc_html_e('Due date: today 18:00 (local) or next day if past', 'nm'); ?></div>
            </div>
            <div class="nm-field">
              <label><?php esc_html_e('Assign executor', 'nm'); ?></label>
              <input type="hidden" id="nm-nt-assignee" name="assignee_id">
              <input type="text" id="nm-nt-assignee-input" placeholder="<?php esc_attr_e('Choose executor…', 'nm'); ?>" disabled>
            </div>
          </div>
          <div class="nm-actions">
            <button type="submit" id="nm-nt-submit" class="nm-btn"><?php esc_html_e('Create ticket', 'nm'); ?></button>
            <button type="button" id="nm-nt-cancel" class="nm-btn" style="background:#374151;">
              <?php esc_html_e('Cancel', 'nm'); ?>
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php
    echo ob_get_clean();
    wp_die();
}

function nm_ajax_create_ticket() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    $subject = nm_expect_non_empty($_POST['subject'] ?? '', 'subject');
    $content = nm_expect_non_empty($_POST['content'] ?? '', 'content');
    $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : null;
    $location_id = isset($_POST['location_id']) ? (int)$_POST['location_id'] : null;
    $i_am_executor = !empty($_POST['i_am_executor']);
    $assignee_id = isset($_POST['assignee_id']) ? (int)$_POST['assignee_id'] : 0;
    $request_id = isset($_POST['request_id']) ? sanitize_text_field($_POST['request_id']) : '';
    nm_idempotency_check_and_set($request_id);

    $wp_uid = nm_current_wp_user_id();
    $glpi_requester_id = nm_glpi_user_id_from_wp($wp_uid);
    if (!$glpi_requester_id) {
        nm_json_error('forbidden', __('GLPI mapping not found', 'nm'), 403);
    }
    if ($i_am_executor) {
        $assignee_id = $glpi_requester_id;
    } else {
        nm_require_can_assign($assignee_id);
    }
    $due_date = nm_calc_due_date_sql();

    try {
        nm_db_begin();
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets')." \
            (name, content, status, priority, date, closedate, due_date, itilcategories_id, locations_id)\
            VALUES (%s, %s, %d, %d, %s, NULL, %s, %d, %d)\
        ", [
            $subject, $content, 1, 3, current_time('mysql'), $due_date, (int)$category_id, (int)$location_id
        ]);
        $ticket_id = nm_db_insert_id();
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets_users')." (tickets_id, users_id, type) VALUES (%d, %d, 1)\
        ", [$ticket_id, $glpi_requester_id]);
        nm_db_query("\
            INSERT INTO ".nm_tbl('tickets_users')." (tickets_id, users_id, type) VALUES (%d, %d, 2)\
        ", [$ticket_id, $assignee_id]);
        nm_db_query("\
            INSERT INTO ".nm_tbl('itilfollowups')." (items_id, itemtype, users_id, content, date)\
            VALUES (%d, 'Ticket', %d, %s, %s)\
        ", [$ticket_id, $assignee_id, __('Created from WordPress', 'nm'), current_time('mysql')]);
        nm_db_commit();
        nm_notify_after_write($ticket_id, 'create', $assignee_id);
        nm_json_ok(['ticket_id' => $ticket_id]);
    } catch (Exception $e) {
        nm_db_rollback();
        nm_json_error('db_error', __('Failed to create ticket', 'nm'));
    }
}
