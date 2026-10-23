<?php
/**
 * @var Conference $conference
 * @var string $join_url
 * @var array[] $occurrences
 * @var array|null $new_passwords
 */

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Passwords;

$node = $conference->getNode();
?>
<? if ($new_passwords): ?>
    <?= MessageBox::warning(
        $_('Neue Passwörter – bitte jetzt notieren, sie werden nicht noch einmal angezeigt:'),
        [
            sprintf($_('Teilnehmende: %s'), $new_passwords['attendee']),
            sprintf($_('Moderierende: %s'), $new_passwords['moderator']),
        ]
    ) ?>
<? endif ?>

<section class="contentbox">
    <header><h1><?= htmlReady($conference->getDisplayName()) ?></h1></header>
    <section>
        <dl>
            <dt><?= $_('Teilnahme-Link') ?></dt>
            <dd>
                <input type="text" readonly value="<?= htmlReady($join_url) ?>" size="80" onfocus="this.select()">
            </dd>
            <dt><?= $_('Server') ?></dt>
            <dd><?= htmlReady($node ? $node->getName() : '') ?></dd>
            <dt><?= $_('Termine') ?></dt>
            <dd>
                <ul class="list-unstyled">
                <? foreach (array_slice($occurrences, 0, 20) as $occurrence): ?>
                    <li>
                        <?= date('d.m.Y H:i', $occurrence['begin']) ?> - <?= date('H:i', $occurrence['end']) ?>
                        <span class="exbbb-hint">
                            (<?= sprintf($_('Raum offen %s - %s'), date('H:i', $occurrence['open_from']), date('H:i', $occurrence['close_at'])) ?>)
                        </span>
                    </li>
                <? endforeach ?>
                <? if (count($occurrences) > 20): ?>
                    <li><?= sprintf($_('... und %u weitere'), count($occurrences) - 20) ?></li>
                <? endif ?>
                </ul>
            </dd>
        </dl>
    </section>
</section>

<form class="default" method="post" enctype="multipart/form-data"
      action="<?= $controller->link_for('conferences/store', $conference->id) ?>">
    <?= CSRFProtection::tokenTag() ?>
    <fieldset>
        <legend><?= $_('Angaben auf der Teilnahmeseite') ?></legend>
        <label>
            <?= $_('Name der Konferenz') ?>
            <input type="text" name="name" maxlength="255" value="<?= htmlReady($conference->name) ?>"
                   placeholder="<?= htmlReady($conference->getDisplayName()) ?>">
        </label>
        <label>
            <?= $_('Beschreibung') ?>
            <textarea name="description" rows="5"><?= htmlReady($conference->description) ?></textarea>
        </label>
        <label>
            <?= $_('Bild (PNG, JPEG oder WebP, max. 2 MB)') ?>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
        </label>
        <? if ($conference->image): ?>
            <img src="<?= $controller->link_for('join/image', $conference->token) ?>" alt="" style="max-width: 240px">
            <label>
                <input type="checkbox" name="delete_image" value="1">
                <?= $_('Bild entfernen') ?>
            </label>
        <? endif ?>
    </fieldset>
    <footer>
        <?= Studip\Button::createAccept($_('Speichern')) ?>
    </footer>
</form>

<form class="default" method="post" action="<?= $controller->link_for('conferences/passwords', $conference->id) ?>">
    <?= CSRFProtection::tokenTag() ?>
    <fieldset>
        <legend><?= $_('Passwörter') ?></legend>
        <p>
            <?= $_('Die Passwörter werden nur verschlüsselt gespeichert. Sie können neue erzeugen lassen oder eigene festlegen.') ?>
            <?= sprintf($_('Eigene Passwörter müssen mindestens %u Zeichen lang sein und sich unterscheiden.'), Passwords::MIN_LENGTH) ?>
        </p>
        <label>
            <?= $_('Passwort für Teilnehmende') ?>
            <input type="text" name="attendee_password" autocomplete="off" minlength="<?= Passwords::MIN_LENGTH ?>">
        </label>
        <label>
            <?= $_('Passwort für Moderierende') ?>
            <input type="text" name="moderator_password" autocomplete="off" minlength="<?= Passwords::MIN_LENGTH ?>">
        </label>
        <label>
            <input type="checkbox" name="send_mail" value="1" checked>
            <?= $_('Neue Passwörter per E-Mail an die Buchenden senden') ?>
        </label>
    </fieldset>
    <footer>
        <?= Studip\Button::create($_('Eigene Passwörter setzen'), 'set') ?>
        <?= Studip\Button::create($_('Neue Passwörter erzeugen'), 'generate') ?>
    </footer>
</form>
