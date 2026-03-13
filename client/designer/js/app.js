import { DesignEditor2D } from './editor2d.js';
import { ModelPreview3D } from './preview3d.js';
import { designService } from './designService.js';
import { deriveModelKeyFromUrl, getZoneByKey, getZonesForModel } from './zonePlacement.js';

const editor = new DesignEditor2D({
  canvasId: 'designCanvas',
  imageInputId: 'imageInput',
  addTextBtnId: 'addTextBtn',
  textColorId: 'textColor',
  deleteSelectedBtnId: 'deleteSelectedBtn'
});

const preview = new ModelPreview3D({ containerId: 'viewer', statusId: 'viewerStatus' });
const modelUrl = document.getElementById('modelUrl');
const placementZone = document.getElementById('placementZone');
const applicationMode = document.getElementById('applicationMode');
const patchStylePanel = document.getElementById('patchStylePanel');
const patchBorderColor = document.getElementById('patchBorderColor');
const patchThickness = document.getElementById('patchThickness');
const jsonOutput = document.getElementById('jsonOutput');
let activeModelKey = deriveModelKeyFromUrl(modelUrl.value);
let activeZones = getZonesForModel(activeModelKey);

function getOrderId() {
  const value = document.getElementById('orderId').value.trim();
  return value ? Number(value) : null;
}

function getCurrentPatchStyle() {
  return {
    borderColor: patchBorderColor.value,
    thickness: Number(patchThickness.value)
  };
}

function applyModeUi() {
  const isPatch = applicationMode.value === 'patch';
  patchStylePanel.classList.toggle('d-none', !isPatch);
  preview.setApplicationMode(applicationMode.value);
  preview.setPatchStyle(getCurrentPatchStyle());
}

function serializeWorkspace() {
  return {
    design_json: editor.toJSON(),
    preview_image_path: editor.toDataURL(),
    model_key: activeModelKey,
    application_mode: applicationMode.value,
    patch_style: applicationMode.value === 'patch' ? getCurrentPatchStyle() : null,
    placement: preview.getPlacementState()
  };
}

function populateZoneSelector(zones, selectedZoneKey = null) {
  placementZone.innerHTML = '';
  zones.forEach((zone) => {
    const option = document.createElement('option');
    option.value = zone.zone_key;
    option.textContent = `${zone.label} (${zone.placement_type})`;
    placementZone.appendChild(option);
  });

  const targetZoneKey = selectedZoneKey && zones.some((zone) => zone.zone_key === selectedZoneKey)
    ? selectedZoneKey
    : zones[0]?.zone_key;

  if (targetZoneKey) {
    placementZone.value = targetZoneKey;
    preview.setZone(getZoneByKey(activeModelKey, targetZoneKey));
  }
}

async function loadZonesForModel(modelKey, selectedZoneKey = null) {
  const result = await designService.listPlacementZones(modelKey);
  activeZones = (result.data || []).length > 0 ? result.data : getZonesForModel(modelKey);
  populateZoneSelector(activeZones, selectedZoneKey);
}

async function applyWorkspace(payload) {
  if (payload.design_json) await editor.loadFromJSON(payload.design_json);
  if (payload.preview_image_path) preview.applyTexture(payload.preview_image_path);
  
  const payloadMode = payload.application_mode || payload.placement?.application_mode || 'embroidery_preview';
  applicationMode.value = payloadMode === 'patch' ? 'patch' : 'embroidery_preview';

  const loadedPatchStyle = payload.patch_style || payload.placement?.patch_style || {};
  if (loadedPatchStyle.borderColor) {
    patchBorderColor.value = loadedPatchStyle.borderColor;
  }
  if (Number.isFinite(Number(loadedPatchStyle.thickness))) {
    patchThickness.value = String(Number(loadedPatchStyle.thickness));
  }

  const payloadModelKey = payload.placement?.model_key || payload.model_key || activeModelKey;
  activeModelKey = payloadModelKey;
  preview.setModelKey(activeModelKey);
  await loadZonesForModel(activeModelKey, payload.placement?.placement_key);
  applyModeUi();
  jsonOutput.value = JSON.stringify(payload, null, 2);
}

async function refreshVersions() {
  const versions = await designService.listVersions(getOrderId());
  const container = document.getElementById('versionsList');
  container.innerHTML = '';
  (versions.data || []).forEach((version) => {
    const btn = document.createElement('button');
    btn.className = 'list-group-item list-group-item-action';
    btn.textContent = `v${version.version_number} - ${version.design_name} [${version.application_mode || 'embroidery_preview'}]`;
    btn.addEventListener('click', async () => {
      const result = await designService.loadVersion(version.id, getOrderId());
      await applyWorkspace(result.data);
    });
    container.appendChild(btn);
  });
}

document.getElementById('syncTextureBtn').addEventListener('click', () => {
  preview.applyTexture(editor.toDataURL());
  applyModeUi();
  jsonOutput.value = JSON.stringify(serializeWorkspace(), null, 2);
});

document.getElementById('exportJsonBtn').addEventListener('click', () => {
  jsonOutput.value = JSON.stringify(serializeWorkspace(), null, 2);
});

document.getElementById('loadModelBtn').addEventListener('click', async () => {
activeModelKey = deriveModelKeyFromUrl(modelUrl.value.trim());
  preview.setModelKey(activeModelKey);
  await loadZonesForModel(activeModelKey);
  await preview.loadModel(modelUrl.value.trim());
  applyModeUi();
});

placementZone.addEventListener('change', () => {
  preview.setZone(getZoneByKey(activeModelKey, placementZone.value));
});

applicationMode.addEventListener('change', () => {
  applyModeUi();
  jsonOutput.value = JSON.stringify(serializeWorkspace(), null, 2);
});

patchBorderColor.addEventListener('input', applyModeUi);
patchThickness.addEventListener('input', applyModeUi);

document.getElementById('saveBtn').addEventListener('click', async () => {
  const payload = {
    order_id: getOrderId(),
    design_name: document.getElementById('designName').value.trim() || 'Untitled Design',
    ...serializeWorkspace()
  };

  const result = await designService.saveDesign(payload);
  jsonOutput.value = JSON.stringify(result.data, null, 2);
  await refreshVersions();
});

document.getElementById('loadLatestBtn').addEventListener('click', async () => {
  const result = await designService.loadLatest(getOrderId());
  await applyWorkspace(result.data);
});

document.getElementById('loadVersionsBtn').addEventListener('click', refreshVersions);

preview.loadModel(modelUrl.value.trim()).catch(() => {
  document.getElementById('viewerStatus').textContent = 'Model load failed';
});
preview.setModelKey(activeModelKey);
preview.setApplicationMode(applicationMode.value);
preview.setPatchStyle(getCurrentPatchStyle());
loadZonesForModel(activeModelKey, placementZone.value).catch(() => {
  activeZones = getZonesForModel(activeModelKey);
  populateZoneSelector(activeZones, placementZone.value);
});
applyModeUi();