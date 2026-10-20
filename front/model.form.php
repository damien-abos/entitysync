<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, READ);

$model = new Model();

if (isset($_POST['add'])) {
    $model->check(-1, CREATE, $_POST);
    if ($id = $model->add($_POST)) {
        Html::redirect($model->getLinkURL());
    }
    Html::back();
}

if (isset($_POST['update'])) {
    $model->check((int) $_POST['id'], UPDATE);
    $model->update($_POST);
    Html::back();
}

if (isset($_POST['purge'])) {
    $model->check((int) $_POST['id'], PURGE);
    $model->delete($_POST, true);
    $model->redirectToList();
}

// GLPI derives the itemtype of `forcetab` from the URL, which does not match a
// namespaced class served from a flat `front/` file: set the active tab explicitly.
if (isset($_GET['forcetab'])) {
    Session::setActiveTab(Model::class, (string) $_GET['forcetab']);
}

Html::header(Model::getTypeName(Session::getPluralNumber()), '', 'admin', Model::class);
$model->display(['id' => (int) ($_GET['id'] ?? 0)]);
Html::footer();
