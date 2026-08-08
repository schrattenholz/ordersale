<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldDetailForm;

/**
 * CMS-Bereich "Zerlegepläne".
 *
 * Hier werden die Pläne gepflegt: Teile und Portionsgrössen direkt in der
 * Tabelle, vorhandene Pläne als Vorlage kopieren, leere Pläne aus einer Datei
 * befüllen. Angewendet wird ein Plan nicht hier, sondern an der Warengruppe im
 * Seitenbaum -- dort, wo die Produkte entstehen.
 */
class ZerlegeplanAdmin extends ModelAdmin
{
    private static $managed_models = [
        Zerlegeplan::class,
    ];

    private static $url_segment = 'zerlegeplaene';
    private static $menu_title = 'Zerlegepläne';
    private static $menu_icon_class = 'font-icon-block-content';

    /**
     * Voreingestellt aus.
     *
     * Nicht jeder Shop zerlegt Tiere -- ein Käseladen soll den Menüpunkt gar
     * nicht erst sehen. Wer ihn braucht, schaltet ihn im Projekt ein.
     */
    private static bool $enabled = false;

    public function canView($member = null)
    {
        if (!static::config()->get('enabled')) {
            return false;
        }
        return parent::canView($member);
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
        if (!$grid) {
            return $form;
        }

        $config = $grid->getConfig();
        $config->addComponent(new GridFieldKopierAction());

        $detail = $config->getComponentByType(GridFieldDetailForm::class);
        if ($detail) {
            // Detailansicht um „Aus Datei neu befüllen“ erweitern
            $detail->setItemRequestClass(ZerlegeplanItemRequest::class);
        }
        return $form;
    }
}
