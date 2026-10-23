<?php

namespace ExclusiveBBB\Tests;

use ExclusiveBBB\Schedule;
use PHPUnit\Framework\TestCase;

class ScheduleTest extends TestCase
{
    private const PREP = 900;   // 15 min
    private const BUFFER = 900; // 15 min

    /**
     * Booking 10:00-12:00 with 15 min preparation and buffer:
     * interval as stored by Stud.IP is 09:45-12:15.
     */
    private function occurrences(): array
    {
        $begin = mktime(10, 0, 0, 11, 2, 2026);
        $end = mktime(12, 0, 0, 11, 2, 2026);
        return Schedule::occurrences([
            ['begin' => $begin - self::PREP, 'end' => $end + self::BUFFER, 'takes_place' => 1],
        ], self::PREP, self::BUFFER);
    }

    private function at(int $hour, int $minute): int
    {
        return mktime($hour, $minute, 0, 11, 2, 2026);
    }

    public function testOccurrenceMapsIntervalToConferenceTimes(): void
    {
        [$occurrence] = $this->occurrences();
        $this->assertSame($this->at(9, 45), $occurrence['open_from']);
        $this->assertSame($this->at(10, 0), $occurrence['begin']);
        $this->assertSame($this->at(12, 0), $occurrence['end']);
        $this->assertSame($this->at(12, 15), $occurrence['close_at']);
    }

    public function testStates(): void
    {
        $occurrences = $this->occurrences();
        $this->assertSame(Schedule::STATE_UPCOMING, Schedule::state($occurrences, $this->at(9, 44)));
        $this->assertSame(Schedule::STATE_OPEN, Schedule::state($occurrences, $this->at(9, 45)));
        $this->assertSame(Schedule::STATE_OPEN, Schedule::state($occurrences, $this->at(12, 14)));
        $this->assertSame(Schedule::STATE_ENDED, Schedule::state($occurrences, $this->at(12, 15)));
        $this->assertSame(Schedule::STATE_NONE, Schedule::state([], $this->at(10, 0)));
    }

    public function testModeratorsEnterEarlyAttendeesAtBegin(): void
    {
        [$occurrence] = $this->occurrences();
        $this->assertTrue(Schedule::mayEnter($occurrence, $this->at(9, 50), true));
        $this->assertFalse(Schedule::mayEnter($occurrence, $this->at(9, 50), false));
        $this->assertTrue(Schedule::mayEnter($occurrence, $this->at(10, 0), false));
        // overrun buffer: still allowed to (re)join until the meeting is ended
        $this->assertTrue(Schedule::mayEnter($occurrence, $this->at(12, 10), false));
        $this->assertFalse(Schedule::mayEnter($occurrence, $this->at(12, 15), true));
    }

    public function testRemainingMinutesRoundsUp(): void
    {
        [$occurrence] = $this->occurrences();
        $this->assertSame(150, Schedule::remainingMinutes($occurrence, $this->at(9, 45)));
        $this->assertSame(1, Schedule::remainingMinutes($occurrence, $this->at(12, 14) + 59));
    }

    public function testExceptionsAreSkippedAndSeriesSorted(): void
    {
        $week = 7 * 86400;
        $first = $this->at(9, 45);
        $occurrences = Schedule::occurrences([
            ['begin' => $first + 2 * $week, 'end' => $first + 2 * $week + 9000, 'takes_place' => 1],
            ['begin' => $first + $week, 'end' => $first + $week + 9000, 'takes_place' => 0],
            ['begin' => $first, 'end' => $first + 9000, 'takes_place' => 1],
        ], self::PREP, self::BUFFER);

        $this->assertCount(2, $occurrences);
        $this->assertSame($first, $occurrences[0]['open_from']);
        $this->assertSame($first + 2 * $week, Schedule::next($occurrences, $first + 1)['open_from']);
        // the cancelled week in between has no window
        $this->assertNull(Schedule::current($occurrences, $first + $week + 600));
    }

    public function testMeetingIdDiffersPerOccurrence(): void
    {
        $base = '0f8e2c4a-1111-4222-8333-444455556666';
        $a = ['open_from' => $this->at(9, 45)];
        $b = ['open_from' => $this->at(9, 45) + 7 * 86400];
        $this->assertSame($base . '-20261102-0945', Schedule::meetingId($base, $a));
        $this->assertNotSame(Schedule::meetingId($base, $a), Schedule::meetingId($base, $b));
    }
}
