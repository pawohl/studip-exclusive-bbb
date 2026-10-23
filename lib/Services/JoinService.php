<?php

namespace ExclusiveBBB\Services;

use ExclusiveBBB\BBB\ClientException;
use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Schedule;
use ExclusiveBBB\Settings;

/**
 * Decides who may enter a conference and builds the BBB join URL.
 */
class JoinService
{
    public const DENY_CANCELLED = 'cancelled';
    public const DENY_CLOSED = 'closed';
    public const DENY_NOT_YET = 'not_yet';
    public const DENY_PASSWORD_REQUIRED = 'password_required';
    public const DENY_WRONG_PASSWORD = 'wrong_password';
    public const DENY_RATE_LIMITED = 'rate_limited';
    public const DENY_NAME_REQUIRED = 'name_required';
    public const DENY_UNAVAILABLE = 'unavailable';

    public const ATTEMPT_WINDOW = 900;

    /**
     * Role a logged-in user gets without password, or null if they need one.
     */
    public static function implicitRole(Conference $conference, ?\User $user): ?string
    {
        if (!$user || $user->id === 'nobody') {
            return null;
        }
        if (ConferenceService::canEdit($conference, $user)) {
            return Passwords::ROLE_MODERATOR;
        }
        if (ConferenceService::isCourseMember($conference, $user)) {
            return Passwords::ROLE_ATTENDEE;
        }
        return null;
    }

    /**
     * @return string the BBB join URL to redirect to
     * @throws JoinDeniedException
     */
    public static function join(Conference $conference, string $full_name, ?string $password, ?\User $user, string $ip, int $now): string
    {
        if (!$conference->isActive()) {
            throw new JoinDeniedException(self::DENY_CANCELLED);
        }

        $full_name = self::cleanName($full_name);
        if ($full_name === '') {
            throw new JoinDeniedException(self::DENY_NAME_REQUIRED);
        }

        $role = null;
        if ($password !== null && $password !== '') {
            if (self::isRateLimited($conference, $ip, $now)) {
                Log::event(Log::JOIN_FAIL, $conference->resource_id, 'Zu viele Versuche: ' . $conference->id);
                throw new JoinDeniedException(self::DENY_RATE_LIMITED);
            }
            $role = Passwords::roleFor($password, $conference->attendee_pw_hash, $conference->moderator_pw_hash);
            if ($role === null) {
                self::recordFailedAttempt($conference, $ip, $now);
                Log::event(Log::JOIN_FAIL, $conference->resource_id, 'Falsches Passwort: ' . $conference->id);
                throw new JoinDeniedException(self::DENY_WRONG_PASSWORD);
            }
        }
        // a logged-in editor is always moderator, even if they typed the attendee password
        $implicit = self::implicitRole($conference, $user);
        if ($implicit === Passwords::ROLE_MODERATOR || $role === null) {
            $role = $implicit ?? $role;
        }
        if ($role === null) {
            throw new JoinDeniedException(self::DENY_PASSWORD_REQUIRED);
        }
        $moderator = $role === Passwords::ROLE_MODERATOR;

        $occurrences = $conference->getOccurrences();
        $current = Schedule::current($occurrences, $now);
        if (!$current) {
            throw new JoinDeniedException(
                Schedule::next($occurrences, $now) ? self::DENY_NOT_YET : self::DENY_CLOSED
            );
        }
        if (!Schedule::mayEnter($current, $now, $moderator)) {
            throw new JoinDeniedException(self::DENY_NOT_YET);
        }

        $node = $conference->getNode();
        if (!$node || !$node->active) {
            throw new JoinDeniedException(self::DENY_UNAVAILABLE);
        }

        $meeting_id = Schedule::meetingId($conference->meeting_id, $current);
        $client = $node->getClient();
        $create = [
            'name'      => mb_substr($conference->getDisplayName(), 0, 255),
            'meetingID' => $meeting_id,
            'record'    => 'false',
            'autoStartRecording' => 'false',
            'allowStartStopRecording' => 'false',
            'duration'  => Schedule::remainingMinutes($current, $now),
            'meetingExpireIfNoUserJoinedInMinutes' => 15,
            'logoutURL' => Mailer::joinUrl($conference),
            'meta_exbbb-conference' => $conference->id,
            'meta_exbbb-booking'    => $conference->booking_id,
        ];
        if ((int) $node->max_participants > 0) {
            $create['maxParticipants'] = (int) $node->max_participants;
        }

        try {
            $client->create($create);
        } catch (ClientException $e) {
            Log::event(Log::HEALTH, $node->resource_id, 'create fehlgeschlagen: ' . $e->getMessage());
            throw new JoinDeniedException(self::DENY_UNAVAILABLE);
        }
        Log::debug('create ' . $meeting_id . ' duration=' . $create['duration'], $node->resource_id);

        $extra = [];
        if ($user && $user->id !== 'nobody') {
            $extra['userID'] = $user->id;
        }
        Log::event(
            Log::JOIN,
            $node->resource_id,
            sprintf('%s als %s (%s)', $conference->id, $role, $user && $user->id !== 'nobody' ? 'Stud.IP' : 'Gast'),
            null,
            $user ? $user->id : 'nobody'
        );

        return $client->joinUrl($meeting_id, $full_name, $moderator, $extra);
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
        return mb_substr($name, 0, 64);
    }

    public static function isRateLimited(Conference $conference, string $ip, int $now): bool
    {
        $count = \DBManager::get()->fetchColumn(
            'SELECT COUNT(*) FROM exbbb_join_attempts WHERE token = ? AND ip_hash = ? AND mkdate > ?',
            [$conference->token, self::ipHash($ip), $now - self::ATTEMPT_WINDOW]
        );
        return (int) $count >= Settings::maxJoinAttempts();
    }

    public static function recordFailedAttempt(Conference $conference, string $ip, int $now): void
    {
        $db = \DBManager::get();
        $db->execute(
            'INSERT INTO exbbb_join_attempts (token, ip_hash, mkdate) VALUES (?, ?, ?)',
            [$conference->token, self::ipHash($ip), $now]
        );
        $db->execute('DELETE FROM exbbb_join_attempts WHERE mkdate < ?', [$now - self::ATTEMPT_WINDOW]);
    }

    /**
     * IPs are only stored hashed, salted with the conference-independent install id.
     */
    private static function ipHash(string $ip): string
    {
        return hash('sha256', $ip . '|' . ($GLOBALS['STUDIP_INSTALLATION_ID'] ?? 'studip'));
    }
}
