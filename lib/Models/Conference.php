<?php

namespace ExclusiveBBB\Models;

use ExclusiveBBB\Schedule;

/**
 * The conference belonging to one booking of a node resource.
 * A repeating booking has one conference (one link, one pair of passwords)
 * and one BBB meeting per occurrence. Times are always read from the booking.
 *
 * @property string $id
 * @property string $booking_id
 * @property string $resource_id
 * @property string $name
 * @property string $description
 * @property string|null $image
 * @property string $attendee_pw_hash
 * @property string $moderator_pw_hash
 * @property string $token
 * @property string $meeting_id
 * @property string $status
 * @property int    $mkdate
 * @property int    $chdate
 * @property \ResourceBooking|null $booking
 */
class Conference extends \SimpleORMap
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    protected static function configure($config = [])
    {
        $config['db_table'] = 'exbbb_conferences';
        $config['belongs_to']['booking'] = [
            'class_name'  => \ResourceBooking::class,
            'foreign_key' => 'booking_id',
        ];
        parent::configure($config);
    }

    public static function findByToken(string $token): ?self
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        return self::findOneBySQL('token = ?', [$token]);
    }

    public static function findByBooking(string $booking_id): ?self
    {
        return self::findOneBySQL('booking_id = ?', [$booking_id]);
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newMeetingId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->booking !== null;
    }

    /**
     * @return array[] occurrences as returned by Schedule::occurrences()
     */
    public function getOccurrences(): array
    {
        $booking = $this->booking;
        if (!$booking) {
            return [];
        }
        $intervals = \DBManager::get()->fetchAll(
            'SELECT begin, end, takes_place FROM resource_booking_intervals WHERE booking_id = ?',
            [$booking->id]
        );
        return Schedule::occurrences(
            $intervals,
            (int) $booking->preparation_time,
            (int) $booking->subsequent_time
        );
    }

    public function getNode(): ?Node
    {
        return Node::findByResource($this->resource_id);
    }

    public function getDisplayName(): string
    {
        if (trim((string) $this->name) !== '') {
            return (string) $this->name;
        }
        $booking = $this->booking;
        if ($booking && $booking->assigned_course_date && $booking->assigned_course_date->course) {
            return $booking->assigned_course_date->course->getFullName();
        }
        if ($booking && trim((string) $booking->description) !== '') {
            return (string) $booking->description;
        }
        return 'Konferenz';
    }
}
