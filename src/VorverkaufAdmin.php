<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldDetailForm;

/**
 * CMS-Bereich "Vorverkaeufe".
 *
 * Hier entstehen und leben die Kampagnen: Zeitraum, Endschwelle, und je
 * Teilstueck der Anfangsbestand samt Zwischenstand. Eine Kampagne kann auch
 * von Hand angelegt werden -- den ueblichen Weg geht das Haekchen "Vorverkauf"
 * an der Warengruppe, das eine Kampagne anlegt, falls noch keine laeuft.
 *
 * Voreingestellt abgeschaltet, wie der Zerlegeplan: nicht jeder Shop verkauft
 * im Voraus. Einschalten im Projekt ueber VorverkaufAdmin.enabled.
 */
class VorverkaufAdmin extends ModelAdmin
{
    private static $managed_models = [
        PreSale::class => ['title' => 'Vorverkäufe'],
    ];

    private static $url_segment = 'vorverkaeufe';
    private static $menu_title = 'Vorverkäufe';
    private static $menu_icon_class = 'font-icon-sync';

    private static bool $enabled = false;

    public function getEditForm($id = null, $fields = null)
    {
        $form = parent::getEditForm($id, $fields);
        $grid = $form->Fields()->dataFieldByName($this->sanitiseClassName($this->modelClass));
        if ($grid) {
            $detail = $grid->getConfig()->getComponentByType(GridFieldDetailForm::class);
            if ($detail) {
                $detail->setItemRequestClass(PreSaleItemRequest::class);
            }
        }
        return $form;
    }

    public function canView($member = null)
    {
        if (!static::config()->get('enabled')) {
            return false;
        }
        return parent::canView($member);
    }
}
