<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Support\AuditLogger;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PostResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Post::class;
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $navigationLabel = 'Blog';
    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationBadge(): ?string
    {
        $count = PostComment::pending()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Comments waiting to be approved';
    }

    public static function getEloquentQuery(): Builder
    {
        // Drafts and soft-deleted articles both belong in the admin's list; the
        // public scope is what decides who sees what.
        //
        // The three counts are selected here rather than read off each row in
        // the table, which would be three queries per article on a page of
        // twenty-five.
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->withCount([
                'views',
                'views as reads_count' => fn (Builder $query) => $query->whereNotNull('read_at'),
                'comments',
                'comments as pending_comments_count' => fn (Builder $query) => $query->where('status', 'pending'),
            ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(250)
                        ->live(onBlur: true)
                        // Only while it is a draft nobody has read. Changing the
                        // slug of a published article breaks every link to it
                        // that exists in the world, so after publication it is
                        // something you do on purpose, in the field below.
                        ->afterStateUpdated(function ($state, Forms\Set $set, ?Post $record) {
                            if (! $record || ! $record->isPublished()) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(250)
                        ->unique(ignoreRecord: true)
                        ->prefix(url('/blog') . '/')
                        ->helperText('Changing this on a published post breaks existing links to it.'),

                    Forms\Components\Textarea::make('excerpt')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('The blurb on the blog index. Left blank, the opening of the article is used.'),

                    Forms\Components\RichEditor::make('body')
                        ->columnSpanFull()
                        ->fileAttachmentsDisk('blog')
                        ->fileAttachmentsDirectory('inline')
                        ->fileAttachmentsVisibility('public')
                        ->toolbarButtons([
                            'bold', 'italic', 'underline', 'strike', 'link',
                            'h2', 'h3', 'blockquote', 'codeBlock',
                            'bulletList', 'orderedList',
                            'attachFiles', 'undo', 'redo',
                        ])
                        ->helperText('Formatting is kept; scripts, embeds and form fields are stripped when saved.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Publishing')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(['draft' => 'Draft', 'published' => 'Published'])
                        ->default('draft')
                        ->required()
                        ->live(),

                    Forms\Components\DateTimePicker::make('published_at')
                        ->label('Publish date')
                        ->seconds(false)
                        ->helperText('Leave blank to publish now. A future date holds it back until then.')
                        ->visible(fn (Forms\Get $get) => $get('status') === 'published'),

                    Forms\Components\FileUpload::make('cover_image')
                        ->image()
                        ->disk('blog')
                        ->directory('covers')
                        ->visibility('public')
                        ->maxSize(4096)
                        ->imageEditor(),

                    Forms\Components\Textarea::make('meta_description')
                        ->rows(2)
                        ->maxLength(300)
                        ->helperText('What search engines show under the title. Blank falls back to the excerpt.'),

                    // options(), not relationship(). Filament's relationship
                    // machinery resolves the relation at render time and hands
                    // whatever it finds — including null — to a query builder,
                    // so a relation it cannot see becomes "qualifyColumn() on
                    // null" on the create page rather than anything checkable
                    // earlier. The set of admins is small and fixed; asking for
                    // it directly is both simpler and unable to fail that way.
                    Forms\Components\Select::make('author_id')
                        ->label('Author')
                        ->options(fn () => User::where('role', 'admin')
                            ->orderBy('full_name')
                            ->pluck('full_name', 'id'))
                        ->default(fn () => auth()->id())
                        ->searchable(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_image')
                    ->disk('blog')->label('')->height(36)->width(56),

                Tables\Columns\TextColumn::make('title')
                    ->searchable()->sortable()->wrap()->limit(60),

                // Three states, not two. A post marked published with a future
                // date is live in the database and invisible on the site, and
                // a green "Published" badge on it is the one label guaranteed
                // to be read as "this went out".
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state, Post $p) => $state === 'published' && ! $p->isPublished()
                        ? 'Scheduled'
                        : ucfirst($state))
                    ->color(fn ($state, Post $p) => match (true) {
                        $state !== 'published'  => 'gray',
                        ! $p->isPublished()     => 'info',
                        default                 => 'success',
                    })
                    ->icon(fn ($state, Post $p) => $state === 'published' && ! $p->isPublished()
                        ? 'heroicon-o-clock'
                        : null),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->description(fn (Post $p) => $p->published_at?->isFuture() ? 'Scheduled' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('views_count')
                    ->label('Views')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    // Not "0". A draft has no views because nobody could have
                    // opened it, which is a different fact from an article
                    // nobody opened, and a dash says so.
                    ->formatStateUsing(fn ($state, Post $p) => $p->isPublished() ? number_format($state) : '—'),

                Tables\Columns\TextColumn::make('reads_count')
                    ->label('Reads')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->description(fn (Post $p) => $p->views_count > 0
                        ? round($p->reads_count / $p->views_count * 100) . '%'
                        : null)
                    ->formatStateUsing(fn ($state, Post $p) => $p->isPublished() ? number_format($state) : '—'),

                Tables\Columns\TextColumn::make('comments_count')
                    ->label('Comments')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    ->badge()
                    ->color(fn (Post $p) => $p->pending_comments_count > 0 ? 'warning' : 'gray')
                    ->tooltip(fn (Post $p) => $p->pending_comments_count > 0
                        ? $p->pending_comments_count . ' waiting on a decision'
                        : null),

                Tables\Columns\TextColumn::make('author.full_name')
                    ->label('Author')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('legacy_source')
                    ->label('Source')
                    ->badge()->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published']),

                // Nothing sweeps these up otherwise: a scheduled post is not a
                // draft and does not show on the site, so without a filter the
                // only way to find what is queued is to read every date.
                Tables\Filters\Filter::make('scheduled')
                    ->label('Scheduled to go out')
                    ->query(fn (Builder $query) => $query
                        ->where('status', 'published')
                        ->where('published_at', '>', now()))
                    ->toggle(),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Post $p) => route('blog.show', $p))
                    ->openUrlInNewTab()
                    ->visible(fn (Post $p) => $p->isPublished()),

                Tables\Actions\Action::make('analytics')
                    ->label('Analytics')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (Post $p) => Pages\PostAnalytics::getUrl(['record' => $p])),

                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('publish')
                    ->label(fn (Post $p) => $p->status === 'published' ? 'Unpublish' : 'Publish')
                    ->icon('heroicon-o-megaphone')
                    ->requiresConfirmation()
                    ->action(function (Post $p) {
                        $next = $p->status === 'published' ? 'draft' : 'published';
                        $p->update(['status' => $next]);

                        AuditLogger::record('post.status_changed', 'post', $p->id, ['status' => $next]);

                        Notification::make()
                            ->title($next === 'published' ? 'Published' : 'Moved back to draft')
                            ->success()->send();
                    }),

                Tables\Actions\DeleteAction::make(),
                Tables\Actions\RestoreAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No articles yet');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPosts::route('/'),
            'create' => Pages\CreatePost::route('/create'),
            'edit'   => Pages\EditPost::route('/{record}/edit'),
            'analytics' => Pages\PostAnalytics::route('/{record}/analytics'),
        ];
    }
}
