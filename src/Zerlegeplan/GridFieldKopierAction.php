<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Forms\GridField\AbstractGridFieldComponent;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridField_ColumnProvider;
use SilverStripe\Forms\GridField\GridField_ActionProvider;
use SilverStripe\Forms\GridField\GridField_FormAction;
use SilverStripe\Control\Controller;

/**
 * Schaltflaeche „Kopieren“ in der Liste der Zerlegeplaene.
 *
 * Ein neuer Plan entsteht selten aus dem Nichts: meist ist er eine Abwandlung
 * eines vorhandenen -- ein zweites Rind mit anderen Portionsgroessen etwa. Die
 * Kopie nimmt Teile und Varianten mit, die Datei bewusst nicht: sie war nur
 * die Erstbefuellung und wuerde in der Kopie in die Irre fuehren.
 */
class GridFieldKopierAction extends AbstractGridFieldComponent implements
    GridField_ColumnProvider,
    GridField_ActionProvider
{
    public function augmentColumns($gridField, &$columns)
    {
        if (!in_array('Actions', $columns)) {
            $columns[] = 'Actions';
        }
    }

    public function getColumnsHandled($gridField)
    {
        return ['Actions'];
    }

    public function getColumnMetadata($gridField, $columnName)
    {
        return ['title' => null];
    }

    public function getColumnAttributes($gridField, $record, $columnName)
    {
        return ['class' => 'grid-field__col-compact'];
    }

    public function getColumnContent($gridField, $record, $columnName)
    {
        if (!$record->canCreate()) {
            return null;
        }

        $action = GridField_FormAction::create(
            $gridField,
            'Kopieren' . $record->ID,
            'Kopieren',
            'kopieren',
            ['RecordID' => $record->ID]
        )
            ->addExtraClass('btn btn-secondary btn--no-text font-icon-switch grid-field__icon-action')
            ->setAttribute('title', 'Diesen Zerlegeplan kopieren')
            ->setDescription('Diesen Zerlegeplan kopieren');

        return $action->Field();
    }

    public function getActions($gridField)
    {
        return ['kopieren'];
    }

    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName !== 'kopieren') {
            return;
        }

        $plan = Zerlegeplan::get()->byID($arguments['RecordID'] ?? 0);
        if (!$plan || !$plan->canCreate()) {
            return;
        }

        $kopie = $plan->kopieren();

        $controller = Controller::curr();
        if ($controller && $controller->getResponse()) {
            $controller->getResponse()->addHeader(
                'X-Status',
                rawurlencode('Kopie angelegt: ' . $kopie->Title)
            );
        }
    }
}
