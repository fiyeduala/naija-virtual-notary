<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PostCommentResource\Pages;
use App\Models\Post;
use App\Models\PostComment;
use App\Support\AuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The moderation queue.
 *
 * Nothing a stranger writes appears on the site until it is approved here, so
 * this screen is the only thing standing between the blog and whatever arrives
 * through a public form. The badge in the navigation counts what is waiting,
 * because the question an admin comes here with is always "what needs a
 * decision", never "what has ever been said".
 *
 * The body is shown as plain text in every column and in the form. A comment is
 * never rendered as HTML anywhere, panel included.
 */
class PostCommentResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = PostComment::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $navigationLabel = 'Blog comments';
    protected static ?string $modelLabel = 'comment';
    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = PostComment::pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    protected static function statusOptions(): array
    {
        return array_combine(
            PostComment::STATUSES,
            array_map('ucfirst', PostComment::STATUSES),
        );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\Placeholder::make('article')
                        ->content(fn (PostComment $record) => $record->post?->title ?? '—'),

                    Forms\Components\Placeholder::make('author')
                        ->label('From')
                        ->content(fn (PostComment $record) => $record->author_name
                            . ' · ' . $record->author_email
                            . ($record->fromAccount() ? ' (signed-in account)' : ' (not signed in)')),

                    Forms\Components\Placeholder::make('replying_to')
                        ->label('Replying to')
                        ->content(fn (PostComment $record) => $record->parent?->snippet(80) ?? '—')
                        ->visible(fn (PostComment $record) => $record->parent_id !== null),

                    // Textarea rather than a rich editor. Editing a stranger's
                    // words is for trimming an address or a phone number out of
                    // them, not for formatting, and a rich editor here would be
                    // the one way HTML could get into this column.
                    Forms\Components\Textarea::make('body')
                        ->rows(8)
                        ->maxLength(3000)
                        ->columnSpanFull()
                        ->helperText('Plain text. Edit only to remove something that should not be published.'),

                    Forms\Components\Select::make('status')
                        ->options(fn () => static::statusOptions())
                        ->required(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'pending'  => 'warning',
                        'spam'     => 'danger',
                        default    => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('author_name')
                    ->label('From')
                    ->description(fn (PostComment $c) => $c->fromAccount() ? 'Signed-in account' : $c->author_email)
                    ->searchable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Comment')
                    ->formatStateUsing(fn ($state, PostComment $c) => $c->snippet(140))
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('post.title')
                    ->label('Article')
                    ->limit(40)
                    ->url(fn (PostComment $c) => $c->post ? route('blog.show', $c->post) : null)
                    ->openUrlInNewTab()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('parent_id')
                    ->label('Reply')
                    ->boolean()
                    ->trueIcon('heroicon-o-arrow-uturn-left')
                    ->falseIcon('heroicon-o-minus-small')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime('j M Y, g:ia')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn () => static::statusOptions()),

                Tables\Filters\SelectFilter::make('post_id')
                    ->label('Article')
                    ->options(fn () => Post::withoutGlobalScopes()
                        ->whereHas('comments')
                        ->orderBy('title')
                        ->pluck('title', 'id')),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PostComment $c) => ! $c->isApproved())
                    ->action(fn (PostComment $c) => static::decide($c, 'approved')),

                Tables\Actions\Action::make('spam')
                    ->label('Spam')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PostComment $c) => ! $c->isSpam())
                    ->action(fn (PostComment $c) => static::decide($c, 'spam')),

                Tables\Actions\EditAction::make()->label('Read'),
                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->bulkActions([
                // Bulk approve and bulk spam, and nothing in between. A bulk
                // "reject" would mean reading each one anyway, which is what
                // the row actions are for.
                Tables\Actions\BulkAction::make('approve')
                    ->label('Approve selected')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        $records->each(fn (PostComment $c) => static::decide($c, 'approved', notify: false));

                        Notification::make()
                            ->title($records->count() . ' ' . str('comment')->plural($records->count()) . ' approved')
                            ->success()->send();
                    }),

                Tables\Actions\BulkAction::make('spam')
                    ->label('Mark as spam')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        $records->each(fn (PostComment $c) => static::decide($c, 'spam', notify: false));

                        Notification::make()
                            ->title($records->count() . ' marked as spam')
                            ->success()->send();
                    }),

                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No comments')
            ->emptyStateDescription('Nothing has been left on an article yet.');
    }

    /**
     * One place where a comment changes state, so approving from a row, from a
     * bulk action or from the form all write the same fields and leave the same
     * audit entry.
     */
    protected static function decide(PostComment $comment, string $status, bool $notify = true): void
    {
        $comment->update([
            'status'      => $status,
            'approved_at' => $status === 'approved' ? now() : null,
            'approved_by' => $status === 'approved' ? auth()->id() : null,
        ]);

        AuditLogger::record('post_comment.' . $status, 'post_comment', $comment->id, [
            'post_id' => $comment->post_id,
        ]);

        if ($notify) {
            Notification::make()
                ->title($status === 'approved' ? 'Comment is now live' : 'Marked as ' . $status)
                ->success()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostComments::route('/'),
            'edit'  => Pages\EditPostComment::route('/{record}/edit'),
        ];
    }
}
