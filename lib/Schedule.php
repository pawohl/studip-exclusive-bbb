<?php

namespace ExclusiveBBB;

/**
 * Time window calculations for the occurrences of one booking.
 *
 * Stud.IP stores booking intervals including preparation and subsequent time,
 * so an interval [begin, end] maps to:
 *   moderators may enter:   interval.begin               (= booking begin - preparation)
 *   conference starts:      interval.begin + preparation (attendees may enter)
 *   conference ends:        interval.end - subsequent
 *   meeting is ended:       interval.end                 (= end + overrun buffer)
 */
class Schedule
{
    public const STATE_UPCOMING = 'upcoming';
    public const STATE_OPEN = 'open';
    public const STATE_ENDED = 'ended';
    public const STATE_NONE = 'none';

    /**
     * @param array[] $intervals rows with begin, end and optional takes_place
     * @return array[] occurrences sorted by begin with keys
     *                 open_from, begin, end, close_at
     */
    public static function occurrences(array $intervals, int $preparation, int $subsequent): array
    {
        $occurrences = [];
        foreach ($intervals as $interval) {
            if (isset($interval['takes_place']) && !$interval['takes_place']) {
                continue;
            }
            $occurrences[] = [
                'open_from' => (int) $interval['begin'],
                'begin'     => (int) $interval['begin'] + $preparation,
                'end'       => (int) $interval['end'] - $subsequent,
                'close_at'  => (int) $interval['end'],
            ];
        }
        usort($occurrences, fn($a, $b) => $a['open_from'] <=> $b['open_from']);
        return $occurrences;
    }

    /**
     * The occurrence whose window [open_from, close_at) contains $now.
     */
    public static function current(array $occurrences, int $now): ?array
    {
        foreach ($occurrences as $occurrence) {
            if ($occurrence['open_from'] <= $now && $now < $occurrence['close_at']) {
                return $occurrence;
            }
        }
        return null;
    }

    public static function next(array $occurrences, int $now): ?array
    {
        foreach ($occurrences as $occurrence) {
            if ($occurrence['open_from'] > $now) {
                return $occurrence;
            }
        }
        return null;
    }

    public static function state(array $occurrences, int $now): string
    {
        if (!$occurrences) {
            return self::STATE_NONE;
        }
        if (self::current($occurrences, $now)) {
            return self::STATE_OPEN;
        }
        if (self::next($occurrences, $now)) {
            return self::STATE_UPCOMING;
        }
        return self::STATE_ENDED;
    }

    /**
     * Whether a participant with the given role may enter now.
     * Moderators may enter from open_from, attendees from begin.
     */
    public static function mayEnter(array $occurrence, int $now, bool $moderator): bool
    {
        $from = $moderator ? $occurrence['open_from'] : $occurrence['begin'];
        return $from <= $now && $now < $occurrence['close_at'];
    }

    /**
     * Meeting duration in minutes from $now until the meeting must end, rounded up.
     * Used as safety net only; the cronjob ends the meeting precisely.
     */
    public static function remainingMinutes(array $occurrence, int $now): int
    {
        return max(1, (int) ceil(($occurrence['close_at'] - $now) / 60));
    }

    /**
     * BBB meetingID for one occurrence: stable for the conference and the day/time
     * the occurrence opens, so a weekly series gets one meeting per date.
     */
    public static function meetingId(string $conference_meeting_id, array $occurrence): string
    {
        return $conference_meeting_id . '-' . date('Ymd-Hi', $occurrence['open_from']);
    }
}
