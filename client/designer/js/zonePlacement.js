const FALLBACK_ZONES = {
  tshirt: [
    {
      id: 'tshirt-left-chest-emb',
      model_key: 'tshirt',
      zone_key: 'left_chest',
      label: 'Left Chest',
      placement_type: 'embroidery',
      width_limit: 110,
      height_limit: 110,
      uv_meta: { anchor: { x: 0.38, y: 0.42 }, scale: 0.2 },
      transform_defaults: { x: 0.38, y: 0.42, scale: 0.2, rotation: 0 },
      active: true
    },
    {
      id: 'tshirt-center-chest-emb',
      model_key: 'tshirt',
      zone_key: 'center_chest',
      label: 'Center Chest',
      placement_type: 'embroidery',
      width_limit: 240,
      height_limit: 280,
      uv_meta: { anchor: { x: 0.5, y: 0.45 }, scale: 0.34 },
      transform_defaults: { x: 0.5, y: 0.45, scale: 0.34, rotation: 0 },
      active: true
    },
    {
      id: 'tshirt-sleeve-emb',
      model_key: 'tshirt',
      zone_key: 'sleeve_left',
      label: 'Sleeve',
      placement_type: 'embroidery',
      width_limit: 100,
      height_limit: 90,
      uv_meta: { anchor: { x: 0.18, y: 0.45 }, scale: 0.16 },
      transform_defaults: { x: 0.18, y: 0.45, scale: 0.16, rotation: 0.1 },
      active: true
    },
    {
      id: 'tshirt-back-emb',
      model_key: 'tshirt',
      zone_key: 'back',
      label: 'Back',
      placement_type: 'embroidery',
      width_limit: 280,
      height_limit: 320,
      uv_meta: { anchor: { x: 0.5, y: 0.5 }, scale: 0.42 },
      transform_defaults: { x: 0.5, y: 0.5, scale: 0.42, rotation: Math.PI },
      active: true
    }
  ],
  cap: [
    {
      id: 'cap-front-panel-patch',
      model_key: 'cap',
      zone_key: 'cap_front_panel',
      label: 'Cap Front Panel',
      placement_type: 'patch',
      width_limit: 120,
      height_limit: 60,
      uv_meta: { anchor: { x: 0.5, y: 0.52 }, scale: 0.21 },
      transform_defaults: { x: 0.5, y: 0.52, scale: 0.21, rotation: 0 },
      active: true
    },
    {
      id: 'cap-side-panel-emb',
      model_key: 'cap',
      zone_key: 'cap_side_panel',
      label: 'Cap Side Panel',
      placement_type: 'embroidery',
      width_limit: 70,
      height_limit: 50,
      uv_meta: { anchor: { x: 0.73, y: 0.5 }, scale: 0.14 },
      transform_defaults: { x: 0.73, y: 0.5, scale: 0.14, rotation: -0.4 },
      active: true
    }
  ],
  bag: [
    {
      id: 'bag-tote-center-print-preview',
      model_key: 'bag',
      zone_key: 'tote_center',
      label: 'Tote Center',
      placement_type: 'print_preview',
      width_limit: 220,
      height_limit: 240,
      uv_meta: { anchor: { x: 0.5, y: 0.47 }, scale: 0.38 },
      transform_defaults: { x: 0.5, y: 0.47, scale: 0.38, rotation: 0 },
      active: true
    }
  ]
};

export function deriveModelKeyFromUrl(url = '') {
  const clean = String(url).trim().toLowerCase();
  if (!clean) return 'tshirt';
  if (clean.includes('cap')) return 'cap';
  if (clean.includes('bag') || clean.includes('tote')) return 'bag';
  return 'tshirt';
}

export function getZonesForModel(modelKey = 'tshirt') {
  return (FALLBACK_ZONES[modelKey] || FALLBACK_ZONES.tshirt).filter((zone) => zone.active);
}

export function getZoneByKey(modelKey, zoneKey) {
  return getZonesForModel(modelKey).find((zone) => zone.zone_key === zoneKey) || getZonesForModel(modelKey)[0];
}
