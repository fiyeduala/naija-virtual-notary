{{--
    Everything a browser needs to treat the site as an installable app.

    Kept in one partial because it has to appear identically on the public
    pages, the app layout and the Filament panel — Chrome only fires
    beforeinstallprompt on a page that actually links a manifest, so an admin
    working inside /admin-panel would otherwise never be offered the install.
--}}
<link rel="manifest" href="{{ route('manifest') }}">
<link rel="icon" href="{{ \App\Support\Branding::faviconUrl() }}">
{{-- iOS ignores the manifest icon list and reads this one for the Home Screen. --}}
<link rel="apple-touch-icon" href="{{ \App\Support\Branding::homeScreenIconUrl() }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="NVN">
<meta name="theme-color" content="#54B435">
