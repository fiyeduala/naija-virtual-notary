@extends('layouts.app', ['title' => 'Notarize document'])

@push('styles')
<style>:root { --page-w: 1000px; }</style>
@endpush

@push('styles')
<style>
.tool-active { background: var(--brand-light) !important; border-color: var(--brand) !important; color: var(--brand-dark) !important; }

/* ── Placed item ───────────────────────────────────────────────── */
.nvn-placement {
    position: absolute;
    box-sizing: border-box;
    cursor: move;
    user-select: none;
    -webkit-user-select: none;
    touch-action: none;
    border: 1.5px dashed rgba(84,180,53,.7);
    border-radius: 3px;
    z-index: 5;
    transition: box-shadow .12s, border-color .12s;
}
.nvn-placement:hover { border-color: var(--brand); box-shadow: 0 0 0 2px rgba(84,180,53,.35); z-index: 10; }
.nvn-placement.is-selected {
    border-style: solid;
    border-color: var(--brand);
    box-shadow: 0 0 0 2px rgba(84,180,53,.45);
    z-index: 20;
}
.nvn-placement.is-broken {
    display: flex; align-items: center; justify-content: center;
    background: rgba(255,200,200,.75);
    font-size: 11px;
}

.nvn-placement-img {
    display: block;
    width: 100%; height: 100%;
    object-fit: contain;      /* mirrors the PDF's fitbox — never distorts a stamp */
    pointer-events: none;
}
.nvn-placement--text {
    display: flex; align-items: center;
    padding: 2px 7px;
    background: rgba(255,252,180,.9);
    /* No overflow:hidden here — it would clip the × button and the corner handles,
       which sit outside the box. The inner span does the clipping instead. */
}
.nvn-placement-text {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    font-family: inherit;
    font-weight: 600;
    line-height: 1.1;
    white-space: nowrap;
    pointer-events: none;
}

/* ── Delete button ─────────────────────────────────────────────── */
.nvn-placement .del-btn {
    position: absolute; top: -9px; right: -9px;
    width: 18px; height: 18px;
    background: var(--danger); color: #fff;
    border-radius: 50%; border: none; cursor: pointer;
    font-size: 11px; font-weight: 700; line-height: 1;
    display: none; align-items: center; justify-content: center;
    padding: 0; z-index: 2;
}
.nvn-placement:hover .del-btn,
.nvn-placement.is-selected .del-btn { display: flex; }

/* ── Resize handles ────────────────────────────────────────────── */
.nvn-handle {
    position: absolute;
    width: 11px; height: 11px;
    background: #fff;
    border: 1.5px solid var(--brand);
    border-radius: 2px;
    display: none;
    z-index: 3;
    touch-action: none;
}
.nvn-placement:hover .nvn-handle,
.nvn-placement.is-selected .nvn-handle { display: block; }
.nvn-handle--nw { top: -6px; left:  -6px; cursor: nwse-resize; }
.nvn-handle--ne { top: -6px; right: -6px; cursor: nesw-resize; }
.nvn-handle--sw { bottom: -6px; left:  -6px; cursor: nesw-resize; }
.nvn-handle--se { bottom: -6px; right: -6px; cursor: nwse-resize; }

/* ── Toolbar help text ─────────────────────────────────────────── */
.editor-help {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid var(--line);
    display: grid;
    gap: 5px;
}
.editor-help p { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.55; }
.editor-help strong { color: var(--ink); font-weight: 600; }
.editor-help kbd {
    display: inline-block;
    padding: 1px 5px;
    border: 1px solid var(--line);
    border-bottom-width: 2px;
    border-radius: 4px;
    background: #fff;
    font-family: inherit;
    font-size: 11px;
    font-weight: 600;
    color: var(--ink);
}

#pdf-loading { text-align:center; padding: 40px 0; color: rgba(255,255,255,.7); font-size: 14px; }

/* ── Document tabs ─────────────────────────────────────────────── */
.doc-tabs { display: flex; gap: 8px; flex-wrap: wrap; }
.doc-tab {
    display: inline-flex; align-items: center; gap: 7px;
    max-width: 100%;
    padding: 7px 12px;
    border: 1px solid var(--line);
    border-radius: 7px;
    background: #fff;
    font-size: 12.5px;
    color: var(--ink);
    text-decoration: none;
    transition: border-color .12s, background .12s;
}
.doc-tab:hover { border-color: var(--brand); }
.doc-tab.is-current { border-color: var(--brand); background: var(--brand-light); font-weight: 600; }
.doc-tab-n {
    display: inline-flex; align-items: center; justify-content: center;
    width: 18px; height: 18px; flex-shrink: 0;
    border-radius: 50%;
    background: var(--line);
    font-size: 11px; font-weight: 700;
}
.doc-tab.is-current .doc-tab-n { background: var(--brand); color: #fff; }
.doc-tab-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 220px; }
.doc-tab-flag {
    flex-shrink: 0;
    padding: 1px 6px;
    border-radius: 20px;
    background: rgba(217,119,6,.13);
    color: #b45309;
    font-size: 10.5px; font-weight: 600;
}
</style>
@endpush

@section('content')
@php
    // notary.requests.show is scoped to the assigned notary, so an admin acting as
    // fallback goes to their own dashboard rather than hitting a 403.
    $viewer    = auth()->user();
    $isAssigned = $viewer->notaryProfile && $request->notary_id === $viewer->notaryProfile->id;
    $backRoute = $isAssigned ? route('notary.requests.show', $request) : route('dashboard');
    $backLabel = $isAssigned ? 'Back to request' : 'Back to dashboard';
@endphp

<div class="page-hd">
    <div class="page-hd-inner">
        <a href="{{ $backRoute }}" class="page-back">
            <x-heroicon-o-arrow-left style="width:13px;height:13px;"/>
            {{ $backLabel }}
        </a>
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <h1>Notarize document</h1>
            <span class="pill" style="background:rgba(255,255,255,.12); color:#fff;">{{ $request->reference }}</span>
        </div>
        <div class="sub">Drag or click a tool below, then click on the page to place it. Drag corners to resize, double-click to remove.</div>
    </div>
</div>

<div class="shell">

    {{-- Document tabs — only when there is more than one to move between --}}
    @if ($documents->count() > 1)
        @php $pendingIds = $pending->pluck('id')->all(); @endphp
        <div class="card" style="margin-bottom:14px;">
            <div style="display:flex; justify-content:space-between; align-items:baseline; gap:12px; flex-wrap:wrap; margin-bottom:10px;">
                <span class="text-sm" style="font-weight:600;">
                    {{ $documents->count() }} documents to notarise
                </span>
                <span class="text-sm muted">
                    Each gets its own seal and its own finished PDF. All of them must be
                    marked up before you can finalize.
                </span>
            </div>
            <div class="doc-tabs">
                @foreach ($documents as $i => $doc)
                    @php $isCurrent = $doc->id === $document->id; @endphp
                    <a class="doc-tab @if($isCurrent) is-current @endif"
                       href="{{ route('session.notarize', [$request, 'document' => $doc->id]) }}"
                       @if($isCurrent) aria-current="page" @endif>
                        <span class="doc-tab-n">{{ $i + 1 }}</span>
                        <span class="doc-tab-name">{{ $doc->label() }}</span>
                        @if (in_array($doc->id, $pendingIds, true))
                            <span class="doc-tab-flag" title="Nothing placed yet">not marked up</span>
                        @else
                            <x-heroicon-s-check-circle style="width:14px;height:14px;color:var(--brand);flex-shrink:0;"/>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Toolbar card --}}
    <div class="card" style="margin-bottom:14px;">
        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <span class="text-sm muted" style="font-weight:600; margin-right:4px;">Tools:</span>

            @foreach ($assetSets as $set)
                <span class="text-sm muted" style="width:100%; margin: 4px 0 2px; font-size:11px; text-transform:uppercase; letter-spacing:.06em;">
                    {{ $set['label'] }}
                    @if ($set['substitute'] ?? false)
                        <span style="text-transform:none; letter-spacing:0; color:#b45309; font-weight:600;">· substitute</span>
                    @endif
                </span>
                @if ($set['note'] ?? null)
                    {{-- Said here rather than in the label, because the person
                         reading it is choosing between two seals and needs the
                         reason, not just the name. --}}
                    <span class="text-sm" style="width:100%; margin:0 0 4px; font-size:11px; color:#b45309;">{{ $set['note'] }}</span>
                @endif
                @foreach ($set['assets'] as $asset)
                    @if ($asset->type === 'initials')
                        <button type="button" class="btn btn-ghost btn-sm tool"
                                data-tool="text"
                                data-text="{{ $asset->text_value }}"
                                draggable="true"
                                title="Click or drag onto the document">
                            <x-heroicon-o-cursor-arrow-rays style="width:13px;height:13px;"/>
                            Initials
                        </button>
                    @else
                        <button type="button" class="btn btn-ghost btn-sm tool"
                                data-tool="asset"
                                data-asset-id="{{ $asset->id }}"
                                data-asset-url="{{ route('session.asset', [$request, $asset]) }}"
                                draggable="true"
                                title="Click or drag onto the document">
                            @if ($asset->type === 'signature')
                                <x-heroicon-o-pencil style="width:13px;height:13px;"/>
                            @elseif ($asset->type === 'stamp')
                                <x-heroicon-o-bookmark-square style="width:13px;height:13px;"/>
                            @else
                                <x-heroicon-o-shield-check style="width:13px;height:13px;"/>
                            @endif
                            {{ ucfirst($asset->type) }}
                        </button>
                    @endif
                @endforeach
            @endforeach

            <div style="width:1px; height:28px; background:var(--line); margin: 0 4px;"></div>
            <button type="button" class="btn btn-ghost btn-sm tool" data-tool="text" data-text="" draggable="true" title="Click or drag to add custom text">
                <x-heroicon-o-bars-3-bottom-left style="width:13px;height:13px;"/>
                Text
            </button>
            <button type="button" class="btn btn-ghost btn-sm tool" data-tool="text" data-text="{{ now()->format('j M Y') }}" draggable="true" title="Click or drag to add today's date">
                <x-heroicon-o-calendar style="width:13px;height:13px;"/>
                Date
            </button>
        </div>
        <div class="editor-help">
            <p><strong>Place</strong> — click a tool above (it turns green), then click the document. Or drag the tool straight onto the page to place several in a row.</p>
            <p><strong>Move &amp; resize</strong> — drag an item to reposition it; drag a corner handle to resize. Corners keep the original proportions — hold <kbd>Shift</kbd> while dragging to stretch freely. Arrow keys nudge the selected item.</p>
            <p><strong>Remove</strong> — hover an item and click the red ×, double-click it, or select it and press <kbd>Delete</kbd>.</p>
        </div>
    </div>

    {{-- PDF viewer --}}
    <div style="border:1px solid var(--line); border-radius:var(--radius-sm); overflow:auto; background:#525659; padding:14px; min-height:300px;">
        <div id="pdf-loading">Loading document…</div>
        <div id="pdf-wrap"></div>
    </div>

    {{-- Action bar --}}
    <div style="display:flex; gap:10px; margin-top:14px;">
        <button id="save-btn" class="btn btn-ghost" type="button">
            <x-heroicon-o-cloud-arrow-up style="width:15px;height:15px;"/>
            Save placements
        </button>
        {{-- The editor intercepts this submit and persists placements first — see notarize-editor.js --}}
        <form id="finalize-form" method="POST" action="{{ route('session.finalize', $request) }}" style="flex:1;">
            @csrf
            <button class="btn btn-block" type="submit" style="justify-content:center;">
                <x-heroicon-o-check-badge style="width:15px;height:15px;"/>
                Finalize &amp; seal
                {{ $documents->count() > 1 ? 'all ' . $documents->count() . ' documents' : 'document' }}
            </button>
        </form>
    </div>
    <p id="editor-status" class="muted text-sm" style="margin-top:8px; min-height:18px;"></p>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
@if (in_array($fileExt, ['docx', 'doc']))
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
@endif
<script>
  window.NVN_EDITOR = {
    {{-- The document id rides along on both, so the editor keeps working on the
         tab the notary opened without notarize-editor.js needing to know that a
         request can have more than one document. --}}
    documentUrl: "{{ route('session.document', [$request, 'document' => $document->id]) }}",
    saveUrl:     "{{ route('session.placements', [$request, 'document' => $document->id]) }}",
    assetUrl:    "{{ url('session/' . $request->id . '/asset') }}",
    csrf:        "{{ csrf_token() }}",
    fileExt:     "{{ $fileExt }}",
    existing:    @json($placements),
  };
</script>
<script src="{{ asset('js/notarize-editor.js') }}"></script>
@if ($documents->count() > 1)
<script>
  // Switching tabs is a page load, so anything not yet persisted would be lost.
  // Save first, then follow the link. The editor's own beforeunload prompt is
  // the backstop for when the save itself fails.
  document.querySelectorAll('.doc-tab').forEach(function (tab) {
      tab.addEventListener('click', function (e) {
          var cfg = window.NVN_EDITOR;
          if (!cfg || !cfg.isDirty || !cfg.isDirty()) return;

          e.preventDefault();
          var href = tab.getAttribute('href');
          var status = document.getElementById('editor-status');
          if (status) status.textContent = 'Saving before switching document…';

          cfg.save().then(function () {
              window.location.href = href;
          }).catch(function (err) {
              if (status) status.textContent = 'Could not save this document: ' + err.message
                  + ' — your placements are still here. Try "Save placements" again.';
          });
      });
  });
</script>
@endif
@endsection
