<?php

namespace ExclusiveBBB\Services;

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Settings;

/**
 * Reacts to Stud.IP resource booking events for node resources.
 */
class BookingObserver
{
    public static function register(): void
    {
        \NotificationCenter::on('ResourceBookingWillValidate', [self::class, 'onWillValidate']);
        \NotificationCenter::on('ResourceBookingDidStore', [self::class, 'onDidStore']);
        \NotificationCenter::on('ResourceBookingDidDelete', [self::class, 'onDidDelete']);
    }

    /**
     * Enforces preparation time and overrun buffer on node bookings, then
     * re-runs the core validation so the overlap check uses the new times.
     * Posted at the end of ResourceBooking::validate(); exceptions abort the store.
     */
    public static function onWillValidate($event, \ResourceBooking $booking): void
    {
        if ((int) $booking->booking_type !== \ResourceBooking::TYPE_NORMAL
            || !Node::isNodeResource($booking->resource_id)) {
            return;
        }

        $changed = false;
        if ((int) $booking->preparation_time < Settings::prepSeconds()) {
            $booking->preparation_time = Settings::prepSeconds();
            $changed = true;
        }
        if ((int) $booking->subsequent_time < Settings::bufferSeconds()) {
            $booking->subsequent_time = Settings::bufferSeconds();
            $changed = true;
        }
        if ($changed) {
            // the second run sees enough buffer and returns without recursing again
            $booking->validate();
        }
    }

    public static function onDidStore($event, \ResourceBooking $booking): void
    {
        $conference = Conference::findByBooking($booking->id);

        // booking moved away from a node resource
        if ($conference && $conference->status === Conference::STATUS_ACTIVE
            && !Node::isNodeResource($booking->resource_id)) {
            ConferenceService::cancel($conference, 'Buchung auf andere Ressource verschoben');
            return;
        }

        $created = ConferenceService::ensureForBooking($booking);
        if ($created) {
            [$conference, $attendee_pw, $moderator_pw] = $created;
            Mailer::sendCreated($conference, ConferenceService::recipients($booking), $attendee_pw, $moderator_pw);
        }
    }

    public static function onDidDelete($event, \ResourceBooking $booking): void
    {
        $conference = Conference::findByBooking($booking->id);
        if (!$conference || $conference->status !== Conference::STATUS_ACTIVE) {
            return;
        }
        $recipients = ConferenceService::recipients($booking);
        // the booking row is gone; keep the conference row for the log but detach it
        ConferenceService::cancel($conference, 'Buchung gelöscht');
        Mailer::sendCancelled($conference, $recipients);
    }
}
