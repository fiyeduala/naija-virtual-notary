<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\EmailCampaignService;
use App\Support\AuditLogger;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    protected static ?string $model = User::class;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Users & notaries';
    protected static ?string $navigationLabel = 'Users';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('phone'),
                Tables\Columns\TextColumn::make('role')->badge()
                    ->formatStateUsing(fn (UserRole $state) => $state->label())
                    ->color(fn (UserRole $state) => match ($state) {
                        UserRole::Admin => 'danger', UserRole::Notary => 'info', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'warning'),
                Tables\Columns\IconColumn::make('email_verified_at')->label('Verified')->boolean(),
                Tables\Columns\IconColumn::make('bulk_email_opt_out')
                    ->label('No announcements')
                    ->boolean()->trueIcon('heroicon-o-bell-slash')->falseIcon('heroicon-o-bell')
                    ->trueColor('warning')->falseColor('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime('j M Y')->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('role')->options([
                    'client' => 'Client', 'notary' => 'Notary', 'admin' => 'Admin',
                ]),
                Tables\Filters\SelectFilter::make('status')->options([
                    'active' => 'Active', 'suspended' => 'Suspended', 'pending' => 'Pending',
                ]),
            ])
            ->actions([
                // One-to-one correspondence. It still goes through the campaign
                // ledger — an 'individual' campaign with a single recipient — so
                // that everything the platform ever emailed a person sits in one
                // place, and so a failed send is visible instead of vanishing.
                Tables\Actions\Action::make('email')
                    ->label('Send email')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (User $u) => filled($u->email))
                    ->modalHeading(fn (User $u) => 'Email ' . $u->full_name)
                    ->modalDescription(fn (User $u) => $u->bulk_email_opt_out
                        ? 'This person has opted out of announcements. A direct message about their own account still reaches them — send it only if it is genuinely for them.'
                        : null)
                    ->modalSubmitActionLabel('Send')
                    ->form([
                        Forms\Components\TextInput::make('subject')->required()->maxLength(200),
                        Forms\Components\RichEditor::make('body')
                            ->required()
                            ->toolbarButtons(['bold', 'italic', 'underline', 'link', 'bulletList', 'orderedList', 'undo', 'redo'])
                            ->helperText('Placeholders: {{ name }}, {{ first_name }}, {{ email }}. The logo and footer are added automatically.'),
                    ])
                    ->action(function (User $u, array $data) {
                        $campaign = EmailCampaign::create([
                            'subject'    => $data['subject'],
                            'body'       => $data['body'],
                            'audience'   => 'individual',
                            'status'     => 'draft',
                            'created_by' => auth()->id(),
                        ]);

                        $service = app(EmailCampaignService::class);
                        $service->buildRecipients($campaign, [$u->id]);
                        $service->queue($campaign);

                        Notification::make()
                            ->title('Email queued for ' . $u->email)
                            ->body('It is sent by the queue worker; the delivery result is under Email.')
                            ->success()->send();
                    }),

                Tables\Actions\Action::make('resetPassword')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->modalHeading(fn (User $u) => 'Reset password — ' . $u->full_name)
                    ->modalDescription('Sets a new password immediately and signs the account out of any "remember me" sessions. Share the new password with the account holder over a channel you trust, and ask them to change it.')
                    ->modalSubmitActionLabel('Reset password')
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->confirmed()
                            ->helperText('At least 8 characters.'),
                        Forms\Components\TextInput::make('password_confirmation')
                            ->label('Confirm new password')
                            ->password()
                            ->revealable()
                            ->required(),
                    ])
                    ->action(function (User $u, array $data) {
                        $u->update(['password' => $data['password']]); // hashed by the model cast

                        // Invalidate outstanding "remember me" cookies. Not mass
                        // assignable, so it is set on the model directly.
                        $u->setRememberToken(Str::random(60));
                        $u->save();

                        AuditLogger::record('user.password_reset', 'user', $u->id, [
                            'reset_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Password reset for ' . $u->full_name)
                            ->body('Give them the new password directly — it is not emailed.')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('toggleSuspend')
                    ->label(fn (User $u) => $u->status === 'suspended' ? 'Reactivate' : 'Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color(fn (User $u) => $u->status === 'suspended' ? 'success' : 'danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $u) => ! $u->isAdmin())
                    ->action(function (User $u) {
                        $new = $u->status === 'suspended' ? 'active' : 'suspended';
                        $u->update(['status' => $new]);
                        AuditLogger::record('user.status_changed', 'user', $u->id, ['status' => $new]);
                        Notification::make()->title('User ' . $new)->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListUsers::route('/')];
    }
}
