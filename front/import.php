<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\ImportController;
use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, Model::APPLY);

$controller = new ImportController();

if (isset($_POST['preview'])) {
    Html::redirect($controller->upload($_POST, $_FILES));
}

if (isset($_POST['queue'])) {
    Html::redirect($controller->queue((int) ($_POST['job'] ?? 0)));
}

if (isset($_POST['cancel'])) {
    Html::redirect($controller->cancel((int) ($_POST['job'] ?? 0)));
}

if (isset($_GET['job'])) {
    Html::header(__('Entity import', 'entitysync'), '', 'admin', Model::class);
    $controller->show((int) $_GET['job']);
    Html::footer();
    return;
}

Html::redirect(Model::getSearchURL());
