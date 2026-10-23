<?php

namespace ExclusiveBBB;

/**
 * Writes plugin events to the Stud.IP event log (Admin > Log).
 * Debug entries go to EXBBB_DEBUG and are only written in debug mode.
 * Never pass secrets or checksums in here.
 */
class Log
{
    public const CREATE = 'EXBBB_CREATE';
    public const UPDATE = 'EXBBB_UPDATE';
    public const CANCEL = 'EXBBB_CANCEL';
    public const JOIN = 'EXBBB_JOIN';
    public const JOIN_FAIL = 'EXBBB_JOIN_FAIL';
    public const END = 'EXBBB_END';
    public const HEALTH = 'EXBBB_HEALTH';
    public const DEBUG = 'EXBBB_DEBUG';

    public static function event(string $action, ?string $resource_id, string $info, ?string $debug = null, ?string $user_id = null): void
    {
        \StudipLog::log($action, $resource_id, null, $info, Settings::debug() ? $debug : null, $user_id);
    }

    public static function debug(string $info, ?string $resource_id = null): void
    {
        if (Settings::debug()) {
            \StudipLog::log(self::DEBUG, $resource_id, null, $info);
        }
    }
}
