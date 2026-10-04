<?php

namespace Tests\Feature;

use App\Filament\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Notifications\Organization\OrganizationApproved;
use App\Notifications\Organization\OrganizationDeclined;
use App\Services\OrganizationOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * A body asking to partner, and the one door into being live.
 *
 * The application IS the organization record, created at `pending`. Two things
 * follow from that and both are pinned here: a pending body must be completely
 * inert — no link, no portal, nothing that could be handed out by mistake —
 * and approval must refuse until the money is settled, because a reachable
 * body with no price quotes its applicants ₦0.00 under a government name, and
 * by then the link may already be printed on something.
 *
 * The last test is the drift guard. Organization::APPLICATION_FIELDS is the
 * single list of what the public form writes, and if the admin form ever stops
 * offering one of them there is no way to correct what was sent.
 */
class OrganizationApplicationTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('private');
        Notification::fake();
    }

    public function test_the_public_form_creates_a_pending_body_with_its_paperwork(): void
    {
        $this->post(route('organization.apply.store'), $this->application([
            'name' => 'Federal Lands Bureau',
        ]))->assertRedirect(route('organization.apply.show'));

        $organization = Organization::where('name', 'Federal Lands Bureau')->firstOrFail();

        $this->assertTrue($organization->isPending());
        $this->assertNotNull($organization->applied_at);

        // The contact block is the whole point of asking for it: it is what
        // the office rings when the submission has a mistake in it.
        $this->assertSame('Adaeze Nwosu', $organization->contact_name);
        $this->assertSame('apply@lands.test', $organization->contact_email);
        $this->assertSame('08030000001', $organization->phone);

        // And nothing about the money came from outside.
        $this->assertNull($organization->arrangement);
        $this->assertNull($organization->default_price_ngn);
        $this->assertNull($organization->slug);
        $this->assertNull($organization->code);

        $documents = $organization->documents;
        $this->assertCount(2, $documents);
        $this->assertSame(
            ['authorisation', 'registration'],
            $documents->pluck('document_type')->sort()->values()->all(),
        );
        Storage::disk('private')->assertExists($documents->firstWhere('document_type', 'registration')->file_url);
        Storage::disk('public')->assertExists($organization->fresh()->logo_url);
    }

    public function test_a_pending_body_is_inert(): void
    {
        $organization = $this->makeOrganization(['status' => 'pending']);

        // It has a slug and credentials only because the fixture gives every
        // body those — which is what makes this worth asserting. It is the
        // status that closes the door, not the absence of a link.
        $this->get(route('organization.landing', $organization->slug))->assertNotFound();

        $this->post(route('organization.login.attempt'), [
            'email'    => $organization->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('organization');
    }

    public function test_approval_is_refused_until_the_money_is_settled(): void
    {
        $organization = $this->makeOrganization([
            'status'            => 'pending',
            'slug'              => null,
            'code'              => null,
            'arrangement'       => null,
            'default_price_ngn' => null,
        ]);

        [$ok, $message] = app(OrganizationOnboardingService::class)->approve($organization);

        $this->assertFalse($ok);
        $this->assertStringContainsString('the arrangement', $message);
        $this->assertStringContainsString('price', $message);

        $this->assertTrue($organization->fresh()->isPending());
        $this->assertNull($organization->fresh()->slug);
        Notification::assertNothingSent();
    }

    public function test_commission_without_bank_details_is_refused(): void
    {
        $organization = $this->makeOrganization([
            'status'         => 'pending',
            'account_number' => null,
        ]);

        [$ok, $message] = app(OrganizationOnboardingService::class)->approve($organization);

        $this->assertFalse($ok);
        $this->assertStringContainsString('bank details', $message);
    }

    public function test_a_body_charged_a_rate_only_needs_no_bank_details(): void
    {
        $organization = $this->makeOrganization([
            'status'          => 'pending',
            'arrangement'     => Organization::PRICE_ONLY,
            'commission_rate' => 15,
            'bank_name'       => null,
            'account_name'    => null,
            'account_number'  => null,
        ]);

        [$ok] = app(OrganizationOnboardingService::class)->approve($organization);

        $this->assertTrue($ok);

        // Whatever rate was typed before the arrangement was settled is
        // cleared on the way in, so nothing downstream can read a share off a
        // body that earns none.
        $this->assertSame(0, (int) $organization->fresh()->commission_rate);
    }

    public function test_approval_mints_the_link_and_emails_the_contact(): void
    {
        $notary = $this->makeSystemNotary();
        $this->makeService($notary);

        $organization = $this->makeOrganization([
            'name'          => 'Federal Lands Bureau',
            'status'        => 'pending',
            'slug'          => null,
            'code'          => null,
            'email'         => null,
            'password'      => null,
            'contact_email' => 'adaeze@lands.test',
        ]);

        $admin = $this->makeClient(['role' => 'admin']);

        [$ok, $message] = app(OrganizationOnboardingService::class)->approve($organization, $admin->id);

        $this->assertTrue($ok);
        $this->assertStringContainsString('adaeze@lands.test', $message);

        $organization->refresh();
        $this->assertTrue($organization->isActive());
        $this->assertNotEmpty($organization->slug);
        $this->assertNotEmpty($organization->code);
        $this->assertSame($admin->id, $organization->reviewed_by);

        // The portal login defaults to the person who asked for the
        // partnership, because that is who the credentials are emailed to.
        $this->assertSame('adaeze@lands.test', $organization->email);
        $this->assertNotEmpty($organization->password);

        Notification::assertSentTo($organization, OrganizationApproved::class);

        $this->get(route('organization.landing', $organization->slug))
            ->assertOk()
            ->assertSee('Federal Lands Bureau');
    }

    public function test_a_rejection_keeps_the_record_and_the_reason(): void
    {
        $organization = $this->makeOrganization(['status' => 'pending']);

        [$ok] = app(OrganizationOnboardingService::class)
            ->reject($organization, 'No certificate of registration attached.');

        $this->assertTrue($ok);

        $organization->refresh();
        $this->assertSame('rejected', $organization->status);
        $this->assertSame('No certificate of registration attached.', $organization->review_note);

        // Kept, not deleted. The body may send the paperwork tomorrow, and the
        // conversation so far is on this record.
        $this->assertDatabaseHas('organizations', ['id' => $organization->id]);
        Notification::assertSentTo($organization, OrganizationDeclined::class);
    }

    public function test_a_live_partnership_cannot_be_rejected(): void
    {
        $organization = $this->makeOrganization();

        [$ok, $message] = app(OrganizationOnboardingService::class)->reject($organization, 'Changed our minds.');

        $this->assertFalse($ok);
        $this->assertStringContainsString('Pause it', $message);
        $this->assertSame('active', $organization->fresh()->status);
    }

    public function test_an_admin_edit_of_a_submitted_field_sticks(): void
    {
        $this->post(route('organization.apply.store'), $this->application([
            'name'                => 'Fedral Lands Bureu',
            'registration_number' => 'RC-000000',
        ]));

        $organization = Organization::where('contact_email', 'apply@lands.test')->firstOrFail();

        // The correction path is the ordinary edit. There is no application
        // table to copy across, so a misspelling is fixed on the record the
        // office will keep using afterwards.
        $organization->update([
            'name'                => 'Federal Lands Bureau',
            'registration_number' => 'RC-123456',
        ]);

        $organization->refresh();
        $this->assertSame('Federal Lands Bureau', $organization->name);
        $this->assertSame('RC-123456', $organization->registration_number);
        $this->assertTrue($organization->isPending());
    }

    /**
     * The drift guard.
     *
     * Everything the public form writes has to be correctable, and the two
     * lists are maintained in different files. If a field is ever dropped from
     * the admin form, a wrong RC number submitted by a body becomes permanent
     * with nothing anywhere saying so.
     */
    public function test_every_submitted_field_is_editable_in_the_panel(): void
    {
        $livewire = new class extends \Livewire\Component implements \Filament\Forms\Contracts\HasForms {
            use \Filament\Forms\Concerns\InteractsWithForms;
        };

        $fields = array_keys(
            OrganizationResource::form(\Filament\Forms\Form::make($livewire))
                ->getFlatFields(withHidden: true),
        );

        foreach (Organization::APPLICATION_FIELDS as $field) {
            $this->assertContains(
                $field,
                $fields,
                $field . ' is asked for on the public form but cannot be corrected in the panel.',
            );
        }
    }

    public function test_a_second_application_corrects_the_open_one_rather_than_duplicating_it(): void
    {
        $this->post(route('organization.apply.store'), $this->application(['name' => 'Fedral Lands']));
        $this->post(route('organization.apply.store'), $this->application(['name' => 'Federal Lands Bureau']))
            ->assertRedirect(route('organization.apply.show'));

        // One body, one conversation. Two records would give the office two
        // half-right versions of the same organization.
        $this->assertSame(1, Organization::where('contact_email', 'apply@lands.test')->count());
        $this->assertSame(
            'Federal Lands Bureau',
            Organization::where('contact_email', 'apply@lands.test')->value('name'),
        );
        $this->assertSame(4, OrganizationDocument::count());
    }

    public function test_applying_again_on_a_live_partnership_is_refused(): void
    {
        $this->makeOrganization(['contact_email' => 'apply@lands.test']);

        $this->post(route('organization.apply.store'), $this->application())
            ->assertSessionHasErrors('contact_email');

        $this->assertSame(1, Organization::where('contact_email', 'apply@lands.test')->count());
    }

    /** A complete, valid submission — the shape the public form posts. */
    private function application(array $overrides = []): array
    {
        return array_merge([
            'name'                => 'Lands Bureau',
            'registration_number' => 'RC-123456',
            'sector'              => 'government',
            'website'             => 'https://lands.test',
            'address'             => '1 Marina Road, Lagos',
            'about'               => 'We register title and need deeds notarized for applicants.',
            'expected_volume'     => '50_to_200',
            'contact_name'        => 'Adaeze Nwosu',
            'contact_role'        => 'Head of Registry',
            'contact_email'       => 'apply@lands.test',
            'phone'               => '08030000001',
            'logo'                => UploadedFile::fake()->image('crest.png'),
            'registration'        => UploadedFile::fake()->create('cac.pdf', 120, 'application/pdf'),
            'authorisation'       => UploadedFile::fake()->create('letter.pdf', 90, 'application/pdf'),
            'accuracy_consent'    => '1',
            'contact_consent'     => '1',
        ], $overrides);
    }
}
