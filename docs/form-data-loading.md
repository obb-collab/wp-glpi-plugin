# Form data loading

Endpoint `glpi_get_form_data` returns lists of categories, locations and executors for the "New ticket" modal.

* Primary source: GLPI MySQL database (`glpi_itilcategories` and `glpi_locations`).
* Fallback: GLPI REST API
  * `GET /ITILCategory/?range=0-1000&order=ASC&sort=name`
  * `GET /Location/?range=0-1000&order=ASC&sort=completename`
  * Headers: `Authorization: user_token`, `App-Token`.
* Result is cached for 30 minutes under key `glpi_form_data_v1`.
* Logs are written to `wp-content/uploads/glpi-plugin/logs/actions.log` with prefix `[form-data]` and include source and timings.
