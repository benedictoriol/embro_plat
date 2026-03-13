import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { GLTFLoader } from 'three/addons/loaders/GLTFLoader.js';
import { getZoneByKey } from './zonePlacement.js';

const DEFAULT_PATCH_STYLE = {
  borderColor: '#111827',
  thickness: 5
};

export class ModelPreview3D {
  constructor({ containerId, statusId }) {
    this.container = document.getElementById(containerId);
    this.statusEl = document.getElementById(statusId);
    this.loader = new GLTFLoader();

    this.scene = new THREE.Scene();
    this.scene.background = new THREE.Color('#e5e7eb');
    this.camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
    this.camera.position.set(0, 0.6, 2.2);

    this.renderer = new THREE.WebGLRenderer({ antialias: true });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    this.container.appendChild(this.renderer.domElement);

    this.controls = new OrbitControls(this.camera, this.renderer.domElement);
    this.controls.enableDamping = true;

    this.scene.add(new THREE.HemisphereLight(0xffffff, 0x334155, 1.1));
    const dir = new THREE.DirectionalLight(0xffffff, 1.0);
    dir.position.set(2, 3, 2);
    this.scene.add(dir);

    this.modelRoot = null;
    this.designPlane = null;
    this.patchBackingPlane = null;
    this.modelKey = 'tshirt';
    this.activeZone = null;
    this.activeZoneConfig = null;
    this.applicationMode = 'embroidery_preview';
    this.patchStyle = { ...DEFAULT_PATCH_STYLE };

    this.resize();
    window.addEventListener('resize', () => this.resize());
    this.animate();
  }

  setStatus(text) {
    this.statusEl.textContent = text;
  }

  resize() {
    const w = this.container.clientWidth;
    const h = this.container.clientHeight;
    this.camera.aspect = w / h;
    this.camera.updateProjectionMatrix();
    this.renderer.setSize(w, h);
  }

  animate() {
    requestAnimationFrame(() => this.animate());
    this.controls.update();
    this.renderer.render(this.scene, this.camera);
  }

  async loadModel(url) {
    this.setStatus('Loading model...');
    if (this.modelRoot) this.scene.remove(this.modelRoot);

    const gltf = await this.loader.loadAsync(url);
    this.modelRoot = gltf.scene;
    this.scene.add(this.modelRoot);
    this.fitToView(this.modelRoot);
    this.setStatus('Model ready');
  }

  fitToView(object3D) {
    const box = new THREE.Box3().setFromObject(object3D);
    const center = box.getCenter(new THREE.Vector3());
    const size = box.getSize(new THREE.Vector3()).length();
    this.controls.target.copy(center);
    this.camera.position.set(center.x, center.y + size * 0.2, center.z + size * 1.2);
    this.camera.lookAt(center);
  }

  setModelKey(modelKey) {
    this.modelKey = modelKey || 'tshirt';
  }

  setZone(zoneConfig) {
    this.activeZone = zoneConfig?.zone_key || null;
    this.activeZoneConfig = zoneConfig || null;
    this.applyZoneTransform();
  }

  setApplicationMode(mode) {
    this.applicationMode = mode === 'patch' ? 'patch' : 'embroidery_preview';
    this.applyZoneTransform();
  }

  setPatchStyle(style = {}) {
    this.patchStyle = {
      borderColor: typeof style.borderColor === 'string' ? style.borderColor : DEFAULT_PATCH_STYLE.borderColor,
      thickness: Number.isFinite(Number(style.thickness)) ? Math.min(12, Math.max(1, Number(style.thickness))) : DEFAULT_PATCH_STYLE.thickness
    };
    this.applyZoneTransform();
  }

  ensurePatchMesh() {
    if (!this.patchBackingPlane) {
      const geometry = new THREE.PlaneGeometry(1, 1);
      const material = new THREE.MeshStandardMaterial({
        color: new THREE.Color(this.patchStyle.borderColor),
        roughness: 0.9,
        metalness: 0.05,
        side: THREE.DoubleSide
      });
      this.patchBackingPlane = new THREE.Mesh(geometry, material);
      this.scene.add(this.patchBackingPlane);
    }
    this.patchBackingPlane.material.color = new THREE.Color(this.patchStyle.borderColor);
    this.patchBackingPlane.visible = this.applicationMode === 'patch';
  }

  applyTexture(dataUrl) {
    if (!this.designPlane) {
      const geometry = new THREE.PlaneGeometry(1, 1);
      const material = new THREE.MeshBasicMaterial({ transparent: true, side: THREE.DoubleSide });
      this.designPlane = new THREE.Mesh(geometry, material);
      this.scene.add(this.designPlane);
    }

    const texture = new THREE.TextureLoader().load(dataUrl);
    texture.colorSpace = THREE.SRGBColorSpace;
    this.designPlane.material.map = texture;
    this.designPlane.material.needsUpdate = true;
    
    this.ensurePatchMesh();
    this.applyZoneTransform();
    this.setStatus('Texture synced');
  }

  applyZoneTransform() {
    if (!this.designPlane || !this.modelRoot) return;

    const box = new THREE.Box3().setFromObject(this.modelRoot);
    const min = box.min;
    const max = box.max;
    const zoneConfig = this.activeZoneConfig || getZoneByKey(this.modelKey, this.activeZone);
    if (!zoneConfig?.transform_defaults) return;
    const zone = zoneConfig.transform_defaults;

    const x = min.x + (max.x - min.x) * zone.x;
    const y = min.y + (max.y - min.y) * zone.y;

    const modelWidth = max.x - min.x;
    const planeSize = Math.max(modelWidth * zone.scale, 0.08);
    const isPatch = this.applicationMode === 'patch';
    const baseOffset = isPatch ? 0.018 : 0.004;
    const patchThicknessOffset = isPatch ? (this.patchStyle.thickness / 1000) : 0;
    const z = max.z + baseOffset + patchThicknessOffset;

    this.designPlane.position.set(x, y, z + (isPatch ? 0.003 : 0));
    this.designPlane.scale.set(planeSize, planeSize, 1);
    this.designPlane.rotation.set(0, zone.rotation, 0);
    
    this.ensurePatchMesh();
    if (this.patchBackingPlane) {
      this.patchBackingPlane.position.set(x, y, z);
      const borderScale = 1 + Math.max(0.02, this.patchStyle.thickness / 100);
      this.patchBackingPlane.scale.set(planeSize * borderScale, planeSize * borderScale, 1);
      this.patchBackingPlane.rotation.set(0, zone.rotation, 0);
      this.patchBackingPlane.visible = isPatch;
    }
  }

  getPlacementState() {
    return {
      placement_key: this.activeZone,
      zone_id: this.activeZoneConfig?.id || null,
      model_key: this.modelKey,
      placement_type: this.activeZoneConfig?.placement_type || null,
      application_mode: this.applicationMode,
      patch_style: this.applicationMode === 'patch' ? this.patchStyle : null,
      transform: this.activeZoneConfig?.transform_defaults || null
    };
  }
}
