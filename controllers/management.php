<?php

require_once __DIR__ . '/../lib/ExclusiveBBBController.php';

use ExclusiveBBB\BBB\ClientException;
use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Conference;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Schedule;
use ExclusiveBBB\Services\ConferenceService;

/**
 * Live view of all nodes for root and resource admins/tutors of the nodes.
 */
class ManagementController extends ExclusiveBBBController
{
    public function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);
        $this->user = $this->requireLogin();
        if (!ConferenceService::canManage($this->user)) {
            throw new AccessDeniedException();
        }
        PageLayout::setTitle($this->_('Exklusive BBB-Server'));
        if (Navigation::hasItem('/resources/exclusivebbb')) {
            Navigation::activateItem('/resources/exclusivebbb');
        }
    }

    public function index_action()
    {
        $now = time();
        $this->now = $now;
        $this->nodes = [];

        foreach (Node::findBySQL('1 ORDER BY id') as $node) {
            $entry = ['node' => $node, 'meetings' => [], 'error' => null, 'current' => null, 'upcoming' => []];
            if ($node->active) {
                try {
                    $entry['meetings'] = $node->getClient()->getMeetings();
                } catch (ClientException $e) {
                    $entry['error'] = $e->getMessage();
                }
            }

            $conferences = Conference::findBySQL("resource_id = ? AND status = 'active'", [$node->resource_id]);
            foreach ($conferences as $conference) {
                foreach ($conference->getOccurrences() as $occurrence) {
                    if ($occurrence['close_at'] <= $now) {
                        continue;
                    }
                    $item = ['conference' => $conference, 'occurrence' => $occurrence];
                    if ($occurrence['open_from'] <= $now) {
                        $entry['current'] = $item;
                    } else {
                        $entry['upcoming'][] = $item;
                    }
                }
            }
            usort($entry['upcoming'], fn($a, $b) => $a['occurrence']['open_from'] <=> $b['occurrence']['open_from']);
            $entry['upcoming'] = array_slice($entry['upcoming'], 0, 10);

            $this->nodes[] = $entry;
        }
    }

    public function end_action($node_id = null)
    {
        $this->requirePost();
        $node = Node::find($node_id);
        $meeting_id = Request::get('meeting_id');
        if (!$node || !$meeting_id) {
            throw new Trails\Exceptions\UnknownAction();
        }
        if ($this->user->perms !== 'root' && !$node->resource->userHasPermission($this->user, 'tutor')
            && !ResourceManager::userHasGlobalPermission($this->user, 'admin')) {
            throw new AccessDeniedException();
        }

        try {
            $node->getClient()->end($meeting_id);
            Log::event(Log::END, $node->resource_id, $meeting_id . ': manuell beendet');
            PageLayout::postSuccess($this->_('Das Meeting wurde beendet.'));
        } catch (ClientException $e) {
            PageLayout::postError(sprintf($this->_('Das Meeting konnte nicht beendet werden: %s'), $e->getMessage()));
        }
        $this->redirect('management/index');
    }

    public function booking_plan_url(Node $node): string
    {
        return URLHelper::getURL('dispatch.php/resources/room_planning/booking_plan/' . $node->resource_id);
    }
}
