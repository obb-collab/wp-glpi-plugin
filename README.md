# WP GLPI Plugin

This plugin integrates WordPress with a GLPI helpdesk.

## Database triggers

The plugin maintains `glpi.glpi_tickets.last_followup_at` using two triggers on `glpi.glpi_itilfollowups`.
Triggers are installed once and recorded in the `glpi_triggers_installed` option.
The database user must have the `TRIGGER` privilege (or `ALL PRIVILEGES`) on the `glpi` schema.

### WP-CLI commands

* `wp gexe:triggers install` — install or update the triggers.
* `wp gexe:triggers remove` — drop the triggers and delete plugin options.
* `wp gexe:triggers status` — show trigger presence and definition.

Run these commands from the WordPress root with an account that has the required database privileges.

## GLPI API configuration

The plugin can mark tickets as solved via the GLPI REST API. Configure the API credentials in **Settings → GLPI**:

* `API Base URL` – e.g. `http://192.168.100.12/glpi/apirest.php`
* `Application Token`
* `User Token`
* `Solved Status` – target status for solved tickets (default `6`).

These values are stored in WordPress options and are not exposed to the frontend.

When the user clicks **Задача решена** in the ticket modal, the plugin:

1. Creates an `ITILSolution` object in GLPI.
2. Updates the ticket status to the configured `Solved Status`.
3. Triggers standard GLPI notifications (mail/Telegram).

Errors are logged with the `[GLPI-SOLVE]` prefix.
