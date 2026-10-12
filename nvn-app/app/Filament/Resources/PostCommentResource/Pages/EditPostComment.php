<?php

namespace App\Filament\Resources\PostCommentResource\Pages;

use App\Filament\Resources\PostCommentResource;
use App\Support\AuditLogger;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPostComment extends EditRecord
{
    protected static string $resource = PostCommentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('read_on_site')
                ->label('See it on the article')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn () => $this->record->post
                    ? route('blog.show', $this->record->post) . '#comments'
                    : null)
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->isApproved() && $this->record->post !== null),

            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Approving from this form has to stamp the same fields the row action
     * stamps, or a comment could go live with no record of who let it.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $approved = ($data['status'] ?? null) === 'approved';

        $data['approved_at'] = $approved ? ($this->record->approved_at ?? now()) : null;
        $data['approved_by'] = $approved ? ($this->record->approved_by ?? auth()->id()) : null;

        return $data;
    }

    /**
     * An edit here is a moderator changing what the public will read, so it is
     * audited whether or not the status moved with it.
     */
    protected function afterSave(): void
    {
        AuditLogger::record('post_comment.moderated', 'post_comment', $this->record->id, [
            'post_id' => $this->record->post_id,
            'status'  => $this->record->status,
        ]);
    }
}
