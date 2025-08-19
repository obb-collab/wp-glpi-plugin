<?php
use PHPUnit\Framework\TestCase;

class CanTouchTicketTest extends TestCase {
    protected function setUp(): void {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $GLOBALS['wp_test_is_user_logged_in'] = true;
        $GLOBALS['wp_test_current_user_id'] = 1;
        $GLOBALS['wp_test_user_meta'] = [];
    }

    public function test_not_logged_in() {
        $GLOBALS['wp_test_is_user_logged_in'] = false;
        $this->assertFalse(gexe_can_touch_glpi_ticket(1));
    }

    public function test_no_meta() {
        $this->assertFalse(gexe_can_touch_glpi_ticket(1));
    }

    public function test_global_rights() {
        $GLOBALS['wp_test_user_meta'][1]['glpi_user_id'] = 42;
        $GLOBALS['wpdb']->can_update = true;
        $this->assertTrue(gexe_can_touch_glpi_ticket(1));
    }

    public function test_assignment_right() {
        $GLOBALS['wp_test_user_meta'][1]['glpi_user_id'] = 42;
        $GLOBALS['wpdb']->is_assigned = true;
        $this->assertTrue(gexe_can_touch_glpi_ticket(1));
    }

    public function test_no_rights() {
        $GLOBALS['wp_test_user_meta'][1]['glpi_user_id'] = 42;
        $this->assertFalse(gexe_can_touch_glpi_ticket(1));
    }
}
