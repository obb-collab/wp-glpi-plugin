<?php
if (!defined('ABSPATH')) exit;

global $glpi_db;
if (!isset($glpi_db) || !($glpi_db instanceof wpdb)) {
    $glpi_db = new wpdb(
        'wp_glpi',            // db user
        'xapetVD4OWZqw8f',    // db password
        'glpi',               // db name
        '192.168.100.12'      // db host
    );
}

// Ensure last_followup_at column and triggers exist
if ($glpi_db instanceof wpdb) {
    $col = $glpi_db->get_var("SHOW COLUMNS FROM glpi_tickets LIKE 'last_followup_at'");
    if (!$col) {
        // Add column to store timestamp of last followup
        $glpi_db->query("ALTER TABLE glpi_tickets ADD COLUMN last_followup_at DATETIME NULL AFTER date_mod");
        // Populate existing values
        $glpi_db->query(
            "UPDATE glpi_tickets t"
          . " LEFT JOIN (SELECT items_id, MAX(date) AS d FROM glpi_itilfollowups"
          . "              WHERE itemtype='Ticket' GROUP BY items_id) f"
          . "   ON t.id = f.items_id"
          . " SET t.last_followup_at = f.d"
        );
    }

    // Trigger: update last_followup_at on insert
    $glpi_db->query("DROP TRIGGER IF EXISTS glpi_followups_ai");
    $glpi_db->query(
        "CREATE TRIGGER glpi_followups_ai AFTER INSERT ON glpi_itilfollowups"
      . " FOR EACH ROW BEGIN"
      . "   IF NEW.itemtype='Ticket' THEN"
      . "     UPDATE glpi_tickets SET last_followup_at = NEW.date WHERE id = NEW.items_id;"
      . "   END IF;"
      . " END"
    );

    // Trigger: update last_followup_at on delete
    $glpi_db->query("DROP TRIGGER IF EXISTS glpi_followups_ad");
    $glpi_db->query(
        "CREATE TRIGGER glpi_followups_ad AFTER DELETE ON glpi_itilfollowups"
      . " FOR EACH ROW BEGIN"
      . "   IF OLD.itemtype='Ticket' THEN"
      . "     UPDATE glpi_tickets t"
      . "        SET last_followup_at = (SELECT MAX(date) FROM glpi_itilfollowups f"
      . "                               WHERE f.itemtype='Ticket' AND f.items_id = t.id)"
      . "      WHERE t.id = OLD.items_id;"
      . "   END IF;"
      . " END"
    );
}
