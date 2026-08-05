/*
 * Naija Virtual Notary — notarization editor
 *
 * Renders the source PDF with PDF.js, lets the notary place/move/resize asset
 * images (signature/stamp/seal) and click-to-type text on any page, and saves
 * placements as coordinates NORMALIZED to each page (0..1) so the server can
 * stamp them onto the real PDF at any scale.
 */
(function () {
  const cfg = window.NVN_EDITOR;
  if (!cfg || !window.pdfjsLib) return;

  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

  const wrap = document.getElementById('pdf-wrap');
  const statusEl = document.getElementById('editor-status');
  const pageEls = []; // { page, el(overlay), width, height }
  let placements = [];
  let activeTool = null; // { tool:'asset'|'text', assetId, assetUrl, text }

  // ---- Tool selection ----
  document.querySelectorAll('.tool').forEach((btn) => {
    btn.addEventListener('click', () => {
      activeTool = {
        tool: btn.dataset.tool,
        assetId: btn.dataset.assetId ? Number(btn.dataset.assetId) : null,
        assetUrl: btn.dataset.assetUrl || null,
        text: btn.dataset.text || '',
      };
      document.querySelectorAll('.tool').forEach((b) => (b.style.outline = ''));
      btn.style.outline = '2px solid var(--brand)';
      setStatus('Tool selected — click on the document to place it.');
    });
  });

  function setStatus(msg) { if (statusEl) statusEl.textContent = msg; }

  // ---- Render the PDF ----
  pdfjsLib.getDocument(cfg.documentUrl).promise.then(async (pdf) => {
    for (let n = 1; n <= pdf.numPages; n++) {
      const page = await pdf.getPage(n);
      const viewport = page.getViewport({ scale: 1.3 });

      const pageHolder = document.createElement('div');
      pageHolder.style.cssText =
        'position:relative; margin:0 auto 14px; width:' + viewport.width + 'px; background:#fff; box-shadow:0 1px 6px rgba(0,0,0,.4);';

      const canvas = document.createElement('canvas');
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      pageHolder.appendChild(canvas);

      const overlay = document.createElement('div');
      overlay.style.cssText = 'position:absolute; inset:0; cursor:crosshair;';
      overlay.dataset.page = n;
      pageHolder.appendChild(overlay);

      wrap.appendChild(pageHolder);
      await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;

      pageEls.push({ page: n, el: overlay, width: viewport.width, height: viewport.height });
      overlay.addEventListener('click', (e) => onPlace(e, n, overlay));
    }

    // Restore existing placements
    (cfg.existing || []).forEach((p) => addPlacementEl(p, true));
  }).catch((err) => setStatus('Could not load the document: ' + err.message));

  // ---- Place on click ----
  function onPlace(e, pageNo, overlay) {
    if (!activeTool) { setStatus('Pick a tool first.'); return; }
    const rect = overlay.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;

    let text = activeTool.text;
    if (activeTool.tool === 'text') {
      text = prompt('Enter text:', activeTool.text || '');
      if (text === null) return;
    }

    const p = {
      type: activeTool.tool,
      asset_id: activeTool.assetId,
      asset_url: activeTool.assetUrl,
      text_value: activeTool.tool === 'text' ? text : null,
      page: pageNo,
      x: x,
      y: y,
      width: activeTool.tool === 'asset' ? 0.18 : null,
      height: activeTool.tool === 'asset' ? 0.08 : null,
    };
    placements.push(p);
    addPlacementEl(p, false);
  }

  // ---- Render a placement element (draggable + resizable) ----
  function addPlacementEl(p, isExisting) {
    const pageMeta = pageEls.find((m) => m.page === p.page);
    if (!pageMeta) return;
    if (isExisting) placements.push(p);

    const el = document.createElement('div');
    el.style.cssText =
      'position:absolute; border:1px dashed rgba(24,95,165,.8); background:rgba(24,95,165,.05);' +
      'left:' + (p.x * 100) + '%; top:' + (p.y * 100) + '%; cursor:move;';

    if (p.type === 'asset') {
      el.style.width = (p.width * 100) + '%';
      el.style.height = (p.height * 100) + '%';
      const img = document.createElement('img');
      img.src = p.asset_url || '';
      img.style.cssText = 'width:100%; height:100%; object-fit:contain; pointer-events:none;';
      el.appendChild(img);
      addResizeHandle(el, p, pageMeta);
    } else {
      el.style.padding = '2px 4px';
      el.style.font = '13px Poppins, sans-serif';
      el.style.color = '#0f172a';
      el.textContent = p.text_value || '';
    }

    addRemoveHandle(el, p);
    makeDraggable(el, p, pageMeta);
    pageMeta.el.appendChild(el);
  }

  function makeDraggable(el, p, pageMeta) {
    let startX, startY, origX, origY;
    el.addEventListener('mousedown', (e) => {
      if (e.target.dataset.handle) return;
      e.stopPropagation();
      startX = e.clientX; startY = e.clientY; origX = p.x; origY = p.y;
      const move = (ev) => {
        const dx = (ev.clientX - startX) / pageMeta.width;
        const dy = (ev.clientY - startY) / pageMeta.height;
        p.x = Math.min(1, Math.max(0, origX + dx));
        p.y = Math.min(1, Math.max(0, origY + dy));
        el.style.left = (p.x * 100) + '%';
        el.style.top = (p.y * 100) + '%';
      };
      const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
    });
  }

  function addResizeHandle(el, p, pageMeta) {
    const h = document.createElement('div');
    h.dataset.handle = 'resize';
    h.style.cssText =
      'position:absolute; right:-6px; bottom:-6px; width:12px; height:12px; background:var(--brand); border-radius:50%; cursor:se-resize;';
    el.appendChild(h);
    h.addEventListener('mousedown', (e) => {
      e.stopPropagation();
      const sx = e.clientX, sy = e.clientY, ow = p.width, oh = p.height;
      const move = (ev) => {
        p.width = Math.min(1, Math.max(0.03, ow + (ev.clientX - sx) / pageMeta.width));
        p.height = Math.min(1, Math.max(0.02, oh + (ev.clientY - sy) / pageMeta.height));
        el.style.width = (p.width * 100) + '%';
        el.style.height = (p.height * 100) + '%';
      };
      const up = () => { document.removeEventListener('mousemove', move); document.removeEventListener('mouseup', up); };
      document.addEventListener('mousemove', move);
      document.addEventListener('mouseup', up);
    });
  }

  function addRemoveHandle(el, p) {
    const x = document.createElement('div');
    x.dataset.handle = 'remove';
    x.textContent = '×';
    x.style.cssText =
      'position:absolute; right:-8px; top:-10px; width:18px; height:18px; line-height:16px; text-align:center;' +
      'background:#a12626; color:#fff; border-radius:50%; cursor:pointer; font-size:13px;';
    el.appendChild(x);
    x.addEventListener('click', (e) => {
      e.stopPropagation();
      placements = placements.filter((q) => q !== p);
      el.remove();
    });
  }

  // ---- Save ----
  document.getElementById('save-btn').addEventListener('click', save);

  function save() {
    setStatus('Saving…');
    const payload = placements.map((p) => ({
      type: p.type,
      asset_id: p.asset_id,
      text_value: p.text_value,
      page: p.page,
      x: round(p.x), y: round(p.y),
      width: p.width != null ? round(p.width) : null,
      height: p.height != null ? round(p.height) : null,
    }));

    fetch(cfg.saveUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'Accept': 'application/json' },
      body: JSON.stringify({ placements: payload }),
    })
      .then((r) => r.ok ? r.json() : Promise.reject(r))
      .then((d) => setStatus('Saved ' + d.saved + ' placement(s).'))
      .catch(() => setStatus('Save failed. Please try again.'));
  }

  function round(v) { return Math.round(v * 10000) / 10000; }

  // Save automatically before finalizing
  document.querySelector('form[action$="/finalize"]').addEventListener('submit', function () {
    save();
  });
})();
