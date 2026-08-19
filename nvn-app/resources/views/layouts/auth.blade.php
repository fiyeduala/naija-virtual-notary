<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Naija Virtual Notary' }}</title>
    @include('partials.pwa-head')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --brand:#54B435; --brand-dark:#3d8a27; --ink:#1f2933; --muted:#5F5E5A; --line:#e3e6ea; --bg:#EDFBE2; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:'Poppins',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif; background:var(--bg); color:var(--ink); }
        .wrap { min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; }
        .card { background:#fff; width:100%; max-width:420px; border:1px solid var(--line); border-radius:14px; padding:32px; }
        .brand { text-align:center; margin-bottom:24px; }
        .brand h1 { color:var(--brand-dark); font-size:20px; margin:0; }
        .brand p { color:var(--muted); font-size:13px; margin:4px 0 0; }
        .brand-logo { height:44px; width:auto; max-width:100%; margin:0 auto; display:block; }
        label { display:block; font-size:13px; font-weight:600; margin:14px 0 6px; }
        input[type=text],input[type=email],input[type=tel],input[type=password] {
            width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px; font-size:15px;
        }
        input:focus { outline:none; border-color:var(--brand); }
        .check { display:flex; gap:8px; align-items:flex-start; margin-top:16px; font-size:13px; color:var(--muted); }
        .check input { margin-top:2px; }
        button { width:100%; margin-top:20px; padding:12px; background:var(--brand); color:#fff; border:0; border-radius:9px; font-size:15px; font-weight:600; cursor:pointer; }
        button:hover { background:var(--brand-dark); }
        .alt { text-align:center; margin-top:18px; font-size:13px; color:var(--muted); }
        .alt a { color:var(--brand); text-decoration:none; font-weight:600; }
        .errors { background:#fdecec; border:1px solid #f5c2c2; color:#a12626; border-radius:9px; padding:10px 12px; font-size:13px; margin-bottom:8px; }
        .errors ul { margin:0; padding-left:18px; }
        .status { background:#e8f3ea; border:1px solid #bcdcc3; color:#256b34; border-radius:9px; padding:10px 12px; font-size:13px; margin-bottom:8px; }
        .otp-input { letter-spacing:8px; text-align:center; font-size:22px; }
        .muted-link { background:none; color:var(--brand); width:auto; padding:0; margin-top:12px; font-weight:600; }
        /* A second door on the sign-up page: notaries who land here are not
           clients and need sending somewhere else before they fill the form in. */
        .aside { margin-top:22px; padding-top:18px; border-top:1px solid var(--line); }
        .aside h2 { font-size:14px; margin:0 0 6px; color:var(--ink); }
        .aside p { font-size:13px; line-height:1.55; color:var(--muted); margin:0 0 12px; }
        .aside a.aside-btn {
            display:block; text-align:center; padding:11px 12px;
            border:1px solid var(--brand); border-radius:9px;
            color:var(--brand-dark); font-size:14px; font-weight:600; text-decoration:none;
        }
        .aside a.aside-btn:hover { background:var(--bg); }
    </style>
    @include('partials.meta-pixel')
</head>
<body>
    <div class="wrap">
        <div class="card">
            <div class="brand">
                @if ($authLogo = \App\Support\Branding::logoUrl())
                    <img src="{{ $authLogo }}" alt="{{ config('app.name') }}" class="brand-logo">
                @else
                    <h1>Naija Virtual Notary</h1>
                @endif
                <p>{{ $subtitle ?? 'Secure online notarization' }}</p>
            </div>

            @if (session('status'))
                <div class="status">{{ session('status') }}</div>
            @endif

            @if ($errors->any())
                <div class="errors">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </div>
    </div>
@include('partials.live-chat')
</body>
</html>
