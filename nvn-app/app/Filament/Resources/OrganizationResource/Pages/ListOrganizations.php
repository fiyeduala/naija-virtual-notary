<?php

namespace App\Filament\Resources\OrganizationResource\Pages;

use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListOrganizations extends ListRecords
{
    protected static string $resource = OrganizationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Onboard a body')
                ->icon('heroicon-o-plus'),
        ];
    }

    /**
     * Waiting first, because that is the tab with work in it.
     *
     * A pending body is an application nobody has answered yet, and it is the
     * only state on this screen where somebody outside is waiting on us.
     */
    public function getTabs(): array
    {
        return [
            'waiting' => Tab::make('Waiting')
                ->modifyQueryUsing(fn (Builder $query) => $query->pending())
                ->badge(Organization::pending()->count() ?: null)
                ->badgeColor('warning'),
            'live' => Tab::make('Live')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return Organization::pending()->exists() ? 'waiting' : 'live';
    }
}
