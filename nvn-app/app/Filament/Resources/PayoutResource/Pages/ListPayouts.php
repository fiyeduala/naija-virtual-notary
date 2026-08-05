<?php

namespace App\Filament\Resources\PayoutResource\Pages;

use App\Filament\Resources\PayoutResource;
use App\Models\NotaryProfile;
use App\Services\PayoutService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPayouts extends ListRecords
{
    protected static string $resource = PayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('generate')
                ->label('Generate payouts')
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->modalHeading('Generate payouts')
                ->modalDescription(fn () => $this->owedSummary())
                ->modalSubmitActionLabel('Generate')
                ->action(function (PayoutService $payouts) {
                    $created = $payouts->generateAll(auth()->id());

                    if ($created->isEmpty()) {
                        Notification::make()
                            ->title('Nothing to pay out')
                            ->body('Every cleared fee for a completed job is already on a payout.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $total = $created->sum('amount');

                    Notification::make()
                        ->title($created->count() . ' ' . str('payout')->plural($created->count()) . ' generated')
                        ->body('Totalling ' . PayoutResource::money($total) . '. Nothing has been sent yet — use Send via Paystack on each row.')
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
     * decides money, and "nothing happened" is otherwise indistinguishable from
     * "it silently paid the wrong people".
     */
    private function owedSummary(): string
    {
        $payouts = app(PayoutService::class);

        $lines = NotaryProfile::query()
            ->where('is_system_native', false)
            ->with('user')
            ->get()
            ->map(fn (NotaryProfile $profile) => [
                'name'  => $profile->user?->full_name ?? 'Notary #' . $profile->id,
                'owed'  => $payouts->owed($profile),
            ])
            ->filter(fn (array $row) => $row['owed'] > 0)
            ->sortByDesc('owed');

        if ($lines->isEmpty()) {
            return 'No notary has unpaid fees from a completed job right now, so this would create nothing.';
        }

        $detail = $lines
            ->map(fn (array $row) => $row['name'] . ' — ' . PayoutResource::money($row['owed']))
            ->implode('; ');

        return 'This creates ' . $lines->count() . ' ' . str('payout')->plural($lines->count())
            . ' totalling ' . PayoutResource::money($lines->sum('owed')) . ': ' . $detail
            . '. Generating only records what is owed — no money moves until you send each one.';
    }
}
