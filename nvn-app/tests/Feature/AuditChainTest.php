<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The audit log is evidence, so it has to notice when it has been changed —
 * and it has to not cry wolf when it has not.
 *
 * Both failures are real. A chain that misses an edit is not evidence. A chain
 * that reports tampering whenever the timezone setting changes teaches everyone
 * to ignore it, which ends the same way.
 *
 * These run on SQLite. The timezone cases rely on the timestamp column holding
 * wall-clock digits with no zone attached, which SQLite and MySQL's DATETIME
 * both do, so the behaviour carries over — but a MySQL-only quirk would not
 * show up here.
 */
class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    private function useTimezone(string $zone): void
    {
        config(['app.timezone' => $zone]);
        date_default_timezone_set($zone);
    }

    private function writeEntries(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            AuditLogger::record('test.entry', 'thing', $i, ['n' => $i]);
        }
    }

    public function test_an_untouched_chain_verifies(): void
    {
        $this->writeEntries(5);

        $result = AuditLogger::verify();

        $this->assertSame(5, $result['checked']);
        $this->assertSame([], $result['broken']);
        $this->assertSame([], $result['gaps']);
        $this->assertNull(AuditLogger::verifyChain());
    }

    public function test_each_entry_is_chained_to_the_one_before(): void
    {
        $this->writeEntries(3);

        $rows = AuditLog::orderBy('id')->get();

        $this->assertNull($rows[0]->previous_hash);
        $this->assertSame($rows[0]->content_hash, $rows[1]->previous_hash);
        $this->assertSame($rows[1]->content_hash, $rows[2]->previous_hash);
    }

    /**
     * An edited entry is caught — and only that entry. If the damage cascaded
     * into every later row, one edit and a wholesale rewrite would look alike.
     */
    public function test_an_edited_entry_is_caught_without_blaming_the_rest(): void
    {
        $this->writeEntries(5);
        $third = (int) AuditLog::orderBy('id')->skip(2)->value('id');

        DB::table('audit_log')->where('id', $third)->update(['action' => 'test.rewritten']);

        $this->assertSame([$third], AuditLogger::verify()['broken']);
        $this->assertSame($third, AuditLogger::verifyChain());
    }

    public function test_edited_details_are_caught_too(): void
    {
        $this->writeEntries(3);
        $first = (int) AuditLog::orderBy('id')->value('id');

        DB::table('audit_log')->where('id', $first)->update(['metadata' => json_encode(['n' => 999])]);

        $this->assertSame([$first], AuditLogger::verify()['broken']);
    }

    /** A deleted entry shows up as a hole in the numbering. */
    public function test_a_deleted_entry_leaves_a_visible_gap(): void
    {
        $this->writeEntries(4);
        $second = (int) AuditLog::orderBy('id')->skip(1)->value('id');

        DB::table('audit_log')->where('id', $second)->delete();

        $this->assertContains($second, AuditLogger::verify()['gaps']);
    }

    /**
     * What actually happened on the live server: entries sealed under UTC, then
     * read under Africa/Lagos. Nothing was edited, but the offset written into
     * each hash has changed, so the old entries no longer verify on their own.
     */
    public function test_changing_the_timezone_breaks_the_entries_sealed_before_it(): void
    {
        [$utc, ] = $this->sealTwoUnderUtcThenOneUnderLagos();

        $this->assertSame($utc, AuditLogger::verify()['broken'], 'only the entries sealed under UTC');
    }

    /** Declaring where the boundary falls makes the whole chain verify again. */
    public function test_declaring_the_old_timezone_makes_the_chain_verify(): void
    {
        [$utc, ] = $this->sealTwoUnderUtcThenOneUnderLagos();

        config(['nvn.audit.legacy_timezone' => 'UTC', 'nvn.audit.legacy_through_id' => end($utc)]);

        $this->assertSame([], AuditLogger::verify()['broken']);
    }

    /** The boundary is not an amnesty: an entry inside it that was edited still fails. */
    public function test_an_edit_inside_the_old_timezone_range_is_still_caught(): void
    {
        [$utc, ] = $this->sealTwoUnderUtcThenOneUnderLagos();

        config(['nvn.audit.legacy_timezone' => 'UTC', 'nvn.audit.legacy_through_id' => end($utc)]);

        DB::table('audit_log')->where('id', $utc[0])->update(['action' => 'test.rewritten']);

        $this->assertSame([$utc[0]], AuditLogger::verify()['broken']);
    }

    /** And an entry sealed after the change is not read in the old timezone. */
    public function test_the_boundary_does_not_reach_past_where_it_was_drawn(): void
    {
        [, $lagos] = $this->sealTwoUnderUtcThenOneUnderLagos();

        config(['nvn.audit.legacy_timezone' => 'UTC', 'nvn.audit.legacy_through_id' => $lagos]);

        $this->assertSame([$lagos], AuditLogger::verify()['broken'], 'the last entry was sealed under Lagos');
    }

    /**
     * Returns the ids actually written rather than assuming 1, 2, 3. On SQLite
     * every test starts from an empty database, but on MySQL the tests share one
     * and a rolled-back insert still uses up its id, so numbering carries on.
     *
     * @return array{0: list<int>, 1: int}
     */
    private function sealTwoUnderUtcThenOneUnderLagos(): array
    {
        $this->useTimezone('UTC');
        $this->writeEntries(2);
        $utc = AuditLog::orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->useTimezone('Africa/Lagos');
        $this->writeEntries(1);
        $lagos = (int) AuditLog::max('id');

        return [$utc, $lagos];
    }
}
