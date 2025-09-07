# Project Inventory

This document summarises the current structure and integration points of the plugin before refactoring.

## File tree

```
plugin-root/
├─ gexe-copy.php — main plugin bootstrap (registers hooks, includes all modules, loads assets).
├─ gexe-executor-lock.php — footer script that filters ticket cards by executor tokens.
├─ gexe-filter.js — front‑end controller for filtering panel, card actions and events.
├─ gee.css — shared styles for ticket UI and executor lock.
├─ glpi-api.php — minimal REST client class `Gexe_GLPI_API` (App/User/Session token handling).
├─ glpi-categories-shortcode.php — shortcode `[glpi_categories]` dumping GLPI categories via SQL.
├─ glpi-db-setup.php — establishes `$glpi_db` (wpdb) connection and installs SQL triggers.
├─ glpi-icon-map.php — mapping of category names to FontAwesome icons.
├─ glpi-modal-actions.php — AJAX/REST handlers for comments and ticket state changes.
├─ glpi-new-task.php — modal window and AJAX for creating new tickets.
├─ glpi-new-task.css — styles for the new‑ticket modal.
├─ glpi-new-task.js — script for the new‑ticket modal.
├─ glpi-settings.php — admin settings page (API tokens, solved status, cache flush).
├─ glpi-solve.php — AJAX handler that marks a ticket as solved via REST.
├─ glpi-utils.php — shared helpers for GLPI REST, logging and schema checks.
├─ includes/
│  ├─ glpi-form-data.php — AJAX endpoint loading categories/locations (SQL with REST fallback) and nonce refresh.
│  ├─ logger.php — file logger plus `gexe_log_client_error` AJAX action.
│  └─ rest-client.php — simple REST helper; defines another `gexe_glpi_rest_request` and `gexe_glpi_submit_comment`.
├─ partials/
│  └─ glpi-modal.php — markup for status change modal.
├─ templates/
│  └─ glpi-cards-template.php — mixed PHP/HTML template rendering ticket cards from `$GLOBALS` data.
└─ docs/
   └─ form-data-loading.md — note on form data loading.
```

## Dependencies and observations

- `gexe-copy.php` requires **glpi-db-setup.php**, **gexe-executor-lock.php**, **glpi-categories-shortcode.php**, **glpi-modal-actions.php**, **glpi-api.php**, **glpi-solve.php**, **glpi-icon-map.php**, **glpi-new-task.php**, and **glpi-settings.php**. It acts as a monolithic entry point.
- `glpi-modal-actions.php` and `includes/glpi-form-data.php` both depend on the global `$glpi_db` connection from `glpi-db-setup.php` and on helpers from `glpi-utils.php`.
- `glpi-solve.php` includes `glpi-modal-actions.php`, creating tight coupling between actions.
- `glpi-new-task.php` includes `includes/glpi-form-data.php`, which itself loads `glpi-utils.php`.
- `includes/rest-client.php` defines `gexe_glpi_rest_request` which duplicates the richer implementation in `glpi-utils.php` → risk of divergence.
- Two logging helpers exist: `gexe_glpi_log` (REST timing + bodies) and `gexe_log_action` (simple lines).
- Templates and shortcode output mix PHP logic with HTML/JS, e.g. `templates/glpi-cards-template.php` relies on `$GLOBALS`.
- Hard coded SQL credentials and API tokens live in `glpi-db-setup.php`.

## Public contracts

**Shortcodes**
- `[glpi_cards_exe]` → `gexe_glpi_cards_shortcode`
- `[glpi_categories]` → categories table dump

**AJAX actions**
- `gexe_get_form_data`, `gexe_refresh_nonce`, `gexe_create_ticket`
- `glpi_change_status`, `glpi_resolve`
- `glpi_get_comments`, `glpi_ticket_meta`, `glpi_count_comments_batch`
- `glpi_ticket_started`, `glpi_card_action`, `glpi_accept`
- `gexe_refresh_actions_nonce`, `glpi_comment_add`
- `gexe_log_client_error`

**REST routes**
- `glpi/v1/comments`
- `glpi/v1/followup`

**JS globals / events**
- `window.glpiAjax` / `window.gexeAjax` settings object
- Custom events: `gexe:filters:changed`, `gexe:newtask:open`, `gexe:tokens:ready`, `gexe:refilter`

**Global PHP helpers**
- Numerous functions prefixed `gexe_…` (see repository search); some overlap such as duplicate `gexe_glpi_rest_request`.

## GLPI integration & security notes

- **SQL access**: direct queries against `glpi_*` tables in `gexe_glpi_cards_shortcode`, `includes/glpi-form-data.php`, `glpi-modal-actions.php`, `glpi-new-task.php`, and trigger management in `glpi-db-setup.php`.
- **REST access**: `glpi-utils.php` performs session handling (`gexe_glpi_init_session`) and requests (`gexe_glpi_rest_request`); `glpi-modal-actions.php` and `glpi-solve.php` rely on it for ticket updates and comments.
- **Nonces**: `gexe_form_data` and `gexe_actions` guard AJAX endpoints; REST endpoints use `wp_rest` nonce.
- **Capabilities**: `create_glpi_ticket`, `read`, and `manage_options` gates are used; activation hook adds `create_glpi_ticket` to administrators.
- **Critical globals**: `$glpi_db` (wpdb), `$GLOBALS['gexe_*']` arrays in templates, and hard‑coded constants for API tokens.

## Known issues / risks

- Duplicate implementations of REST client and logger may lead to inconsistent behaviour.
- Extensive use of globals and mixed PHP/HTML/JS make testing and refactoring difficult.
- Hard-coded credentials in version control pose security risks.
- Coupling between modules (e.g., `glpi-solve.php` requiring `glpi-modal-actions.php`) could introduce hidden dependencies.
- Public functions are globally namespaced; no autoloading or modular separation yet.

