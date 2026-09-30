<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\DocumentPlacement;
use App\Models\NotarizationRequest;
use App\Models\RequestDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * The notary places the client's own signature, and only that.
 *
 * Clients send a signature image meaning "sign it here for me", and the job
 * should not wait for a call nobody needs. What must not happen is the two
 * loopholes that come with it: any image id in the request body being accepted
 * (the identification scan, or another client's file), and the client's
 * signature being read afterwards as a notarial act.
 */
class ClientSignaturePlacementTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    /** The request, its notary at the keyboard, and the documents on it. */
    private function desk(): array
    {
        Storage::fake('private');

        $notary = $this->makeNotary();
        $notary->user->forceFill(['email_verified_at' => now()])->save();
        $this->giveSealingAssets($notary);

        $client = $this->makeClient(['email_verified_at' => now()]);

        $request = NotarizationRequest::create([
            'reference'  => 'NVN-SIG-' . uniqid(),
            'client_id'  => $client->id,
            'notary_id'  => $notary->id,
            'status'     => RequestStatus::Notarizing->value,
            'currency'   => 'NGN',
            'is_offsite' => false,
        ]);

        $make = function (string $type, string $name) use ($request, $client) {
            Storage::disk('private')->put('request-documents/' . $name, 'x');

            return RequestDocument::create([
                'request_id'        => $request->id,
                'uploaded_by'       => $client->id,
                'file_url'          => 'request-documents/' . $name,
                'original_filename' => $name,
                'file_type'         => $type,
            ]);
        };

        return [
            'notary'    => $notary,
            'request'   => $request,
            'document'  => $make('document', 'deed.pdf'),
            'signature' => $make('client_signature', 'signature_9_1.png'),
            'id'        => $make('identification', 'passport.png'),
        ];
    }

    private function save(NotarizationRequest $request, RequestDocument $document, array $placement)
    {
        return $this->postJson(
            route('session.placements', ['request' => $request->id, 'document' => $document->id]),
            ['placements' => [array_merge([
                'type'   => 'asset',
                'page'   => 1,
                'x'      => 0.4,
                'y'      => 0.8,
                'width'  => 0.2,
                'height' => 0.06,
            ], $placement)]],
        );
    }

    public function test_the_notary_may_place_the_clients_uploaded_signature(): void
    {
        $d = $this->desk();

        $this->actingAs($d['notary']->user)
            ->save($d['request'], $d['document'], ['signature_document_id' => $d['signature']->id])
            ->assertOk()
            ->assertJson(['saved' => 1]);

        $placement = DocumentPlacement::where('document_id', $d['document']->id)->sole();

        $this->assertSame($d['signature']->id, $placement->signature_document_id);
        // The one thing that must be true of it: it names no notary asset, which
        // is what keeps it out of the sealed PDF's authorship.
        $this->assertNull($placement->asset_id);
    }

    /** An ID scan is evidence the notary looked at, not a mark to stamp on a deed. */
    public function test_the_identification_scan_may_not_be_placed(): void
    {
        $d = $this->desk();

        $this->actingAs($d['notary']->user)
            ->save($d['request'], $d['document'], ['signature_document_id' => $d['id']->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('placements.0.signature_document_id');

        $this->assertSame(0, DocumentPlacement::count());
    }

    /** The id arrives from the browser, so it is checked against this request. */
    public function test_a_signature_belonging_to_another_request_is_refused(): void
    {
        $d     = $this->desk();
        $other = $this->desk();

        $this->actingAs($d['notary']->user)
            ->save($d['request'], $d['document'], ['signature_document_id' => $other['signature']->id])
            ->assertStatus(422);
    }

    /** A notary mark wins: a placement is one or the other, never both. */
    public function test_a_placement_carrying_both_keeps_only_the_notary_asset(): void
    {
        $d     = $this->desk();
        $asset = $d['notary']->assets()->where('type', 'signature')->sole();

        $this->actingAs($d['notary']->user)
            ->save($d['request'], $d['document'], [
                'asset_id'              => $asset->id,
                'signature_document_id' => $d['signature']->id,
            ])
            ->assertOk();

        $placement = DocumentPlacement::where('document_id', $d['document']->id)->sole();

        $this->assertSame($asset->id, $placement->asset_id);
        $this->assertNull($placement->signature_document_id);
    }

    public function test_the_signature_image_streams_but_the_identification_does_not(): void
    {
        $d = $this->desk();

        $this->actingAs($d['notary']->user)
            ->get(route('session.client-signature', ['request' => $d['request']->id, 'signature' => $d['signature']->id]))
            ->assertOk();

        $this->actingAs($d['notary']->user)
            ->get(route('session.client-signature', ['request' => $d['request']->id, 'signature' => $d['id']->id]))
            ->assertNotFound();
    }

    /** Nobody outside the notary side reaches it, not even the client themselves. */
    public function test_a_stranger_cannot_stream_the_signature(): void
    {
        $d = $this->desk();

        $stranger = User::create([
            'full_name'         => 'Stranger',
            'email'             => 'stranger-' . uniqid() . '@example.test',
            'password'          => 'password',
            'role'              => 'client',
            'status'            => 'active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($stranger)
            ->get(route('session.client-signature', ['request' => $d['request']->id, 'signature' => $d['signature']->id]))
            ->assertForbidden();
    }

    /**
     * The sealed PDF's Author must never say the client sealed anything.
     *
     * sealAuthor() derives it from asset_id alone, so a document carrying only
     * the client's signature falls back to the notary of record. Pinned here
     * because the natural "simplification" — widening asset_id to mean any
     * image — would silently break it.
     */
    public function test_a_client_signature_does_not_become_the_seals_author(): void
    {
        $d = $this->desk();

        $placement = DocumentPlacement::create([
            'document_id'           => $d['document']->id,
            'type'                  => 'asset',
            'signature_document_id' => $d['signature']->id,
            'page'                  => 1,
            'x'                     => 0.4,
            'y'                     => 0.8,
            'width'                 => 0.2,
            'height'                => 0.06,
            'placed_by'             => $d['notary']->user_id,
        ]);

        $service = app(\App\Services\PdfNotarizationService::class);
        $method  = new \ReflectionMethod($service, 'sealAuthor');
        $method->setAccessible(true);

        $author = $method->invoke(
            $service,
            $d['request']->fresh(),
            collect([1 => [$placement]]),
        );

        $this->assertSame($d['notary']->user->full_name, $author);
    }
}
