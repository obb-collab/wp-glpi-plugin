# Form data loading

Endpoint `gexe_get_form_data` returns lists of categories, locations and executors for the "New ticket" modal.

* Primary source: GLPI MySQL database (`glpi_itilcategories` and `glpi_locations`).
* Fallback: GLPI REST API
  * `GET /ITILCategory/?range=0-1000&order=ASC&sort=name`
  * `GET /Location/?range=0-1000&order=ASC&sort=completename`
  * Headers: `Authorization: user_token`, `App-Token`.
* Result is cached for 30 minutes under key `glpi_form_data_v1`.
* Result format:
  * Success: `{"ok":true,"categories":[...],"locations":[...],"executors":[...],"took_ms":123}`
  * Error: `{"ok":false,"code":"AJAX_FORBIDDEN|DB_CONNECT_FAILED|SQL_ERROR|API_UNAVAILABLE","message":"...","reason":"nonce|cap","took_ms":123}`
* Logs are written to `wp-content/uploads/glpi-plugin/logs/actions.log` with prefix `[form-data]` and include user id, capability check, nonce state, HTTP code and timings.
