<?php
/**
 * @var Node[] $nodes
 * @var array $settings
 */

use ExclusiveBBB\BBB\Client;
use ExclusiveBBB\Models\Node;
?>
<table class="default">
    <caption>
        <?= $_('Server') ?>
        <span class="actions">
            <a href="<?= $controller->link_for('admin/node') ?>">
                <?= Icon::create('add')->asImg(['title' => $_('Server hinzufügen')]) ?>
            </a>
        </span>
    </caption>
    <thead>
        <tr>
            <th><?= $_('Ressource') ?></th>
            <th><?= $_('URL') ?></th>
            <th><?= $_('Max. Teilnehmende') ?></th>
            <th><?= $_('Aktiv') ?></th>
            <th><?= $_('Letzte Prüfung') ?></th>
            <th class="actions"><?= $_('Aktionen') ?></th>
        </tr>
    </thead>
    <tbody>
    <? if (!$nodes): ?>
        <tr>
            <td colspan="6"><?= $_('Es sind noch keine Server eingerichtet.') ?></td>
        </tr>
    <? endif ?>
    <? foreach ($nodes as $node): ?>
        <tr>
            <td><?= htmlReady($node->getName()) ?></td>
            <td><?= htmlReady(Client::normalizeUrl($node->url)) ?></td>
            <td><?= $node->max_participants ?: $_('unbegrenzt') ?></td>
            <td><?= $node->active ? $_('ja') : $_('nein') ?></td>
            <td>
                <? if ($node->last_check): ?>
                    <?= date('d.m.Y H:i', $node->last_check) ?>: <?= htmlReady($node->last_status) ?>
                <? else: ?>
                    –
                <? endif ?>
            </td>
            <td class="actions">
                <form method="post" style="display: inline">
                    <?= CSRFProtection::tokenTag() ?>
                    <a href="<?= $controller->link_for('admin/node', $node->id) ?>">
                        <?= Icon::create('edit')->asImg(['title' => $_('Bearbeiten')]) ?>
                    </a>
                    <?= Icon::create('refresh')->asInput([
                        'title'      => $_('Verbindung testen'),
                        'formaction' => $controller->url_for('admin/test_node', $node->id),
                    ]) ?>
                    <?= Icon::create('trash')->asInput([
                        'title'        => $_('Entfernen'),
                        'formaction'   => $controller->url_for('admin/delete_node', $node->id),
                        'data-confirm' => $_('Server wirklich entfernen?'),
                    ]) ?>
                </form>
            </td>
        </tr>
    <? endforeach ?>
    </tbody>
</table>

<form class="default" method="post" enctype="multipart/form-data"
      action="<?= $controller->link_for('admin/store_settings') ?>">
    <?= CSRFProtection::tokenTag() ?>
    <fieldset>
        <legend><?= $_('Zeiten') ?></legend>
        <label>
            <?= $_('Überziehungspuffer nach Buchungsende (Minuten)') ?>
            <?= tooltipIcon($_('Die Konferenz wird erst nach Buchungsende plus Puffer beendet. Der Puffer wird als Nachbereitungszeit an jeder Buchung gespeichert und ist damit auch der Mindestabstand zur nächsten Buchung.')) ?>
            <input type="number" name="buffer" min="0" value="<?= $settings['buffer'] ?>">
        </label>
        <label>
            <?= $_('Vorlauf für Moderierende (Minuten)') ?>
            <?= tooltipIcon($_('Moderierende können den Raum so viele Minuten vor Beginn betreten. Wird als Vorbereitungszeit an jeder Buchung gespeichert.')) ?>
            <input type="number" name="prep" min="0" value="<?= $settings['prep'] ?>">
        </label>
    </fieldset>
    <fieldset>
        <legend><?= $_('Sicherheit und Protokoll') ?></legend>
        <label>
            <?= $_('Erlaubte Fehlversuche je Konferenz und IP in 15 Minuten') ?>
            <input type="number" name="attempts" min="1" value="<?= $settings['attempts'] ?>">
        </label>
        <label>
            <?= $_('Intervall der Statusprüfung (Minuten)') ?>
            <input type="number" name="health" min="1" value="<?= $settings['health'] ?>">
        </label>
        <label>
            <input type="checkbox" name="debug" value="1" <?= $settings['debug'] ? 'checked' : '' ?>>
            <?= $_('Debug-Protokollierung (API-Aufrufe und jede Statusprüfung)') ?>
        </label>
    </fieldset>
    <fieldset>
        <legend><?= $_('Teilnahmeseite') ?></legend>
        <label>
            <?= $_('Logo (PNG, JPEG oder WebP, max. 2 MB)') ?>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp">
        </label>
        <? if ($settings['logo']): ?>
            <img src="<?= $controller->link_for('join/logo') ?>" alt="" style="max-height: 80px">
            <label>
                <input type="checkbox" name="delete_logo" value="1">
                <?= $_('Logo entfernen') ?>
            </label>
        <? endif ?>
    </fieldset>
    <footer>
        <?= Studip\Button::createAccept($_('Speichern')) ?>
    </footer>
</form>
