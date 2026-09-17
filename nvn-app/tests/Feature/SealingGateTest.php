<?php

namespace Tests\Feature;

use App\Models\NotaryAsset;
use App\Models\NotaryProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * Nobody seals a document without all three marks.
 *
 * canSeal() is the single answer to that question — listing, booking and the
 * editor all ask it. A notary who got past it with a mark missing would be
 * booked and paid for a job they cannot finish, and the client would find out
 * at the end rather than the start.
 */
class SealingGateTest extends TestCase
{
    use Fixtures, RefreshDatabase;

    public function test_a_notary_with_signature_stamp_and_seal_can_seal(): void
    {
        $notary = $this->makeNotary();
        $this->giveSealingAssets($notary);

        $this->assertTrue($notary->fresh()->canSeal());
        $this->assertSame([], $notary->fresh()->missingSealingAssets());
    }

    public function test_a_notary_with_nothing_uploaded_cannot_seal(): void
    {
        $notary = $this->makeNotary();

        $this->assertFalse($notary->canSeal());
        $this->assertSame(NotaryProfile::SEALING_ASSETS, $notary->missingSealingAssets());
    }

    /** Each of the three is required on its own — two out of three is not enough. */
    public function test_any_single_missing_mark_blocks_sealing(): void
    {
        foreach (NotaryProfile::SEALING_ASSETS as $missing) {
            $notary = $this->makeNotary();
            $this->giveSealingAssets($notary, array_values(array_diff(NotaryProfile::SEALING_ASSETS, [$missing])));

            $this->assertFalse($notary->fresh()->canSeal(), "a notary without a {$missing} must not seal");
            $this->assertSame([$missing], $notary->fresh()->missingSealingAssets(), 'and the missing one is named');
        }
    }

    /**
     * A row whose image is gone is not a mark. This is what a host migration
     * leaves behind: the database says the seal exists, the file does not.
     */
    public function test_an_asset_row_without_a_file_does_not_count(): void
    {
        $notary = $this->makeNotary();
        $this->giveSealingAssets($notary, ['signature', 'stamp']);

        NotaryAsset::create([
            'notary_profile_id' => $notary->id,
            'type'              => 'seal',
            'file_url'          => '',
        ]);

        $this->assertFalse($notary->fresh()->canSeal());
        $this->assertSame(['seal'], $notary->fresh()->missingSealingAssets());
    }

    /** Initials are for initialling pages, not part of the seal; they cannot stand in for one. */
    public function test_initials_do_not_substitute_for_a_seal_mark(): void
    {
        $notary = $this->makeNotary();
        $this->giveSealingAssets($notary, ['signature', 'stamp', 'initials']);

        $this->assertFalse($notary->fresh()->canSeal());
    }
}
