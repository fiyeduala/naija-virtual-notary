<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Who a rate limit is allowed to refuse.
 *
 * Every public form used to carry a flat per-IP limit, which is the shape
 * Laravel hands you and the wrong shape for who visits us. Most of our traffic
 * comes over Nigerian mobile networks, where a very large number of phones
 * share one carrier address, and a law firm or a business centre is one
 * address with a room of people behind it. Five an hour on the address means
 * the sixth honest person is refused for something a stranger did — and the
 * site really was turning people away, which is how this came to be looked at.
 *
 * So the rule these tests hold to is: stop the person who is hammering the
 * form, leave everybody else alone, and keep a ceiling on the address that is
 * high enough to be out of an ordinary visitor's way and low enough to matter
 * to a script.
 *
 * The submissions below are deliberately invalid. A rejected submission still
 * counts against a limit — the limiter runs long before the validator — and
 * that is exactly how a real person gets refused: by mistyping the password
 * rules four times and then being told to come back in an hour.
 */
class PublicFormThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /** An incomplete signup. Enough to be counted, not enough to be accepted. */
    private function signup(string $email): array
    {
        return ['email' => $email];
    }

    public function test_one_visitor_is_stopped_after_five_tries(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/register', $this->signup('same@example.test'))
                ->assertStatus(302);
        }

        $this->post('/register', $this->signup('same@example.test'))
            ->assertStatus(429);
    }

    public function test_the_next_person_on_that_same_connection_is_not(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post('/register', $this->signup('noisy@example.test'));
        }

        // The whole point. Same IP, different person, still served.
        $this->post('/register', $this->signup('someone-else@example.test'))
            ->assertStatus(302);
    }

    public function test_the_address_still_has_a_ceiling(): void
    {
        // Twenty distinct people from one address is already well beyond an
        // office, and it is where a script varying the email runs out.
        for ($i = 1; $i <= 20; $i++) {
            $this->post('/register', $this->signup("person{$i}@example.test"))
                ->assertStatus(302);
        }

        $this->post('/register', $this->signup('twenty-first@example.test'))
            ->assertStatus(429);
    }

    public function test_a_blocked_person_does_not_spend_the_shared_ceiling(): void
    {
        // The limits are checked in order and the first one that trips throws,
        // so a refusal never reaches the address limit. Otherwise one person
        // holding down a button would eat the allowance of everybody sharing
        // their connection, which is the problem this was meant to solve.
        for ($i = 1; $i <= 12; $i++) {
            $this->post('/register', $this->signup('persistent@example.test'));
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', $this->signup("bystander{$i}@example.test"))
                ->assertStatus(302);
        }
    }

    public function test_each_form_counts_separately(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post('/register', $this->signup('busy@example.test'));
        }

        $this->post('/register', $this->signup('busy@example.test'))
            ->assertStatus(429);

        // Having used up the signup form says nothing about the comment form.
        // The keys carry the route name precisely so they cannot share.
        $this->assertNotSame(
            429,
            $this->post(route('notary.apply'), ['email' => 'busy@example.test'])->status()
        );
    }

    public function test_the_refusal_explains_itself(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->post('/register', $this->signup('blocked@example.test'));
        }

        $response = $this->post('/register', $this->signup('blocked@example.test'));

        $response->assertStatus(429);

        // Not the framework's bare TOO MANY REQUESTS page.
        $response->assertSee('One moment');
        $response->assertSee('nothing you sent has been lost', false);
    }

    public function test_the_refusal_is_logged(): void
    {
        Log::spy();

        for ($i = 1; $i <= 6; $i++) {
            $this->post('/register', $this->signup('watched@example.test'));
        }

        $this->post('/register', $this->signup('watched@example.test'))
            ->assertStatus(429);

        // A 429 is an HttpException and Laravel reports none of those, so
        // until this was turned on the only evidence a limit was too tight
        // was somebody mentioning it.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Throttled')
            ->atLeast()->once();
    }
}
