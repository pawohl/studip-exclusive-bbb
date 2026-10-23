<?php

class Setup extends Migration
{
    private const CONFIG = [
        'EXBBB_BUFFER_MINUTES' => [
            'value' => 15, 'type' => 'integer',
            'description' => 'Exklusive BBB: Puffer in Minuten nach Buchungsende, bevor die Konferenz beendet wird; gilt zugleich als Mindestabstand zur nächsten Buchung.',
        ],
        'EXBBB_PREP_MINUTES' => [
            'value' => 15, 'type' => 'integer',
            'description' => 'Exklusive BBB: Minuten vor Buchungsbeginn, ab denen Moderierende den Raum betreten können.',
        ],
        'EXBBB_DEBUG' => [
            'value' => 0, 'type' => 'boolean',
            'description' => 'Exklusive BBB: Debug-Protokollierung (API-Aufrufe, regelmäßige Statusprüfungen).',
        ],
        'EXBBB_HEALTH_INTERVAL' => [
            'value' => 5, 'type' => 'integer',
            'description' => 'Exklusive BBB: Intervall der Statusprüfung der Server in Minuten.',
        ],
        'EXBBB_MAX_JOIN_ATTEMPTS' => [
            'value' => 10, 'type' => 'integer',
            'description' => 'Exklusive BBB: Erlaubte Fehlversuche bei der Passworteingabe je Konferenz und IP in 15 Minuten.',
        ],
        'EXBBB_LOGO' => [
            'value' => '', 'type' => 'string',
            'description' => 'Exklusive BBB: Dateiname des Logos auf der Teilnahmeseite.',
        ],
    ];

    private const LOG_ACTIONS = [
        'EXBBB_CREATE'    => ['Exklusive BBB: Konferenz angelegt', '%user legt Konferenz für Buchung auf %res(%affected) an: %info'],
        'EXBBB_UPDATE'    => ['Exklusive BBB: Konferenz geändert', '%user ändert Konferenz auf %res(%affected): %info'],
        'EXBBB_CANCEL'    => ['Exklusive BBB: Konferenz storniert', '%user storniert Konferenz auf %res(%affected): %info'],
        'EXBBB_JOIN'      => ['Exklusive BBB: Teilnahme', 'Teilnahme an Konferenz auf %res(%affected): %info'],
        'EXBBB_JOIN_FAIL' => ['Exklusive BBB: Teilnahme abgelehnt', 'Abgelehnte Teilnahme an Konferenz auf %res(%affected): %info'],
        'EXBBB_END'       => ['Exklusive BBB: Meeting beendet', 'Meeting auf %res(%affected) beendet: %info'],
        'EXBBB_HEALTH'    => ['Exklusive BBB: Serverstatus', 'Serverstatus %res(%affected): %info'],
        'EXBBB_DEBUG'     => ['Exklusive BBB: Debug', '%info'],
    ];

    public function description()
    {
        return 'Tables, config, log actions, public access and cronjob for exclusive BBB nodes';
    }

    public function up()
    {
        $db = DBManager::get();

        $db->exec("CREATE TABLE IF NOT EXISTS `exbbb_nodes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `resource_id` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `url` varchar(255) NOT NULL,
            `secret` varchar(255) NOT NULL,
            `algorithm` varchar(10) NOT NULL DEFAULT 'sha256',
            `max_participants` int(11) unsigned NOT NULL DEFAULT 0,
            `active` tinyint(1) unsigned NOT NULL DEFAULT 1,
            `last_check` int(11) unsigned DEFAULT NULL,
            `last_status` varchar(255) DEFAULT NULL,
            `mkdate` int(11) unsigned NOT NULL,
            `chdate` int(11) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `resource_id` (`resource_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $db->exec("CREATE TABLE IF NOT EXISTS `exbbb_conferences` (
            `id` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `booking_id` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `resource_id` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `name` varchar(255) NOT NULL DEFAULT '',
            `description` text NOT NULL,
            `image` varchar(64) DEFAULT NULL,
            `attendee_pw_hash` varchar(255) NOT NULL,
            `moderator_pw_hash` varchar(255) NOT NULL,
            `token` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `meeting_id` char(36) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `status` enum('active','cancelled') CHARACTER SET latin1 COLLATE latin1_bin NOT NULL DEFAULT 'active',
            `mkdate` int(11) unsigned NOT NULL,
            `chdate` int(11) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `booking_id` (`booking_id`),
            UNIQUE KEY `token` (`token`),
            KEY `resource_id` (`resource_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        $db->exec("CREATE TABLE IF NOT EXISTS `exbbb_join_attempts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `token` char(32) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `ip_hash` char(64) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
            `mkdate` int(11) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `lookup` (`token`, `ip_hash`, `mkdate`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC");

        foreach (self::CONFIG as $field => $data) {
            if (!Config::get()->getMetadata($field)) {
                Config::get()->create($field, $data + ['range' => 'global', 'section' => 'exclusivebbb']);
            }
        }

        foreach (self::LOG_ACTIONS as $name => [$description, $template]) {
            if (!LogAction::findOneByName($name)) {
                StudipLog::registerActionPlugin($name, $description, $template, 'ExclusiveBBB');
            }
        }

        // the join page must be reachable without login
        $plugin_id = $this->pluginId();
        $db->execute(
            "INSERT IGNORE INTO roles_plugins (roleid, pluginid)
             SELECT roleid, ? FROM roles WHERE rolename = 'Nobody'",
            [$plugin_id]
        );

        $scheduler = CronjobScheduler::getInstance();
        $task_id = $scheduler->registerTask($this->cronjobFile(), true);
        if (!CronjobSchedule::countBySQL('task_id = ?', [$task_id])) {
            // every minute
            $scheduler->schedulePeriodic($task_id);
        }
    }

    public function down()
    {
        $task = CronjobTask::findOneByClass('ExclusiveBBBEnforcerJob');
        if ($task) {
            CronjobScheduler::getInstance()->unregisterTask($task->task_id);
        }

        foreach (array_keys(self::LOG_ACTIONS) as $name) {
            StudipLog::unregisterAction($name);
        }
        foreach (array_keys(self::CONFIG) as $field) {
            Config::get()->delete($field);
        }

        $db = DBManager::get();
        $db->exec('DROP TABLE IF EXISTS `exbbb_join_attempts`');
        $db->exec('DROP TABLE IF EXISTS `exbbb_conferences`');
        $db->exec('DROP TABLE IF EXISTS `exbbb_nodes`');
    }

    private function pluginId(): int
    {
        return (int) DBManager::get()->fetchColumn(
            "SELECT pluginid FROM plugins WHERE pluginclassname = 'ExclusiveBBB'"
        );
    }

    private function cronjobFile(): string
    {
        return studip_relative_path(dirname(__DIR__) . '/cronjobs/exclusivebbb_enforcer.php');
    }
}
