<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\Job;
use GlpiPlugin\Entitysync\JobStorage;
use GlpiPlugin\Entitysync\Link;
use GlpiPlugin\Entitysync\Model;
use GlpiPlugin\Entitysync\Run;

/**
 * Install or upgrade the plugin.
 */
function plugin_entitysync_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_ENTITYSYNC_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    $models = Model::getTable();
    if (!$DB->tableExists($models)) {
        $DB->doQuery("CREATE TABLE `$models` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `comment` text,
            `entities_id_target` int {$sign} NOT NULL DEFAULT '0',
            `csv_delimiter` varchar(8) NOT NULL DEFAULT 'auto',
            `csv_enclosure` varchar(8) NOT NULL DEFAULT '\"',
            `csv_encoding` varchar(32) NOT NULL DEFAULT 'auto',
            `levels` longtext,
            `filter` longtext,
            `known_columns` longtext,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `entities_id_target` (`entities_id_target`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    $links = Link::getTable();
    if (!$DB->tableExists($links)) {
        $DB->doQuery("CREATE TABLE `$links` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_entitysync_models_id` int {$sign} NOT NULL DEFAULT '0',
            `entities_id` int {$sign} NOT NULL DEFAULT '0',
            `path_hash` char(40) NOT NULL,
            `path` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_entitysync_models_id`, `path_hash`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    $runs = Run::getTable();
    if (!$DB->tableExists($runs)) {
        $DB->doQuery("CREATE TABLE `$runs` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_entitysync_models_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `date` timestamp NULL DEFAULT NULL,
            `filename` varchar(255) DEFAULT NULL,
            `nb_rows` int NOT NULL DEFAULT '0',
            `nb_selected` int NOT NULL DEFAULT '0',
            `nb_added` int NOT NULL DEFAULT '0',
            `nb_linked` int NOT NULL DEFAULT '0',
            `nb_updated` int NOT NULL DEFAULT '0',
            `nb_deleted` int NOT NULL DEFAULT '0',
            `nb_failed` int NOT NULL DEFAULT '0',
            `nb_skipped` int NOT NULL DEFAULT '0',
            `details` longtext,
            `status` varchar(16) NOT NULL DEFAULT 'done',
            PRIMARY KEY (`id`),
            KEY `plugin_entitysync_models_id` (`plugin_entitysync_models_id`),
            KEY `users_id` (`users_id`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // 1.1.0: final status of the job that produced the run.
    if (!$DB->fieldExists($runs, 'status')) {
        $migration->addField($runs, 'status', "varchar(16) NOT NULL DEFAULT 'done'", ['after' => 'details']);
    }

    $jobs = Job::getTable();
    if (!$DB->tableExists($jobs)) {
        $DB->doQuery("CREATE TABLE `$jobs` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_entitysync_models_id` int {$sign} NOT NULL DEFAULT '0',
            `users_id` int {$sign} NOT NULL DEFAULT '0',
            `status` varchar(16) NOT NULL DEFAULT 'draft',
            `filename` varchar(255) DEFAULT NULL,
            `nb_rows` int NOT NULL DEFAULT '0',
            `nb_total` int NOT NULL DEFAULT '0',
            `nb_processed` int NOT NULL DEFAULT '0',
            `nb_added` int NOT NULL DEFAULT '0',
            `nb_linked` int NOT NULL DEFAULT '0',
            `nb_updated` int NOT NULL DEFAULT '0',
            `nb_deleted` int NOT NULL DEFAULT '0',
            `nb_failed` int NOT NULL DEFAULT '0',
            `nb_skipped` int NOT NULL DEFAULT '0',
            `selection` longtext,
            `forbidden` text,
            `results` longtext,
            `message` text,
            `plugin_entitysync_runs_id` int {$sign} NOT NULL DEFAULT '0',
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_queued` timestamp NULL DEFAULT NULL,
            `date_start` timestamp NULL DEFAULT NULL,
            `date_end` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_entitysync_models_id` (`plugin_entitysync_models_id`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`),
            KEY `date_creation` (`date_creation`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC;");
    }

    // Background application of queued imports (GLPI automatic action, run by the system cron).
    CronTask::register(Job::class, Job::CRON_TASK, MINUTE_TIMESTAMP, [
        'mode'    => CronTask::MODE_EXTERNAL,
        'param'   => Job::DEFAULT_BATCH_SIZE,
        'comment' => 'Entity Sync: applies the queued imports by batches',
    ]);

    JobStorage::ensureDirectory();

    // Full rights for profiles able to update the configuration (super-admins), none for the others.
    $migration->addRight(Model::$rightname, Model::ALL_RIGHTS, [Config::$rightname => UPDATE]);

    $migration->executeMigration();

    return true;
}

/**
 * Uninstall the plugin: drop tables and rights.
 */
function plugin_entitysync_uninstall(): bool
{
    global $DB;

    foreach ([Job::getTable(), Run::getTable(), Link::getTable(), Model::getTable()] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    ProfileRight::deleteProfileRights([Model::$rightname]);
    CronTask::unregister('entitysync');
    JobStorage::removeAll();

    foreach ([Model::class, Run::class, Job::class] as $itemtype) {
        $DB->delete('glpi_displaypreferences', ['itemtype' => $itemtype]);
        $DB->delete('glpi_logs', ['itemtype' => $itemtype]);
    }

    return true;
}

/**
 * Removes the links of an entity purged outside of the plugin.
 */
function plugin_entitysync_item_purge_entity(Entity $entity): void
{
    global $DB;

    $DB->delete(Link::getTable(), ['entities_id' => $entity->getID()]);
}
