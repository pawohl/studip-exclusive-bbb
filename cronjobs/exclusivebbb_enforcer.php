<?php

use ExclusiveBBB\Services\Enforcer;

StudipAutoloader::addAutoloadPath(__DIR__ . '/../lib', 'ExclusiveBBB');

/**
 * Ends meetings on exclusive BBB nodes that are outside a booking window
 * (booking end + buffer reached, booking cancelled, or not booked at all)
 * and checks the nodes' health.
 */
class ExclusiveBBBEnforcerJob extends CronJob
{
    public static function getName()
    {
        return 'Exklusive BBB-Server: Buchungen durchsetzen';
    }

    public static function getDescription()
    {
        return 'Beendet Meetings auf exklusiven BigBlueButton-Servern außerhalb gültiger Buchungen und prüft die Erreichbarkeit der Server. Sollte jede Minute laufen.';
    }

    public function execute($last_result, $parameters = [])
    {
        foreach (Enforcer::run(time()) as $node_id => $status) {
            printf(
                "node %d: %s, %d meeting(s), %d ended%s\n",
                $node_id,
                $status['ok'] ? 'ok' : 'unreachable',
                $status['meetings'],
                $status['ended'],
                $status['error'] ? ' (' . $status['error'] . ')' : ''
            );
        }
    }
}
