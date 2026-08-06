@extends('layouts.public', [
    'title' => ($resubscribed ? 'Subscribed again' : 'Unsubscribed') . ' — Naija Virtual Notary',
    'description' => 'Manage the announcement emails you receive from Naija Virtual Notary.',
])

@push('styles')
<style>
    .prefs-wrap { max-width: 560px; margin: 80px auto 120px; padding: 0 24px; text-align: center; }
    .prefs-card {
        background: var(--surface); border: 1px solid var(--line);
        border-radius: var(--radius); padding: 40px 32px; box-shadow: var(--shadow);
    }
    .prefs-mark { font-size: 44px; line-height: 1; margin-bottom: 16px; }
    .prefs-card h1 { font-size: 24px; font-weight: 700; margin-bottom: 12px; }
    .prefs-card p { color: var(--muted); line-height: 1.65; margin-bottom: 14px; }
    .prefs-email { font-weight: 600; color: var(--ink); }
    .prefs-actions { margin-top: 24px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
    .prefs-btn {
        display: inline-block; padding: 12px 22px; border-radius: 10px;
        font-weight: 600; font-size: 15px; border: 1px solid var(--line);
    }
    .prefs-btn.primary { background: var(--brand); color: #fff; border-color: var(--brand); }
</style>
@endpush

@section('content')
<div class="prefs-wrap">
    <div class="prefs-card">
        <div class="prefs-mark">{{ $resubscribed ? '📬' : '✅' }}</div>

        @if ($resubscribed)
            <h1>You're back on the list</h1>
            <p><span class="prefs-email">{{ $user->email }}</span> will receive our announcements again.</p>
        @else
            <h1>You've been unsubscribed</h1>
            <p>We won't send announcements to <span class="prefs-email">{{ $user->email }}</span> any more.</p>
            <p>
                Email about your own notarizations still comes through — when a document
                is ready, when a session is about to start, when a notary replies. Those
                aren't announcements, and switching them off would leave you waiting on
                work you paid for.
            </p>
        @endif

        <div class="prefs-actions">
            <a class="prefs-btn primary" href="{{ route('home') }}">Back to the site</a>

            @unless ($resubscribed)
                <a class="prefs-btn" href="{{ \Illuminate\Support\Facades\URL::signedRoute('email.resubscribe', ['user' => $user->id]) }}">
                    Undo — keep me subscribed
                </a>
            @endunless
        </div>
    </div>
</div>
@endsection
