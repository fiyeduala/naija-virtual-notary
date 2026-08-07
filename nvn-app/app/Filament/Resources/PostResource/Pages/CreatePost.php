<?php

namespace App\Filament\Resources\PostResource\Pages;

use App\Filament\Resources\PostResource;
use App\Support\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // An article with no author is possible — author_id is nullable so that
        // deleting a member of staff does not delete what they wrote — but a
        // brand new one always has somebody sitting at the keyboard.
        $data['author_id'] ??= auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        AuditLogger::record('post.created', 'post', $this->record->id, [
            'title'  => $this->record->title,
            'status' => $this->record->status,
        ]);
    }
}
