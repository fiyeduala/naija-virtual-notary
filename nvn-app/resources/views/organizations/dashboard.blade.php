@extends('layouts.app', ['title' => $organization->name . ' — partner portal'])

{{--
    A partner body's own numbers.

    What is absent is the design: no client name, no contact detail, no
    document, no link to one. A body referred the work and is entitled to know
    what state it is in; it is not a party to the notarization and has no
    standing to see what was notarized or for whom.

    The earnings panel appears only under the commission arrangement. A body
    that is charged a rate earns nothing, and a row of zeroes would only raise
    a question whose answer is "that is not your arrangement".
--}}

@push('styles')
<style>
    .org-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:20px; }
    .org-stat { background:var(--surface); border:1px solid var(--line); border-radius:var(--radius-lg);
                padding:18px 20px; box-shadow:var(--shadow-sm); }
    .org-stat .n { font-size:26px; font-weight:800; color:var(--ink); line-height:1.1;
                   font-variant-numeric:tabular-nums; }
    .org-stat .l { font-size:12px; color:var(--muted); margin-top:4px; }
    @media (max-width:860px) { .org-stats { grid-template-columns:1fr 1fr; } }

    .org-link { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .org-link code { flex:1; min-width:220px; background:var(--bg); border:1px solid var(--line);
                     border-radius:var(--radius-sm); padding:11px 13px; font-size:13.5px;
                     word-break:break-all; color:var(--ink); }

    .org-table { width:100%; border-collapse:collapse; font-size:13.5px; }
    .org-table th { text-align:left; font-size:11.5px; text-transform:uppercase; letter-spacing:.04em;
                    color:var(--muted); font-weight:600; padding:0 10px 9px; }
    .org-table td { padding:11px 10px; border-top:1px solid var(--line); vertical-align:top; }
    .org-table td.num, .org-table th.num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .org-scroll { overflow-x:auto; }
</style>
@endpush

@section('content')
<div class="page-hd">
    <div class="page-hd-inner">
        <h1>{{ $organization->name }}</h1>
        <div class="sub">
            {{ $organization->arrangementLabel() }} ·
            your applicants are quoted {{ $organization->displayDefaultPrice('NGN') }} as standard
        </div>
    </div>
</div>

<div class="shell">
    {{-- Sign-out lives here rather than in the navbar: the navbar reads the
         default guard, and an organization is signed in on its own. --}}
    <div style="display:flex; justify-content:flex-end; margin-bottom:14px;">
        <form method="POST" action="{{ route('organization.logout') }}" style="margin:0;">
            @csrf
            <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
        </form>
    </div>

    <div class="org-stats">
        <div class="org-stat">
            <div class="n">{{ number_format($counts['referred']) }}</div>
            <div class="l">Notarizations referred</div>
        </div>
        <div class="org-stat">
            <div class="n">{{ number_format($counts['inProgress']) }}</div>
            <div class="l">In progress</div>
        </div>
        <div class="org-stat">
            <div class="n">{{ number_format($counts['completed']) }}</div>
            <div class="l">Completed</div>
        </div>
        <div class="org-stat">
            <div class="n">{{ number_format($counts['people']) }}</div>
            <div class="l">People who signed up through you</div>
        </div>
    </div>

    <div class="card" style="margin-bottom:16px;">
        <h2 style="font-size:15px; margin:0 0 4px;">Your link</h2>
        <p class="text-sm muted" style="margin:0 0 14px;">
            Give this to anyone you are sending to us. Work that arrives through it
            is notarized by our own notary public and priced at your agreed rate.
        </p>

        <div class="org-link">
            <code id="org-link">{{ $organization->landingUrl() }}</code>
            <button class="btn btn-sm" type="button" data-copy="#org-link">Copy link</button>
        </div>

        <div class="org-link" style="margin-top:12px;">
            <code id="org-short">{{ $organization->shortUrl() }}</code>
            <button class="btn btn-ghost btn-sm" type="button" data-copy="#org-short">Copy short link</button>
        </div>

        <p class="text-sm muted" style="margin:14px 0 0;">
            For a form or a letter, your code is
            <strong style="color:var(--ink);">{{ $organization->code }}</strong> — anyone can
            type it at <span style="color:var(--ink);">{{ route('organization.code') }}</span>.
        </p>
    </div>

    @if ($earnings)
    <div class="card" style="margin-bottom:16px;">
        <h2 style="font-size:15px; margin:0 0 14px;">
            Your commission
            <span class="pill" style="margin-left:6px;">{{ $organization->commission_rate }}%</span>
        </h2>

        <div class="grid-3">
            <div>
                <div style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">
                    {{ \App\Models\NotarizationRequest::money($earnings['owed'], 'NGN') }}
                </div>
                <div class="text-sm muted">Owed to you now</div>
            </div>
            <div>
                <div style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">
                    {{ \App\Models\NotarizationRequest::money($earnings['paid'], 'NGN') }}
                </div>
                <div class="text-sm muted">Paid to you so far</div>
            </div>
            <div>
                <div style="font-size:20px; font-weight:700; font-variant-numeric:tabular-nums;">
                    {{ \App\Models\NotarizationRequest::money($gross, 'NGN') }}
                </div>
                <div class="text-sm muted">Total your referrals have paid</div>
            </div>
        </div>

        <p class="text-sm muted" style="margin:14px 0 0;">
            Commission is earned when a notarization is completed, not when it is paid for,
            and is settled by bank transfer to {{ $organization->bank_name ?: 'your account' }}
            ({{ $organization->maskedAccountNumber() }}).
        </p>

        @if ($earnings['payouts']->isNotEmpty())
        <div class="org-scroll" style="margin-top:18px;">
            <table class="org-table">
                <thead>
                    <tr>
                        <th>Payout</th>
                        <th>Period</th>
                        <th class="num">Amount</th>
                        <th>Status</th>
                        <th>Settled</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($earnings['payouts'] as $payout)
                    <tr>
                        <td style="font-weight:600;">{{ $payout->reference }}</td>
                        <td class="muted">
                            @if ($payout->period_start)
                                {{ $payout->period_start->format('j M Y') }} –
                                {{ $payout->period_end?->format('j M Y') }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="num">{{ $payout->displayAmount() }}</td>
                        <td>
                            @if ($payout->isPaid())
                                <span class="pill pill-approved">Paid</span>
                            @elseif ($payout->isFailed())
                                <span class="pill pill-rejected">Cancelled</span>
                            @else
                                <span class="pill pill-pending">Pending</span>
                            @endif
                        </td>
                        <td class="muted">
                            @if ($payout->isPaid())
                                {{ $payout->settlementLabel() }}
                                @if ($payout->settlement_reference)
                                    <br><span style="font-size:12px;">Ref {{ $payout->settlement_reference }}</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    @endif

    @if ($services->isNotEmpty())
    <div class="card" style="margin-bottom:16px;">
        <h2 style="font-size:15px; margin:0 0 4px;">Your rates</h2>
        <p class="text-sm muted" style="margin:0 0 14px;">
            What your applicants are quoted, per document.
        </p>
        <div class="org-scroll">
            <table class="org-table">
                <thead><tr><th>Notarization</th><th class="num">Your rate</th></tr></thead>
                <tbody>
                @foreach ($services as $service)
                    <tr>
                        <td>{{ $service['name'] }}</td>
                        <td class="num" style="font-weight:600;">{{ $service['price'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <div class="card">
        <h2 style="font-size:15px; margin:0 0 4px;">Work you have sent</h2>
        <p class="text-sm muted" style="margin:0 0 14px;">
            By reference, newest first. We do not show who sent a document or what
            is in it — that stays between the client and the notary.
        </p>

        @if ($requests->isEmpty())
            <p class="text-sm muted" style="margin:0;">
                Nothing yet. Once someone uses your link, their notarizations appear here.
            </p>
        @else
        <div class="org-scroll">
            <table class="org-table">
                <thead>
                    <tr>
                        <th>Reference</th>
                        <th>Notarization</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th class="num">Charged</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($requests as $req)
                    @php
                        $status = $req->status instanceof \App\Enums\RequestStatus
                            ? $req->status
                            : \App\Enums\RequestStatus::tryFrom((string) $req->status);
                    @endphp
                    <tr>
                        <td style="font-weight:600;">{{ $req->reference }}</td>
                        <td class="muted">{{ $req->service?->service_type ?? '—' }}</td>
                        <td class="muted">{{ $req->created_at->format('j M Y') }}</td>
                        <td>
                            <span class="pill {{ $status === \App\Enums\RequestStatus::Completed ? 'pill-approved' : '' }}">
                                {{ $status?->label() ?? $req->status }}
                            </span>
                        </td>
                        <td class="num">{{ $req->displayFeeOrPending() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px;">{{ $requests->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
        var target = document.querySelector(button.dataset.copy);
        if (!target) return;

        var text = target.textContent.trim();

        // A copy that silently fails is worse than no button, so the label
        // itself is the confirmation, and a browser without the clipboard API
        // is handed the text to copy by hand.
        var done = function () {
            var was = button.textContent;
            button.textContent = 'Copied';
            setTimeout(function () { button.textContent = was; }, 1600);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(done, function () {
                window.prompt('Copy this link:', text);
            });
        } else {
            window.prompt('Copy this link:', text);
        }
    });
});
</script>
@endpush
