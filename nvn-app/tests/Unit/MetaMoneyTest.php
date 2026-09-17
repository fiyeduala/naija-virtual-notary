<?php

namespace Tests\Unit;

use App\Services\MetaConversionsService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two arithmetic mistakes that would cost real advertising money.
 *
 * Neither one throws, logs, or shows up anywhere in the application. They are
 * only ever visible as Meta spending the budget badly, weeks later, which is
 * why they are worth pinning down here.
 */
class MetaMoneyTest extends TestCase
{
    private MetaConversionsService $meta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meta = new MetaConversionsService;
    }

    /**
     * payments.amount is kobo; Meta is told naira. A dropped division reports a
     * ₦45,000 notarization as a ₦4,500,000 one, and Meta answers by hunting for
     * more customers like that imaginary whale.
     */
    public function test_kobo_becomes_naira(): void
    {
        $this->assertSame(45000.0, $this->meta->majorUnits(4500000));
        $this->assertSame(1000.0, $this->meta->majorUnits(100000));
        $this->assertSame(0.0, $this->meta->majorUnits(0));
    }

    /** Half a kobo should not silently disappear or become a whole naira. */
    public function test_odd_amounts_keep_their_kobo(): void
    {
        $this->assertSame(45000.5, $this->meta->majorUnits(4500050));
        $this->assertSame(0.99, $this->meta->majorUnits(99));
    }

    /**
     * The ceiling exists to catch the division going missing in a refactor, so
     * the figure it must reject is precisely the hundredfold one.
     */
    public function test_the_ceiling_rejects_a_hundredfold_error(): void
    {
        $this->assertTrue($this->meta->withinCeiling(45000.0), 'an ordinary fee must be allowed through');
        $this->assertFalse($this->meta->withinCeiling(4500000.0), 'the kobo figure must be refused');
    }

    /** Nothing is not a sale, and neither is a negative. */
    public function test_the_ceiling_rejects_nothing_and_less_than_nothing(): void
    {
        $this->assertFalse($this->meta->withinCeiling(0.0));
        $this->assertFalse($this->meta->withinCeiling(-45000.0));
    }

    /**
     * Meta matches a customer partly on their phone number, and matches nothing
     * at all unless the format is exactly right: country code, digits, no plus.
     * Nigerians write their number all four of these ways, and they are one
     * person. Get this wrong and attribution quietly collapses — the ads look
     * like they produced fewer sales than they did, and the budget follows.
     *
     * hashPhone is private, and reached by reflection rather than made public:
     * it is an implementation detail everywhere except here, and widening it to
     * suit a test would be the test changing the application.
     */
    public function test_every_way_a_nigerian_writes_their_number_hashes_the_same(): void
    {
        $hash = new ReflectionMethod($this->meta, 'hashPhone');

        $written = [
            '08031234567',      // as it appears on a business card
            '+234 803 123 4567',// as a phone shows a saved contact
            '2348031234567',    // as an export writes it
            '8031234567',       // as a form strips the leading zero
        ];

        $hashes = array_map(fn (string $n) => $hash->invoke($this->meta, $n), $written);

        $this->assertCount(1, array_unique($hashes), 'the same number written four ways must match one person');
        $this->assertSame(hash('sha256', '2348031234567'), $hashes[0]);
    }

    /** No number is not a match on an empty string — it is no signal at all. */
    public function test_an_absent_number_hashes_to_nothing(): void
    {
        $hash = new ReflectionMethod($this->meta, 'hashPhone');

        $this->assertNull($hash->invoke($this->meta, null));
        $this->assertNull($hash->invoke($this->meta, ''));
        $this->assertNull($hash->invoke($this->meta, 'not a phone number'));
    }

    /**
     * With no dataset and no token nothing may be sent, whatever else is true.
     * This is what makes tracking opt-in rather than something a half-finished
     * install does by accident.
     */
    public function test_tracking_is_off_until_both_credentials_are_present(): void
    {
        config(['nvn.meta.dataset_id' => '', 'nvn.meta.access_token' => '']);
        $this->assertFalse((new MetaConversionsService)->configured());

        config(['nvn.meta.dataset_id' => '123', 'nvn.meta.access_token' => '']);
        $this->assertFalse((new MetaConversionsService)->configured(), 'a dataset alone must not enable sending');

        config(['nvn.meta.dataset_id' => '', 'nvn.meta.access_token' => 'tok']);
        $this->assertFalse((new MetaConversionsService)->configured(), 'a token alone must not enable sending');

        config(['nvn.meta.dataset_id' => '123', 'nvn.meta.access_token' => 'tok']);
        $this->assertTrue((new MetaConversionsService)->configured());
    }
}
