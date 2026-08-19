<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Naija Virtual Notary' }}</title>
    @include('partials.pwa-head')

    {{-- Poppins via direct link tag (same as public pages — not CSS @import) --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        /* ── Design tokens — identical to public layout ── */
        :root {
            --brand:        #54B435;
            --brand-dark:   #3d8a27;
            --brand-light:  #EDFBE2;
            --accent:       #2d7d16;
            --ink:          #1f2933;
            --muted:        #5f6b7a;
            --line:         #e3e6ea;
            --bg:           #f9fafb;
            --surface:      #ffffff;
            --danger:       #a12626;
            --danger-bg:    #fdecec;
            --danger-line:  #f5c2c2;
            --success:      #256b34;
            --success-bg:   #e8f3ea;
            --success-line: #bcdcc3;
            --warning:      #8a5a00;
            --warning-bg:   #fdf3e2;
            --font:         'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --radius-sm:    8px;
            --radius:       12px;
            --radius-lg:    14px;
            --shadow-sm:    0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow:       0 4px 24px rgba(0,0,0,.07);
            --shadow-lg:    0 10px 40px rgba(0,0,0,.10);
        }

        /* ── Reset & base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
            font-size: 15px;
            line-height: 1.6;
        }
        a { color: var(--brand); text-decoration: none; }
        a:hover { color: var(--brand-dark); }
        img { max-width: 100%; }
        h1, h2, h3, h4 { font-weight: 700; line-height: 1.25; color: var(--ink); }
        h1 { font-size: 22px; }
        h2 { font-size: 17px; }

        /* ── Navbar ── */
        .navbar {
            background: linear-gradient(90deg, #0f1a0b 0%, #1a3011 55%, #2a5020 100%);
            height: 62px;
            padding: 0 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 200;
            box-shadow: 0 2px 12px rgba(0,0,0,.25);
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .brand-badge {
            width: 30px; height: 30px;
            background: var(--brand);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .brand-badge svg { display: block; }
        .brand-name { font-size: 15px; font-weight: 700; color: #fff; letter-spacing: -.01em; }
        .brand-name span { color: #78d44a; }
        /* Height-capped, width free: an uploaded logo of any proportion sits in
           the bar without pushing the nav around. */
        .brand-logo { height: 32px; width: auto; max-width: 200px; display: block; }


        .navbar-nav {
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .nav-link {
            color: rgba(255,255,255,.72);
            font-size: 13.5px;
            font-weight: 500;
            padding: 7px 13px;
            border-radius: 8px;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,.13);
            color: #fff;
        }
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .nav-user-name {
            font-size: 12.5px;
            font-weight: 500;
            color: rgba(255,255,255,.55);
        }
        .btn-signout {
            background: rgba(255,255,255,.1);
            color: #fff;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 8px;
            padding: 7px 15px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            font-family: var(--font);
            transition: background .15s;
        }
        .btn-signout:hover { background: rgba(255,255,255,.2); }

        /* On narrow screens the nav wraps onto its own scrollable row rather
           than disappearing — hiding it left phones with no way back to the
           dashboard at all. */
        @media (max-width: 760px) {
            .navbar { height: auto; flex-wrap: wrap; padding: 10px 16px; row-gap: 8px; }
            .nav-user-name { display: none; }
            .navbar-nav {
                order: 3;
                width: 100%;
                gap: 4px;
                overflow-x: auto;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
            }
            .navbar-nav::-webkit-scrollbar { display: none; }
            .nav-link { white-space: nowrap; flex-shrink: 0; }
        }

        /* ── Shared form controls ── */
        label { display: block; font-size: 13px; font-weight: 500; margin: 14px 0 5px; }
        input[type=text], input[type=email], input[type=tel], input[type=password],
        input[type=number], select, textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid var(--line);
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 14px;
            background: #fff;
            color: var(--ink);
            transition: border-color .15s;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(84,180,53,.12); }
        textarea { min-height: 96px; resize: vertical; }

        /* ── Buttons — identical system to public pages ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 11px 20px;
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: var(--brand);
            color: #fff;
            text-align: center;
            transition: background .15s, transform .1s, box-shadow .15s;
        }
        .btn:hover { background: var(--brand-dark); color: #fff; }
        .btn:active { transform: scale(.98); }
        .btn-block { display: flex; width: 100%; justify-content: center; }
        .btn-ghost { background: transparent; color: var(--brand); border: 1.5px solid var(--line); }
        .btn-ghost:hover { background: var(--brand-light); color: var(--brand-dark); border-color: var(--brand); }
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #881f1f; }
        .btn-sm { padding: 7px 14px; font-size: 13px; }
        .btn-lg { padding: 14px 28px; font-size: 16px; }

        /* ── Cards ── */
        .card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 24px 26px;
            box-shadow: var(--shadow-sm);
        }

        /* ── Alerts ── */
        .alert { border-radius: var(--radius-sm); padding: 12px 14px; font-size: 13px; margin-bottom: 12px; }
        .alert-error   { background: var(--danger-bg);  border: 1px solid var(--danger-line);  color: var(--danger); }
        .alert-success { background: var(--success-bg); border: 1px solid var(--success-line); color: var(--success); }
        .alert ul { margin: 0; padding-left: 18px; }

        /* ── Pills / badges ── */
        .pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--brand-light); color: var(--brand-dark); }
        .pill-pending  { background: var(--warning-bg);  color: var(--warning); }
        .pill-approved { background: var(--success-bg);  color: var(--success); }
        .pill-rejected { background: var(--danger-bg);   color: var(--danger); }

        /* ── Bank verification state ── */
        .bank-state { display: flex; flex-direction: column; gap: 3px; padding: 12px 14px; margin-bottom: 18px;
                      border-radius: var(--radius-sm); border: 1px solid transparent; font-size: 13px; line-height: 1.5; }
        .bank-state strong { font-weight: 600; }
        .bank-state.is-ok   { background: var(--success-bg); border-color: var(--success-line); color: var(--success); }
        .bank-state.is-warn { background: var(--warning-bg); border-color: var(--warning);      color: var(--warning); }
        .bank-state.is-idle { background: var(--surface-2, #f4f5f7); border-color: var(--line, #e3e5e8); color: var(--muted); }

        /* ── Utilities ── */
        .muted    { color: var(--muted); }
        .text-sm  { font-size: 13px; }
        .grid-2   { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .grid-3   { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
        @media (max-width: 580px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

        /* Onboarding step indicator */
        .steps { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .step { flex: 1; min-width: 110px; padding: 10px 12px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 500; background: #fff; border: 1px solid var(--line); color: var(--muted); }
        .step.active { background: var(--brand-light); border-color: var(--brand); color: var(--brand-dark); }
        .step.done   { background: var(--success-bg);  border-color: var(--success-line); color: var(--success); }

        /* ── Shell ──
           A page that wants to be narrower than 1080px sets --page-w once (see
           any of the form pages). Both the header and the body read the same
           variable, so the H1 always sits on the same left edge as the cards
           beneath it — setting max-width on .shell alone left the two misaligned. */
        .shell { max-width: var(--page-w, 1080px); margin: 0 auto; padding: 32px 28px 80px; }

        /* Flash messages get their own wrapper. The dashboards override .shell
           to run full-bleed, which used to drag the flash banner to the very
           edge of the viewport with it. */
        .flash-wrap { max-width: 1080px; margin: 0 auto; padding: 20px 28px 0; }

        /* ── Inner page header (non-dashboard pages) ── */
        .page-hd {
            background: linear-gradient(135deg, #0f1a0b 0%, #1a3011 55%, #2a5020 100%);
            padding: 28px 28px 24px;
            position: relative;
            overflow: hidden;
        }
        .page-hd::before {
            content: ''; position: absolute; inset: 0;
            background: radial-gradient(ellipse 60% 80% at 90% 50%, rgba(84,180,53,.12) 0%, transparent 70%);
            pointer-events: none;
        }
        .page-hd-inner { max-width: var(--page-w, 1080px); margin: 0 auto; position: relative; }
        .page-hd h1 { color: #fff; font-size: 22px; font-weight: 800; margin-bottom: 4px; }
        .page-hd .sub { color: rgba(255,255,255,.55); font-size: 13px; }
        .page-back {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 13px; color: rgba(255,255,255,.65);
            margin-bottom: 10px; text-decoration: none; transition: color .15s;
        }
        .page-back:hover { color: #fff; }

        /* ── Chat / message threads (client, notary and admin all share these) ── */
        .chat { display: flex; flex-direction: column; height: min(62vh, 560px); padding: 0; overflow: hidden; }
        .chat-scroll { flex: 1; overflow-y: auto; padding: 20px 22px; background: var(--bg); }
        .chat-compose { border-top: 1px solid var(--line); background: var(--surface); padding: 14px 18px; }
        .chat-compose label { margin-top: 0; }
        .chat-compose textarea { min-height: 74px; }
        .chat-compose-row { display: flex; gap: 10px; align-items: flex-end; margin-top: 10px; }
        .chat-compose-row .btn { flex-shrink: 0; }
        .chat-hint { flex: 1; margin: 0; }

        .bubbles { display: flex; flex-direction: column; gap: 12px; }
        .bubble { max-width: min(78%, 520px); }
        .bubble.mine { align-self: flex-end; }
        .bubble.theirs { align-self: flex-start; }
        .bubble-meta { font-size: 11.5px; color: var(--muted); margin-bottom: 3px; padding: 0 2px; }
        .bubble.mine .bubble-meta { text-align: right; }
        .bubble-body {
            padding: 10px 13px; font-size: 14px; line-height: 1.55;
            border-radius: var(--radius); border: 1px solid var(--line);
            background: var(--surface); color: var(--ink);
            white-space: pre-wrap; word-break: break-word;
        }
        .bubble.mine   .bubble-body { background: var(--brand); border-color: var(--brand); color: #fff; border-bottom-right-radius: 4px; }
        .bubble.theirs .bubble-body { border-bottom-left-radius: 4px; }
        .bubble.support .bubble-body { background: var(--brand-light); border-color: var(--brand); color: var(--brand-dark); }
        @media (max-width: 580px) {
            .chat { height: min(70vh, 520px); }
            .chat-scroll { padding: 16px 14px; }
            .bubble { max-width: 88%; }
        }

        /* ── Sticky bottom CTA bar ── */
        .sticky-bar {
            position: sticky; bottom: 0;
            background: var(--brand);
            z-index: 50;
            box-shadow: 0 -4px 20px rgba(0,0,0,.18);
        }
        .sticky-bar form { margin: 0; }
        .sticky-bar button {
            display: block; width: 100%; padding: 17px 28px;
            background: transparent; color: #fff; border: none; cursor: pointer;
            font-family: var(--font); font-size: 15px; font-weight: 700;
            letter-spacing: .02em; transition: background .15s;
        }
        .sticky-bar button:hover { background: rgba(0,0,0,.12); }
    </style>
    @stack('styles')
    @include('partials.meta-pixel')
</head>
<body>

{{-- Navbar --}}
<nav class="navbar">
    <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" class="navbar-brand">
        @if ($navLogo = \App\Support\Branding::logoUrl())
            <img src="{{ $navLogo }}" alt="{{ config('app.name') }}" class="brand-logo">
        @else
            {{-- The shield the site shipped with, and still its answer when no
                 logo has been uploaded. --}}
            <div class="brand-badge">
                <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                </svg>
            </div>
            <span class="brand-name">Naija <span>Virtual Notary</span></span>
        @endif
    </a>

    @auth
    {{-- Role is a UserRole enum — compare with the helpers, never with a
         string, or every link below silently disappears. --}}
    <div class="navbar-nav">
        @if(auth()->user()->isNotary())
            <a href="{{ route('notary.dashboard') }}"         class="nav-link {{ request()->routeIs('notary.dashboard')      ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('notary.requests.incoming') }}"  class="nav-link {{ request()->routeIs('notary.requests.*')     ? 'active' : '' }}">Requests</a>
            <a href="{{ route('notary.profile.edit') }}"       class="nav-link {{ request()->routeIs('notary.profile.*')      ? 'active' : '' }}">My Profile</a>
        @elseif(auth()->user()->isClient())
            <a href="{{ route('client.dashboard') }}"          class="nav-link {{ request()->routeIs('client.dashboard')      ? 'active' : '' }}">Dashboard</a>
            <a href="{{ route('client.request.create') }}"     class="nav-link {{ request()->routeIs('client.request.*')      ? 'active' : '' }}">New Request</a>
        @elseif(auth()->user()->isAdmin())
            <a href="{{ route('filament.admin.pages.dashboard') }}" class="nav-link">Admin Panel</a>
            <a href="{{ route('admin.notaries.index') }}"      class="nav-link {{ request()->routeIs('admin.notaries.*')     ? 'active' : '' }}">Notary Review</a>
            <a href="{{ route('admin.messages.index') }}"      class="nav-link {{ request()->routeIs('admin.messages.*')     ? 'active' : '' }}">Messages</a>
        @endif
    </div>

    <div class="navbar-right">
        @include('partials.push-toggle', ['variant' => 'nav'])
        <span class="nav-user-name">{{ auth()->user()->full_name }}</span>
        <form method="POST" action="{{ route('logout') }}" style="margin:0;">
            @csrf
            <button class="btn-signout" type="submit">Sign out</button>
        </form>
    </div>
    @endauth
</nav>

{{-- Flash messages (outside the page shell so full-width pages still align) --}}
@if (session('status') || $errors->any())
<div class="flash-wrap">
    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-error">
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
</div>
@endif

@yield('content')

<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
</script>
@stack('scripts')
@include('partials.live-chat')
</body>
</html>
