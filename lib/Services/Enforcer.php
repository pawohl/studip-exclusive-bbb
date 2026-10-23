<?php

namespace ExclusiveBBB\Services;

use ExclusiveBBB\BBB\ClientException;
use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Schedule;
use ExclusiveBBB\Settings;

/**
 * Keeps nodes exclusive: ends every meeting that has no active booking window.
 * Run by the cronjob every minute.
 */
class Enforcer
{
    public const REASON_FOREIGN = 'fremdes Meeting ohne Buchung';
    public const REASON_CANCELLED = 'Buchung storniert';
    public const REASON_OUTSIDE = 'Buchungsende plus Puffer erreicht';
    public const REASON_OTHER_OCCURRENCE = 'Meeting eines anderen Termins';

    /**
     * Pure decision for one running meeting.
     *
     * @param array      $meeting    as returned by Client::getMeetings()
     * @param array|null $conference null if unknown, else keys active (bool),
     *                               meeting_id (string), occurrences (array)
     * @return string|null reason to end the meeting, or null to keep it
     */
    public static function decide(array $meeting, ?array $conference, int $now): ?string
    {
        if ($conference === null) {
            return self::REASON_FOREIGN;
        }
        if (!$conference['active']) {
            return self::REASON_CANCELLED;
        }
        $current = Schedule::current($conference['occurrences'], $now);
        if (!$current) {
            return self::REASON_OUTSIDE;
        }
        if ($meeting['meetingID'] !== Schedule::meetingId($conference['meeting_id'], $current)) {
            return self::REASON_OTHER_OCCURRENCE;
        }
        return null;
    }

    /**
     * @return array per node id: ['ok' => bool, 'meetings' => int, 'ended' => int, 'error' => ?string]
     */
    public static function run(int $now): array
    {
        $result = [];
        $log_health = (int) date('i', $now) % Settings::healthIntervalMinutes() === 0;

        foreach (Node::findActive() as $node) {
            $status = ['ok' => true, 'meetings' => 0, 'ended' => 0, 'error' => null];
            try {
                $client = $node->getClient();
                $meetings = $client->getMeetings();
                $status['meetings'] = count($meetings);

                foreach ($meetings as $meeting) {
                    $conference_id = $meeting['metadata']['exbbb-conference'] ?? null;
                    $conference = $conference_id ? Conference::find($conference_id) : null;
                    $info = $conference ? [
                        'active'      => $conference->isActive() && $conference->resource_id === $node->resource_id,
                        'meeting_id'  => $conference->meeting_id,
                        'occurrences' => $conference->getOccurrences(),
                    ] : null;

                    $reason = self::decide($meeting, $info, $now);
                    if ($reason !== null) {
                        $client->end($meeting['meetingID']);
                        $status['ended']++;
                        Log::event(
                            Log::END,
                            $node->resource_id,
                            sprintf('%s (%s): %s', $meeting['meetingID'], $meeting['meetingName'], $reason),
                            null,
                            'nobody'
                        );
                    }
                }
            } catch (ClientException $e) {
                $status['ok'] = false;
                $status['error'] = $e->getMessage();
            }

            self::recordHealth($node, $status, $now, $log_health);
            $result[$node->id] = $status;
        }
        return $result;
    }

    /**
     * Stores the last check on the node. Logs failures and recoveries always,
     * successful checks only in debug mode.
     */
    private static function recordHealth(Node $node, array $status, int $now, bool $log_health): void
    {
        $text = $status['ok']
            ? sprintf('erreichbar, %u Meeting(s)', $status['meetings'])
            : 'nicht erreichbar: ' . $status['error'];
        $was_ok = $node->last_status === null || strpos((string) $node->last_status, 'erreichbar,') === 0;

        if (!$status['ok'] && ($was_ok || $log_health)) {
            Log::event(Log::HEALTH, $node->resource_id, $text, null, 'nobody');
        } elseif ($status['ok'] && !$was_ok) {
            Log::event(Log::HEALTH, $node->resource_id, 'wieder ' . $text, null, 'nobody');
        } elseif ($log_health) {
            Log::debug('Health ' . $node->getName() . ': ' . $text, $node->resource_id);
        }

        $node->last_check = $now;
        $node->last_status = mb_substr($text, 0, 255);
        $node->store();
    }
}
