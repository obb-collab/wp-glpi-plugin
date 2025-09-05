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
