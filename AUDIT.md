# Technical Audit Report

This audit covers the current state of the GLPI WordPress plugin.
It includes automated checks and a manual review performed in this
repository.

## Summary Table

| Tool | Target | Result |
| ---- | ------ | ------ |
| PHPCS (WordPress, Docs, VIP) | `glpi-api.php` | 174 errors, 6 warnings |
| ESLint (airbnb-base) | `glpi-new-task.js`, `gexe-filter.js` | 121 problems |
| PHPStan (level 4) | `glpi-api.php` | 9 errors |

## Notable Issues

### PHP Coding Standards
* `glpi-api.php` uses spaces for indentation and lacks file/class/function
  level documentation.
* Short array syntax and inline control structures are present.
* Several functions lack sanitization and rely on direct remote requests.

### JavaScript
* `glpi-new-task.js` defines functions before declaration and relies on
  unnamed callbacks.
* Missing spaces, missing arrow function parentheses, and string
  concatenation are prevalent.
* `gexe-filter.js` fails to parse due to an unexpected optional chaining
  token, indicating an outdated build target.

### Static Analysis (PHPStan)
* WordPress core functions like `wp_json_encode` and `wp_remote_request`
  were not found, suggesting missing stubs or improper bootstrapping for
  analysis.

### Security & SQL
* Direct calls to `wpdb` were not reviewed in depth. Future work should
  ensure all queries use prepared statements and proper capability checks.

### Assets & Build
* No build system exists for minification or bundling of JS/CSS.
  Consider adding a dedicated build step.

## Recommendations
1. Adopt WordPress Coding Standards across all PHP files and add missing
   PHPDoc blocks.
2. Refactor JavaScript to modern ES modules with lint‑clean code and
   event delegation.
3. Provide stubs or bootstrap WordPress for static analysis tools.
4. Introduce a build pipeline and apply versioning via `filemtime` when
   enqueuing assets.

