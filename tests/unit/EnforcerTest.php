<?php

namespace ExclusiveBBB\Tests;

use ExclusiveBBB\Schedule;
use ExclusiveBBB\Services\Enforcer;
use PHPUnit\Framework\TestCase;

class EnforcerTest extends TestCase
{
    private const MEETING_BASE = '0f8e2c4a-1111-4222-8333-444455556666';

    private function conference(bool $active = true): array
    {
        $open = mktime(9, 45, 0, 11, 2, 2026);
        return [
            'active'      => $active,
            'meeting_id'  => self::MEETING_BASE,
            'occurrences' => Schedule::occurrences([['begin' => $open, 'end' => $open + 9000]], 900, 900),
        ];
    }

    private function meeting(string $id): array
    {
        return ['meetingID' => $id, 'meetingName' => 'x', 'metadata' => []];
    }

    public function testForeignMeetingIsEnded(): void
    {
        $this->assertSame(Enforcer::REASON_FOREIGN, Enforcer::decide($this->meeting('foreign'), null, time()));
    }

    public function testCancelledConferenceIsEnded(): void
    {
        $now = mktime(10, 30, 0, 11, 2, 2026);
        $reason = Enforcer::decide($this->meeting(self::MEETING_BASE . '-20261102-0945'), $this->conference(false), $now);
        $this->assertSame(Enforcer::REASON_CANCELLED, $reason);
    }

    public function testMeetingInsideWindowIsKept(): void
    {
        $now = mktime(12, 14, 0, 11, 2, 2026);
        $this->assertNull(Enforcer::decide($this->meeting(self::MEETING_BASE . '-20261102-0945'), $this->conference(), $now));
    }

    public function testMeetingAfterBufferIsEnded(): void
    {
        $now = mktime(12, 15, 0, 11, 2, 2026);
        $reason = Enforcer::decide($this->meeting(self::MEETING_BASE . '-20261102-0945'), $this->conference(), $now);
        $this->assertSame(Enforcer::REASON_OUTSIDE, $reason);
    }

    public function testMeetingOfOtherOccurrenceIsEnded(): void
    {
        $now = mktime(10, 30, 0, 11, 2, 2026);
        $reason = Enforcer::decide($this->meeting(self::MEETING_BASE . '-20261026-0945'), $this->conference(), $now);
        $this->assertSame(Enforcer::REASON_OTHER_OCCURRENCE, $reason);
    }
}
