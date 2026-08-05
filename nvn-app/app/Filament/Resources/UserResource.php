<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
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
