<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The calendar attachment on an interview invitation.
 *
 * The whole file hangs on two fields:
 *
 *   UID       — stable for the life of the interview. A rescheduled interview
 *               must carry the SAME uid, or the candidate's calendar gains a
 *               second event instead of moving the first, and they turn up at
 *               the old time. The uid is derived from the interview's own id,
 *               so it cannot drift.
 *   SEQUENCE  — incremented on every change. A calendar client ignores an
 *               update whose sequence is not higher than the one it already
 *               holds, so a reschedule that forgets to bump this is silently
 *               discarded at the other end.
 *
 * METHOD:REQUEST for a booking or a change, METHOD:CANCEL with STATUS
 * CANCELLED to withdraw one.
 */
class IcsBuilder
{
    /** The stable identity of an interview across every version of it. */
    public static function uid(int $interviewId): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'naijavirtualnotary.com';

        return 'nvn-interview-' . $interviewId . '@' . $host;
    }

    /**
     * Build the file.
     *
     * @param  array{uid: string, sequence: int, summary: string, description: string, starts_at: CarbonInterface, ends_at: CarbonInterface, organiser_name?: string, organiser_email?: string, attendee_name?: string, attendee_email?: string, location?: ?string, cancelled?: bool}  $event
     */
    public static function build(array $event): string
    {
        $cancelled = (bool) ($event['cancelled'] ?? false);
        $method    = $cancelled ? 'CANCEL' : 'REQUEST';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Naija Virtual Notary//Recruitment//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'BEGIN:VEVENT',
            'UID:' . $event['uid'],
            'SEQUENCE:' . max(0, (int) $event['sequence']),
            'DTSTAMP:' . static::stamp(CarbonImmutable::now()),
            'DTSTART:' . static::stamp($event['starts_at']),
            'DTEND:' . static::stamp($event['ends_at']),
            'SUMMARY:' . static::escape($event['summary']),
            'DESCRIPTION:' . static::escape($event['description']),
            'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:OPAQUE',
        ];

        if (! empty($event['location'])) {
            $lines[] = 'LOCATION:' . static::escape((string) $event['location']);
        }

        if (! empty($event['organiser_email'])) {
            $lines[] = 'ORGANIZER;CN=' . static::escape((string) ($event['organiser_name'] ?? 'Naija Virtual Notary'))
                . ':mailto:' . $event['organiser_email'];
        }

        if (! empty($event['attendee_email'])) {
            $lines[] = 'ATTENDEE;CN=' . static::escape((string) ($event['attendee_name'] ?? 'Candidate'))
                . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=TRUE:mailto:' . $event['attendee_email'];
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /** UTC, basic format. A floating local time would land in the wrong hour. */
    private static function stamp(CarbonInterface $at): string
    {
        return CarbonImmutable::parse($at)->utc()->format('Ymd\THis\Z');
    }

    /**
     * RFC 5545 text escaping.
     *
     * Order matters: the backslash has to be doubled before the comma and
     * semicolon get theirs, or their new backslashes are doubled too.
     */
    private static function escape(string $text): string
    {
        $bs = chr(92);

        $text = str_replace($bs, $bs . $bs, $text);
        $text = str_replace([',', ';'], [$bs . ',', $bs . ';'], $text);
        $text = str_replace(["\r\n", "\r", "\n"], $bs . 'n', $text);

        return $text;
    }

    /**
     * Lines longer than 75 octets must be folded, or strict parsers reject
     * the whole file — which shows up as "the invitation did not attach".
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 73) {
            return $line;
        }

        $chunks = str_split($line, 73);

        return implode("\r\n ", $chunks);
    }

    /** The filename the attachment is given. */
    public static function filename(string $reference): string
    {
        return 'interview-' . strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $reference)) . '.ics';
    }
}
