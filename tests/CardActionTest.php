<?php
use PHPUnit\Framework\TestCase;

class CardActionTest extends TestCase {
    protected function setUp(): void {
        global $wpdb;
        $wpdb = new FakeWpdb();
        $GLOBALS['wp_test_is_user_logged_in'] = true;
        $GLOBALS['wp_test_current_user_id'] = 1;
        $GLOBALS['wp_test_user_meta'] = [];
        $GLOBALS['wp_test_user_meta'][1]['glpi_user_id'] = 42;
        $wpdb->can_update = true;
        $wpdb->comments = [
            ['id'=>1,'users_id'=>1,'date'=>'2020-01-01 00:00:00','content'=>'Initial comment']
        ];
    }

    public function test_card_action_returns_json_and_comments() {
        $_POST = [
            'ticket_id' => 1,
            'type' => 'start',
            'payload' => '{}'
        ];

        ob_start();
        gexe_glpi_card_action();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertTrue($data['ok']);
        $this->assertStringContainsString('Статус изменён через WP: Принято в работу', $data['comment_html']);
        $this->assertEquals(2, $data['new_status']);
    }
}
