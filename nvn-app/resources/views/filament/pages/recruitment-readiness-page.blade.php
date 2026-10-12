{{--
 | The launch checklist, and the rollout note under it.
 |
 | Three statuses, three colours, and a "what to do" line on anything that is
 | not already in order — because a checklist that reports a problem without
 | saying where the switch is just moves the search somewhere else.
--}}
<x-filament-panels::page>

    @php
        $colours = [
            'ok'   => ['dot' => 'bg-success-500', 'text' => 'text-success-700 dark:text-success-400', 'word' => 'In order'],
            'warn' => ['dot' => 'bg-warning-500', 'text' => 'text-warning-700 dark:text-warning-400', 'word' => 'Unfinished'],
            'fail' => ['dot' => 'bg-danger-500',  'text' => 'text-danger-700 dark:text-danger-400',   'word' => 'Would go wrong'],
        ];
    @endphp

    <x-filament::section>
        <x-slot name="heading">
            @if ($tally['fail'] > 0)
                Not ready — {{ $tally['fail'] }} {{ str('thing')->plural($tally['fail']) }} would go wrong
            @elseif ($tally['warn'] > 0)
                Nothing would break, {{ $tally['warn'] }} {{ str('item')->plural($tally['warn']) }} unfinished
            @else
                Everything that can be checked is in order
            @endif
        </x-slot>

        <x-slot name="description">
            {{ $tally['ok'] }} in order · {{ $tally['warn'] }} unfinished · {{ $tally['fail'] }} would go wrong.
            This page checks configuration and data. It cannot check that your mail actually
            arrives, that your cron really fires, or that your privacy notice is lawful — the
            items below say which of them are inferred rather than verified.
        </x-slot>

        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($checks as $check)
                @php $c = $colours[$check['status']] ?? $colours['warn']; @endphp

                <div class="flex gap-3 py-4">
                    <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full {{ $c['dot'] }}"></span>

                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-medium text-gray-950 dark:text-white">
                            {{ $check['label'] }}
                            <span class="ms-1 text-xs font-normal {{ $c['text'] }}">— {{ $c['word'] }}</span>
                        </p>

                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $check['detail'] }}</p>

                        @if ($check['fix'])
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <span class="font-medium">What to do:</span> {{ $check['fix'] }}
                            </p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">The part no check can see</x-slot>
        <x-slot name="description">
            Decisions and facts that have to come from you. Nothing above can tell whether these
            are done, so they are listed rather than reported.
        </x-slot>

        <ul class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
            <li>
                <span class="font-medium text-gray-950 dark:text-white">The terms of each job.</span>
                Salary or the decision not to disclose one, employment type, which countries somebody
                may work from, the hours and the deadline. This module refuses to publish a vacancy
                until they are on the record, and it will never fill one of them in for you — an
                invented salary is a promise somebody did not make.
            </li>
            <li>
                <span class="font-medium text-gray-950 dark:text-white">Whether a role needs identity documents or a guarantor.</span>
                Both are off unless you switch them on for that vacancy with a stated reason, and
                neither is ever asked for on the application form — only after an offer.
            </li>
            <li>
                <span class="font-medium text-gray-950 dark:text-white">Send yourself one of everything first.</span>
                Apply to a draft vacancy as a fictional candidate from a mailbox you own, and walk it
                through to hired. The acknowledgment, the sign-in link, the invitation and the
                interview invitation are the four that matter, because a candidate who gets none of
                them does not complain — they simply disappear.
            </li>
            <li>
                <span class="font-medium text-gray-950 dark:text-white">A backup you have actually restored.</span>
                Take one before enabling the feature switch and confirm it restores somewhere that is
                not the live server. Rolling this module back is one line —
                <code>NVN_RECRUITMENT_ENABLED=false</code> — which hides every careers page and this
                whole panel group while leaving the data untouched; the backup is for the case where
                you need the schema gone too.
            </li>
            <li>
                <span class="font-medium text-gray-950 dark:text-white">Who holds which recruitment role.</span>
                A reviewer sees only the vacancies they are assigned to and never identity documents.
                Only an HR administrator sees those. Nobody needs a platform administrator account to
                read applications, and giving somebody one to save a step hands them the payments
                ledger as well.
            </li>
        </ul>
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
