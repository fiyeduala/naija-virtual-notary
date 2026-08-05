{{--
    Tawk.to live chat.

    Included once at the foot of every visitor-facing layout (app, public, auth).
    The Filament admin panel does not include it and should not — staff have the
    panel's own message threads, and a support widget aimed at clients has no
    business floating over admin screens.

    Renders absolutely nothing until an admin sets the property ID in
    Platform settings (or TAWK_PROPERTY_ID in .env). No IDs, no script tag, no
    third-party request, no cookie.
--}}
@php
    [$tawkProperty, $tawkWidget] = \App\Support\Settings::tawk();

    // Some pages are the wrong place for a floating chat button — a live
    // notarization above all, where it would sit over the document.
    $tawkHidden = in_array(request()->route()?->getName(), config('nvn.tawk.hidden_routes', []), true);
@endphp

@if ($tawkProperty !== '' && ! $tawkHidden)
    <script>
        var Tawk_API = Tawk_API || {}, Tawk_LoadStart = new Date();

        @auth
        // Saves the visitor typing out who they are. Tawk's own docs call this
        // "unsecured" mode: it is a convenience for the agent, not proof of
        // identity, so nothing here is anything the person could not tell the
        // agent themselves in the first message.
        Tawk_API.visitor = {
            name:  @json(auth()->user()->full_name),
            email: @json(auth()->user()->email),
        };
        @endauth

        (function () {
            var s1 = document.createElement('script'),
                s0 = document.getElementsByTagName('script')[0];
            s1.async = true;
            s1.src = @json('https://embed.tawk.to/' . $tawkProperty . '/' . $tawkWidget);
            s1.charset = 'UTF-8';
            s1.setAttribute('crossorigin', '*');
            s0.parentNode.insertBefore(s1, s0);
        })();
    </script>
@endif
