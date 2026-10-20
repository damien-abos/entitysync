<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Progress of an import job (GET, JSON).
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\ImportController;
use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, Model::APPLY);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode((new ImportController())->status((int) ($_GET['job'] ?? 0)), JSON_UNESCAPED_UNICODE);
