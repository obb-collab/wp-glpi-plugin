<?php
// Enable object cache via Redis
define('WP_CACHE', true);
// Redis connection settings
define('WP_REDIS_HOST', 'localhost');
define('WP_REDIS_PORT', 6379);
define('WP_REDIS_PASSWORD', '');
define('WP_REDIS_DATABASE', 0);
// Optional tuning
define('WP_REDIS_TIMEOUT', 1);
define('WP_REDIS_READ_TIMEOUT', 1);
// Unique cache prefix for this site
define('WP_CACHE_KEY_SALT', 'glpi:');
