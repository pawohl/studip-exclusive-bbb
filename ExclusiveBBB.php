<?php
/**
 * ExclusiveBBB - exclusively bookable BigBlueButton nodes for Stud.IP.
 *
 * Nodes are rooms in the Stud.IP resource management. Each normal booking
 * of such a room gets a conference with its own join page and passwords.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation; either version 2 of
 * the License, or (at your option) any later version.
 *
 * @license http://www.gnu.org/licenses/gpl-2.0.html GPL version 2
 */

use ExclusiveBBB\Services\BookingObserver;
use ExclusiveBBB\Services\ConferenceService;

class ExclusiveBBB extends StudIPPlugin implements SystemPlugin
{
    public function __construct()
    {
        parent::__construct();

        StudipAutoloader::addAutoloadPath(__DIR__ . '/lib', 'ExclusiveBBB');

        BookingObserver::register();

        $user = User::findCurrent();
        if ($user && $user->id !== 'nobody') {
            $this->addNavigation($user);
        }
    }

    private function addNavigation(User $user): void
    {
        if ($user->perms === 'root' && Navigation::hasItem('/admin/config')) {
            Navigation::addItem('/admin/config/exclusivebbb', new Navigation(
                $this->_('Exklusive BBB-Server'),
                PluginEngine::getURL($this, [], 'admin/index')
            ));
        }

        if (Navigation::hasItem('/resources') && ConferenceService::canManage($user)) {
            Navigation::addItem('/resources/exclusivebbb', new Navigation(
                $this->_('Exklusive BBB-Server'),
                PluginEngine::getURL($this, [], 'management/index')
            ));
        }

        if (Navigation::hasItem('/contents')) {
            Navigation::addItem('/contents/exclusivebbb', new Navigation(
                $this->_('Exklusive Konferenzen'),
                PluginEngine::getURL($this, [], 'conferences/index')
            ));
        }
    }

    /**
     * Plugins get every system role except Nobody on registration; the public
     * join page needs it.
     */
    public static function onEnable($plugin_id)
    {
        self::grantNobodyRole((int) $plugin_id);
    }

    public static function grantNobodyRole(int $plugin_id): void
    {
        DBManager::get()->execute(
            "INSERT IGNORE INTO roles_plugins (roleid, pluginid)
             SELECT roleid, ? FROM roles WHERE rolename = 'Nobody'",
            [$plugin_id]
        );
    }

    public function getPluginName()
    {
        return $this->_('Exklusive BBB-Server');
    }
}
