{{--
    Meta pixel — the browser half of conversion tracking.

    Its real job here is not counting. It is to write the _fbc and _fbp cookies
    on the page the advert lands on, because those cookies are the only record
    of which click brought someone, and the payment that matters clears in a
    Paystack webhook where no browser exists. Without this script loading on the
    landing page there is nothing for the server to attribute later, and Meta
    does not allow backfills.

    Everything else is deliberately absent. No Purchase fires from the browser:
    a purchase reported from the client's device is a purchase reported by
    whoever can open the developer console, and the server already knows the
    truth. PageView is the only event sent from here.

    Renders nothing at all until META_DATASET_ID and META_CAPI_TOKEN are both
    set — no script tag, no request to Meta, no cookie. Tracking is opt-in, and
    a half-configured install that sets cookies it can never use would be the
    worst of both.
--}}
@php
    $metaPixelId = trim((string) config('nvn.meta.dataset_id', ''));
    $metaEnabled = $metaPixelId !== '' && trim((string) config('nvn.meta.access_token', '')) !== '';
@endphp

@if ($metaEnabled)
    <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window,document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');

        fbq('init', @json($metaPixelId));
        fbq('track', 'PageView');
    </script>
    <noscript><img height="1" width="1" style="display:none" alt=""
        src="https://www.facebook.com/tr?id={{ $metaPixelId }}&ev=PageView&noscript=1"></noscript>
@endif
