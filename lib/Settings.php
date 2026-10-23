<?php

namespace ExclusiveBBB;

/**
 * Typed access to the plugin's global Stud.IP config entries.
 */
class Settings
{
    public const BUFFER_MINUTES = 'EXBBB_BUFFER_MINUTES';
    public const PREP_MINUTES = 'EXBBB_PREP_MINUTES';
    public const DEBUG = 'EXBBB_DEBUG';
    public const HEALTH_INTERVAL = 'EXBBB_HEALTH_INTERVAL';
    public const MAX_JOIN_ATTEMPTS = 'EXBBB_MAX_JOIN_ATTEMPTS';
    public const LOGO = 'EXBBB_LOGO';

    public static function bufferSeconds(): int
    {
        return max(0, (int) \Config::get()->getValue(self::BUFFER_MINUTES)) * 60;
    }

    public static function prepSeconds(): int
    {
        return max(0, (int) \Config::get()->getValue(self::PREP_MINUTES)) * 60;
    }

    public static function debug(): bool
    {
        return (bool) \Config::get()->getValue(self::DEBUG);
    }

    public static function healthIntervalMinutes(): int
    {
        return max(1, (int) \Config::get()->getValue(self::HEALTH_INTERVAL));
    }

    public static function maxJoinAttempts(): int
    {
        return max(1, (int) \Config::get()->getValue(self::MAX_JOIN_ATTEMPTS));
    }

    public static function logo(): ?string
    {
        return \Config::get()->getValue(self::LOGO) ?: null;
    }

    public static function store(string $field, $value): void
    {
        \Config::get()->store($field, $value);
    }

    /**
     * Directory for uploaded logo and conference images.
     */
    public static function uploadPath(): string
    {
        $path = $GLOBALS['UPLOAD_PATH'] . '/exclusivebbb';
        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }
        return $path;
    }
}
