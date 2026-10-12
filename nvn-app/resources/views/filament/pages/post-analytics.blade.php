@php
    $stats    = $this->stats();
    $series   = $this->series();
    $readers  = $this->readers();
    $sources  = $this->sources();
    $comments = $this->comments();
    $bounced  = $this->bounced();
    $peak     = max(1, max($series['values']));
@endphp

<x-filament-panels::page>
    {{-- The four numbers. Views and reads sit side by side on purpose: the gap
         between them is the only honest measure of whether the article held
         anybody. --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Views', number_format($stats['views']), $stats['people'] . ' ' . str('person')->plural($stats['people']) . ', once each per day'],
            ['Real reads', number_format($stats['reads']), $stats['rate'] . '% of everyone who opened it'],
            ['Average time on it', $stats['dwell'] > 0 ? floor($stats['dwell'] / 60) . 'm ' . ($stats['dwell'] % 60) . 's' : '—', 'Of a ' . $stats['minutes'] . ' min article'],
            ['Comments', number_format($stats['comments']), $stats['waiting'] > 0 ? $stats['waiting'] . ' waiting on you' : 'None waiting'],
        ] as [$label, $value, $note])
            <x-filament::section>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="mt-1 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $value }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $note }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section>
        <x-slot name="heading">Views, last 30 days</x-slot>
        <x-slot name="description">
            Nothing before tracking went live is here — those visits were never recorded.
        </x-slot>

        @if (array_sum($series['values']) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No views recorded in the last 30 days.
            </p>
        @else
            {{-- Bars in plain CSS rather than a charting library. Thirty
                 integers do not need Chart.js, and this cannot fail to load. --}}
            <div class="flex items-end gap-1" style="height: 140px;">
                @foreach ($series['values'] as $i => $count)
                    <div class="flex-1 flex flex-col justify-end items-center gap-1"
                         title="{{ $series['labels'][$i] }}: {{ $count }}">
                        <div class="w-full rounded-t bg-primary-500/80"
                             style="height: {{ $count === 0 ? 2 : round(($count / $peak) * 120) }}px"></div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>{{ $series['labels'][0] }}</span>
                <span>{{ $series['labels'][count($series['labels']) - 1] }}</span>
            </div>
        @endif
    </x-filament::section>

    <div class="grid gap-4 lg:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">Readers we can name</x-slot>
            <x-slot name="description">
                Only people who were signed in. Most readers of a marketing article
                have no account, so this is a small part of the
                {{ number_format($stats['reads']) }} {{ str('read')->plural($stats['reads']) }}
                above — not all of them.
            </x-slot>

            @forelse ($readers as $view)
                <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-white/5 last:border-0">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-950 dark:text-white truncate">
                            {{ $view->user?->full_name ?? 'Deleted account' }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                            {{ $view->user?->email }}
                        </p>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                        {{ $view->read_at?->format('j M, g:ia') }}
                    </p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Nobody signed in has read it through yet.
                </p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Where they came from</x-slot>
            <x-slot name="description">
                The referring site only. Full URLs are never stored — they carry
                campaign ids and search terms.
            </x-slot>

            @forelse ($sources as $source)
                <div class="flex items-center justify-between gap-3 py-2 border-b border-gray-100 dark:border-white/5 last:border-0">
                    <p class="text-sm text-gray-950 dark:text-white truncate">{{ $source->referrer_host }}</p>
                    <p class="text-sm font-medium tabular-nums text-gray-500 dark:text-gray-400">{{ $source->hits }}</p>
                </div>
            @empty
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Every visit so far arrived with no referrer — typed in, from a
                    message, or from an app that strips it.
                </p>
            @endforelse

            @if ($bounced > 0)
                <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                    {{ number_format($bounced) }} {{ str('visit')->plural($bounced) }}
                    opened it and left without reading.
                </p>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Comments on this article</x-slot>

        @forelse ($comments as $comment)
            <div class="py-3 border-b border-gray-100 dark:border-white/5 last:border-0">
                <div class="flex items-center gap-2 flex-wrap text-xs text-gray-500 dark:text-gray-400">
                    <span class="font-medium text-gray-950 dark:text-white">{{ $comment->author_name }}</span>
                    @if ($comment->fromAccount())
                        <x-filament::badge size="xs" color="success">Account</x-filament::badge>
                    @endif
                    <x-filament::badge size="xs" :color="match ($comment->status) {
                        'approved' => 'success',
                        'pending'  => 'warning',
                        'spam'     => 'danger',
                        default    => 'gray',
                    }">{{ ucfirst($comment->status) }}</x-filament::badge>
                    @if ($comment->parent_id)
                        <span>reply</span>
                    @endif
                    <span>{{ $comment->created_at->format('j M Y, g:ia') }}</span>
                </div>

                {{-- Escaped, like everywhere else a comment is shown. --}}
                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $comment->body }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No comments on this one.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
