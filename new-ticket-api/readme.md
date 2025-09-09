# New ticket (API) module

Isolated module under `/new-ticket-api`. UI mirrors the SQL module but creates tickets via GLPI REST API.

## Usage

1. Ensure the main plugin includes:
```php
require_once __DIR__ . '/new-ticket-api/new-ticket-api.php';
```
2. Place shortcode `[glpi_new_ticket_api]` on a page.
3. User credentials:
   - Each WP user must have `glpi_user_id` in usermeta.
   - GLPI user token is resolved automatically from (first match wins):
     - usermeta `glpi_user_token`
     - `nt_glpi_user_token` filter
     - helper functions `glpi_get_user_token()` or `gexe_get_glpi_user_token()` if available
     - `GEXE_GLPI_USER_TOKENS` constant/array keyed by login, email, `wp:id` or `glpi:id`

## Notes
- Dictionaries (categories, locations, assignees) are loaded from GLPI DB for performance.
- Ticket creation uses API: initSession → POST /Ticket → POST /Ticket_User (requester, assignee) → killSession.
- Duplicate protection uses a short SQL check (≤3 seconds window).
- Errors are returned to the frontend with clear messages; no file/DB logging is performed.
- Tickets are planned for 17:30 local time; after 17:30 or on weekends, the due date moves to the next business day at 17:30.
