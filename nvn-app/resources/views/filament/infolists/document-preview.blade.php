{{--
    A document preview that opens over the page instead of in a new tab.

    Reading a request means looking at four or five files in turn — the deed,
    the ID, the signature — and deciding one thing about the request as a whole.
    A new tab per file loses the request while you are looking at the file, and
    leaves a row of tabs to close afterwards. This keeps the page underneath.

    It is deliberately not a Filament modal action: those cost a Livewire round
    trip per open, and there is nothing to fetch — the URL is known at render
    time. The overlay is teleported to <body> so no ancestor's overflow or
    stacking context can clip it, and the file itself is only requested when the
    overlay opens, so a request with twenty uploads still loads one page.

    Route::has() rather than a bare route(): route() throws when a name is
    missing, and a throw inside an infolist entry takes the whole page with it —
    the fee, the status, the client, the sealed output — over a preview link.
    That is exactly what happened here once, on a server still holding a route
    cache built before these routes existed. `php artisan route:clear` fixes the
    cause; this makes the symptom a grey badge naming the fix.

    @var \App\Models\RequestDocument $document
    @var string $kind  'upload' (client's file, any type) or 'sealed' (always a PDF)
--}}
@php
    $document = $getRecord();
    $kind ??= 'upload';

    $filename = $document->original_filename ?: ('Document #' . $document->id);
    $extension = strtolower(pathinfo(
        $document->original_filename ?: $document->file_url,
        PATHINFO_EXTENSION,
    ));

    $routeName = $kind === 'sealed' ? 'admin.requests.notarized' : 'admin.requests.document';

    $url = \Illuminate\Support\Facades\Route::has($routeName)
        ? ($kind === 'sealed'
            ? route($routeName, [$document->request_id, 'document' => $document->id])
            : route($routeName, [$document->request_id, $document->id]))
        : null;

    // What the browser can actually draw. The sealed output is always a PDF the
    // app produced itself; an upload is whatever the client had to hand, and
    // RequestDocumentController serves anything outside this list as an
    // attachment — so offering to "preview" a .docx would just be a download
    // with an overlay in front of it.
    $renderAs = match (true) {
        $kind === 'sealed', $extension === 'pdf' => 'pdf',
        in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => 'image',
        default => 'download',
    };
@endphp

@once
    <style>
        .nvn-preview__trigger {
            display: inline-flex; align-items: center; gap: .375rem;
            border: 0; cursor: pointer; font: inherit; font-size: .75rem; line-height: 1rem;
            font-weight: 500; padding: .25rem .5rem; border-radius: .375rem;
            background: rgb(219 234 254); color: rgb(29 78 216);
            box-shadow: inset 0 0 0 1px rgb(59 130 246 / .25);
        }
        .nvn-preview__trigger:hover { background: rgb(191 219 254); }
        .nvn-preview--sealed .nvn-preview__trigger {
            background: rgb(220 252 231); color: rgb(21 128 61);
            box-shadow: inset 0 0 0 1px rgb(34 197 94 / .25);
        }
        .nvn-preview--sealed .nvn-preview__trigger:hover { background: rgb(187 247 208); }
        .nvn-preview__trigger svg { width: .875rem; height: .875rem; }
        .dark .nvn-preview__trigger { background: rgb(30 58 138 / .5); color: rgb(147 197 253); }
        .dark .nvn-preview--sealed .nvn-preview__trigger { background: rgb(20 83 45 / .5); color: rgb(134 239 172); }

        .nvn-preview__unavailable {
            display: inline-block; font-size: .75rem; line-height: 1rem; font-weight: 500;
            padding: .25rem .5rem; border-radius: .375rem;
            background: rgb(244 244 245); color: rgb(82 82 91);
            box-shadow: inset 0 0 0 1px rgb(113 113 122 / .25);
        }
        .dark .nvn-preview__unavailable { background: rgb(63 63 70 / .5); color: rgb(212 212 216); }

        .nvn-preview__overlay {
            position: fixed; inset: 0; z-index: 9999;
            display: flex; align-items: center; justify-content: center; padding: 1rem;
        }
        .nvn-preview__backdrop { position: absolute; inset: 0; background: rgb(0 0 0 / .65); }
        .nvn-preview__panel {
            position: relative; display: flex; flex-direction: column;
            width: 100%; max-width: 64rem; height: min(90vh, 900px);
            background: #fff; border-radius: .75rem; overflow: hidden;
            box-shadow: 0 25px 50px -12px rgb(0 0 0 / .55);
        }
        .dark .nvn-preview__panel { background: rgb(24 24 27); }
        .nvn-preview__bar {
            display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
            padding: .625rem .75rem .625rem 1rem;
            border-bottom: 1px solid rgb(228 228 231);
        }
        .dark .nvn-preview__bar { border-color: rgb(63 63 70); }
        .nvn-preview__name {
            font-size: .875rem; font-weight: 600; color: rgb(24 24 27);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .dark .nvn-preview__name { color: rgb(244 244 245); }
        .nvn-preview__spacer { flex: 1 1 auto; }
        .nvn-preview__link { font-size: .75rem; white-space: nowrap; color: rgb(82 82 91); text-decoration: underline; }
        .nvn-preview__link:hover { color: rgb(24 24 27); }
        .dark .nvn-preview__link { color: rgb(161 161 170); }
        .dark .nvn-preview__link:hover { color: #fff; }
        .nvn-preview__close {
            border: 0; background: transparent; cursor: pointer; padding: 0 .25rem;
            font-size: 1.5rem; line-height: 1; color: rgb(113 113 122);
        }
        .nvn-preview__close:hover { color: rgb(24 24 27); }
        .dark .nvn-preview__close:hover { color: #fff; }
        .nvn-preview__body {
            flex: 1 1 auto; min-height: 0; overflow: auto;
            display: flex; align-items: center; justify-content: center;
            background: rgb(244 244 245);
        }
        .dark .nvn-preview__body { background: rgb(39 39 42); }
        .nvn-preview__body iframe { width: 100%; height: 100%; border: 0; background: #fff; }
        .nvn-preview__body img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .nvn-preview__fallback { text-align: center; padding: 2rem; max-width: 26rem; }
        .nvn-preview__fallback p { font-size: .875rem; color: rgb(82 82 91); margin: 0 0 1rem; }
        .dark .nvn-preview__fallback p { color: rgb(161 161 170); }
        .nvn-preview__download {
            display: inline-block; font-size: .875rem; font-weight: 500;
            padding: .5rem .875rem; border-radius: .5rem;
            background: rgb(24 24 27); color: #fff; text-decoration: none;
        }
        .dark .nvn-preview__download { background: rgb(244 244 245); color: rgb(24 24 27); }
    </style>
@endonce

@if ($url === null)
    <span class="nvn-preview__unavailable">Unavailable — clear the route cache</span>
@else
    <div
        class="nvn-preview{{ $kind === 'sealed' ? ' nvn-preview--sealed' : '' }}"
        x-data="{ open: false }"
        x-effect="document.body.style.overflow = open ? 'hidden' : ''"
    >
        <button type="button" class="nvn-preview__trigger" x-on:click="open = true">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178Z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
            </svg>
            {{ $kind === 'sealed' ? 'View sealed' : 'Preview' }}
        </button>

        {{-- Teleported so no section, table or overflow-hidden card can clip it. --}}
        <template x-teleport="body">
            <div
                class="nvn-preview__overlay"
                style="display: none;"
                x-show="open"
                x-on:keydown.escape.window="open = false"
            >
                <div class="nvn-preview__backdrop" x-on:click="open = false"></div>

                <div class="nvn-preview__panel" role="dialog" aria-modal="true" aria-label="{{ $filename }}">
                    <div class="nvn-preview__bar">
                        <span class="nvn-preview__name">{{ $filename }}</span>
                        <span class="nvn-preview__spacer"></span>
                        <a href="{{ $url }}" target="_blank" rel="noopener" class="nvn-preview__link">Open in a new tab</a>
                        <button type="button" class="nvn-preview__close" x-on:click="open = false" aria-label="Close preview">&times;</button>
                    </div>

                    {{-- x-if, so the file is fetched on the first open and never
                         before: a request with twenty uploads renders twenty
                         triggers and downloads nothing. --}}
                    <div class="nvn-preview__body">
                        <template x-if="open">
                            @if ($renderAs === 'pdf')
                                <iframe src="{{ $url }}" title="{{ $filename }}"></iframe>
                            @elseif ($renderAs === 'image')
                                <img src="{{ $url }}" alt="{{ $filename }}">
                            @else
                                <div class="nvn-preview__fallback">
                                    <p>
                                        A <strong>.{{ $extension ?: 'file' }}</strong> cannot be shown in the
                                        browser. Download it to read it — Word and the like open it directly.
                                    </p>
                                    <a href="{{ $url }}" class="nvn-preview__download">Download {{ $filename }}</a>
                                </div>
                            @endif
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif
