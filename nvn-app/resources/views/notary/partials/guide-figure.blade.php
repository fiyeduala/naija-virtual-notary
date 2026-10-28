{{--
    One screenshot on the guide, or nothing at all.

    Guarded on the file rather than rendered blindly: these images are built
    locally and shipped as static files, so a deploy that misses public/images
    would otherwise put a row of broken-image icons in front of every partner.
    A missing screenshot leaves the prose, which still reads.

    $shot    — basename under public/images/guide, without the extension
    $caption — what the reader is looking at, in a sentence
--}}
@php
    $guidePath = public_path('images/guide/' . $shot . '.jpg');
@endphp
@if (file_exists($guidePath))
<figure class="g-fig">
    <img src="{{ asset('images/guide/' . $shot . '.jpg') }}"
         alt="{{ $caption }}"
         loading="lazy"
         width="1200">
    <figcaption>{{ $caption }}</figcaption>
</figure>
@endif
