<?php

namespace ExclusiveBBB\Services;

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Schedule;

/**
 * Mails about conferences. Links are absolute so they work outside Stud.IP.
 */
class Mailer
{
    public static function joinUrl(Conference $conference): string
    {
        return self::pluginUrl('join/index/' . $conference->token);
    }

    public static function settingsUrl(Conference $conference): string
    {
        return self::pluginUrl('conferences/edit/' . $conference->id);
    }

    public static function pluginUrl(string $path): string
    {
        return rtrim($GLOBALS['ABSOLUTE_URI_STUDIP'], '/') . '/plugins.php/exclusivebbb/' . $path;
    }

    /**
     * Sends link and passwords. Passwords are only known right after
     * generating them, so this is the only place they are sent.
     *
     * @param \User[] $recipients
     */
    public static function sendCreated(Conference $conference, array $recipients, string $attendee_pw, string $moderator_pw): void
    {
        $subject = sprintf('Exklusiver Videokonferenz-Server gebucht: %s', $conference->getDisplayName());
        $text = implode("\n", [
            'Guten Tag,',
            '',
            'für die folgende Buchung wurde eine Videokonferenz auf einem exklusiven BigBlueButton-Server eingerichtet:',
            '',
            self::summary($conference),
            '',
            'Teilnahme-Link (für alle Teilnehmenden, auch ohne Stud.IP-Zugang):',
            self::joinUrl($conference),
            '',
            'Passwort für Teilnehmende:  ' . $attendee_pw,
            'Passwort für Moderierende:  ' . $moderator_pw,
            '',
            'Moderierende können den Raum vor Beginn betreten. Die Konferenz wird nach dem Ende der Buchung',
            'plus Pufferzeit automatisch beendet.',
            '',
            'Name, Beschreibung und Passwörter können Sie hier ändern:',
            self::settingsUrl($conference),
        ]);
        self::send($recipients, $subject, $text);
    }

    /**
     * @param \User[] $recipients
     */
    public static function sendPasswords(Conference $conference, array $recipients, string $attendee_pw, string $moderator_pw): void
    {
        $subject = sprintf('Neue Passwörter für Videokonferenz: %s', $conference->getDisplayName());
        $text = implode("\n", [
            'Guten Tag,',
            '',
            'die Passwörter der folgenden Videokonferenz wurden neu gesetzt:',
            '',
            self::summary($conference),
            '',
            'Teilnahme-Link: ' . self::joinUrl($conference),
            'Passwort für Teilnehmende:  ' . $attendee_pw,
            'Passwort für Moderierende:  ' . $moderator_pw,
        ]);
        self::send($recipients, $subject, $text);
    }

    /**
     * @param \User[] $recipients
     */
    public static function sendCancelled(Conference $conference, array $recipients): void
    {
        $subject = sprintf('Videokonferenz storniert: %s', $conference->getDisplayName());
        $text = implode("\n", [
            'Guten Tag,',
            '',
            'die Buchung des exklusiven Videokonferenz-Servers wurde gelöscht. Die folgende Konferenz findet',
            'dort nicht mehr statt und der Teilnahme-Link ist nicht mehr gültig:',
            '',
            $conference->getDisplayName(),
        ]);
        self::send($recipients, $subject, $text);
    }

    private static function summary(Conference $conference): string
    {
        $lines = ['Konferenz: ' . $conference->getDisplayName()];
        $node = $conference->getNode();
        if ($node) {
            $lines[] = 'Server:    ' . $node->getName();
        }
        $occurrences = $conference->getOccurrences();
        foreach (array_slice($occurrences, 0, 10) as $occurrence) {
            $lines[] = 'Termin:    ' . date('d.m.Y H:i', $occurrence['begin']) . ' - ' . date('H:i', $occurrence['end']);
        }
        if (count($occurrences) > 10) {
            $lines[] = sprintf('           ... und %u weitere Termine', count($occurrences) - 10);
        }
        return implode("\n", $lines);
    }

    /**
     * @param \User[] $recipients
     */
    private static function send(array $recipients, string $subject, string $text): void
    {
        foreach ($recipients as $user) {
            if ($user && $user->email) {
                \StudipMail::sendMessage($user->email, $subject, $text);
            }
        }
    }
}
