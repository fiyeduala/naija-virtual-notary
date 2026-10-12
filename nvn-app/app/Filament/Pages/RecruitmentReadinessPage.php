<?php

namespace App\Filament\Pages;

use App\Support\RecruitmentPermission;
use App\Support\RecruitmentReadiness;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Whether this module is fit to be pointed at real people yet.
 *
 * It exists because the launch failure mode for recruitment is quiet. A
 * misconfigured notarization breaks loudly and somebody complains; a
 * misconfigured vacancy accepts an application, writes the sign-in email to a
 * log file, and the candidate simply never hears anything. Nobody reports
 * that, so the panel has to.
 *
 * The page asserts nothing it has not checked. Where a fact is inferred rather
 * than verified — whether cron is running, which can only be guessed from
 * whether queued email has gone stale — the wording says so, and the one
 * question software cannot answer at all, whether a privacy notice is lawful,
 * is reported as "has anybody read it" and nothing more.
 */
class RecruitmentReadinessPage extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Launch readiness';
    protected static ?string $navigationGroup = 'Recruitment';
    protected static ?string $title           = 'Launch readiness';
    protected static ?int    $navigationSort  = 9;
    protected static string  $view            = 'filament.pages.recruitment-readiness-page';

    public static function canAccess(): bool
    {
        return RecruitmentPermission::check(RecruitmentPermission::MANAGE_VACANCIES);
    }

    /**
     * A badge on the navigation item, so an outstanding failure is visible
     * from any screen in the panel rather than only from this one.
     */
    public static function getNavigationBadge(): ?string
    {
        $tally = RecruitmentReadiness::tally();

        return $tally['fail'] > 0 ? (string) $tally['fail'] : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recheck')
                ->label('Check again')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    // Settings are memoised per request, so a value changed on
                    // the settings screen would otherwise look unchanged here.
                    \App\Support\Settings::flush();

                    $tally = RecruitmentReadiness::tally();

                    Notification::make()
                        ->title('Checked')
                        ->body($tally['fail'] > 0
                            ? $tally['fail'] . ' ' . str('thing')->plural($tally['fail']) . ' would go wrong.'
                            : ($tally['warn'] > 0
                                ? 'Nothing would break. ' . $tally['warn'] . ' ' . str('item')->plural($tally['warn']) . ' unfinished.'
                                : 'Everything checked is in order.'))
                        ->status($tally['fail'] > 0 ? 'danger' : ($tally['warn'] > 0 ? 'warning' : 'success'))
                        ->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'checks' => RecruitmentReadiness::checks(),
            'tally'  => RecruitmentReadiness::tally(),
        ];
    }
}
