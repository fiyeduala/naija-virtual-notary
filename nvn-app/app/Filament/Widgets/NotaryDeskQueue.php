<?php

namespace App\Filament\Widgets;

use App\Enums\RequestStatus;
use App\Models\NotarizationRequest;
use App\Services\RequestFulfillmentService;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The admin's notarization queue, on the dashboard.
 *
 * It lists every request still in flight, not only the ones handed to the
 * admin. The admin may notarize on any partner's behalf at any moment — there
 * is no waiting period — so limiting this to their own desk would hide exactly
 * the requests they are meant to be watching.
 *
 * Whose seal goes on the document never changes with who does the work: it is
 * always the notary the client selected. See NotarizeController::availableAssetSets().
 *
 * The "Waiting" column is the clock: how long since the client paid, and
 * whether the assigned notary's response window has passed. Nothing is
 * reassigned when it does — it is there to be looked at.
 */
class NotaryDeskQueue extends BaseWidget
{
    protected static ?string $heading = 'Notarization desk';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->description('Every request still in flight. You can take any of them over on the assigned '
                . 'notary\'s behalf — the document is still sealed with their signature, stamp and seal.')
            ->query(
                NotarizationRequest::query()
                    ->inFlight()
                    ->with('client', 'service', 'session', 'notary.user', 'handledBy')
            )
            ->columns([
                Tables\Columns\TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable(),

                Tables\Columns\TextColumn::make('client.full_name')
                    ->label('Client')
                    ->searchable(),

                Tables\Columns\TextColumn::make('notary.user.full_name')
                    ->label('Sealed by')
                    ->description(fn (NotarizationRequest $r) => $r->handled_by
                        ? 'work done by ' . ($r->handledBy?->full_name ?? 'the platform')
                        : null)
                    ->placeholder('No notary selected')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state) => $state->label())
                    ->color(fn (RequestStatus $state) => match ($state) {
                        RequestStatus::Paid => 'danger',
                        RequestStatus::Notarizing, RequestStatus::InVerification => 'warning',
                        default => 'info',
                    }),

                // Uploaded → paid → notarized, in one line. This replaced the
                // countdown that used to matter because it moved the job.
                Tables\Columns\TextColumn::make('paid_at')
                    ->label('Waiting')
                    ->since()
                    ->placeholder('not paid')
                    ->description(fn (NotarizationRequest $r) => match (true) {
                        $r->isNotarized() => 'notarized',
                        $r->isOverdue()   => 'past the response window',
                        default           => 'not yet notarized',
                    })
                    ->color(fn (NotarizationRequest $r) => $r->isOverdue() ? 'danger' : null)
                    ->sortable(),
            ])
            ->actions([
                // Only ever offered on a Paid request booked with the platform's
                // own notary — that is the only case where "accept" is the
                // admin answering as the assigned notary rather than covering.
                Tables\Actions\Action::make('accept')
                    ->label('Accept')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription('This locks the session in and tells the client you are handling it.')
                    ->visible(fn (NotarizationRequest $r) => $r->status === RequestStatus::Paid
                        && $r->notary?->is_system_native)
                    ->action(function (NotarizationRequest $r) {
                        app(RequestFulfillmentService::class)->accept($r);

                        Notification::make()->success()
                            ->title('Request accepted')
                            ->body($r->reference . ' is now scheduled.')
                            ->send();
                    }),

                Tables\Actions\Action::make('takeOver')
                    ->label('Take over')
                    ->icon('heroicon-o-hand-raised')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Notarize on the assigned notary\'s behalf?')
                    ->modalDescription(fn (NotarizationRequest $r) => 'The document stays booked with '
                        . ($r->notary?->user?->full_name ?? 'the assigned notary')
                        . ' and is sealed with their signature, stamp and seal. Only the record of who did the work changes.')
                    ->visible(fn (NotarizationRequest $r) => $r->handled_by === null
                        && ! ($r->notary?->is_system_native ?? false))
                    ->action(function (NotarizationRequest $r) {
                        app(RequestFulfillmentService::class)->takeOver($r, 'admin_took_over');

                        Notification::make()->success()
                            ->title('Taken over')
                            ->body($r->reference . ' is on your desk. It will be sealed under '
                                . ($r->notary?->user?->full_name ?? 'the assigned notary') . '.')
                            ->send();
                    }),

                Tables\Actions\Action::make('session')
                    ->label('Notarize')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (NotarizationRequest $r) => $r->status !== RequestStatus::Paid)
                    ->url(fn (NotarizationRequest $r) => route('session.join', $r)),

                Tables\Actions\Action::make('open')
                    ->label('Details')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (NotarizationRequest $r) => route('notary.requests.show', $r)),
            ])
            // Default ordering is in applyDefaultSortingToTableQuery() below —
            // it needs two keys, which defaultSort() cannot express.
            ->emptyStateIcon('heroicon-o-check-circle')
            ->emptyStateHeading('Nothing in flight')
            ->emptyStateDescription('Paid requests appear here until the sealed document is issued.')
            ->paginated([5, 10, 25]);
    }

    /** Hide the whole card from any admin who is not set up to notarize. */
    public static function canView(): bool
    {
        $user = Auth::user();

        return (bool) $user?->isAdmin();
    }

    protected function getTableQueryStringIdentifier(): ?string
    {
        // Keeps this table's paging/sorting out of the other widgets' query string.
        return 'desk';
    }

    protected function applyDefaultSortingToTableQuery(Builder $query): Builder
    {
        // Paid-but-unanswered first — the only rows with a clock on them —
        // then longest wait first within each group.
        return $query
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RequestStatus::Paid->value])
            ->oldest('paid_at');
    }
}
