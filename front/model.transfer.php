<?php

/**
 * Entity Sync plugin for GLPI
 *
 * Export of a model configuration (GET export=<id>) and import of an export file.
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\Model;
use GlpiPlugin\Entitysync\ModelTransfer;

Session::checkRight(Model::$rightname, READ);

$transfer = new ModelTransfer();

if (isset($_GET['export'])) {
    return $transfer->export((int) $_GET['export']);
}

if (isset($_POST['import'])) {
    Html::redirect($transfer->import($_POST, $_FILES));
}

Html::header(__('Import a model configuration', 'entitysync'), '', 'admin', Model::class);
$transfer->showForm((int) ($_GET['models_id'] ?? 0));
Html::footer();
