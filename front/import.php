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

if (isset($_POST['apply'])) {
    Html::redirect($controller->apply($_POST));
}

if (isset($_POST['cancel'])) {
    Html::redirect($controller->cancel((string) ($_POST['token'] ?? '')));
}

if (isset($_GET['token'])) {
    Html::header(__('Entity import', 'entitysync'), '', 'admin', Model::class);
    $controller->preview((string) $_GET['token']);
    Html::footer();
    return;
}

Html::redirect(Model::getSearchURL());
