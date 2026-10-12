<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        A layout of its own, not layouts.app.

        layouts.app carries the client navigation — dashboard, requests,
        documents, payments — and a candidate has no business seeing any of it.
        Sharing that layout would mean a candidate screen rendering links into
        an area their guard cannot open, which is confusing at best and an
        invitation at worst.

        `noreferrer` on the referrer policy is here for one specific reason: a
        sign-in link carries a token in its path, and a candidate who clicks any
        outbound link from that page would otherwise hand the token to whatever
        site they landed on in the Referer header.
    --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Your application — Naija Virtual Notary' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: #54B435; --brand-dark: #3d8a27; --brand-light: #EDFBE2;
            --ink: #1f2933; --muted: #5f6b7a; --line: #e3e6ea;
            --bg: #f9fafb; --surface: #ffffff;
            --font: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --radius: 12px;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: var(--font); color: var(--ink); background: var(--bg); -webkit-font-smoothing: antialiased; }
        a { color: var(--brand-dark); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .bar { background: #0f1a0b; padding: 14px 24px; }
        .bar-inner {
            max-width: 900px; margin: 0 auto; display: flex; align-items: center;
            justify-content: space-between; gap: 16px;
        }
        .bar-brand { color: #fff; font-weight: 600; font-size: 15px; }
        .bar-brand:hover { text-decoration: none; }
        .bar form { margin: 0; }
        .bar button {
            background: none; border: 1px solid rgba(255,255,255,.3); color: rgba(255,255,255,.85);
            font-family: var(--font); font-size: 13.5px; padding: 7px 14px; border-radius: 7px; cursor: pointer;
        }
        .bar button:hover { border-color: var(--brand); color: #fff; }

        .shell { max-width: 900px; margin: 0 auto; padding: 32px 24px 64px; }
        .narrow { max-width: 520px; }

        h1 { font-size: clamp(23px, 3vw, 31px); font-weight: 700; line-height: 1.25; margin-bottom: 10px; }
        h2 { font-size: 19px; font-weight: 700; margin-bottom: 12px; }
        .lede { font-size: 16px; color: var(--muted); line-height: 1.7; margin-bottom: 28px; }

        .card {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 24px; margin-bottom: 18px;
        }
        .card-tight { padding: 18px 22px; }

        .pill {
            display: inline-block; font-size: 12.5px; font-weight: 600; padding: 4px 11px;
            border-radius: 999px; background: var(--brand-light); color: var(--brand-dark);
        }
        .pill-grey { background: #f1f4f6; color: var(--muted); }
        .pill-warn { background: #fff4e5; color: #7a4b00; }
        .pill-stop { background: #fdeceb; color: #8a1a14; }

        .meta { font-size: 13.5px; color: var(--muted); line-height: 1.7; }
        .ref { font-family: ui-monospace, Menlo, monospace; font-weight: 600; letter-spacing: .04em; }

        label { display: block; font-size: 14.5px; font-weight: 600; margin-bottom: 7px; }
        input[type=text], input[type=email], input[type=file], textarea, select {
            width: 100%; padding: 11px 13px; font-family: var(--font); font-size: 15px;
            color: var(--ink); background: #fff; border: 1px solid #ccd3da; border-radius: 8px;
        }
        textarea { min-height: 150px; resize: vertical; line-height: 1.65; }
        input:focus, textarea:focus, select:focus { outline: 2px solid var(--brand); outline-offset: 1px; }

        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px;
            border-radius: 9px; font-family: var(--font); font-size: 15px; font-weight: 600;
            cursor: pointer; border: none; background: var(--brand); color: #fff;
        }
        .btn:hover { background: var(--brand-dark); text-decoration: none; color: #fff; }
        .btn-outline { background: transparent; color: var(--brand-dark); border: 2px solid var(--brand); }
        .btn-outline:hover { background: var(--brand-light); color: var(--brand-dark); }
        .btn-quiet { background: #f1f4f6; color: var(--ink); }
        .btn-quiet:hover { background: #e6ebee; color: var(--ink); }
        .btn-danger { background: transparent; color: #8a1a14; border: 1px solid #e0a9a5; }
        .btn-danger:hover { background: #fdeceb; color: #8a1a14; }
        .btn[disabled] { opacity: .6; cursor: not-allowed; }

        .flash {
            background: var(--brand-light); border: 1px solid #b9e3a4; color: #24571a;
            padding: 15px 19px; border-radius: var(--radius); margin-bottom: 22px;
            font-size: 14.5px; line-height: 1.6;
        }
        .errs {
            background: #fff5f5; border: 1px solid #f0b4b0; color: #8a1a14;
            padding: 15px 19px; border-radius: var(--radius); margin-bottom: 22px;
            font-size: 14.5px; line-height: 1.6;
        }
        .errs ul { margin: 6px 0 0 20px; }

        .check { display: flex; gap: 11px; align-items: flex-start; margin-bottom: 14px; }
        .check input { margin-top: 3px; width: 17px; height: 17px; flex-shrink: 0; accent-color: var(--brand); }
        .check label { font-weight: 400; font-size: 14.5px; line-height: 1.6; margin: 0; }

        .foot { font-size: 13px; color: var(--muted); text-align: center; padding: 0 24px 40px; line-height: 1.7; }
    </style>
</head>
<body>

<div class="bar">
    <div class="bar-inner">
        <a href="{{ auth('candidate')->check() ? route('candidate.home') : route('careers.index') }}" class="bar-brand">
            Naija Virtual Notary
        </a>

        @auth('candidate')
            <form method="POST" action="{{ route('candidate.logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        @endauth
    </div>
</div>

<main class="shell @yield('shell-class')">
    @if (session('status'))
        <div class="flash" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="errs" role="alert">
            <strong>Please check the following:</strong>
            <ul>
                @foreach ($errors->all() as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

<p class="foot">
    We never ask applicants for money or for bank details.
    <a href="{{ route('careers.privacy') }}">Recruitment privacy notice</a>
</p>

</body>
</html>
