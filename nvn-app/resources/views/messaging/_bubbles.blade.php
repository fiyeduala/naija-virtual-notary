{{-- Renders a list of messages. Expects $messages (collection) and optionally
     $viewerId to right-align the current viewer's own messages.
     Styling lives in layouts/app.blade.php (.bubble*) so the client, notary and
     admin threads all look identical. --}}
@php $viewerId = $viewerId ?? auth()->id(); @endphp

<div class="bubbles">
    @forelse ($messages as $m)
        @php
            $mine    = $m->sender_user_id === $viewerId;
            $support = $m->sender_role === \App\Enums\UserRole::Admin && ! $mine;
        @endphp
        <div class="bubble {{ $mine ? 'mine' : 'theirs' }} {{ $support ? 'support' : '' }}">
            <div class="bubble-meta">
                {{ $m->senderDisplayName() }} · {{ $m->created_at->format('j M, g:i A') }}
            </div>
            <div class="bubble-body">{{ $m->body }}</div>
        </div>
    @empty
        <p class="muted text-sm" style="text-align:center; margin:auto 0;">No messages yet — say hello.</p>
    @endforelse
</div>
