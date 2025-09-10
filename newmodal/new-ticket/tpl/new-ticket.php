<?php
// newmodal/new-ticket/tpl/new-ticket.php
if (!defined('ABSPATH')) { exit; }
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
        <button type="button" id="nm-nt-cancel" class="nm-btn" style="background:#374151;"><?php esc_html_e('Cancel', 'nm'); ?></button>
      </div>
    </form>
  </div>
</div>
