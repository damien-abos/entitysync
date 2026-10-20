<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Entitysync\Model;

Session::checkRight(Model::$rightname, READ);

Html::header(Model::getTypeName(Session::getPluralNumber()), '', 'admin', Model::class);
Search::show(Model::class);
Html::footer();
