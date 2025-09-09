# New Modal (API-only) test plan

## How to enable
- Append `?use_newmodal=1` to any page with ticket list **or**
- Set `define('GEXE_USE_NEWMODAL', true);` in plugin bootstrap.

The old modal remains intact. The new modal hijacks ticket clicks only when enabled.

## What to verify
1. **Open card** by clicking any ticket — modal opens, title/meta render.
2. **Comments load** — existing followups appear in chronological order.
3. **Send comment** — submit, textarea clears, list refreshes with new item.
4. **Принято в работу** — button sets status=2, meta updates.
5. **Status change** — Plan/Stop/Resolve buttons update status accordingly.
6. **Assign self** — upper-right avatar button assigns current user (if allowed).
7. **Errors** — any API error is shown inline under the form; no page reload.

## Notes
- This module uses only GLPI REST API with per-user tokens from `glpi-db-setup.php`.
- No SQL is executed here.
- All actions are idempotent; repeated clicks won’t create duplicates.
