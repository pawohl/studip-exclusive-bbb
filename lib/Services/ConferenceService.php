<?php

namespace ExclusiveBBB\Services;

use ExclusiveBBB\BBB\ClientException;
use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Schedule;

/**
 * Lifecycle and permissions of conferences.
 */
class ConferenceService
{
    /**
     * Ensures an active conference exists for a normal booking on a node resource.
     *
     * @return array|null [Conference, attendee password, moderator password] when newly created
     */
    public static function ensureForBooking(\ResourceBooking $booking): ?array
    {
        if (!Node::isNodeResource($booking->resource_id)) {
            return null;
        }

        $conference = Conference::findByBooking($booking->id);

        if ((int) $booking->booking_type !== \ResourceBooking::TYPE_NORMAL) {
            // reservations, locks and planned bookings do not get a conference
            if ($conference && $conference->status === Conference::STATUS_ACTIVE) {
                self::cancel($conference, 'Buchungstyp geändert');
            }
            return null;
        }

        if ($conference) {
            $changed = $conference->resource_id !== $booking->resource_id
                || $conference->status !== Conference::STATUS_ACTIVE;
            $conference->resource_id = $booking->resource_id;
            $conference->status = Conference::STATUS_ACTIVE;
            $conference->store();
            Log::event(Log::UPDATE, $booking->resource_id, self::describe($conference));
            if ($changed) {
                Log::debug('Conference ' . $conference->id . ' re-activated or moved to resource ' . $booking->resource_id);
            }
            return null;
        }

        $attendee = Passwords::generate();
        $moderator = Passwords::generate();

        $conference = new Conference();
        $conference->id = $conference->getNewId();
        $conference->booking_id = $booking->id;
        $conference->resource_id = $booking->resource_id;
        $conference->name = '';
        $conference->description = '';
        $conference->attendee_pw_hash = Passwords::hash($attendee);
        $conference->moderator_pw_hash = Passwords::hash($moderator);
        $conference->token = Conference::newToken();
        $conference->meeting_id = Conference::newMeetingId();
        $conference->status = Conference::STATUS_ACTIVE;
        $conference->store();

        Log::event(Log::CREATE, $booking->resource_id, self::describe($conference));

        return [$conference, $attendee, $moderator];
    }

    /**
     * Marks the conference cancelled and ends its running meeting, if any.
     */
    public static function cancel(Conference $conference, string $reason): void
    {
        $conference->status = Conference::STATUS_CANCELLED;
        $conference->store();
        self::endRunningMeetings($conference, $reason);
        Log::event(Log::CANCEL, $conference->resource_id, self::describe($conference) . ' (' . $reason . ')');
    }

    /**
     * Ends all meetings of this conference that are running on its node.
     */
    public static function endRunningMeetings(Conference $conference, string $reason): int
    {
        $node = $conference->getNode();
        if (!$node) {
            return 0;
        }
        $ended = 0;
        try {
            $client = $node->getClient();
            foreach ($client->getMeetings() as $meeting) {
                if (($meeting['metadata']['exbbb-conference'] ?? null) === $conference->id) {
                    $client->end($meeting['meetingID']);
                    Log::event(Log::END, $node->resource_id, $meeting['meetingID'] . ': ' . $reason);
                    $ended++;
                }
            }
        } catch (ClientException $e) {
            Log::event(Log::HEALTH, $node->resource_id, 'Fehler beim Beenden: ' . $e->getMessage());
        }
        return $ended;
    }

    /**
     * Users who may edit the conference: root, the person who booked,
     * the requester, resource tutors/admins of the node and, for course
     * date bookings, the lecturers and tutors of that course.
     */
    public static function canEdit(Conference $conference, \User $user): bool
    {
        if ($user->perms === 'root') {
            return true;
        }
        $booking = $conference->booking;
        if (!$booking) {
            return false;
        }
        if ($booking->booking_user_id === $user->id || $booking->range_id === $user->id) {
            return true;
        }
        if ($booking->resource && $booking->resource->userHasPermission($user, 'tutor')) {
            return true;
        }
        $course_id = self::courseId($booking);
        if ($course_id && $GLOBALS['perm']->have_studip_perm('tutor', $course_id, $user->id)) {
            return true;
        }
        return in_array($user->id, self::requesterIds($booking), true);
    }

    /**
     * May the user see the node management view?
     */
    public static function canManage(\User $user): bool
    {
        if ($user->perms === 'root') {
            return true;
        }
        if (\ResourceManager::userHasGlobalPermission($user, 'admin')) {
            return true;
        }
        foreach (Node::findBySQL('1') as $node) {
            if ($node->resource && $node->resource->userHasPermission($user, 'tutor')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Logged-in members of the booked course enter as attendees without password.
     */
    public static function isCourseMember(Conference $conference, \User $user): bool
    {
        $course_id = $conference->booking ? self::courseId($conference->booking) : null;
        return $course_id && $GLOBALS['perm']->have_studip_perm('user', $course_id, $user->id);
    }

    /**
     * Conferences the user may edit (for "Meine exklusiven Konferenzen").
     *
     * @return Conference[]
     */
    public static function findEditableFor(\User $user): array
    {
        $conferences = Conference::findBySQL(
            "status = 'active' AND booking_id IN (
                SELECT id FROM resource_bookings WHERE end + subsequent_time > UNIX_TIMESTAMP() OR repeat_end > UNIX_TIMESTAMP()
             ) ORDER BY mkdate"
        );
        return array_values(array_filter($conferences, fn($conference) => self::canEdit($conference, $user)));
    }

    public static function courseId(\ResourceBooking $booking): ?string
    {
        $date = $booking->assigned_course_date;
        return $date ? $date->range_id : null;
    }

    /**
     * Users who filed the request that led to this booking (course dates only;
     * for simple bookings the requester is the booking's range_id).
     *
     * @return string[]
     */
    public static function requesterIds(\ResourceBooking $booking): array
    {
        $date = $booking->assigned_course_date;
        if (!$date) {
            return [];
        }
        return \DBManager::get()->fetchFirst(
            "SELECT DISTINCT user_id FROM resource_requests
             WHERE resource_id = :resource_id
               AND (termin_id = :date_id
                    OR (metadate_id != '' AND metadate_id = :metadate_id)
                    OR id IN (SELECT request_id FROM resource_request_appointments WHERE appointment_id = :date_id))",
            [
                'resource_id' => $booking->resource_id,
                'date_id'     => $date->id,
                'metadate_id' => (string) $date->metadate_id,
            ]
        );
    }

    /**
     * Users to notify about a new or cancelled conference.
     *
     * @return \User[]
     */
    public static function recipients(\ResourceBooking $booking): array
    {
        $ids = [];
        if ($booking->assigned_user) {
            $ids[] = $booking->range_id;
        }
        $ids = array_merge($ids, self::requesterIds($booking));
        $course_id = self::courseId($booking);
        if ($course_id) {
            $ids = array_merge($ids, \DBManager::get()->fetchFirst(
                "SELECT user_id FROM seminar_user WHERE Seminar_id = ? AND status = 'dozent'",
                [$course_id]
            ));
        }
        if (!$ids && $booking->booking_user_id) {
            $ids[] = $booking->booking_user_id;
        }
        return \User::findMany(array_unique($ids));
    }

    public static function describe(Conference $conference): string
    {
        $parts = [$conference->getDisplayName()];
        $occurrence = Schedule::next($conference->getOccurrences(), 0);
        if ($occurrence) {
            $parts[] = date('d.m.Y H:i', $occurrence['begin']) . '-' . date('H:i', $occurrence['end']);
        }
        $parts[] = 'Buchung ' . $conference->booking_id;
        return implode(', ', $parts);
    }
}
