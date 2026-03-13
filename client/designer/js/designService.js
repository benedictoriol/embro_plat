const API_PATH = '../../api/design_api.php';
const ZONES_API_PATH = '../../api/placement_zones_api.php';

async function request(method, payload = null, query = '') {
  const response = await fetch(`${API_PATH}${query}`, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: payload ? JSON.stringify(payload) : null
  });

  const data = await response.json();
  if (!response.ok) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }

  return data;
}

export const designService = {
  saveDesign(payload) {
    return request('POST', payload);
  },

  loadLatest(orderId = null) {
    const query = orderId ? `?order_id=${encodeURIComponent(orderId)}` : '';
    return request('GET', null, query);
  },

  listVersions(orderId = null) {
    const params = new URLSearchParams();
    params.set('versions', '1');
    if (orderId) params.set('order_id', String(orderId));
    return request('GET', null, `?${params.toString()}`);
  },

  loadVersion(versionId, orderId = null) {
    const params = new URLSearchParams();
    params.set('version_id', String(versionId));
    if (orderId) params.set('order_id', String(orderId));
    return request('GET', null, `?${params.toString()}`);
    },

  async listPlacementZones(modelKey) {
    const params = new URLSearchParams();
    if (modelKey) params.set('model_key', String(modelKey));
    const response = await fetch(`${ZONES_API_PATH}?${params.toString()}`);
    const data = await response.json();
    if (!response.ok) {
      throw new Error(data.error || `Request failed (${response.status})`);
    }
    return data;
  }
};
