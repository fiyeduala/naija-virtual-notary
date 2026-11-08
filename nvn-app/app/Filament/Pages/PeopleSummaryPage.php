<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Support\PeopleSummary;
use Filament\Actions;
use Filament\Pages\Page;

/**
 * One screen that answers "who is on this platform".
 *
 * It sits beside the Users and Notaries resources rather than replacing them:
 * those two are for finding and editing one record, this one is for the shape
 * of the whole set. Nothing here is editable, and nothing here is cached — an
 * owner reading a figure off this page is reading the database as it is now.
 *
 * Admin-only, by canAccess() rather than by a hidden link: it carries account
 * emails, what each notary is owed, and who has never signed in.
 */
class PeopleSummaryPage extends Page
{
    use AdminOnly;

    protected static ?string $navigationIcon  = 'heroicon-o-user-group';
    protected static ?string $navigationLabel = 'People summary';
    protected static ?string $navigationGroup = 'Users & notaries';
    protected static ?string $title           = 'People summary';
    protected static ?int    $navigationSort  = -1;
    protected static string  $view            = 'filament.pages.people-summary-page';

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('refresh')
                ->label('Refresh')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                // Nothing to invalidate — the figures are read on render. This
                // is only a re-render, and it is here so an owner watching a
                // signup land does not have to reach for the browser.
                ->action(fn () => null),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'users'       => PeopleSummary::users(),
            'notaries'    => PeopleSummary::notaries(),
            'notaryRows'  => PeopleSummary::notaryRows(),
            'recentUsers' => PeopleSummary::recentUsers(),
        ];
    }
}
