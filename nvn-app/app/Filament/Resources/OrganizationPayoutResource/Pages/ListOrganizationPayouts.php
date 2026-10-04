<?php

namespace App\Filament\Resources\OrganizationPayoutResource\Pages;

use App\Filament\Resources\OrganizationPayoutResource;
use App\Models\Organization;
use App\Services\OrganizationPayoutService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListOrganizationPayouts extends ListRecords
{
    protected static string $resource = OrganizationPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Generate payouts')
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->modalHeading('Generate organization payouts')
                ->modalDescription(fn () => $this->owedSummary())
                ->modalSubmitActionLabel('Generate')
                ->action(function (OrganizationPayoutService $payouts) {
                    $created = $payouts->generateAll(auth()->id());

                    if ($created->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to pay out')
                            ->body('Every cleared fee from a completed referral is already on a payout, '
                                . 'and bodies charged a rate only never earn one.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($created->count() . ' ' . str('payout')->plural($created->count())
                            . ' generated')
                        ->body('Totalling ' . OrganizationPayoutResource::money($created->sum('amount'))
                            . '. Nothing has been sent — settle each one by hand and record it here.')
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * What pressing the button would actually create, worked out live.
     *
     * Shown before confirming because a payout run is the one action here that
     * decides money, and "nothing happened" is otherwise indistinguishable
     * from "it silently paid the wrong people".
     *
     * Only bodies on the commission arrangement are counted, because only they
     * can produce a payout at all — a body charged a rate only is skipped by
     * the run outright, rather than generating an empty payout that would
     * swallow its own fees into an unreachable ledger row.
     */
    private function owedSummary(): string
    {
        $payouts = app(OrganizationPayoutService::class);

        $lines = Organization::active()
            ->where('arrangement', Organization::COMMISSION)
            ->get()
            ->map(fn (Organization $organization) => [
                'name' => $organization->name,
                'owed' => $payouts->owed($organization),
            ])
            ->filter(fn (array $row) => $row['owed'] > 0)
            ->sortByDesc('owed');

        if ($lines->isEmpty()) {
            return 'No organization has an unpaid share from a completed job right now, so this would '
                . 'create nothing.';
        }

        $detail = $lines
            ->map(fn (array $row) => $row['name'] . ' — ' . OrganizationPayoutResource::money($row['owed']))
            ->implode('; ');

        return 'This creates ' . $lines->count() . ' ' . str('payout')->plural($lines->count())
            . ' totalling ' . OrganizationPayoutResource::money($lines->sum('owed')) . ': ' . $detail
            . '. Generating only records what is owed — no money moves.';
    }
}
