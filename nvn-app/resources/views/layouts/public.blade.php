<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Naija Virtual Notary — Nigeria\'s #1 Online Notary Service' }}</title>
    <meta name="description" content="{{ $description ?? 'Secure, fast, and accessible online notarization for Nigerians at home and in the diaspora. Notarize documents anytime, anywhere.' }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @include('partials.pwa-head')
    {{-- Set before first paint so the reveal-on-scroll rules only ever apply
         when scripting is actually available to undo them. --}}
    <script>document.documentElement.className += ' js';</script>
    <style>
        /*
        | ====================================================================
        | Public site — shared design system
        | ====================================================================
        | Every public page extends this layout, so this block is the only
        | place the brand is defined for visitors. Pages add their own
        | section styles through @push('styles'), which lands after this
        | block and therefore wins — that is deliberate, and it is why a page
        | that still carries its own .page-hero keeps the look it had.
        */
        :root {
            /* ---- Brand green: the action colour, unchanged ---- */
            --brand:        #54B435;
            --brand-dark:   #3d8a27;
            --brand-deep:   #2a5020;
            --brand-light:  #EDFBE2;

            /* ---- Lemon: the secondary, for warm grounds and highlights ---- */
            --lemon:        #E4F5A3;
            --lemon-soft:   #F7FBE8;
            --lemon-deep:   #C8E26A;
            --lemon-ink:    #46570F;

            /* ---- Forest: the authority ground ---- */
            --forest:       #0f1a0b;
            --forest-2:     #16250f;

            /* ---- Neutrals, biased very slightly green so they read as chosen ---- */
            --ink:          #16231c;
            --ink-soft:     #2c3a31;
            --muted:        #5e6e63;
            --faint:        #8a978e;
            --line:         #e4eae3;
            --line-soft:    #eff3ee;
            --bg:           #fbfcfa;
            --surface:      #ffffff;

            --display: 'Plus Jakarta Sans', 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font:    'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;

            --r-sm:  10px;
            --radius: 14px;
            --r-lg:  20px;
            --r-xl:  28px;

            --shadow-xs: 0 1px 2px rgba(22,35,28,.05);
            --shadow-sm: 0 2px 8px rgba(22,35,28,.06);
            --shadow:    0 8px 30px rgba(22,35,28,.08);
            --shadow-lg: 0 24px 60px rgba(22,35,28,.12);

            --maxw: 1160px;
            --nav-h: 74px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: var(--font);
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }
        a { text-decoration: none; color: inherit; }
        img { max-width: 100%; display: block; }
        ::selection { background: var(--lemon); color: var(--lemon-ink); }

        h1, h2, h3, h4 { font-family: var(--display); letter-spacing: -.02em; text-wrap: balance; }

        :focus-visible {
            outline: 3px solid var(--brand);
            outline-offset: 2px;
            border-radius: 4px;
        }

        /* ── Skip link: the first thing a keyboard reaches ── */
        .skip-link {
            position: absolute; left: 16px; top: -60px; z-index: 300;
            background: var(--brand); color: #fff; padding: 12px 20px;
            border-radius: 0 0 var(--r-sm) var(--r-sm); font-size: 14px; font-weight: 600;
            transition: top .2s;
        }
        .skip-link:focus { top: 0; }

        /* ── Nav ── */
        .nav {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255,255,255,.88);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid transparent;
            transition: border-color .25s, box-shadow .25s;
        }
        .nav.is-scrolled { border-bottom-color: var(--line); box-shadow: 0 1px 20px rgba(22,35,28,.05); }
        .nav-inner {
            max-width: var(--maxw); margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between;
            gap: 20px; height: var(--nav-h);
        }
        .nav-brand {
            display: flex; align-items: center; gap: 10px; flex-shrink: 0;
            font-family: var(--display); font-weight: 800; font-size: 17px;
            letter-spacing: -.02em; color: var(--brand-deep);
        }
        .nav-brand img { height: 38px; width: auto; }

        .nav-menu { display: flex; align-items: center; gap: 28px; }
        .nav-links { display: flex; align-items: center; gap: 2px; }
        .nav-links a {
            position: relative; padding: 9px 13px; border-radius: var(--r-sm);
            font-size: 14.5px; font-weight: 500; color: var(--ink-soft);
            transition: color .15s, background .15s;
        }
        .nav-links a:hover { color: var(--brand-dark); background: var(--brand-light); }
        .nav-links a[aria-current="page"] { color: var(--brand-dark); font-weight: 600; }
        .nav-links a[aria-current="page"]::after {
            content: ''; position: absolute; left: 13px; right: 13px; bottom: 2px;
            height: 2px; border-radius: 2px; background: var(--brand);
        }

        .nav-actions { display: flex; align-items: center; gap: 10px; }
        .nav-login {
            padding: 9px 16px; border-radius: var(--r-sm);
            font-size: 14.5px; font-weight: 600; color: var(--ink);
            transition: background .15s;
        }
        .nav-login:hover { background: var(--line-soft); }
        .btn-nav {
            background: var(--brand); color: #fff;
            padding: 10px 22px; border-radius: 999px;
            font-size: 14.5px; font-weight: 600;
            box-shadow: 0 2px 12px rgba(84,180,53,.3);
            transition: background .15s, transform .12s, box-shadow .15s;
        }
        .btn-nav:hover {
            background: var(--brand-dark); color: #fff; transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(84,180,53,.38);
        }

        .nav-toggle {
            display: none; background: none; border: none; cursor: pointer;
            width: 42px; height: 42px; border-radius: var(--r-sm);
            align-items: center; justify-content: center; flex-direction: column; gap: 5px;
        }
        .nav-toggle:hover { background: var(--line-soft); }
        .nav-toggle span {
            display: block; width: 22px; height: 2px; border-radius: 2px;
            background: var(--ink); transition: transform .25s, opacity .18s;
        }
        .nav-toggle[aria-expanded="true"] span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
        .nav-toggle[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
        .nav-toggle[aria-expanded="true"] span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }

        @media (max-width: 900px) {
            .nav-toggle { display: flex; }
            .nav-menu {
                position: fixed; top: var(--nav-h); left: 0; right: 0;
                max-height: calc(100dvh - var(--nav-h)); overflow-y: auto;
                flex-direction: column; align-items: stretch; gap: 0;
                background: #fff; border-bottom: 1px solid var(--line);
                padding: 12px 24px 28px; box-shadow: var(--shadow);
                transform: translateY(-8px); opacity: 0; visibility: hidden;
                transition: opacity .2s, transform .2s, visibility .2s;
            }
            .nav-menu.open { transform: translateY(0); opacity: 1; visibility: visible; }
            .nav-links { flex-direction: column; align-items: stretch; gap: 0; }
            .nav-links a { padding: 14px 12px; font-size: 16px; border-radius: var(--r-sm); }
            .nav-links a[aria-current="page"]::after { display: none; }
            .nav-links a[aria-current="page"] { background: var(--brand-light); }
            .nav-actions {
                flex-direction: column; align-items: stretch; gap: 10px;
                margin-top: 16px; padding-top: 18px; border-top: 1px solid var(--line);
            }
            .nav-login, .btn-nav { text-align: center; padding: 14px 20px; font-size: 16px; }
        }

        /* ── Shared section layout ── */
        .section    { padding: 96px 24px; }
        .section-sm { padding: 64px 24px; }
        .container  { max-width: var(--maxw); margin: 0 auto; }
        .container-narrow { max-width: 760px; margin: 0 auto; }

        .section-label, .eyebrow {
            display: inline-flex; align-items: center; gap: 8px;
            font-family: var(--display);
            font-size: 12.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase;
            color: var(--brand-dark); margin-bottom: 14px;
        }
        .section-label::before, .eyebrow::before {
            content: ''; width: 20px; height: 2px; border-radius: 2px; background: var(--brand);
        }
        .center .section-label, .center .eyebrow { justify-content: center; }

        .section-title {
            font-size: clamp(30px, 4.2vw, 48px); font-weight: 800; line-height: 1.12;
            color: var(--ink); margin-bottom: 18px;
        }
        .section-sub {
            font-size: 17.5px; color: var(--muted); line-height: 1.68; max-width: 620px;
            font-weight: 300;
        }
        .center { text-align: center; }
        .center .section-sub { margin-left: auto; margin-right: auto; }

        /* ── Buttons ── */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 9px;
            padding: 14px 28px; border-radius: 999px; font-family: var(--font);
            font-size: 15px; font-weight: 600; cursor: pointer; border: none;
            text-align: center; line-height: 1.2;
            transition: background .15s, color .15s, transform .12s, box-shadow .18s, border-color .15s;
        }
        .btn:active { transform: scale(.98); }
        .btn-primary {
            background: var(--brand); color: #fff;
            box-shadow: 0 4px 16px rgba(84,180,53,.28);
        }
        .btn-primary:hover {
            background: var(--brand-dark); color: #fff; transform: translateY(-2px);
            box-shadow: 0 10px 26px rgba(84,180,53,.34);
        }
        .btn-outline {
            background: transparent; color: var(--brand-dark);
            border: 1.5px solid var(--line); box-shadow: var(--shadow-xs);
        }
        .btn-outline:hover { background: var(--brand-light); border-color: var(--brand); transform: translateY(-2px); }
        .btn-white { background: #fff; color: var(--brand-dark); box-shadow: 0 4px 16px rgba(0,0,0,.14); }
        .btn-white:hover { background: var(--lemon-soft); color: var(--brand-deep); transform: translateY(-2px); }
        .btn-ghost-light {
            background: rgba(255,255,255,.08); color: #fff;
            border: 1.5px solid rgba(255,255,255,.26);
            -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
        }
        .btn-ghost-light:hover { background: rgba(255,255,255,.17); color: #fff; transform: translateY(-2px); }
        .btn-lg { padding: 17px 36px; font-size: 16px; }

        /* ── Shared page hero (a page may still override this) ── */
        .page-hero {
            position: relative; overflow: hidden;
            background: linear-gradient(140deg, var(--forest) 0%, #1a3011 52%, var(--brand-deep) 100%);
            color: #fff; padding: 92px 24px 84px; text-align: center;
        }
        .page-hero::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background:
                radial-gradient(60% 70% at 15% 0%, rgba(228,245,163,.14), transparent 60%),
                radial-gradient(50% 60% at 88% 100%, rgba(84,180,53,.22), transparent 60%);
        }
        .page-hero > * { position: relative; z-index: 1; }
        .page-hero h1 {
            font-size: clamp(32px, 4.6vw, 54px); font-weight: 800; line-height: 1.1;
            color: #fff; margin-bottom: 18px;
        }
        .page-hero p {
            font-size: 18px; color: rgba(255,255,255,.76); max-width: 580px;
            margin: 0 auto; line-height: 1.65; font-weight: 300;
        }

        /* ── Shared closing CTA (a page may still override this) ── */
        .cta-strip {
            position: relative; overflow: hidden;
            background: linear-gradient(135deg, var(--brand-deep), var(--brand-dark) 55%, var(--brand));
            color: #fff; text-align: center; padding: 88px 24px;
        }
        .cta-strip::after {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background: radial-gradient(50% 80% at 50% 0%, rgba(228,245,163,.18), transparent 65%);
        }
        .cta-strip > * { position: relative; z-index: 1; }
        .cta-strip h2 {
            font-size: clamp(26px, 3.4vw, 40px); font-weight: 800; margin-bottom: 14px; color: #fff;
        }
        .cta-strip p {
            font-size: 17px; color: rgba(255,255,255,.85); margin: 0 auto 34px;
            max-width: 520px; font-weight: 300; line-height: 1.6;
        }

        /* ── Reveal on scroll (no library; disabled for reduced motion) ──
           Scoped to .js so that content is never stranded invisible: without
           scripting, or if this page's script throws before the observer is
           attached, the rule below simply does not apply. */
        .js [data-reveal] { opacity: 0; transform: translateY(18px); }
        .js [data-reveal].revealed {
            opacity: 1; transform: none;
            transition: opacity .65s cubic-bezier(.22,.61,.36,1), transform .65s cubic-bezier(.22,.61,.36,1);
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .js [data-reveal] { opacity: 1; transform: none; }
            *, *::before, *::after {
                animation-duration: .01ms !important; animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }

        /* ── Footer ── */
        .footer {
            background: var(--forest); color: rgba(255,255,255,.62);
            padding: 72px 24px 32px;
        }
        .footer-inner { max-width: var(--maxw); margin: 0 auto; }
        .footer-top {
            display: grid; grid-template-columns: 2.2fr 1fr 1fr 1fr; gap: 48px; margin-bottom: 56px;
        }
        .footer-brand {
            font-family: var(--display); font-weight: 800; font-size: 19px;
            letter-spacing: -.02em; color: #fff; margin-bottom: 14px;
        }
        .footer-desc { font-size: 14.5px; line-height: 1.75; max-width: 330px; font-weight: 300; }
        .footer-note {
            display: inline-flex; align-items: center; gap: 8px; margin-top: 20px;
            font-size: 13px; color: var(--lemon); font-weight: 500;
        }
        .footer-col h4 {
            font-family: var(--display); color: #fff; font-size: 13px; font-weight: 700;
            letter-spacing: .07em; text-transform: uppercase; margin-bottom: 16px;
        }
        .footer-col a, .footer-col button {
            display: block; font-size: 14.5px; margin-bottom: 11px;
            color: rgba(255,255,255,.6); transition: color .15s, transform .15s;
        }
        .footer-col a:hover, .footer-col button:hover { color: var(--lemon); transform: translateX(3px); }
        .footer-bottom {
            border-top: 1px solid rgba(255,255,255,.1); padding-top: 26px;
            font-size: 13px; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between;
        }
        @media (max-width: 900px) {
            .footer-top { grid-template-columns: 1fr 1fr; gap: 36px; }
            .footer-top > :first-child { grid-column: 1 / -1; }
        }
        @media (max-width: 560px) {
            .section { padding: 68px 20px; }
            .section-sm { padding: 48px 20px; }
            .page-hero { padding: 68px 20px 60px; }
            .cta-strip { padding: 68px 20px; }
            .footer-top { grid-template-columns: 1fr; gap: 32px; }
            .btn { width: 100%; }
            .btn-lg { padding: 16px 28px; }
        }
    </style>
    @stack('styles')
    @include('partials.meta-pixel')
</head>
<body>

<a href="#main" class="skip-link">Skip to content</a>

<nav class="nav" id="site-nav">
    <div class="nav-inner">
        <a href="{{ route('home') }}" class="nav-brand">
            @if ($logo = \App\Support\Branding::logoUrl())
                <img src="{{ $logo }}" alt="{{ config('app.name') }}">
            @else
                Naija Virtual Notary
            @endif
        </a>

        <div class="nav-menu" id="nav-menu">
            <div class="nav-links">
                <a href="{{ route('home') }}" @if (request()->routeIs('home')) aria-current="page" @endif>Home</a>
                <a href="{{ route('about') }}" @if (request()->routeIs('about')) aria-current="page" @endif>About</a>
                <a href="{{ route('how-it-works') }}" @if (request()->routeIs('how-it-works')) aria-current="page" @endif>How It Works</a>
                <a href="{{ route('blog.index') }}" @if (request()->routeIs('blog.*')) aria-current="page" @endif>Blog</a>
                <a href="{{ route('partner') }}" @if (request()->routeIs('partner')) aria-current="page" @endif>Partner With Us</a>
                @if (\App\Support\RecruitmentPermission::moduleEnabled())
                    <a href="{{ route('careers.index') }}" @if (request()->routeIs('careers.*')) aria-current="page" @endif>Careers</a>
                @endif
            </div>
            <div class="nav-actions">
                @auth
                    <a href="{{ route('dashboard') }}" class="btn-nav">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="nav-login">Login</a>
                    <a href="{{ route('register') }}" class="btn-nav">Sign Up</a>
                @endauth
            </div>
        </div>

        <button class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="nav-menu" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<main id="main">
@yield('content')
</main>

<footer class="footer">
    <div class="footer-inner">
        <div class="footer-top">
            <div>
                <div class="footer-brand">Naija Virtual Notary</div>
                <p class="footer-desc">Nigeria's #1 online notary platform. Bringing notarization to your fingertips — anytime, anywhere. Serving Nigeria and the diaspora.</p>
                <span class="footer-note">
                    <x-heroicon-o-shield-check style="width:16px;height:16px;"/>
                    Every document sealed by a licensed notary
                </span>
            </div>
            <div class="footer-col">
                <h4>Platform</h4>
                <a href="{{ route('home') }}">Home</a>
                <a href="{{ route('about') }}">About Us</a>
                <a href="{{ route('how-it-works') }}">How It Works</a>
                <a href="{{ route('blog.index') }}">Blog</a>
            </div>
            <div class="footer-col">
                <h4>Partner</h4>
                <a href="{{ route('partner') }}">Partner With Us</a>
                @if (\App\Support\RecruitmentPermission::moduleEnabled())
                    <a href="{{ route('careers.index') }}">Work With Us</a>
                @endif
                {{-- /partner-with-us is for notaries. A government body, an
                     embassy or a firm wanting to send us work needs its own
                     door, and this was the only one marked "partner". --}}
                <a href="{{ route('organization.apply.show') }}">For Organizations</a>
            </div>
            <div class="footer-col">
                <h4>Account</h4>
                @auth
                    <a href="{{ route('dashboard') }}">Dashboard</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:14.5px;color:rgba(255,255,255,.6);padding:0;text-align:left;font-family:var(--font)">Sign Out</button>
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
/* Reveal on scroll. Runs first and alone, because anything that threw above
   it would leave every [data-reveal] section permanently invisible. */
(function () {
    var targets = document.querySelectorAll('[data-reveal]');
    var reveal  = function (el) { el.classList.add('revealed'); };
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced || !('IntersectionObserver' in window)) {
        Array.prototype.forEach.call(targets, reveal);
        return;
    }
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) { return; }
            entry.target.style.transitionDelay = (entry.target.dataset.revealDelay || '0') + 'ms';
            reveal(entry.target);
            io.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });
    Array.prototype.forEach.call(targets, function (el) { io.observe(el); });
})();

(function () {
    /* Mobile menu: the button owns the state, aria-expanded is the state. */
    var toggle = document.getElementById('nav-toggle');
    var menu   = document.getElementById('nav-menu');
    if (!toggle || !menu) { return; }

    function setMenu(open) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        menu.classList.toggle('open', open);
    }

    toggle.addEventListener('click', function () {
        setMenu(toggle.getAttribute('aria-expanded') !== 'true');
    });

    menu.addEventListener('click', function (e) {
        if (e.target.closest('a')) { setMenu(false); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setMenu(false);
            toggle.focus();
        }
    });

    /* Reopening at desktop width must not leave the panel stuck open. */
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) { setMenu(false); }
    });

    /* Hairline under the nav only once the page has moved. */
    var nav = document.getElementById('site-nav');
    if (nav) {
        var onScroll = function () { nav.classList.toggle('is-scrolled', window.scrollY > 8); };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
    }
})();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
</script>
@include('partials.live-chat')
</body>
</html>
