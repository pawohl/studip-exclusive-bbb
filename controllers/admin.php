<?php

require_once __DIR__ . '/../lib/ExclusiveBBBController.php';

use ExclusiveBBB\BBB\Client;
use ExclusiveBBB\BBB\ClientException;
use ExclusiveBBB\Log;
use ExclusiveBBB\Models\Node;
use ExclusiveBBB\Settings;

/**
 * Root-only settings: timing, logging, logo and the node list.
 */
class AdminController extends ExclusiveBBBController
{
    public function before_filter(&$action, &$args)
    {
        parent::before_filter($action, $args);
        $GLOBALS['perm']->check('root');
        PageLayout::setTitle($this->_('Exklusive BBB-Server'));
        if (Navigation::hasItem('/admin/config/exclusivebbb')) {
            Navigation::activateItem('/admin/config/exclusivebbb');
        }
    }

    public function index_action()
    {
        $this->nodes = Node::findBySQL('1 ORDER BY id');
        $this->settings = [
            'buffer'   => (int) Config::get()->getValue(Settings::BUFFER_MINUTES),
            'prep'     => (int) Config::get()->getValue(Settings::PREP_MINUTES),
            'health'   => Settings::healthIntervalMinutes(),
            'attempts' => Settings::maxJoinAttempts(),
            'debug'    => Settings::debug(),
            'logo'     => Settings::logo(),
        ];
    }

    public function store_settings_action()
    {
        $this->requirePost();

        Settings::store(Settings::BUFFER_MINUTES, max(0, Request::int('buffer', 15)));
        Settings::store(Settings::PREP_MINUTES, max(0, Request::int('prep', 15)));
        Settings::store(Settings::HEALTH_INTERVAL, max(1, Request::int('health', 5)));
        Settings::store(Settings::MAX_JOIN_ATTEMPTS, max(1, Request::int('attempts', 10)));
        Settings::store(Settings::DEBUG, Request::bool('debug'));

        try {
            if (Request::bool('delete_logo')) {
                $this->deleteImage('logo');
                Settings::store(Settings::LOGO, '');
            }
            $logo = $this->storeUploadedImage('logo', 'logo');
            if ($logo) {
                Settings::store(Settings::LOGO, $logo);
            }
        } catch (InvalidArgumentException $e) {
            PageLayout::postError($e->getMessage());
        }

        PageLayout::postSuccess($this->_('Die Einstellungen wurden gespeichert. Neue Puffer- und Vorlaufzeiten gelten für Buchungen, die ab jetzt angelegt oder geändert werden.'));
        $this->redirect('admin/index');
    }

    public function node_action($id = null)
    {
        $this->node = $id ? Node::find($id) : new Node();
        if (!$this->node) {
            throw new Trails\Exceptions\UnknownAction();
        }
        $this->resources = $this->selectableResources($this->node);
        PageLayout::setTitle($this->node->isNew() ? $this->_('Server hinzufügen') : $this->_('Server bearbeiten'));
    }

    public function store_node_action($id = null)
    {
        $this->requirePost();
        $node = $id ? Node::find($id) : new Node();
        if (!$node) {
            throw new Trails\Exceptions\UnknownAction();
        }

        $resource_id = Request::option('resource_id');
        $url = trim(Request::get('url', ''));
        $secret = trim(Request::get('secret', ''));
        $algorithm = Request::option('algorithm', 'sha256');

        $errors = [];
        if (!$resource_id || !Resource::exists($resource_id)) {
            $errors[] = $this->_('Bitte wählen Sie eine Ressource aus.');
        } elseif (($other = Node::findByResource($resource_id)) && $other->id != $node->id) {
            $errors[] = $this->_('Diese Ressource ist bereits einem anderen Server zugeordnet.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $errors[] = $this->_('Bitte geben Sie die URL des Servers an (https://...).');
        }
        if ($secret === '' && $node->isNew()) {
            $errors[] = $this->_('Bitte geben Sie das Secret des Servers an.');
        }
        if (!in_array($algorithm, Client::ALGORITHMS, true)) {
            $errors[] = $this->_('Ungültiger Prüfsummen-Algorithmus.');
        }
        if ($errors) {
            PageLayout::postError($this->_('Der Server wurde nicht gespeichert.'), $errors);
            $this->redirect('admin/node' . ($node->isNew() ? '' : '/' . $node->id));
            return;
        }

        $node->resource_id = $resource_id;
        $node->url = $url;
        if ($secret !== '') {
            $node->secret = $secret;
        }
        $node->algorithm = $algorithm;
        $node->max_participants = max(0, Request::int('max_participants', 0));
        $node->active = Request::bool('active') ? 1 : 0;
        $node->store();

        Log::debug('Node ' . $node->id . ' gespeichert: ' . Client::normalizeUrl($node->url), $node->resource_id);
        PageLayout::postSuccess($this->_('Der Server wurde gespeichert.'));
        $this->redirect('admin/index');
    }

    public function delete_node_action($id = null)
    {
        $this->requirePost();
        $node = Node::find($id);
        if ($node) {
            $node->delete();
            PageLayout::postSuccess($this->_('Der Server wurde entfernt. Bestehende Konferenzen auf dieser Ressource können nicht mehr betreten werden.'));
        }
        $this->redirect('admin/index');
    }

    public function test_node_action($id = null)
    {
        $this->requirePost();
        $node = Node::find($id);
        if ($node) {
            try {
                $meetings = $node->getClient()->getMeetings();
                PageLayout::postSuccess(sprintf(
                    $this->_('Verbindung zu %s erfolgreich, %u laufende(s) Meeting(s).'),
                    $node->getName(),
                    count($meetings)
                ));
                Log::event(Log::HEALTH, $node->resource_id, 'Verbindungstest erfolgreich');
            } catch (ClientException $e) {
                PageLayout::postError(sprintf($this->_('Verbindung zu %s fehlgeschlagen: %s'), $node->getName(), $e->getMessage()));
                Log::event(Log::HEALTH, $node->resource_id, 'Verbindungstest fehlgeschlagen: ' . $e->getMessage());
            }
        }
        $this->redirect('admin/index');
    }

    /**
     * Rooms not yet mapped to another node, grouped by category name.
     *
     * @return array<string, array<string, string>> category => [resource_id => name]
     */
    private function selectableResources(Node $node): array
    {
        $rows = DBManager::get()->fetchAll(
            "SELECT r.id, r.name, c.name AS category
             FROM resources r
             JOIN resource_categories c ON c.id = r.category_id
             WHERE c.class_name = 'Room'
               AND (r.id NOT IN (SELECT resource_id FROM exbbb_nodes) OR r.id = ?)
             ORDER BY c.name, r.name",
            [(string) $node->resource_id]
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category']][$row['id']] = $row['name'];
        }
        return $grouped;
    }
}
