<?php

namespace App\Filament\Pages;

use App\Models\PlatformSetting;
use App\Services\Recruitment\RetentionService;
use App\Support\RecruitmentPermission;
use App\Support\RecruitmentPrivacyNotice;
use App\Support\RecruitmentTemplates;
use App\Support\Settings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The three recruitment decisions that are the owner's to make.
 *
 * What a candidate is told (the eleven emails), what they are told about their
 * own data (the privacy notice), and how long any of it is kept (retention).
 * They sit on one screen because they are the same kind of decision: policy,
 * not operation. Nothing on this page moves a candidate anywhere.
 *
 * Three rules this page keeps:
 *
 *   - An email body is PLAIN TEXT. The layout escapes every paragraph and
 *     supplies the branding, so an editor can change the words without being
 *     able to change what the email can do. There is no rich-text field here
 *     and that is not an oversight.
 *   - A placeholder is substituted by string replacement, never compiled as
 *     Blade. The preview below runs the real renderer, so what it shows is
 *     what an inbox gets — including a line silently dropped because its
 *     placeholder had nothing behind it.
 *   - The retention period starts EMPTY and the automatic purge refuses to run
 *     until somebody sets one. This module does not pick a legal retention
 *     period on an owner's behalf, and a helpful default here would be exactly
 *     that.
 *
 * MANAGE_RETENTION rather than admin-only, because an HR administrator is the
 * person who owns this wording — but the ability is granted narrowly and a
 * plain reviewer does not hold it.
 */
class RecruitmentSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationLabel = 'Recruitment settings';
    protected static ?string $navigationGroup = 'Recruitment';
    protected static ?string $title           = 'Recruitment settings';
    protected static ?int    $navigationSort  = 8;
    protected static string  $view            = 'filament.pages.recruitment-settings-page';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return RecruitmentPermission::check(RecruitmentPermission::MANAGE_RETENTION);
    }

    public function mount(): void
    {
        $templates = [];

        foreach (array_keys(RecruitmentTemplates::KEYS) as $key) {
            $templates[$key] = [
                'subject' => RecruitmentTemplates::subject($key),
                'body'    => RecruitmentTemplates::body($key),
            ];
        }

        $this->form->fill([
            'reply_to'         => Settings::string('recruitment_reply_to', (string) config('recruitment.reply_to', '')),
            'privacy_version'  => RecruitmentPrivacyNotice::version(),
            'privacy_contact'  => Settings::string(RecruitmentPrivacyNotice::SETTING_CONTACT, ''),
            'privacy_body'     => RecruitmentPrivacyNotice::body(),
            'retention_months' => app(RetentionService::class)->months(),
            'retention_auto'   => Settings::bool('recruitment_retention_auto', false),
            'templates'        => $templates,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Where replies go')
                    ->description('A candidate who replies to any recruitment email reaches this address. Left blank, the platform\'s own from-address is used — no careers@ mailbox is invented, because a reply-to nobody reads is worse than none.')
                    ->schema([
                        Forms\Components\TextInput::make('reply_to')
                            ->label('Reply-to address')
                            ->email()
                            ->maxLength(255)
                            ->placeholder('Leave blank to use the platform address')
                            ->helperText('This is also the fallback contact on the privacy notice.'),
                    ]),

                Forms\Components\Section::make('The privacy notice')
                    ->description('Shown on the application form, with a tick-box recording the version below and the moment it was ticked. An applicant who applied under version 1 keeps that record when you rewrite it.')
                    ->schema([
                        Forms\Components\Placeholder::make('privacy_warning')
                            ->hiddenLabel()
                            ->content(new \Illuminate\Support\HtmlString(
                                '<p class="text-sm text-warning-600 dark:text-warning-400">'
                                . 'The shipped wording is a <strong>draft for your review</strong>. It describes what '
                                . 'this software actually does, which is the part we can be sure of — it does not and '
                                . 'cannot assert that it satisfies the NDPA or any other law. Have somebody qualified '
                                . 'read it before you publish a vacancy.'
                                . '</p>',
                            )),

                        Forms\Components\TextInput::make('privacy_version')
                            ->label('Version')
                            ->required()
                            ->maxLength(40)
                            ->helperText('Change this whenever you change the wording below. It is stamped onto every acknowledgment, so a consent record never silently points at text nobody saw.'),

                        Forms\Components\TextInput::make('privacy_contact')
                            ->label('Where candidates write about their own data')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Blank falls back to the reply-to above. If both are blank the notice says so rather than naming a mailbox that may not exist.'),

                        Forms\Components\Textarea::make('privacy_body')
                            ->label('The notice')
                            ->rows(18)
                            ->required()
                            ->maxLength(20000)
                            ->helperText('Plain text. A line on its own ending in a colon becomes a heading; blank lines separate paragraphs. There is no HTML here on purpose — this is rendered on a public page.'),
                    ])
                    ->collapsed(),

                Forms\Components\Section::make('How long records are kept')
                    ->description('Deliberately empty until you set it. Nothing is deleted automatically while there is no period here, and the readiness check reports it as missing.')
                    ->schema([
                        Forms\Components\TextInput::make('retention_months')
                            ->label('Keep an unsuccessful application for')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(120)
                            ->suffix('months')
                            ->helperText('Measured from the last thing that happened to the application, not from when it arrived. Somebody you hired is never swept automatically.'),

                        Forms\Components\Toggle::make('retention_auto')
                            ->label('Delete automatically once that period passes')
                            ->helperText('Off, records are only removed when somebody does it by hand with a preview in front of them. On, the nightly command sweeps. It refuses either way while the period above is blank.'),

                        Forms\Components\Placeholder::make('retention_backups')
                            ->label('About backups')
                            ->content('Deleting here deletes from the live system. Database backups taken before a purge still contain the data until those backups age out on your host\'s own cycle, which is not something this application controls or can report on.'),
                    ])
                    ->collapsed(),

                Forms\Components\Section::make('What candidates are told')
                    ->description('Eleven emails. Edit the words; the branding, the escaping and the single button come from the layout. Save the shipped wording back unchanged and the override is cleared.')
                    ->schema([
                        Forms\Components\Placeholder::make('placeholder_help')
                            ->label('Placeholders you can use')
                            ->content(new \Illuminate\Support\HtmlString(
                                '<ul class="text-sm space-y-1">'
                                . collect(RecruitmentTemplates::PLACEHOLDERS)
                                    ->map(fn (string $what, string $token) => '<li><code>'
                                        . e($token) . '</code> — ' . e($what) . '</li>')
                                    ->implode('')
                                . '</ul><p class="text-sm mt-2">A paragraph whose placeholder has nothing behind it is dropped '
                                . 'rather than sent with the braces showing. Use the preview to see which.</p>',
                            )),

                        Forms\Components\Tabs::make('templates')->tabs(
                            collect(RecruitmentTemplates::KEYS)
                                ->map(fn (string $label, string $key) => Forms\Components\Tabs\Tab::make($label)
                                    ->badge(RecruitmentTemplates::isEdited($key) ? 'edited' : null)
                                    ->schema([
                                        Forms\Components\TextInput::make("templates.{$key}.subject")
                                            ->label('Subject')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\Textarea::make("templates.{$key}.body")
                                            ->label('Body')
                                            ->rows(12)
                                            ->required()
                                            ->maxLength(8000),

                                        Forms\Components\Placeholder::make("templates.{$key}.action")
                                            ->label('The button')
                                            ->content(RecruitmentTemplates::action($key)
                                                ?? 'This email has no button — there is nothing for the candidate to do.'),

                                        Forms\Components\Actions::make([
                                            Forms\Components\Actions\Action::make("preview_{$key}")
                                                ->label('Preview with example details')
                                                ->icon('heroicon-o-eye')
                                                ->action(fn () => $this->preview($key)),

                                            Forms\Components\Actions\Action::make("restore_{$key}")
                                                ->label('Restore the shipped wording')
                                                ->icon('heroicon-o-arrow-uturn-left')
                                                ->color('gray')
                                                ->requiresConfirmation()
                                                ->action(fn () => $this->restore($key)),
                                        ]),
                                    ]))
                                ->values()
                                ->all(),
                        ),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PlatformSetting::set('recruitment_reply_to', trim((string) ($data['reply_to'] ?? '')), 'string');

        PlatformSetting::set(
            RecruitmentPrivacyNotice::SETTING_VERSION,
            trim((string) ($data['privacy_version'] ?? '')) ?: '1',
            'string',
        );

        PlatformSetting::set(
            RecruitmentPrivacyNotice::SETTING_CONTACT,
            trim((string) ($data['privacy_contact'] ?? '')),
            'string',
        );

        // Saving the shipped draft back unchanged clears the override, so the
        // screen keeps telling the truth about whether anybody has reviewed it.
        $body = trim((string) ($data['privacy_body'] ?? ''));

        PlatformSetting::set(
            RecruitmentPrivacyNotice::SETTING_BODY,
            $body === trim(RecruitmentPrivacyNotice::draft()) ? '' : $body,
            'string',
        );

        PlatformSetting::set('recruitment_retention_months', max(0, (int) ($data['retention_months'] ?? 0)), 'integer');
        PlatformSetting::set('recruitment_retention_auto', ($data['retention_auto'] ?? false) ? 1 : 0, 'boolean');

        foreach ((array) ($data['templates'] ?? []) as $key => $parts) {
            if (! isset(RecruitmentTemplates::KEYS[$key])) {
                continue;
            }

            RecruitmentTemplates::store(
                (string) $key,
                (string) ($parts['subject'] ?? ''),
                (string) ($parts['body'] ?? ''),
            );
        }

        // Settings memoises per request; without this the page re-renders with
        // the values it had before the save.
        Settings::flush();

        $retention = app(RetentionService::class);

        Notification::make()
            ->title('Saved')
            ->body($retention->blockedReason() ?? 'The automatic purge is configured and running.')
            ->success()
            ->send();
    }

    /* ---- preview --------------------------------------------------------- */

    /** The rendered preview, or null. Held on the page so the view can show it. */
    public ?array $previewed = null;

    /**
     * What a candidate would actually receive.
     *
     * Rendered from the text currently in the form — unsaved included — and
     * through the same renderText() the sender uses, with the real email
     * layout. So a paragraph dropped because its placeholder was empty is
     * dropped here too, which is the main thing a preview is for.
     *
     * The example details are obviously fictional. Nothing is sent: this
     * produces HTML on the page and does not touch the outbox.
     */
    public function preview(string $key): void
    {
        $state = (array) ($this->data['templates'][$key] ?? []);

        $rendered = RecruitmentTemplates::renderText(
            (string) ($state['subject'] ?? ''),
            (string) ($state['body'] ?? ''),
            static::exampleData(),
            RecruitmentTemplates::action($key),
        );

        $this->previewed = [
            'label'   => RecruitmentTemplates::KEYS[$key] ?? $key,
            'subject' => $rendered['subject'],
            'html'    => view('emails.recruitment.candidate', [
                'subject'    => $rendered['subject'],
                'paragraphs' => $rendered['paragraphs'],
                'actionText' => $rendered['action'],
                // No URL: a preview must not hand anybody a working link into
                // somebody else's application, and there is no application.
                'actionUrl'  => null,
                'footnote'   => null,
                'replyTo'    => RecruitmentTemplates::replyTo(),
            ])->render(),
        ];
    }

    /** @return array<string, string> */
    private static function exampleData(): array
    {
        return [
            'first_name'     => 'Amaka',
            'full_name'      => 'Amaka Obi',
            'role'           => 'Customer Service Representative',
            'reference'      => 'NVN-EXAMPLE-0000',
            'company'        => 'Naija Virtual Notary',
            'deadline'       => 'Friday 16 October 2026 at 5:00pm (Africa/Lagos)',
            'interview_time' => 'Tuesday 20 October 2026 at 11:00am (Africa/Lagos)',
            'meeting_url'    => 'https://example.invalid/meeting-link',
            'effort'         => 'about 30 to 45 minutes',
        ];
    }

    public function restore(string $key): void
    {
        $default = RecruitmentTemplates::defaults()[$key] ?? null;

        if (! $default) {
            return;
        }

        $this->data['templates'][$key] = [
            'subject' => $default['subject'],
            'body'    => trim($default['body']),
        ];

        Notification::make()
            ->title('Shipped wording restored')
            ->body('Nothing is saved until you press Save, so you can still change your mind.')
            ->success()
            ->send();
    }
}
