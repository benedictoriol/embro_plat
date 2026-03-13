# Design persistence migration notes

## Canonical model
- Use `saved_designs` as the single source of truth for saved design versions.
- Every save creates an immutable row (`version_number` increments per `client_user_id + order_id` scope).
- `is_active = 1` marks the newest selected version for an order.
- `orders.design_version_id` points directly to `saved_designs.id`.

## Legacy model mapping
- Legacy `design_projects` + `design_versions` data maps to:
  - `design_projects.client_id` -> `saved_designs.client_user_id`
  - `design_projects.title` -> `saved_designs.design_name`
  - `design_versions.design_json` -> `saved_designs.design_json`
  - `design_versions.preview_file` -> `saved_designs.preview_image_path`
  - `design_versions.version_no` -> `saved_designs.version_number`
- Legacy `order_id`-based rows from old mixed APIs should be reinserted with canonical field names.

## API behavior after refactor
- `POST /api/design_api.php`
  - Input: `order_id` (nullable), `design_name`, `design_json`, optional `product_id`, `preview_image_path`
  - Output: canonical version row
- `GET /api/design_api.php?versions=1&order_id={id}`
  - Output: canonical version list
- `GET /api/design_api.php?order_id={id}&version_id={id}`
  - Output: one canonical version row

## Local storage policy
- Local storage is now draft cache only (`embroider_design_editor_draft`).
- Version history UI is server-backed via `saved_designs`.
