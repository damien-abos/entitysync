<?php

/**
 * Entity Sync plugin for GLPI
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace GlpiPlugin\Entitysync;

use CommonGLPI;
use Html;
use Profile;
use Session;

/**
 * "Entity import" tab on profiles, to grant the plugin rights.
 */
class ProfileRights extends CommonGLPI
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return __('Entity import', 'entitysync');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry(self::getTypeName(), 0, null, Model::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Profile) {
            return false;
        }

        $profile = new Profile();
        if (!$profile->getFromDB($item->getID())) {
            return false;
        }

        $canEdit = Session::haveRight(Profile::$rightname, UPDATE);

        echo "<div class='spaced'>";
        if ($canEdit) {
            echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";
        }

        $profile->displayRightsChoiceMatrix(
            [
                [
                    'itemtype' => Model::class,
                    'label'    => Model::getTypeName(Session::getPluralNumber()),
                    'field'    => Model::$rightname,
                ],
            ],
            [
                'canedit'       => $canEdit,
                'default_class' => 'tab_bg_2',
                'title'         => __('Entity import', 'entitysync'),
            ],
        );

        echo "<div class='alert alert-info mt-2'>"
            . __s('Applying an import also requires the create, update and delete rights on entities, and a recursive access to the target root entity.', 'entitysync')
            . '</div>';

        if ($canEdit) {
            echo "<div class='text-center mt-2'>";
            echo Html::hidden('id', ['value' => $profile->getID()]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
            Html::closeForm();
        }
        echo '</div>';

        return true;
    }
}
