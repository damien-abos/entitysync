<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Changes the selection of an import preview (POST, JSON body, CSRF token in the
 * X-Glpi-Csrf-Token header). The selection is never posted as form fields, so its
 * size is not limited by PHP's max_input_vars.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Entitysync\ImportController;
use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, Model::APPLY);

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input)) {
    throw new BadRequestHttpException();
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode((new ImportController())->select($input), JSON_UNESCAPED_UNICODE);
