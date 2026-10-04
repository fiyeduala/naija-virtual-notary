<?php

namespace App\Filament\Resources\OrganizationResource\RelationManagers;

use App\Filament\Resources\OrganizationResource;
use App\Models\NotaryProfile;
use App\Models\NotaryService;
use App\Models\Organization;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Per-category prices, on top of the body's default.
 *
 * Optional by design: most bodies agree one figure per document and never need
 * a row here. The ones that do are usually agreeing a different rate for the
 * expensive end of the list, so each row shows our own public price beside the
 * agreed one — a figure that is ten times the public price is the arrangement,
 * and one that is a tenth of it is a typo.
 *
 * Keyed on the service row rather than a category name, which is only safe
 * because organization work is always sealed by the same profile.
 */
class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Category prices';

    protected static ?string $modelLabel = 'category price';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('notary_service_id')
                ->label('Category')
                ->options(fn () => static::serviceOptions())
                ->required()
                ->searchable()
                ->live()
                ->helperText('Only our own notary’s categories, since that is who seals this work.'),
            Forms\Components\Placeholder::make('public')
                ->label('Our public price for it')
                ->content(function (Forms\Get $get) {
                    $service = NotaryService::find($get('notary_service_id'));

                    return $service
                        ? OrganizationResource::money($service->priceFor('NGN'))
                        : 'Choose a category first.';
                }),
            Forms\Components\TextInput::make('price_ngn')
                ->label('Their price (₦, in kobo)')
                ->numeric()->minValue(0)->required()
                ->helperText('₦250,000 is 25000000.'),
            Forms\Components\TextInput::make('price_usd')
                ->label('Their price ($, in cents)')
                ->numeric()->minValue(0)
                ->helperText('Optional, and earns no commission — transfers settle in naira.'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('service.service_type')
                    ->label('Category')
                    ->placeholder('Category removed')
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_ngn')
                    ->label('Their price')
                    ->state(fn ($record) => OrganizationResource::money($record->price_ngn))
                    ->alignRight(),
                // Side by side, because a mistyped figure is invisible on its
                // own and obvious next to the number it was negotiated from.
                Tables\Columns\TextColumn::make('public_ngn')
                    ->label('Our public price')
                    ->state(fn ($record) => $record->service
                        ? OrganizationResource::money($record->service->priceFor('NGN'))
                        : '—')
                    ->alignRight(),
                Tables\Columns\TextColumn::make('price_usd')
                    ->label('Their price ($)')
                    ->state(fn ($record) => $record->price_usd !== null
                        ? OrganizationResource::money($record->price_usd, 'USD')
                        : '—')
                    ->alignRight()
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Add a category price'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->modalDescription('Work in this category falls back to their default rate.'),
            ])
            ->emptyStateHeading('No category prices')
            ->emptyStateDescription(fn () => 'Every category is charged their default rate of '
                . $this->getOwnerRecord()->displayDefaultPrice('NGN') . ' per document.');
    }

    /** Our own notary's active categories — nobody else seals organization work. */
    private static function serviceOptions(): array
    {
        $notary = NotaryProfile::systemNative()->first();

        if (! $notary) {
            return [];
        }

        return NotaryService::where('notary_profile_id', $notary->id)
            ->where('active', true)
            ->orderBy('service_type')
            ->pluck('service_type', 'id')
            ->all();
    }
}
