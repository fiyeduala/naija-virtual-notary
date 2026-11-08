{{--
 | Who is on the platform.
 |
 | Three sections: accounts, notaries, then a row per notary. Every figure is a
 | count of rows — see App\Support\PeopleSummary, which is the only place any of
 | them is worked out. A tile with a zero still prints the zero: "none awaiting
 | review" is an answer, and hiding it would make the page look like it had not
 | checked.
--}}
<x-filament-panels::page>

    @php
        // A tile is [label, value, hint]. Kept as data so the markup below is
        // one loop rather than twenty near-identical divs that drift apart.
        $accountTiles = [
            ['Total accounts',  number_format($users['total']),      $users['archived'] . ' archived'],
            ['Clients',         number_format($users['clients']),    $users['from_organization'] . ' referred by a partner body'],
            ['Notary accounts', number_format($users['notaries']),   $users['admins'] . ' ' . str('administrator')->plural($users['admins'])],
            ['New this month',  number_format($users['new_month']),  $users['new_week'] . ' in the last 7 days'],
        ];

        $accountDetail = [
            ['Email verified',      $users['verified'],        $users['unverified'] . ' not yet verified'],
            ['Signed in at least once', $users['signed_in'],   $users['never_signed_in'] . ' never signed in'],
            ['Signed in this month', $users['active_month'],   'of ' . $users['total'] . ' accounts'],
            ['Suspended',           $users['suspended'],       $users['pending'] . ' pending'],
            ['Imported from WordPress', $users['imported'],    $users['imported_live'] . ' of them have signed in'],
            ['Opted out of bulk email', $users['email_opted_out'], 'excluded from every campaign'],
        ];

        $notaryTiles = [
            ['Notary profiles',   number_format($notaries['total']),     $notaries['archived'] . ' archived'],
            ['Listed publicly',   number_format($notaries['listed']),    'bookable in the marketplace right now'],
            ['Able to seal',      number_format($notaries['can_seal']),  $notaries['cannot_seal'] . ' missing a signature, stamp or seal'],
            ['Awaiting review',   number_format($notaries['awaiting_review']), 'fee paid, waiting on a decision'],
        ];

        $notaryDetail = [
            ['Approved',             $notaries['approved'],          $notaries['pending'] . ' pending · ' . $notaries['rejected'] . ' rejected'],
            ['Membership in date',   $notaries['membership_live'],   $notaries['membership_soon'] . ' ending soon · ' . $notaries['membership_lapsed'] . ' lapsed'],
            ['In the listing queue', $notaries['listing_queue'],     'asked to be listed, not yet answered'],
            ['Onboarding fee unpaid', $notaries['fee_unpaid'],       'an application, not a queue'],
            ['Individuals',          $notaries['individual'],        $notaries['firm'] . ' ' . str('firm')->plural($notaries['firm'])],
            ['Platform-owned',       $notaries['system_native'],     'the system-native notary'],
        ];

        $statusColours = [
            'approved'  => 'success',
            'pending'   => 'warning',
            'rejected'  => 'danger',
            'suspended' => 'danger',
        ];
    @endphp

    {{-- ───────── Accounts ───────── --}}
    <x-filament::section>
        <x-slot name="heading">Accounts</x-slot>
        <x-slot name="description">
            Every user record on the platform, by role and by state. Soft-deleted rows are excluded
            from these counts and reported separately as archived.
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::link :href="\App\Filament\Resources\UserResource::getUrl('index')" size="sm">
                Open the user list
            </x-filament::link>
        </x-slot>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($accountTiles as [$label, $value, $hint])
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <dl class="mt-6 divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($accountDetail as [$label, $value, $hint])
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-sm text-gray-700 dark:text-gray-300">
                        {{ $label }}
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    {{-- ───────── Notaries ───────── --}}
    <x-filament::section>
        <x-slot name="heading">Notaries</x-slot>
        <x-slot name="description">
            "Able to seal" is not a column — it asks the same question the editor asks before it offers
            a notary's marks, so this figure and the gate that stops a job finishing cannot disagree.
        </x-slot>

        <x-slot name="headerEnd">
            <x-filament::link :href="\App\Filament\Resources\NotaryProfileResource::getUrl('index')" size="sm">
                Open the notary list
            </x-filament::link>
        </x-slot>

        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($notaryTiles as [$label, $value, $hint])
                <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <dl class="mt-6 divide-y divide-gray-100 dark:divide-white/10">
            @foreach ($notaryDetail as [$label, $value, $hint])
                <div class="flex items-baseline justify-between gap-4 py-2.5">
                    <dt class="text-sm text-gray-700 dark:text-gray-300">
                        {{ $label }}
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</span>
                    </dt>
                    <dd class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>

    {{-- ───────── Every notary ───────── --}}
    <x-filament::section>
        <x-slot name="heading">Every notary</x-slot>
        <x-slot name="description">
            Busiest first. "Owed" is what a payout run would hand over right now — the figure comes
            from the payout service itself, not from a second sum written for this screen.
        </x-slot>

        {{-- Its own scroller: a wide table must never make the panel scroll sideways. --}}
        <div class="-mx-2 overflow-x-auto px-2">
            <table class="w-full min-w-[56rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pe-3 font-medium">Notary</th>
                        <th class="py-2 pe-3 font-medium">Status</th>
                        <th class="py-2 pe-3 font-medium">Listed</th>
                        <th class="py-2 pe-3 font-medium">Can seal</th>
                        <th class="py-2 pe-3 font-medium">Membership</th>
                        <th class="py-2 pe-3 text-right font-medium">Jobs</th>
                        <th class="py-2 pe-3 text-right font-medium">Owed</th>
                        <th class="py-2 font-medium">Last signed in</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @forelse ($notaryRows as $row)
                        <tr>
                            <td class="py-3 pe-3 align-top">
                                <a href="{{ \App\Filament\Resources\NotaryProfileResource::getUrl('view', ['record' => $row['id']]) }}"
                                   class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $row['name'] }}</a>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $row['email'] }}</span>
                                @if ($row['system'])
                                    <span class="mt-1 inline-block text-xs text-gray-500 dark:text-gray-400">Platform-owned</span>
                                @endif
                            </td>
                            <td class="py-3 pe-3 align-top">
                                <x-filament::badge :color="$statusColours[$row['status']] ?? 'gray'">
                                    {{ ucfirst($row['status']) }}
                                </x-filament::badge>
                            </td>
                            <td class="py-3 pe-3 align-top text-gray-700 dark:text-gray-300">
                                {{ $row['listed'] ? 'Yes' : 'No' }}
                            </td>
                            <td class="py-3 pe-3 align-top">
                                @if ($row['can_seal'])
                                    <span class="text-success-700 dark:text-success-400">Yes</span>
                                @else
                                    <span class="text-danger-700 dark:text-danger-400">No</span>
                                    {{-- Naming the missing marks is the difference between "chase
                                         them" and "ask them for the stamp". --}}
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">
                                        missing {{ implode(', ', str_replace('_', ' ', $row['missing'])) }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 pe-3 align-top text-gray-700 dark:text-gray-300">
                                @if ($row['system'])
                                    <span class="text-gray-500 dark:text-gray-400">Not applicable</span>
                                @elseif ($row['member_until'])
                                    {{ $row['member_until']->format('j M Y') }}
                                    <span class="block text-xs {{ $row['member_days'] !== null && $row['member_days'] < 0 ? 'text-danger-700 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">
                                        @if ($row['member_days'] === null)
                                            &nbsp;
                                        @elseif ($row['member_days'] < 0)
                                            lapsed {{ abs($row['member_days']) }} {{ str('day')->plural(abs($row['member_days'])) }} ago
                                        @else
                                            {{ $row['member_days'] }} {{ str('day')->plural($row['member_days']) }} left
                                        @endif
                                    </span>
                                @else
                                    <span class="text-gray-500 dark:text-gray-400">Never set</span>
                                @endif
                            </td>
                            <td class="py-3 pe-3 align-top text-right tabular-nums text-gray-950 dark:text-white">{{ number_format($row['jobs']) }}</td>
                            <td class="py-3 pe-3 align-top text-right tabular-nums text-gray-950 dark:text-white">{{ \App\Support\Analytics::money($row['owed']) }}</td>
                            <td class="py-3 align-top text-gray-700 dark:text-gray-300">
                                {{ $row['last_login'] ? $row['last_login']->diffForHumans() : 'Never' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                No notary profiles yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    {{-- ───────── Newest accounts ───────── --}}
    <x-filament::section collapsible>
        <x-slot name="heading">Newest accounts</x-slot>
        <x-slot name="description">The last {{ $recentUsers->count() }} to register, newest first.</x-slot>

        <div class="-mx-2 overflow-x-auto px-2">
            <table class="w-full min-w-[40rem] text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="py-2 pe-3 font-medium">Name</th>
                        <th class="py-2 pe-3 font-medium">Role</th>
                        <th class="py-2 pe-3 font-medium">Registered</th>
                        <th class="py-2 font-medium">Signed in</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($recentUsers as $user)
                        <tr>
                            <td class="py-3 pe-3">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $user->full_name }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $user->email }}</span>
                            </td>
                            <td class="py-3 pe-3 text-gray-700 dark:text-gray-300">
                                {{ $user->role?->label() ?? '—' }}
                                @if ($user->legacy_source)
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">imported</span>
                                @endif
                            </td>
                            <td class="py-3 pe-3 text-gray-700 dark:text-gray-300">{{ $user->created_at?->format('j M Y') ?? '—' }}</td>
                            <td class="py-3 text-gray-700 dark:text-gray-300">{{ $user->last_login_at ? $user->last_login_at->diffForHumans() : 'Never' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

</x-filament-panels::page>
