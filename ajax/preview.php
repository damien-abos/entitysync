<?php

/**
 * Entity Sync plugin for GLPI
 *
 * One page of a section of the import preview (GET, JSON).
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\ImportController;
use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, Model::APPLY);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode((new ImportController())->page($_GET), JSON_UNESCAPED_UNICODE);
