export class DesignEditor2D {
  constructor({ canvasId, imageInputId, addTextBtnId, textColorId, deleteSelectedBtnId }) {
    this.canvas = new fabric.Canvas(canvasId, { preserveObjectStacking: true });
    this.canvas.setBackgroundColor('#ffffff', () => this.canvas.renderAll());

    this.imageInput = document.getElementById(imageInputId);
    this.addTextBtn = document.getElementById(addTextBtnId);
    this.textColor = document.getElementById(textColorId);
    this.deleteSelectedBtn = document.getElementById(deleteSelectedBtnId);

    this.bindEvents();
  }

  bindEvents() {
    this.imageInput.addEventListener('change', (event) => this.addUploadedImage(event));

    this.addTextBtn.addEventListener('click', () => {
      const text = new fabric.IText('Embroidery Text', {
        left: 150,
        top: 180,
        fontSize: 42,
        fill: this.textColor.value
      });
      this.canvas.add(text);
      this.canvas.setActiveObject(text);
      this.canvas.requestRenderAll();
    });

    this.textColor.addEventListener('input', () => {
      const active = this.canvas.getActiveObject();
      if (active && (active.type === 'i-text' || active.type === 'text')) {
        active.set('fill', this.textColor.value);
        this.canvas.requestRenderAll();
      }
    });

    this.deleteSelectedBtn.addEventListener('click', () => {
      const active = this.canvas.getActiveObject();
      if (!active) return;
      this.canvas.remove(active);
      this.canvas.discardActiveObject();
      this.canvas.requestRenderAll();
    });
  }

  addUploadedImage(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    const reader = new FileReader();

    reader.onload = (loadEvent) => {
      fabric.Image.fromURL(loadEvent.target.result, (img) => {
        const maxSize = 300;
        const scale = Math.min(maxSize / img.width, maxSize / img.height, 1);
        img.set({ left: 160, top: 160, scaleX: scale, scaleY: scale });
        this.canvas.add(img);
        this.canvas.setActiveObject(img);
        this.canvas.requestRenderAll();
      });
    };

    reader.readAsDataURL(file);
    event.target.value = '';
  }

  toJSON() {
    return this.canvas.toJSON();
  }

  loadFromJSON(payload) {
    return new Promise((resolve) => {
      this.canvas.loadFromJSON(payload, () => {
        this.canvas.requestRenderAll();
        resolve();
      });
    });
  }

  toDataURL() {
    return this.canvas.toDataURL({ format: 'png', multiplier: 1 });
  }
}
