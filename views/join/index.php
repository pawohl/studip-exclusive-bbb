<?php
/**
 * @var Conference $conference
 * @var string $state
 * @var array|null $current
 * @var array|null $next
 * @var int $now
 * @var string|null $implicit_role
 * @var string|null $error
 * @var string $fullname
 */

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Passwords;
use ExclusiveBBB\Schedule;

$shown = $current ?: $next;
?>
<h1><?= htmlReady($conference->getDisplayName()) ?></h1>

<? if ($conference->image && $conference->isActive()): ?>
    <img class="exbbb-image" src="<?= $controller->link_for('join/image', $conference->token) ?>" alt="">
<? endif ?>

<? if (trim((string) $conference->description) !== ''): ?>
    <p class="exbbb-description"><?= nl2br(htmlReady($conference->description)) ?></p>
<? endif ?>

<? if ($shown && $state !== 'cancelled'): ?>
    <p class="exbbb-time">
        <?= htmlReady(strftime('%A, %d.%m.%Y', $shown['begin'])) ?><br>
        <?= sprintf($_('%s bis %s Uhr'), date('H:i', $shown['begin']), date('H:i', $shown['end'])) ?>
    </p>
<? endif ?>

<? if ($error): ?>
    <p class="exbbb-message exbbb-error" role="alert"><?= htmlReady($error) ?></p>
<? endif ?>

<? if ($state === 'cancelled'): ?>
    <p class="exbbb-message"><?= $_('Diese Konferenz wurde storniert.') ?></p>
<? elseif ($state === Schedule::STATE_ENDED || $state === Schedule::STATE_NONE): ?>
    <p class="exbbb-message"><?= $_('Diese Konferenz ist beendet.') ?></p>
<? elseif ($state === Schedule::STATE_UPCOMING): ?>
    <p class="exbbb-message">
        <?= sprintf(
            $_('Der Raum öffnet am %s um %s Uhr für Moderierende, Teilnehmende können ab %s Uhr eintreten.'),
            date('d.m.Y', $next['open_from']),
            date('H:i', $next['open_from']),
            date('H:i', $next['begin'])
        ) ?>
        <? if ($next['open_from'] - $now < 3600): ?>
            <?= $_('Diese Seite aktualisiert sich automatisch.') ?>
        <? endif ?>
    </p>
<? else: ?>
    <? if ($current['begin'] > $now): ?>
        <p class="exbbb-message">
            <?= sprintf($_('Moderierende können bereits eintreten, Teilnehmende ab %s Uhr.'), date('H:i', $current['begin'])) ?>
        </p>
    <? endif ?>
    <? if ($current['end'] <= $now): ?>
        <p class="exbbb-message">
            <?= sprintf($_('Die geplante Zeit ist vorbei. Die Konferenz wird um %s Uhr automatisch beendet.'), date('H:i', $current['close_at'])) ?>
        </p>
    <? endif ?>

    <form class="exbbb-form" method="post" action="<?= $controller->link_for('join/enter', $conference->token) ?>">
        <?= CSRFProtection::tokenTag() ?>

        <label for="exbbb-fullname"><?= $_('Ihr Name') ?></label>
        <input type="text" id="exbbb-fullname" name="fullname" maxlength="64" required
               autocomplete="name" value="<?= htmlReady($fullname) ?>">

        <? if ($implicit_role === Passwords::ROLE_MODERATOR): ?>
            <p class="exbbb-hint"><?= $_('Sie sind angemeldet und treten als Moderator/in ein.') ?></p>
        <? else: ?>
            <label for="exbbb-password">
                <?= $_('Passwort') ?>
                <? if ($implicit_role === Passwords::ROLE_ATTENDEE): ?>
                    <span class="exbbb-hint">(<?= $_('nur zum Eintreten als Moderator/in nötig') ?>)</span>
                <? endif ?>
            </label>
            <input type="password" id="exbbb-password" name="password" autocomplete="off"
                   <?= $implicit_role ? '' : 'required' ?>>
        <? endif ?>

        <button type="submit"><?= $_('Konferenz betreten') ?></button>
    </form>
<? endif ?>
