<?php
/**
 * @var Conference[] $conferences
 */

use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Schedule;
?>
<? if (!$conferences): ?>
    <?= MessageBox::info($_('Sie haben keine anstehenden Konferenzen auf exklusiven BigBlueButton-Servern.')) ?>
<? else: ?>
    <table class="default">
        <caption><?= $_('Anstehende Konferenzen') ?></caption>
        <thead>
            <tr>
                <th><?= $_('Konferenz') ?></th>
                <th><?= $_('Server') ?></th>
                <th><?= $_('Nächster Termin') ?></th>
                <th><?= $_('Termine') ?></th>
                <th class="actions"><?= $_('Aktionen') ?></th>
            </tr>
        </thead>
        <tbody>
        <? foreach ($conferences as $conference): ?>
            <? $occurrences = $conference->getOccurrences() ?>
            <? $next = Schedule::current($occurrences, time()) ?: Schedule::next($occurrences, time()) ?>
            <? $node = $conference->getNode() ?>
            <tr>
                <td>
                    <a href="<?= $controller->link_for('conferences/edit', $conference->id) ?>">
                        <?= htmlReady($conference->getDisplayName()) ?>
                    </a>
                </td>
                <td><?= htmlReady($node ? $node->getName() : '') ?></td>
                <td>
                    <? if ($next): ?>
                        <?= date('d.m.Y H:i', $next['begin']) ?> - <?= date('H:i', $next['end']) ?>
                    <? endif ?>
                </td>
                <td><?= count($occurrences) ?></td>
                <td class="actions">
                    <a href="<?= $controller->link_for('conferences/edit', $conference->id) ?>">
                        <?= Icon::create('edit')->asImg(['title' => $_('Bearbeiten')]) ?>
                    </a>
                    <a href="<?= $controller->link_for('join/index', $conference->token) ?>" target="_blank">
                        <?= Icon::create('link-extern')->asImg(['title' => $_('Teilnahmeseite öffnen')]) ?>
                    </a>
                </td>
            </tr>
        <? endforeach ?>
        </tbody>
    </table>
<? endif ?>
