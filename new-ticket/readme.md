# New ticket module

This module is isolated under `/new-ticket`.

## Usage

1. In the main plugin file add:

```php
require_once __DIR__ . '/new-ticket/new-ticket.php';
```

2. Use shortcode `[glpi_new_ticket2]` to render the modal form.

The module handles its own assets and AJAX requests.
