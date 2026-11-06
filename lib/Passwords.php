<?php

namespace ExclusiveBBB;

/**
 * Conference passwords. Only hashes are stored; the plain password is shown
 * once (mail / settings page) and never sent to BigBlueButton.
 */
class Passwords
{
    // no 0/O/o, 1/l/I to keep dictated passwords unambiguous
    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_ATTENDEE = 'attendee';

    public const MIN_LENGTH = 8;

    public static function generate(int $length = 10): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }
        return $password;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /**
     * @return string|null ROLE_MODERATOR, ROLE_ATTENDEE or null if the password matches neither
     */
    public static function roleFor(string $password, ?string $attendee_hash, ?string $moderator_hash): ?string
    {
        if ($password === '') {
            return null;
        }
        // check both hashes so timing does not reveal which one matched
        $is_moderator = $moderator_hash && password_verify($password, $moderator_hash);
        $is_attendee = $attendee_hash && password_verify($password, $attendee_hash);
        if ($is_moderator) {
            return self::ROLE_MODERATOR;
        }
        if ($is_attendee) {
            return self::ROLE_ATTENDEE;
        }
        return null;
    }

    /**
     * @return string|null error message, or null if the pair is acceptable
     */
    public static function validatePair(string $attendee, string $moderator): ?string
    {
        if (strlen($attendee) < self::MIN_LENGTH || strlen($moderator) < self::MIN_LENGTH) {
            return sprintf('Passwörter müssen mindestens %u Zeichen lang sein.', self::MIN_LENGTH);
        }
        if ($attendee === $moderator) {
            return 'Die Passwörter für Teilnehmende und Moderierende müssen sich unterscheiden.';
        }
        return null;
    }
}
