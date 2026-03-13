# Designer Module Refactor: 2D Editor + 3D Preview

## Module architecture

The designer is split into four local modules (no embedded full third-party designer):

1. **2D editor (`editor2d.js`)**
   - Fabric.js helper for canvas object manipulation.
   - Supports image upload, text insertion, move/resize/rotate via Fabric controls, color editing for text, and layer persistence through Fabric JSON serialization.

2. **3D preview (`preview3d.js`)**
   - Three.js helper for model loading and rendering.
   - Handles GLB loading, camera controls (rotate/zoom), and texture application from the 2D export.

3. **Zone placement (`zonePlacement.js`)**
   - Single source of truth for placement zones and transform definitions.
    - Uses backend-configured `placement_zones` when available, with local fallback config for offline-safe behavior.
   - Used by 3D preview and persisted through API payloads.

4. **Save/load integration (`designService.js` + `api/design_api.php`)**
   - Frontend service wraps backend API requests.
   - Backend stores design JSON + preview + placement linkage in platform-owned tables.

## Page responsibilities

`client/designer/index.html` now has explicit sections:

- **2D Design Editor**: create/edit design layers.
- **3D Preview + Zone Placement**: model load, zone select, texture sync.
- **Save / Load / Order Linkage**: persist versioned designs linked to optional order.

## JS structure

- `js/app.js`: composition root / workflow orchestration.
- `js/editor2d.js`: Fabric canvas logic.
- `js/preview3d.js`: Three scene and placement application.
- `js/zonePlacement.js`: placement presets.
- `js/designService.js`: backend API adapter.

## Placement zone schema

- `placement_zones`
  - `id`
  - `product_id` (nullable)
  - `model_key`
  - `zone_key`
  - `label`
  - `placement_type` (`patch`, `embroidery`, `print_preview`)
  - `width_limit`, `height_limit`
  - `uv_meta_json`
  - `transform_defaults_json`
  - `active`

- `design_placements` now stores placement selection context:
  - `zone_id`
  - `model_key`
  - `placement_key`
  - `placement_type`
  - `transform_json`

## Backend endpoints needed

### Existing endpoint updated

- `POST /api/design_api.php`
  - Accepts:
    - `design_name`
    - `design_json`
    - `preview_image_path`
    - `placement` (`placement_key`, `transform`)
    - `order_id` (optional)
  - Persists design in `saved_designs` and placement in `design_placements`.

### New endpoint

- `GET /api/placement_zones_api.php?model_key=tshirt`
  - Returns editable placement zones for the requested model/product scope.

- `GET /api/design_api.php`
  - Returns latest/versioned design plus placement metadata.

## Data flow

1. User edits design in Fabric canvas.
2. Canvas exports live PNG data URL for preview texture.
3. Three.js applies texture on selected zone.
4. Save action sends design JSON + preview image + placement transform.
5. Backend stores and returns versioned payload for reload/order linkage.
