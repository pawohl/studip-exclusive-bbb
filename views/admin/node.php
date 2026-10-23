<?php
/**
 * @var Node $node
 * @var array $resources
 */

use ExclusiveBBB\BBB\Client;
use ExclusiveBBB\Models\Node;
?>
<form class="default" method="post"
      action="<?= $controller->link_for('admin/store_node' . ($node->isNew() ? '' : '/' . $node->id)) ?>">
    <?= CSRFProtection::tokenTag() ?>
    <fieldset>
        <legend><?= $node->isNew() ? $_('Server hinzufügen') : $_('Server bearbeiten') ?></legend>

        <label class="studiprequired">
            <span class="textlabel"><?= $_('Ressource (Raum) in der Raumverwaltung') ?></span>
            <span class="asterisk">*</span>
            <select name="resource_id" required>
                <option value=""><?= $_('Bitte wählen') ?></option>
                <? foreach ($resources as $category => $rooms): ?>
                    <optgroup label="<?= htmlReady($category) ?>">
                    <? foreach ($rooms as $id => $name): ?>
                        <option value="<?= htmlReady($id) ?>" <?= $id === $node->resource_id ? 'selected' : '' ?>>
                            <?= htmlReady($name) ?>
                        </option>
                    <? endforeach ?>
                    </optgroup>
                <? endforeach ?>
            </select>
        </label>

        <label class="studiprequired">
            <span class="textlabel"><?= $_('URL des BigBlueButton-Servers') ?></span>
            <span class="asterisk">*</span>
            <input type="url" name="url" required placeholder="https://bbb-exklusiv-1.example.org/bigbluebutton/"
                   value="<?= htmlReady($node->url) ?>">
        </label>

        <label <?= $node->isNew() ? 'class="studiprequired"' : '' ?>>
            <span class="textlabel"><?= $_('Secret') ?></span>
            <? if ($node->isNew()): ?>
                <span class="asterisk">*</span>
            <? endif ?>
            <input type="password" name="secret" autocomplete="new-password" <?= $node->isNew() ? 'required' : '' ?>
                   placeholder="<?= $node->isNew() ? '' : htmlReady($_('unverändert lassen')) ?>">
        </label>

        <label>
            <?= $_('Prüfsummen-Algorithmus') ?>
            <select name="algorithm">
                <? foreach (Client::ALGORITHMS as $algorithm): ?>
                    <option <?= $algorithm === ($node->algorithm ?: 'sha256') ? 'selected' : '' ?>><?= $algorithm ?></option>
                <? endforeach ?>
            </select>
        </label>

        <label>
            <?= $_('Maximale Teilnehmendenzahl je Konferenz (0 = unbegrenzt)') ?>
            <input type="number" name="max_participants" min="0" value="<?= (int) $node->max_participants ?>">
        </label>

        <label>
            <input type="checkbox" name="active" value="1" <?= $node->isNew() || $node->active ? 'checked' : '' ?>>
            <?= $_('Aktiv') ?>
        </label>
    </fieldset>
    <footer>
        <?= Studip\Button::createAccept($_('Speichern')) ?>
        <?= Studip\LinkButton::createCancel($_('Abbrechen'), $controller->url_for('admin/index')) ?>
    </footer>
</form>
