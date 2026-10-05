<?php

namespace App\Filament\Resources\PayoutResource\Pages;

use App\Filament\Resources\PayoutResource;
use App\Models\NotarizationRequest;
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

        // Work that earned something a payout run cannot send. Not added to
        // any total above — a dollar share and a naira share are different
        // units — but said out loud, because a notary whose client was abroad
        // can otherwise watch a completed job earn nothing on every screen
        // and have no way to ask about it. See PayoutService::unpayableEarnings().
        $foreign = NotaryProfile::query()
            ->where('is_system_native', false)
            ->with('user')
            ->get()
            ->flatMap(fn (NotaryProfile $profile) => $payouts->unpayableEarnings($profile)
                ->map(fn (array $row, string $currency) => ($profile->user?->full_name ?? 'Notary #' . $profile->id)
                    . ' — ' . NotarizationRequest::money($row['shareMinor'], $currency)
                    . ' on ' . $row['count'] . ' ' . str('job')->plural($row['count']))
                ->values())
            ->implode('; ');

        $note = $foreign === '' ? '' : ' Separately, and NOT included above, these cannot be sent'
            . ' by transfer because the client paid in a foreign currency — settle them by hand: '
            . $foreign . '.';

        if ($lines->isEmpty()) {
            return 'No notary has unpaid naira fees from a completed job right now, so this would create nothing.'
                . $note;
        }

        $detail = $lines
            ->map(fn (array $row) => $row['name'] . ' — ' . PayoutResource::money($row['owed']))
            ->implode('; ');

        return 'This creates ' . $lines->count() . ' ' . str('payout')->plural($lines->count())
            . ' totalling ' . PayoutResource::money($lines->sum('owed')) . ': ' . $detail
            . '. Generating only records what is owed — no money moves until you send each one.'
            . $note;
    }
}
