<?php
/**
 * @var array[] $nodes
 * @var int $now
 */

use ExclusiveBBB\Schedule;
?>
<? if (!$nodes): ?>
    <?= MessageBox::info($_('Es sind noch keine exklusiven Server eingerichtet.')) ?>
<? endif ?>

<? foreach ($nodes as $entry): ?>
    <? $node = $entry['node'] ?>
    <article class="studip">
        <header>
            <h1>
                <?= htmlReady($node->getName()) ?>
                <? if (!$node->active): ?>
                    (<?= $_('inaktiv') ?>)
                <? elseif ($entry['error']): ?>
                    <?= Icon::create('exclaim-circle', Icon::ROLE_ATTENTION)->asImg(['title' => $_('nicht erreichbar')]) ?>
                <? else: ?>
                    <?= Icon::create('check-circle', Icon::ROLE_STATUS_GREEN)->asImg(['title' => $_('erreichbar')]) ?>
                <? endif ?>
            </h1>
            <nav>
                <a href="<?= htmlReady($controller->booking_plan_url($node)) ?>">
                    <?= Icon::create('timetable')->asImg(['title' => $_('Belegungsplan')]) ?>
                    <?= $_('Belegungsplan') ?>
                </a>
            </nav>
        </header>
        <section>
            <? if ($entry['error']): ?>
                <?= MessageBox::error(sprintf($_('Server nicht erreichbar: %s'), $entry['error'])) ?>
            <? endif ?>

            <p>
                <strong><?= $_('Aktuelle Buchung:') ?></strong>
                <? if ($entry['current']): ?>
                    <? $occurrence = $entry['current']['occurrence'] ?>
                    <?= htmlReady($entry['current']['conference']->getDisplayName()) ?>,
                    <?= date('H:i', $occurrence['begin']) ?> - <?= date('H:i', $occurrence['end']) ?>
                    (<?= sprintf($_('wird um %s Uhr beendet'), date('H:i', $occurrence['close_at'])) ?>)
                <? else: ?>
                    <?= $_('keine') ?>
                <? endif ?>
            </p>

            <table class="default">
                <caption><?= $_('Laufende Meetings') ?></caption>
                <thead>
                    <tr>
                        <th><?= $_('Meeting') ?></th>
                        <th><?= $_('Teilnehmende') ?></th>
                        <th><?= $_('Moderierende') ?></th>
                        <th><?= $_('Videos') ?></th>
                        <th><?= $_('Gestartet') ?></th>
                        <th class="actions"><?= $_('Aktionen') ?></th>
                    </tr>
                </thead>
                <tbody>
                <? if (!$entry['meetings']): ?>
                    <tr><td colspan="6"><?= $_('Keine Meetings') ?></td></tr>
                <? endif ?>
                <? foreach ($entry['meetings'] as $meeting): ?>
                    <tr>
                        <td>
                            <?= htmlReady($meeting['meetingName']) ?>
                            <? if (empty($meeting['metadata']['exbbb-conference'])): ?>
                                <?= Icon::create('exclaim-circle', Icon::ROLE_ATTENTION)->asImg([
                                    'title' => $_('Nicht über diese Buchungsverwaltung gestartet; wird automatisch beendet.'),
                                ]) ?>
                            <? endif ?>
                        </td>
                        <td><?= $meeting['participantCount'] ?></td>
                        <td><?= $meeting['moderatorCount'] ?></td>
                        <td><?= $meeting['videoCount'] ?></td>
                        <td><?= $meeting['createTime'] ? date('d.m.Y H:i', intdiv($meeting['createTime'], 1000)) : '' ?></td>
                        <td class="actions">
                            <form method="post" action="<?= $controller->link_for('management/end', $node->id) ?>">
                                <?= CSRFProtection::tokenTag() ?>
                                <input type="hidden" name="meeting_id" value="<?= htmlReady($meeting['meetingID']) ?>">
                                <?= Icon::create('decline')->asInput([
                                    'title'        => $_('Meeting jetzt beenden'),
                                    'data-confirm' => $_('Meeting wirklich für alle Teilnehmenden beenden?'),
                                ]) ?>
                            </form>
                        </td>
                    </tr>
                <? endforeach ?>
                </tbody>
            </table>

            <table class="default">
                <caption><?= $_('Nächste Buchungen') ?></caption>
                <thead>
                    <tr>
                        <th><?= $_('Konferenz') ?></th>
                        <th><?= $_('Beginn') ?></th>
                        <th><?= $_('Ende') ?></th>
                        <th><?= $_('Raum offen') ?></th>
                    </tr>
                </thead>
                <tbody>
                <? if (!$entry['upcoming']): ?>
                    <tr><td colspan="4"><?= $_('Keine') ?></td></tr>
                <? endif ?>
                <? foreach ($entry['upcoming'] as $item): ?>
                    <? $occurrence = $item['occurrence'] ?>
                    <tr>
                        <td>
                            <a href="<?= $controller->link_for('conferences/edit', $item['conference']->id) ?>">
                                <?= htmlReady($item['conference']->getDisplayName()) ?>
                            </a>
                        </td>
                        <td><?= date('d.m.Y H:i', $occurrence['begin']) ?></td>
                        <td><?= date('d.m.Y H:i', $occurrence['end']) ?></td>
                        <td><?= date('H:i', $occurrence['open_from']) ?> - <?= date('H:i', $occurrence['close_at']) ?></td>
                    </tr>
                <? endforeach ?>
                </tbody>
            </table>
        </section>
    </article>
<? endforeach ?>
