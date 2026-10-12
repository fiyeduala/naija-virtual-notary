<?php

namespace App\Filament\Resources;

use App\Enums\UserRole;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Resources\UserResource\Pages;
use App\Models\EmailCampaign;
use App\Models\User;
use App\Services\EmailCampaignService;
use App\Support\AuditLogger;
use App\Support\RecruitmentPermission;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class UserResource extends Resource
{
    use AdminOnly;

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
                // Hidden by default: almost nobody has one, and a column of
                // dashes on every row trains people to stop reading it.
                Tables\Columns\TextColumn::make('recruitment_role')
                    ->label('Recruitment')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn (?string $state) => RecruitmentPermission::ROLES[$state] ?? $state)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
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
                Tables\Filters\SelectFilter::make('recruitment_role')
                    ->label('Recruitment role')
                    ->options(RecruitmentPermission::ROLES),
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

                /*
                 | Recruitment access.
                 |
                 | This is the one place a recruitment role is granted, and it
                 | is deliberately a separate act from being an administrator.
                 | A reviewer gets the Recruitment group in this panel and
                 | nothing else: no payments, no payouts, no client documents,
                 | no notary desk. Every other resource in here refuses them
                 | explicitly rather than relying on the navigation to hide it.
                 |
                 | It does widen access — somebody with no role at all cannot
                 | open the panel, and somebody with one can — so the modal
                 | says so in those words rather than leaving it to be
                 | discovered.
                 */
                Tables\Actions\Action::make('recruitmentRole')
                    ->label('Recruitment access')
                    ->icon('heroicon-o-identification')
                    ->color('gray')
                    ->modalHeading(fn (User $u) => 'Recruitment access — ' . $u->full_name)
                    ->modalDescription('A recruitment role lets this person sign in to the admin panel and see the Recruitment group only. It grants nothing financial, nothing about clients, and no notary powers.')
                    ->modalSubmitActionLabel('Save')
                    ->fillForm(fn (User $u) => ['recruitment_role' => $u->recruitment_role])
                    ->form([
                        Forms\Components\Select::make('recruitment_role')
                            ->label('Role')
                            ->options(RecruitmentPermission::ROLES)
                            ->placeholder('No recruitment access')
                            ->native(false)
                            ->helperText('A reviewer sees only the vacancies they are assigned to and never identity documents. Only an HR administrator sees those. Publishing a vacancy and setting the retention policy need the recruitment lead.'),
                    ])
                    ->action(function (User $u, array $data) {
                        $role = $data['recruitment_role'] ?: null;

                        if ($role !== null && ! isset(RecruitmentPermission::ROLES[$role])) {
                            Notification::make()->title('Not a recruitment role.')->danger()->send();

                            return;
                        }

                        // Set directly, not mass assigned: `recruitment_role`
                        // is deliberately absent from $fillable, because a
                        // column that grants access to this panel should not
                        // be reachable from any array of request input.
                        $u->recruitment_role = $role;
                        $u->save();

                        // The role is recorded, not the abilities it implies:
                        // the grants map is in code and may change, and an
                        // audit entry listing today's abilities would be a
                        // claim about a map that no longer exists.
                        AuditLogger::record('user.recruitment_role_changed', 'user', $u->id, [
                            'role'       => $role,
                            'changed_by' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title($role ? 'Recruitment access granted' : 'Recruitment access removed')
                            ->body($role
                                ? RecruitmentPermission::ROLES[$role] . '. They can now sign in to this panel.'
                                : 'They can no longer sign in to this panel unless they are an administrator.')
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
