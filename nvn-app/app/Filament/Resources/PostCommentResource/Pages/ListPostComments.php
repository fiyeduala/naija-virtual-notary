<?php

namespace App\Filament\Resources\PostCommentResource\Pages;

use App\Filament\Resources\PostCommentResource;
use App\Models\PostComment;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListPostComments extends ListRecords
{
    protected static string $resource = PostCommentResource::class;

    /**
     * Waiting first, and the default.
     *
     * An admin opening this screen is answering "is there anything to decide",
     * so the queue of pending comments is what the page should already be
     * showing. Everything else is one click away.
     */
    public function getTabs(): array
    {
        $pending = PostComment::pending()->count();

        return [
            'pending' => Tab::make('Waiting')
                ->badge($pending > 0 ? $pending : null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->pending()),

            'approved' => Tab::make('Live')
                ->modifyQueryUsing(fn (Builder $query) => $query->approved()),

            'spam' => Tab::make('Spam')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'spam')),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
