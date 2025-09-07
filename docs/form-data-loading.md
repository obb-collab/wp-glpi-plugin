# Form data loading

Endpoint `gexe_get_form_data` returns lists of categories and locations for the "New ticket" modal.

* Primary source: GLPI MySQL database (`glpi_itilcategories` and `glpi_locations`).
  * SQL:
    * `SELECT id, name FROM \`glpi\`.\`glpi_itilcategories\` WHERE is_deleted = 0 ORDER BY name ASC LIMIT 1000`
    * `SELECT id, completename AS name FROM \`glpi\`.\`glpi_locations\` WHERE is_deleted = 0 ORDER BY completename ASC LIMIT 2000`
* Fallback: GLPI REST API
  * `GET /ITILCategory/?range=0-1000&order=ASC&sort=name`
  * `GET /Location/?range=0-2000&order=ASC&sort=completename`
  * Headers: `Authorization: user_token`, `App-Token`.
* Result is cached for 30 minutes under key `glpi_form_data_v1`.
* Result format:
  * Success: `{"ok":true,"categories":[...],"locations":[...],"took_ms":123,"source":"db|api|cache"}`
  * Error: `{"ok":false,"code":"AJAX_FORBIDDEN|DB_CONNECT_FAILED|SQL_ERROR|API_UNAVAILABLE","message":"...","took_ms":123}`
* Logs are written to `wp-content/uploads/glpi-plugin/logs/actions.log` with entries like `[form-data] source=db|api|cache http=200|500 elapsed=XXXms cats=N locs=M err="<short>"`.
* With `?debug=1` the handler runs `SELECT 1`, `SELECT id FROM glpi.glpi_itilcategories LIMIT 1` and `SELECT id FROM glpi.glpi_locations LIMIT 1` and logs `SHOW GRANTS FOR CURRENT_USER`.
