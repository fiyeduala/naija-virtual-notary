@php($d = \App\Filament\Widgets\PushHealth::diagnosis())

@if ($d)
    <x-filament-widgets::widget>
        <div style="
            border: 1px solid rgb(251 191 36 / .45);
            background: rgb(254 252 232 / .9);
            border-radius: .75rem;
            padding: 1rem 1.25rem;
        " class="dark:!bg-amber-950/30 dark:!border-amber-500/30">
            <div style="display:flex; gap:.75rem; align-items:flex-start;">
                <div style="color:#d97706; flex:none; margin-top:.1rem;">
                    @svg('heroicon-o-bell-slash', ['style' => 'width:1.35rem;height:1.35rem;'])
                </div>

                <div style="min-width:0;">
                    <p style="font-weight:600; font-size:.95rem; margin:0;"
                       class="text-gray-950 dark:text-white">
                        {{ $d['title'] }}
                    </p>

                    <p style="font-size:.825rem; line-height:1.55; margin:.35rem 0 0;"
                       class="text-gray-600 dark:text-gray-300">
                        {{ $d['body'] }}
                    </p>

                    <ol style="font-size:.8rem; line-height:1.6; margin:.6rem 0 0; padding-left:1.15rem;"
                        class="text-gray-600 dark:text-gray-300">
                        @foreach ($d['fix'] as $step)
                            <li>
                                @if (str_starts_with($step, 'php artisan'))
                                    <code style="font-size:.78rem;">{{ $step }}</code>
                                @else
                                    {{ $step }}
                                @endif
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </x-filament-widgets::widget>
@endif
