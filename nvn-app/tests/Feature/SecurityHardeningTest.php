<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Enums\UserRole;
use App\Http\Middleware\SecurityHeaders;
use App\Models\NotarizationRequest;
use App\Models\User;
use App\Support\BlogAttachment;
use Database\Seeders\SystemNativeNotarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The hardening that has no feature attached to it.
 *
 * Everything here is something that was absent and is now present: response
 * headers, a privilege column that a form can no longer write, a short list of
 * file types the blog editor will accept, and the refusal that stops one
 * client reading another's paperwork. None of it shows up on a screen, which
 * is exactly why it needs tests — a header that quietly stops being sent, or a
 * column that quietly becomes mass-assignable again, looks like nothing at all
 * until somebody is already inside.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase, Fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    // ---------------------------------------------------------------- headers

    public function test_a_public_page_carries_the_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->assertHeader('Permissions-Policy');
    }

    public function test_the_admin_panel_carries_them_too(): void
    {
        // The panel assembles its own middleware stack instead of using the
        // `web` group, so the middleware is listed there a second time. This is
        // the test that catches somebody editing one list and not the other —
        // and the panel is where the documents and the bank details live.
        $response = $this->get(route('filament.admin.auth.login'));

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_the_policy_ships_report_only(): void
    {
        $response = $this->get('/');

        // Enforcing before the reports have been read is how a payment page
        // stops working, so the default is the safe one. Flipping
        // NVN_CSP_ENFORCE is a deliberate act and this asserts it has not
        // happened by accident.
        $response->assertHeader('Content-Security-Policy-Report-Only');
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
    }

    public function test_the_policy_names_what_the_application_actually_loads(): void
    {
        $policy = SecurityHeaders::contentSecurityPolicy();

        // The ones worth asserting are the ones whose absence breaks a
        // notarization rather than a decoration: the video call's socket, the
        // room's frame, and the PDF worker's blob.
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('wss://*.daily.co', $policy);
        $this->assertStringContainsString('https://*.daily.co', $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
    }

    public function test_the_permissions_policy_leaves_the_camera_alone(): void
    {
        $policy = $this->get('/')->headers->get('Permissions-Policy');

        // Deliberate: a virtual notarization is a video call in a cross-origin
        // frame. Naming camera or microphone here means naming every room
        // origin exactly, and one mistake is a session that fails with the
        // camera blocked and no explanation.
        $this->assertStringNotContainsString('camera', $policy);
        $this->assertStringNotContainsString('microphone', $policy);
        $this->assertStringNotContainsString('display-capture', $policy);

        $this->assertStringContainsString('geolocation=()', $policy);
        $this->assertStringContainsString('payment=()', $policy);
    }

    public function test_a_violation_report_is_accepted_and_says_nothing_back(): void
    {
        $response = $this->call(
            'POST',
            '/csp-report',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/csp-report'],
            json_encode(['csp-report' => [
                'document-uri'        => 'https://example.test/client',
                'effective-directive' => 'script-src',
                'blocked-uri'         => 'https://example.invalid/x.js',
            ]])
        );

        $response->assertNoContent();
        $this->assertSame('', $response->getContent());
    }

    public function test_a_malformed_report_is_not_an_error(): void
    {
        // A browser does not read the response and neither does anybody
        // friendly, so there is nothing to gain from a 422 and a stack trace.
        $this->call('POST', '/csp-report', [], [], [], [], 'not json at all')
            ->assertNoContent();
    }

    // ------------------------------------------------------- the role column

    public function test_a_role_cannot_be_mass_assigned(): void
    {
        $user = new User([
            'full_name' => 'Hopeful',
            'email'     => 'hopeful@example.test',
            'password'  => 'password',
            'role'      => 'admin',
            'status'    => 'active',
        ]);

        $user->save();

        // The column defaults to 'client', so the attempt does not fail — it
        // is simply ignored, which is the direction to fail in.
        $this->assertSame(UserRole::Client, $user->fresh()->role);
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_an_update_cannot_promote_an_account(): void
    {
        $user = $this->makeClient();

        $user->update(['role' => 'admin', 'full_name' => 'Renamed']);

        // The rest of the update still lands. Only the privilege column is
        // deaf to it, which is what makes this safe to rely on.
        $this->assertSame('Renamed', $user->fresh()->full_name);
        $this->assertSame(UserRole::Client, $user->fresh()->role);
    }

    public function test_a_public_signup_is_still_a_client(): void
    {
        // The other half of the same change: taking the column out of
        // $fillable is only safe if the paths that legitimately set it still
        // do. A signup that silently produced a roleless account would pass
        // the test above and break the application.
        $this->post('/register', [
            'full_name'             => 'New Person',
            'email'                 => 'new-person@example.test',
            'phone'                 => '08030000001',
            'password'              => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
            'terms'                 => '1',
        ])->assertRedirect(route('verify.show'));

        $user = User::where('email', 'new-person@example.test')->first();

        $this->assertNotNull($user);
        $this->assertSame(UserRole::Client, $user->role);
    }

    public function test_a_signup_cannot_ask_to_be_an_admin(): void
    {
        $this->post('/register', [
            'full_name'             => 'Ambitious',
            'email'                 => 'ambitious@example.test',
            'phone'                 => '08030000002',
            'password'              => 'Correct-Horse-9',
            'password_confirmation' => 'Correct-Horse-9',
            'terms'                 => '1',
            'role'                  => 'admin',
            'status'                => 'active',
        ]);

        $user = User::where('email', 'ambitious@example.test')->first();

        if ($user) {
            $this->assertSame(UserRole::Client, $user->role);
        }

        // Either the form refused the extra fields or it ignored them. Both
        // are correct; an admin account is not.
        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
    }

    public function test_the_platform_notary_is_still_seeded_as_an_admin(): void
    {
        // The riskiest half of taking 'role' out of $fillable, and the reason
        // this test exists at all: firstOrCreate() fills, the column defaults
        // to 'client', and the account being created here is the one that
        // applies the platform's own seal. A silent demotion would not have
        // shown up until somebody could not get into the panel.
        $this->seed(SystemNativeNotarySeeder::class);

        $admin = User::where('email', config('nvn.system_native_email'))->first();

        $this->assertNotNull($admin);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->notaryProfile->is_system_native);
    }

    public function test_seeding_twice_leaves_the_admin_an_admin(): void
    {
        $this->seed(SystemNativeNotarySeeder::class);
        $this->seed(SystemNativeNotarySeeder::class);

        $this->assertSame(
            1,
            User::where('email', config('nvn.system_native_email'))->count()
        );

        $this->assertSame(
            UserRole::Admin,
            User::where('email', config('nvn.system_native_email'))->first()->role
        );
    }

    // ------------------------------------------------- one client, one client

    public function test_one_client_cannot_open_another_clients_request(): void
    {
        $notary = $this->makeNotary();
        $mine   = $this->makeClient();
        $theirs = $this->makeClient();

        $request = NotarizationRequest::create([
            'reference'  => 'NVN-2026-001',
            'client_id'  => $mine->id,
            'notary_id'  => $notary->id,
            'status'     => RequestStatus::Submitted->value,
            'currency'   => 'NGN',
            'is_offsite' => false,
        ]);

        // The reference is guessable by design — it is printed on paperwork
        // and read out over the phone. What stops the holder of 001 reading
        // 002 is not the reference being secret; it is that every one of these
        // routes compares client_id against the session before it answers.
        $this->actingAs($theirs)
            ->get(route('client.request.review', $request))
            ->assertForbidden();

        $this->actingAs($theirs)
            ->get(route('client.marketplace.index', $request))
            ->assertForbidden();

        $this->actingAs($theirs)
            ->get(route('client.request.category.show', $request))
            ->assertForbidden();

        $this->actingAs($theirs)
            ->post(route('client.request.payment.pay', $request))
            ->assertForbidden();

        $this->actingAs($theirs)
            ->get(route('client.request.payment.status', $request))
            ->assertForbidden();

        $this->actingAs($theirs)
            ->get(route('client.documents.download', $request))
            ->assertForbidden();

        // And the owner is not locked out by the same check. Where their own
        // review screen sends them next depends on how far the request has got,
        // so what is asserted is only that they are not refused.
        $owner = $this->actingAs($mine)->get(route('client.request.review', $request));

        $this->assertNotContains($owner->status(), [403, 404],
            'the owner must not be a stranger to their own request');
    }

    public function test_a_notary_cannot_reach_a_clients_own_screens(): void
    {
        $notary = $this->makeNotary();
        $mine   = $this->makeClient();

        $request = NotarizationRequest::create([
            'reference'  => 'NVN-2026-002',
            'client_id'  => $mine->id,
            'notary_id'  => $notary->id,
            'status'     => RequestStatus::Submitted->value,
            'currency'   => 'NGN',
            'is_offsite' => false,
        ]);

        // Refused a layer earlier than the test above: every client route
        // sits behind role:client, so a notary never reaches the ownership
        // check at all. Both layers are worth having — the role middleware is
        // what a new client route inherits for free, and the ownership check
        // is what stops one client reading another's.
        $this->actingAs($notary->user)
            ->get(route('client.request.review', $request))
            ->assertForbidden();

        $this->actingAs($notary->user)
            ->post(route('client.request.payment.pay', $request))
            ->assertForbidden();
    }

    // ------------------------------------------------------ blog attachments

    public function test_the_editor_refuses_everything_but_three_raster_formats(): void
    {
        $this->assertSame(
            ['image/png', 'image/jpeg', 'image/webp'],
            array_keys(BlogAttachment::ALLOWED)
        );

        foreach ([
            'application/x-httpd-php',
            'text/html',
            'image/svg+xml',   // an XML document that may carry <script>
            'application/pdf',
            'text/plain',
            'application/octet-stream',
        ] as $mime) {
            $this->assertNotNull(
                BlogAttachment::rejectionFor($mime, 1024),
                "{$mime} must not be attachable"
            );
        }
    }

    public function test_an_oversized_image_is_refused(): void
    {
        $limit = BlogAttachment::MAX_KILOBYTES * 1024;

        $this->assertNull(BlogAttachment::rejectionFor('image/png', $limit));
        $this->assertNotNull(BlogAttachment::rejectionFor('image/png', $limit + 1));
    }

    public function test_a_claimed_type_is_matched_case_insensitively(): void
    {
        // The detected type comes back from a library, not from us, so a
        // difference in case must not become a difference in answer.
        $this->assertNull(BlogAttachment::rejectionFor('IMAGE/PNG', 1024));
        $this->assertNull(BlogAttachment::rejectionFor(' image/jpeg ', 1024));
    }

    public function test_the_upload_directories_keep_their_htaccess(): void
    {
        // Both directories' .gitignore is a bare `*`, which once meant the
        // .htaccess beside it was untracked and absent from every deployment.
        // These are the files that stop the web server executing an upload.
        foreach (['brand', 'blog-media'] as $directory) {
            $this->assertFileExists(
                public_path($directory . '/.htaccess'),
                "public/{$directory}/.htaccess is what stops an upload being executed"
            );
        }
    }
}
