/**
 * NVN Notarize Editor
 * Supports PDF (PDF.js), images (JPG/PNG), and DOCX (mammoth.js).
 *
 * COORDINATE CONTRACT
 * -------------------
 * x / y      normalized (0–1) TOP-LEFT corner of the item, relative to the page box.
 * width/height  normalized (0–1) size of the item, relative to page width / height.
 *
 * This matches PdfNotarizationService::renderPlacement(), which passes x/y straight
 * into TCPDF's Image()/SetXY() — both of which anchor at the top-left.
 */
(function () {
    'use strict';

    var cfg        = window.NVN_EDITOR;
    var placements = [];
    var activeTool = null;
    var selected   = null;
    var pages      = {};   // pageNum → { el, overlay, width, height }
    var dirty      = false;

    /* Clicks that immediately follow a drag/resize must not place a new item. */
    var suppressClickUntil = 0;

    var MIN_SIZE = 18;     // px — smallest an item may be resized to

    var wrap      = document.getElementById('pdf-wrap');
    var loadingEl = document.getElementById('pdf-loading');
    var statusEl  = document.getElementById('editor-status');

    function setStatus(msg, isError) {
        if (statusEl) {
            statusEl.textContent = msg;
            statusEl.style.color = isError ? 'var(--danger)' : '';
        }
    }

    function hideLoading() {
        if (loadingEl) { loadingEl.remove(); loadingEl = null; }
    }

    function markDirty() {
        dirty = true;
        setStatus('Unsaved changes — they are saved automatically when you finalize.');
    }

    function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

    /* ── Route by file type ──────────────────────────────────────────── */

    var ext = (cfg.fileExt || '').toLowerCase();

    if (ext === 'pdf') {
        loadPdf();
    } else if (ext === 'jpg' || ext === 'jpeg' || ext === 'png' || ext === 'gif' || ext === 'webp') {
        loadImage();
    } else if (ext === 'docx') {
        loadDocx();
    } else if (ext === 'doc') {
        showDocFallback();
    } else {
        // Try PDF.js as a last resort
        loadPdf();
    }

    /* ══════════════════════════════════════════════════════════════════
       PDF — PDF.js renderer
    ══════════════════════════════════════════════════════════════════ */

    function loadPdf() {
        if (typeof pdfjsLib === 'undefined') {
            setStatus('PDF viewer not loaded. Please refresh.', true);
            return;
        }
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

        var task = pdfjsLib.getDocument({ url: cfg.documentUrl, withCredentials: true });
        task.promise.then(function (doc) {
            hideLoading();
            var chain = Promise.resolve();
            for (var i = 1; i <= doc.numPages; i++) {
                (function (n) { chain = chain.then(function () { return renderPdfPage(doc, n); }); })(i);
            }
            chain.then(function () { loadExistingPlacements(); });
        }).catch(function (err) {
            hideLoading();
            setStatus('Could not load PDF: ' + err.message, true);
        });
    }

    function renderPdfPage(pdfDoc, pageNum) {
        return pdfDoc.getPage(pageNum).then(function (page) {
            var baseVp = page.getViewport({ scale: 1 });
            var maxW   = (wrap.parentElement ? wrap.parentElement.clientWidth : 800) - 56;
            var scale  = Math.min(maxW / baseVp.width, 2.0);
            var vp     = page.getViewport({ scale: scale });

            var canvas   = document.createElement('canvas');
            canvas.width = vp.width; canvas.height = vp.height;
            canvas.style.cssText = 'display:block;width:100%;height:100%;';

            var pageDiv = makePageDiv(vp.width, vp.height, pageNum);
            pageDiv.insertBefore(canvas, pageDiv.firstChild);

            return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
        });
    }

    /* ══════════════════════════════════════════════════════════════════
       IMAGE — single-page overlay
    ══════════════════════════════════════════════════════════════════ */

    function loadImage() {
        var img = new window.Image();
        img.crossOrigin = 'use-credentials';
        img.onload = function () {
            hideLoading();
            var maxW  = (wrap.parentElement ? wrap.parentElement.clientWidth : 800) - 56;
            var scale = Math.min(maxW / img.naturalWidth, 2.0);
            var w     = Math.round(img.naturalWidth  * scale);
            var h     = Math.round(img.naturalHeight * scale);

            var imgEl       = document.createElement('img');
            imgEl.src       = cfg.documentUrl;
            imgEl.style.cssText = 'display:block;width:100%;height:100%;pointer-events:none;';

            var pageDiv = makePageDiv(w, h, 1);
            pageDiv.insertBefore(imgEl, pageDiv.firstChild);

            loadExistingPlacements();
        };
        img.onerror = function () {
            hideLoading();
            setStatus('Could not load image document. It may be corrupted or inaccessible.', true);
        };
        img.src = cfg.documentUrl;
    }

    /* ══════════════════════════════════════════════════════════════════
       DOCX — mammoth.js → HTML renderer
    ══════════════════════════════════════════════════════════════════ */

    function loadDocx() {
        if (typeof mammoth === 'undefined') {
            setStatus('DOCX renderer not loaded. Please refresh.', true);
            return;
        }
        if (loadingEl) loadingEl.textContent = 'Converting DOCX…';

        fetch(cfg.documentUrl, { credentials: 'include' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.arrayBuffer();
            })
            .then(function (buf) { return mammoth.convertToHtml({ arrayBuffer: buf }); })
            .then(function (result) {
                hideLoading();
                var maxW = (wrap.parentElement ? wrap.parentElement.clientWidth : 800) - 56;
                var w    = Math.min(maxW, 794); // A4-ish width
                var h    = 1123;                // A4-ish height minimum; will grow

                var contentDiv  = document.createElement('div');
                contentDiv.innerHTML = result.value || '<p style="color:#888">No content extracted.</p>';
                contentDiv.style.cssText = [
                    'padding:40px 50px',
                    'font-family:Georgia,serif',
                    'font-size:14px',
                    'line-height:1.7',
                    'color:#1f2933',
                    'background:#fff',
                    'box-sizing:border-box',
                    'width:100%',
                    'min-height:' + h + 'px',
                    'pointer-events:none',
                ].join(';');

                var pageDiv = makePageDiv(w, h, 1);
                // Height should stretch to content; update after rendering
                pageDiv.style.height = 'auto';
                pageDiv.style.minHeight = h + 'px';
                pageDiv.insertBefore(contentDiv, pageDiv.firstChild);

                // After content renders, update overlay height to match
                requestAnimationFrame(function () {
                    var actualH = pageDiv.offsetHeight;
                    pages[1].height = actualH;
                    var ov = pages[1].overlay;
                    ov.style.height = actualH + 'px';
                });

                if (result.messages && result.messages.length) {
                    setStatus('DOCX loaded with ' + result.messages.length + ' formatting note(s). Some styles may not render exactly.');
                }

                loadExistingPlacements();
            })
            .catch(function (err) {
                hideLoading();
                setStatus('Could not convert DOCX: ' + err.message, true);
            });
    }

    /* ══════════════════════════════════════════════════════════════════
       DOC — no browser renderer; show empty canvas with warning
    ══════════════════════════════════════════════════════════════════ */

    function showDocFallback() {
        hideLoading();
        var maxW = (wrap.parentElement ? wrap.parentElement.clientWidth : 800) - 56;
        var w    = Math.min(maxW, 794);
        var h    = 1123;

        var notice = document.createElement('div');
        notice.style.cssText = [
            'display:flex', 'flex-direction:column', 'align-items:center',
            'justify-content:center', 'gap:12px', 'height:100%',
            'color:#888', 'font-size:13px', 'text-align:center', 'padding:40px',
        ].join(';');
        notice.innerHTML = [
            '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">',
            '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/>',
            '<polyline points="14 2 14 8 20 8"/></svg>',
            '<strong style="color:#555">.DOC format cannot be previewed in the browser.</strong>',
            '<span>You can still place your signature, stamp and other elements below — they will be applied when you finalize. <a href="' + cfg.documentUrl + '" target="_blank" style="color:var(--brand)">Download original</a> to review in Word.</span>',
        ].join('');

        var pageDiv = makePageDiv(w, h, 1);
        pageDiv.style.background = '#f5f5f5';
        pageDiv.insertBefore(notice, pageDiv.firstChild);

        loadExistingPlacements();
    }

    /* ══════════════════════════════════════════════════════════════════
       Shared page/overlay creation
    ══════════════════════════════════════════════════════════════════ */

    function makePageDiv(w, h, pageNum) {
        var pageDiv = document.createElement('div');
        pageDiv.className = 'nvn-page';
        pageDiv.style.cssText = [
            'position:relative',
            'width:' + w + 'px',
            'height:' + h + 'px',
            'margin:0 auto 14px',
            'background:#fff',
            'box-shadow:0 2px 12px rgba(0,0,0,.3)',
            'overflow:hidden',
        ].join(';');

        var overlay = document.createElement('div');
        overlay.className = 'nvn-overlay';
        overlay.dataset.page = pageNum;
        overlay.style.cssText = [
            'position:absolute',
            'inset:0',
            'z-index:3',
        ].join(';');

        if (pageNum > 1) {
            var label = document.createElement('div');
            label.style.cssText = 'position:absolute;bottom:-20px;left:0;right:0;text-align:center;font-size:11px;color:rgba(255,255,255,.5);pointer-events:none;';
            label.textContent = 'Page ' + pageNum;
            wrap.appendChild(label);
        }

        pageDiv.appendChild(overlay);
        wrap.appendChild(pageDiv);

        pages[pageNum] = { el: pageDiv, overlay: overlay, width: w, height: h };

        wireOverlay(overlay, pageNum);
        return pageDiv;
    }

    /* ══════════════════════════════════════════════════════════════════
       Drop / click wiring
    ══════════════════════════════════════════════════════════════════ */

    function wireOverlay(overlay, pageNum) {
        overlay.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            overlay.style.background = 'rgba(84,180,53,.09)';
        });
        overlay.addEventListener('dragleave', function () {
            overlay.style.background = '';
        });
        overlay.addEventListener('drop', function (e) {
            e.preventDefault();
            overlay.style.background = '';
            try {
                var raw = e.dataTransfer.getData('application/nvn-tool');
                if (!raw) return;
                var toolData = JSON.parse(raw);
                var rect = overlay.getBoundingClientRect();
                placeTool(toolData, pageNum,
                    (e.clientX - rect.left) / rect.width,
                    (e.clientY - rect.top)  / getOverlayHeight(pageNum));
            } catch (_) {}
        });

        overlay.addEventListener('click', function (e) {
            /* Clicks that landed on an existing item (or its handles) select — never place.
               Without this guard, repositioning an item while a tool was armed dropped a
               duplicate underneath it. */
            if (e.target !== overlay) return;

            /* A drag or resize that ended on this overlay also fires a click. Swallow it. */
            if (Date.now() < suppressClickUntil) return;

            if (!activeTool) { select(null); return; }

            var rect = overlay.getBoundingClientRect();
            placeTool(activeTool, pageNum,
                (e.clientX - rect.left) / rect.width,
                (e.clientY - rect.top)  / getOverlayHeight(pageNum));

            /* One click, one item. Drag the tool from the toolbar to place several. */
            setActiveTool(null);
        });
    }

    function getOverlayHeight(pageNum) {
        var pg = pages[pageNum];
        return pg ? pg.overlay.offsetHeight || pg.height : 1;
    }

    /* ══════════════════════════════════════════════════════════════════
       Placement creation
    ══════════════════════════════════════════════════════════════════ */

    function placeTool(tool, pageNum, cx, cy) {
        var textValue = (tool.textValue !== undefined && tool.textValue !== null) ? tool.textValue : null;

        if (tool.tool === 'text' && (textValue === '' || textValue === null)) {
            textValue = prompt('Enter text to place on the document:');
            if (!textValue || !textValue.trim()) return;
            textValue = textValue.trim();
        }

        var p = {
            _id:        'p' + Date.now() + Math.random().toString(36).slice(2),
            type:       tool.tool === 'asset' ? 'asset' : 'text',
            asset_id:   tool.tool === 'asset' ? (tool.assetId || null) : null,
            asset_url:  tool.tool === 'asset' ? (tool.assetUrl || null) : null,
            text_value: textValue,
            page:       pageNum,
            /* Held as the CENTER only until renderPlacement sizes the box and
               converts to the top-left contract. */
            x: clamp(cx, 0, 1),
            y: clamp(cy, 0, 1),
            width: null, height: null,
        };

        placements.push(p);
        renderPlacement(p, true);
        markDirty();
    }

    /* ══════════════════════════════════════════════════════════════════
       Render a placement element
    ══════════════════════════════════════════════════════════════════ */

    /**
     * @param {object}  p          the placement record
     * @param {boolean} centerMode true → p.x/p.y are a centre point to be converted
     *                             to top-left once the box has been sized.
     */
    function renderPlacement(p, centerMode) {
        var pg = pages[p.page];
        if (!pg) return;

        var W = pg.overlay.offsetWidth  || pg.width;
        var H = pg.overlay.offsetHeight || pg.height;

        var el = document.createElement('div');
        el.className = 'nvn-placement nvn-placement--' + p.type;
        el.dataset.pid = p._id;

        /* × delete button */
        var del = document.createElement('button');
        del.type = 'button';
        del.className = 'del-btn';
        del.innerHTML = '&times;';
        del.title = 'Remove this item';
        del.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        del.addEventListener('click', function (e) {
            e.stopPropagation();
            removePlacement(p, el);
        });
        el.appendChild(del);

        if (p.type === 'asset' && p.asset_url) {
            var img = document.createElement('img');
            img.src = p.asset_url;
            img.className = 'nvn-placement-img';
            img.onload = function () {
                var aspect = (img.naturalWidth / img.naturalHeight) || 1;
                var w, h;
                if (p.width && p.height) {
                    w = p.width  * W;
                    h = p.height * H;
                } else {
                    w = Math.min(0.22 * W, 160);
                    h = w / aspect;
                }
                applyBox(p, el, W, H, w, h, centerMode);
            };
            img.onerror = function () {
                el.classList.add('is-broken');
                var warn = document.createElement('span');
                warn.textContent = '⚠ Image';
                el.appendChild(warn);
                applyBox(p, el, W, H, 100, 40, centerMode);
            };
            el.appendChild(img);
        } else {
            /* text / initials / date */
            var span = document.createElement('span');
            span.className = 'nvn-placement-text';
            span.textContent = p.text_value || '';
            el.appendChild(span);

            /* Size to the natural text extent first, then freeze into an explicit box. */
            el.style.width  = 'auto';
            el.style.height = 'auto';
            requestAnimationFrame(function () {
                var w, h;
                if (p.width && p.height) {
                    w = p.width  * W;
                    h = p.height * H;
                } else {
                    w = el.offsetWidth  || 80;
                    h = el.offsetHeight || 24;
                }
                applyBox(p, el, W, H, w, h, centerMode);
            });
        }

        el.addEventListener('click', function (e) {
            /* Never let a click on an item reach the overlay — that is what used to
               create a duplicate when an armed tool was still selected. */
            e.stopPropagation();
            if (Date.now() < suppressClickUntil) return;
            select(p);
        });
        el.addEventListener('dblclick', function (e) {
            e.stopPropagation();
            removePlacement(p, el);
        });

        makeDraggable(el, p, pg);
        makeResizable(el, p, pg);

        pg.overlay.appendChild(el);
        p._el = el;
    }

    /**
     * Commit a pixel box onto the element and write the normalized values back
     * onto the placement record. Always stores TOP-LEFT + size.
     */
    function applyBox(p, el, W, H, w, h, centerMode) {
        w = Math.max(MIN_SIZE, w);
        h = Math.max(MIN_SIZE, h);

        var left = centerMode ? (p.x * W - w / 2) : (p.x * W);
        var top  = centerMode ? (p.y * H - h / 2) : (p.y * H);

        left = clamp(left, 0, Math.max(0, W - w));
        top  = clamp(top,  0, Math.max(0, H - h));

        el.style.width  = w    + 'px';
        el.style.height = h    + 'px';
        el.style.left   = left + 'px';
        el.style.top    = top  + 'px';

        p.x      = left / W;
        p.y      = top  / H;
        p.width  = w    / W;
        p.height = h    / H;

        if (p.type === 'text') syncTextSize(el, h);
    }

    /** Read the element's current pixel box back into the placement record. */
    function commitBox(p, el, pg) {
        var W = pg.overlay.offsetWidth  || pg.width;
        var H = pg.overlay.offsetHeight || pg.height;

        p.x      = clamp((parseFloat(el.style.left) || 0) / W, 0, 1);
        p.y      = clamp((parseFloat(el.style.top)  || 0) / H, 0, 1);
        p.width  = clamp(el.offsetWidth  / W, 0, 1);
        p.height = clamp(el.offsetHeight / H, 0, 1);

        markDirty();
    }

    /** Text scales with its box, so resizing an initials/date item changes its size. */
    function syncTextSize(el, h) {
        var span = el.querySelector('.nvn-placement-text');
        if (span) span.style.fontSize = Math.max(8, h * 0.58) + 'px';
    }

    function removePlacement(p, el) {
        placements = placements.filter(function (q) { return q._id !== p._id; });
        if (selected === p) selected = null;
        el.remove();
        markDirty();
    }

    /* ══════════════════════════════════════════════════════════════════
       Selection
    ══════════════════════════════════════════════════════════════════ */

    function select(p) {
        if (selected && selected._el) selected._el.classList.remove('is-selected');
        selected = p;
        if (p && p._el) p._el.classList.add('is-selected');
    }

    /* Delete / Backspace removes the selected item; arrows nudge it. */
    document.addEventListener('keydown', function (e) {
        if (!selected || !selected._el) return;
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

        if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            removePlacement(selected, selected._el);
            return;
        }

        var step = e.shiftKey ? 10 : 1;
        var dx = 0, dy = 0;
        if (e.key === 'ArrowLeft')  dx = -step;
        else if (e.key === 'ArrowRight') dx = step;
        else if (e.key === 'ArrowUp')    dy = -step;
        else if (e.key === 'ArrowDown')  dy = step;
        else return;

        e.preventDefault();
        var el = selected._el;
        el.style.left = ((parseFloat(el.style.left) || 0) + dx) + 'px';
        el.style.top  = ((parseFloat(el.style.top)  || 0) + dy) + 'px';
        commitBox(selected, el, pages[selected.page]);
    });

    /* ══════════════════════════════════════════════════════════════════
       Drag-to-reposition
    ══════════════════════════════════════════════════════════════════ */

    function makeDraggable(el, p, pg) {
        function begin(startX, startY) {
            var startLeft = parseFloat(el.style.left) || 0;
            var startTop  = parseFloat(el.style.top)  || 0;
            var moved     = false;

            return {
                move: function (cx, cy) {
                    var dx = cx - startX, dy = cy - startY;
                    if (Math.abs(dx) > 2 || Math.abs(dy) > 2) moved = true;

                    var W = pg.overlay.offsetWidth  || pg.width;
                    var H = pg.overlay.offsetHeight || pg.height;
                    el.style.left = clamp(startLeft + dx, 0, Math.max(0, W - el.offsetWidth))  + 'px';
                    el.style.top  = clamp(startTop  + dy, 0, Math.max(0, H - el.offsetHeight)) + 'px';
                },
                end: function () {
                    commitBox(p, el, pg);
                    /* Suppress the click the browser fires after the drag, so an
                       armed tool cannot drop a duplicate at the release point. */
                    if (moved) suppressClickUntil = Date.now() + 250;
                },
            };
        }

        el.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            if (e.target.classList.contains('nvn-handle')) return;   // resize owns this
            e.stopPropagation(); e.preventDefault();

            select(p);
            var drag = begin(e.clientX, e.clientY);
            function mm(ev) { drag.move(ev.clientX, ev.clientY); }
            function mu()   {
                drag.end();
                document.removeEventListener('mousemove', mm);
                document.removeEventListener('mouseup',   mu);
            }
            document.addEventListener('mousemove', mm);
            document.addEventListener('mouseup',   mu);
        });

        el.addEventListener('touchstart', function (e) {
            if (e.target.classList.contains('nvn-handle')) return;
            e.stopPropagation();
            e.preventDefault();

            select(p);
            var t0   = e.touches[0];
            var drag = begin(t0.clientX, t0.clientY);
            function tm(ev) { ev.preventDefault(); var t = ev.touches[0]; drag.move(t.clientX, t.clientY); }
            function te()   {
                drag.end();
                el.removeEventListener('touchmove', tm);
                el.removeEventListener('touchend',  te);
            }
            el.addEventListener('touchmove', tm, { passive: false });
            el.addEventListener('touchend',  te);
        }, { passive: false });
    }

    /* ══════════════════════════════════════════════════════════════════
       Resize — four corner handles, aspect-locked (hold Shift to distort)
    ══════════════════════════════════════════════════════════════════ */

    function makeResizable(el, p, pg) {
        ['nw', 'ne', 'sw', 'se'].forEach(function (corner) {
            var handle = document.createElement('span');
            handle.className = 'nvn-handle nvn-handle--' + corner;
            handle.dataset.corner = corner;
            el.appendChild(handle);

            function begin(startX, startY) {
                var startW    = el.offsetWidth;
                var startH    = el.offsetHeight;
                var startLeft = parseFloat(el.style.left) || 0;
                var startTop  = parseFloat(el.style.top)  || 0;
                var aspect    = startH > 0 ? startW / startH : 1;

                /* Which way each axis grows for this corner. */
                var signX = (corner === 'ne' || corner === 'se') ?  1 : -1;
                var signY = (corner === 'sw' || corner === 'se') ?  1 : -1;

                return function (cx, cy, freeform) {
                    var dx = (cx - startX) * signX;
                    var dy = (cy - startY) * signY;

                    var w = startW + dx;
                    var h = startH + dy;

                    if (!freeform) {
                        /* Aspect lock: let the axis the user moved furthest win. */
                        if (Math.abs(dx) >= Math.abs(dy)) h = w / aspect;
                        else                              w = h * aspect;
                    }

                    w = Math.max(MIN_SIZE, w);
                    h = Math.max(MIN_SIZE, h);

                    /* Corners anchored on the left/top edge move that edge instead. */
                    var left = (signX < 0) ? startLeft + (startW - w) : startLeft;
                    var top  = (signY < 0) ? startTop  + (startH - h) : startTop;

                    el.style.width  = w    + 'px';
                    el.style.height = h    + 'px';
                    el.style.left   = left + 'px';
                    el.style.top    = top  + 'px';

                    if (p.type === 'text') syncTextSize(el, h);
                };
            }

            handle.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return;
                e.stopPropagation(); e.preventDefault();

                select(p);
                var resize = begin(e.clientX, e.clientY);
                function mm(ev) { resize(ev.clientX, ev.clientY, ev.shiftKey); }
                function mu()   {
                    commitBox(p, el, pg);
                    suppressClickUntil = Date.now() + 250;
                    document.removeEventListener('mousemove', mm);
                    document.removeEventListener('mouseup',   mu);
                }
                document.addEventListener('mousemove', mm);
                document.addEventListener('mouseup',   mu);
            });

            handle.addEventListener('touchstart', function (e) {
                e.stopPropagation(); e.preventDefault();

                select(p);
                var t0     = e.touches[0];
                var resize = begin(t0.clientX, t0.clientY);
                function tm(ev) { ev.preventDefault(); var t = ev.touches[0]; resize(t.clientX, t.clientY, false); }
                function te()   {
                    commitBox(p, el, pg);
                    suppressClickUntil = Date.now() + 250;
                    handle.removeEventListener('touchmove', tm);
                    handle.removeEventListener('touchend',  te);
                }
                handle.addEventListener('touchmove', tm, { passive: false });
                handle.addEventListener('touchend',  te);
            }, { passive: false });
        });
    }

    /* ══════════════════════════════════════════════════════════════════
       Load existing (saved) placements
    ══════════════════════════════════════════════════════════════════ */

    function loadExistingPlacements() {
        if (!Array.isArray(cfg.existing) || !cfg.existing.length) return;
        cfg.existing.forEach(function (row) {
            var p = {
                _id:        'p-saved-' + row.id,
                type:       row.type,
                asset_id:   row.asset_id   || null,
                asset_url:  row.asset_id   ? (cfg.assetUrl + '/' + row.asset_id) : null,
                text_value: row.text_value || null,
                page:       parseInt(row.page, 10) || 1,
                x:          parseFloat(row.x)      || 0,
                y:          parseFloat(row.y)      || 0,
                width:      row.width  != null ? parseFloat(row.width)  : null,
                height:     row.height != null ? parseFloat(row.height) : null,
            };
            placements.push(p);
            renderPlacement(p, false);   // stored values are already top-left
        });
        setStatus(cfg.existing.length + ' saved placement(s) loaded.');
    }

    /* ══════════════════════════════════════════════════════════════════
       Save
    ══════════════════════════════════════════════════════════════════ */

    function savePlacements() {
        var payload = placements.map(function (p) {
            return {
                type:       p.type,
                asset_id:   p.asset_id   || null,
                text_value: p.text_value || null,
                page:       p.page,
                x:          p.x,
                y:          p.y,
                width:      p.width  || null,
                height:     p.height || null,
            };
        });

        return fetch(cfg.saveUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.csrf },
            body:    JSON.stringify({ placements: payload }),
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (data) {
            dirty = false;
            return data;
        });
    }

    /* A request can have several documents and the tab strip in notarize.blade
       navigates between them. It needs to flush this document's placements
       before leaving, so the two are published here rather than the strip
       having to reimplement the save. */
    cfg.save    = savePlacements;
    cfg.isDirty = function () { return dirty; };

    document.getElementById('save-btn').addEventListener('click', function () {
        setStatus('Saving…');
        savePlacements().then(function (data) {
            setStatus('Saved ' + data.saved + ' placement(s). Click "Finalize & seal" when ready.');
        }).catch(function (err) {
            setStatus('Save failed: ' + err.message, true);
        });
    });

    /* ══════════════════════════════════════════════════════════════════
       Finalize — ALWAYS persists placements first.

       Previously this was a plain form POST, so anything placed but not
       explicitly saved was silently dropped and the sealed PDF came out blank.
    ══════════════════════════════════════════════════════════════════ */

    var finalizeForm = document.getElementById('finalize-form');
    if (finalizeForm) {
        finalizeForm.addEventListener('submit', function (e) {
            if (finalizeForm.dataset.ready === '1') return;   // second pass — let it through
            e.preventDefault();

            if (!placements.length) {
                setStatus('Place at least your signature, stamp or seal before sealing the document.', true);
                return;
            }
            if (!confirm('Finalize and seal this document? All ' + placements.length +
                         ' placement(s) will be baked in and this cannot be undone.')) {
                return;
            }

            var btn = finalizeForm.querySelector('button');
            if (btn) btn.disabled = true;
            setStatus('Saving placements…');

            savePlacements().then(function (data) {
                setStatus('Saved ' + data.saved + ' placement(s). Sealing document…');
                finalizeForm.dataset.ready = '1';
                finalizeForm.submit();
            }).catch(function (err) {
                if (btn) btn.disabled = false;
                setStatus('Could not save placements: ' + err.message + ' — document was NOT sealed.', true);
            });
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (!dirty) return;
        e.preventDefault();
        e.returnValue = '';
    });

    /* ══════════════════════════════════════════════════════════════════
       Toolbar wiring
    ══════════════════════════════════════════════════════════════════ */

    function setActiveTool(tool, btn) {
        document.querySelectorAll('.tool').forEach(function (b) { b.classList.remove('tool-active'); });
        activeTool = tool;

        document.querySelectorAll('.nvn-overlay').forEach(function (ov) {
            ov.style.cursor = tool ? 'crosshair' : 'default';
        });

        if (tool && btn) btn.classList.add('tool-active');
    }

    document.querySelectorAll('.tool').forEach(function (btn) {
        var toolData = {
            tool:      btn.dataset.tool,
            assetId:   btn.dataset.assetId  || null,
            assetUrl:  btn.dataset.assetUrl || null,
            textValue: btn.hasAttribute('data-text') ? btn.dataset.text : null,
        };

        btn.setAttribute('draggable', 'true');
        btn.addEventListener('dragstart', function (e) {
            e.dataTransfer.setData('application/nvn-tool', JSON.stringify(toolData));
            e.dataTransfer.effectAllowed = 'copy';
        });

        btn.addEventListener('click', function () {
            var same = activeTool
                && activeTool.tool      === toolData.tool
                && activeTool.assetId   === toolData.assetId
                && activeTool.textValue === toolData.textValue;

            setActiveTool(same ? null : toolData, btn);
        });
    });

})();
