{{--
    The three images, shown at a size you can actually judge.

    This exists because the listing decision is a decision about pictures. A
    modal that only lists the words "signature · stamp · seal" is exactly the
    check that already passed in code and let the wrong seal through; the whole
    point of putting a person in the loop is that the person sees the picture.

    Served through admin.assets.view because the files live on the private disk
    and have no public URL.
--}}
@php
    $marks = collect(\App\Models\NotaryProfile::SEALING_ASSETS)
        ->map(fn (string $type) => [
            'type'  => $type,
            'asset' => $profile->assets->firstWhere('type', $type),
        ]);

    $initials = $profile->assets->firstWhere('type', 'initials')?->text_value;
@endphp

<div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px;">
    @foreach ($marks as $mark)
        <div style="border:1px solid rgb(228 228 231 / 1); border-radius:8px; overflow:hidden;">
            <div style="padding:6px 10px; font-size:11px; font-weight:600; text-transform:uppercase;
                        letter-spacing:.06em; color:#6b7280; background:#f9fafb;
                        border-bottom:1px solid rgb(228 228 231 / 1);">
                {{ $mark['type'] }}
            </div>
            <div style="height:130px; display:flex; align-items:center; justify-content:center;
                        background:#ffffff; padding:8px;">
                @if ($mark['asset']?->file_url)
                    {{-- White ground on purpose: a seal scanned onto white paper
                         disappears against a dark panel, and "I could not see it"
                         is not a reason to reject someone. --}}
                    <img src="{{ route('admin.assets.view', $mark['asset']->id) }}"
                         alt="{{ $mark['type'] }}"
                         style="max-width:100%; max-height:100%; object-fit:contain;">
                @else
                    <span style="font-size:12px; color:#b91c1c;">Not uploaded</span>
                @endif
            </div>
        </div>
    @endforeach
</div>

<p style="margin-top:10px; font-size:12px; color:#6b7280;">
    Initials on file: <strong>{{ $initials ?: '— none —' }}</strong>
</p>
