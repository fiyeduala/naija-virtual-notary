<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Naija Virtual Notary — Nigeria\'s #1 Online Notary Service' }}</title>
    <meta name="description" content="{{ $description ?? 'Secure, fast, and accessible online notarization for Nigerians at home and in the diaspora. Notarize documents anytime, anywhere.' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <link rel="icon" href="{{ \App\Support\Branding::faviconUrl() }}">
    <style>
        :root {
            --brand:       #54B435;
            --brand-dark:  #3d8a27;
            --brand-light: #EDFBE2;
            --ink:         #1f2933;
            --muted:       #5f6b7a;
            --line:        #e3e6ea;
            --bg:          #f9fafb;
            --surface:     #ffffff;
            --font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --radius: 12px;
            --shadow: 0 4px 24px rgba(0,0,0,.07);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); color: var(--ink); background: var(--bg); -webkit-font-smoothing: antialiased; }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 100;
            background: #fff; border-bottom: 1px solid var(--line);
            padding: 0 24px;
        }
        .nav-inner {
            max-width: 1160px; margin: 0 auto;
            display: flex; align-items: center; justify-content: space-between;
            height: 68px;
        }
        .nav-brand {
            display: flex; align-items: center; gap: 10px;
            font-weight: 700; font-size: 18px; color: var(--brand-dark);
        }
        .nav-brand img { height: 36px; width: auto; }
        .nav-links { display: flex; align-items: center; gap: 6px; }
        .nav-links a {
            padding: 8px 14px; border-radius: 8px; font-size: 14px; font-weight: 500;
            color: var(--ink); transition: background .15s, color .15s;
        }
        .nav-links a:hover { background: var(--brand-light); color: var(--brand-dark); }
        .nav-links .btn-nav {
            background: var(--brand); color: #fff; padding: 9px 20px; border-radius: 8px;
        }
        .nav-links .btn-nav:hover { background: var(--brand-dark); }
        .nav-mobile-toggle { display: none; background: none; border: none; cursor: pointer; padding: 4px; }
        .nav-mobile-toggle span {
            display: block; width: 24px; height: 2px; background: var(--ink); margin: 5px 0;
            transition: all .2s;
        }
        @media (max-width: 820px) {
            .nav-links { display: none; }
            .nav-mobile-toggle { display: block; }
            .nav-links.open {
                display: flex; flex-direction: column; align-items: stretch;
                position: absolute; top: 68px; left: 0; right: 0;
                background: #fff; border-bottom: 1px solid var(--line);
                padding: 12px 24px 20px;
            }
        }

        /* ── Shared section layout ── */
        .section { padding: 80px 24px; }
        .section-sm { padding: 56px 24px; }
        .container { max-width: 1160px; margin: 0 auto; }
        .section-label {
            font-size: 13px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
            color: var(--brand); margin-bottom: 12px;
        }
        .section-title { font-size: clamp(28px, 4vw, 44px); font-weight: 700; line-height: 1.2; color: var(--ink); margin-bottom: 16px; }
        .section-sub { font-size: 17px; color: var(--muted); line-height: 1.6; max-width: 600px; }
        .center { text-align: center; }
        .center .section-sub { margin: 0 auto; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 13px 26px; border-radius: 9px; font-family: var(--font);
            font-size: 15px; font-weight: 600; cursor: pointer; border: none;
            transition: background .15s, transform .1s;
        }
        .btn:active { transform: scale(.98); }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:hover { background: var(--brand-dark); color: #fff; }
        .btn-outline { background: transparent; color: var(--brand); border: 2px solid var(--brand); }
        .btn-outline:hover { background: var(--brand-light); }
        .btn-white { background: #fff; color: var(--brand-dark); }
        .btn-white:hover { background: var(--brand-light); }
        .btn-lg { padding: 16px 32px; font-size: 16px; }

        /* ── Footer ── */
        .footer { background: #0f1a0b; color: rgba(255,255,255,.7); padding: 56px 24px 32px; }
        .footer-inner { max-width: 1160px; margin: 0 auto; }
        .footer-top {
            display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 48px; margin-bottom: 48px;
        }
        .footer-brand { font-weight: 700; font-size: 18px; color: #fff; margin-bottom: 12px; }
        .footer-desc { font-size: 14px; line-height: 1.7; }
        .footer-col h4 { color: #fff; font-size: 14px; font-weight: 600; margin-bottom: 14px; }
        .footer-col a { display: block; font-size: 14px; margin-bottom: 8px; color: rgba(255,255,255,.65); transition: color .15s; }
        .footer-col a:hover { color: var(--brand); }
        .footer-bottom { border-top: 1px solid rgba(255,255,255,.1); padding-top: 24px; font-size: 13px; }
        @media (max-width: 720px) {
            .footer-top { grid-template-columns: 1fr; gap: 32px; }
        }
    </style>
    @stack('styles')
</head>
<body>

<nav class="nav">
    <div class="nav-inner">
        <a href="{{ route('home') }}" class="nav-brand">
            @if ($logo = \App\Support\Branding::logoUrl())
                <img src="{{ $logo }}" alt="{{ config('app.name') }}">
            @else
                Naija Virtual Notary
            @endif
        </a>
        <div class="nav-links" id="nav-links">
            <a href="{{ route('home') }}">Home</a>
            <a href="{{ route('about') }}">About</a>
            <a href="{{ route('how-it-works') }}">How It Works</a>
            <a href="{{ route('partner') }}">Partner With Us</a>
            @auth
                <a href="{{ route('dashboard') }}">Dashboard</a>
            @else
                <a href="{{ route('login') }}">Login</a>
                <a href="{{ route('register') }}" class="btn-nav">Sign Up</a>
            @endauth
        </div>
        <button class="nav-mobile-toggle" onclick="document.getElementById('nav-links').classList.toggle('open')">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

@yield('content')

<footer class="footer">
    <div class="footer-inner">
        <div class="footer-top">
            <div>
                <div class="footer-brand">Naija Virtual Notary</div>
                <p class="footer-desc">Nigeria's #1 online notary platform. Bringing notarization to your fingertips — anytime, anywhere. Serving Nigeria and the diaspora.</p>
            </div>
            <div class="footer-col">
                <h4>Platform</h4>
                <a href="{{ route('home') }}">Home</a>
                <a href="{{ route('about') }}">About Us</a>
                <a href="{{ route('how-it-works') }}">How It Works</a>
                <a href="{{ route('partner') }}">Partner With Us</a>
            </div>
            <div class="footer-col">
                <h4>Account</h4>
                @auth
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:14px;color:rgba(255,255,255,.65);padding:0;margin-bottom:8px;font-family:var(--font)">Sign Out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}">Login</a>
                    <a href="{{ route('register') }}">Create Account</a>
                    <a href="{{ route('notary.apply') }}">Apply as Notary</a>
                @endauth
            </div>
        </div>
        <div class="footer-bottom">
            <p>© {{ date('Y') }} Naija Virtual Notary. All rights reserved.</p>
        </div>
    </div>
</footer>

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
</script>
@include('partials.live-chat')
</body>
</html>
