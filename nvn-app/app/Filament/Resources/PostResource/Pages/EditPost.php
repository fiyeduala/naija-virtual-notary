<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Support\AuditLogger;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('view')
                ->label('View on site')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => route('blog.show', $this->record))
                ->openUrlInTab()
                ->visible(fn () => $this->record->isPublished()),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        AuditLogger::record('post.updated', 'post', $this->record->id, [
            'title'  => $this->record->title,
            'status' => $this->record->status,
        ]);
    }
}
