<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PostResource\Pages;
use App\Models\Post;
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
    protected static ?string $model = Post::class;
    protected static ?string $navigationIcon = 'heroicon-o-newspaper';
    protected static ?string $navigationGroup = 'Content & settings';
    protected static ?string $navigationLabel = 'Blog';
    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        // Drafts and soft-deleted articles both belong in the admin's list; the
        // public scope is what decides who sees what.
        return parent::getEloquentQuery()->withoutGlobalScopes();
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

                    Forms\Components\Select::make('author_id')
                        ->label('Author')
                        ->relationship('author', 'full_name', fn (Builder $q) => $q->where('role', 'admin'))
                        ->default(fn () => auth()->id())
                        ->searchable()
                        ->preload(),
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

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => $state === 'published' ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime('j M Y')
                    ->placeholder('—')
                    ->description(fn (Post $p) => $p->published_at?->isFuture() ? 'Scheduled' : null)
                    ->sortable(),

                Tables\Columns\TextColumn::make('author.full_name')
                    ->label('Author')->placeholder('—')->toggleable(),

                Tables\Columns\TextColumn::make('legacy_source')
                    ->label('Source')
                    ->badge()->color('gray')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published']),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Post $p) => route('blog.show', $p))
                    ->openUrlInNewTab()
                    ->visible(fn (Post $p) => $p->isPublished()),

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
        ];
    }
}
