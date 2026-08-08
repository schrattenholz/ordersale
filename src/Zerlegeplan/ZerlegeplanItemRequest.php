<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;

/**
 * Ergaenzt die Detailansicht eines Zerlegeplans um „Aus Datei neu befüllen“.
 *
 * Ein leerer Plan zieht sich die Datei beim Speichern von selbst; ein bereits
 * gefuellter nicht -- sonst wuerde ein versehentlicher Upload die Handarbeit
 * ueberschreiben. Wer es doch will, sagt es hier ausdruecklich.
 */
class ZerlegeplanItemRequest extends GridFieldDetailForm_ItemRequest
{
    private static $allowed_actions = [
        'edit',
        'view',
        'ItemEditForm',
        'doAusDateiFuellen',
        'doKopieren',
    ];

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();
        $record = $this->getRecord();

        if (!$form || !$record || !$record->isInDB()) {
            return $form;
        }

        $actions = $form->Actions();

        if ($record->Datei()->exists()) {
            $actions->push(
                FormAction::create('doAusDateiFuellen', 'Aus Datei neu befüllen')
                    ->setUseButtonTag(true)
                    ->addExtraClass('btn btn-outline-primary')
                    ->setAttribute('data-confirm',
                        'Die vorhandenen Teile dieses Plans werden dabei ersetzt. Fortfahren?')
            );
        }

        $actions->push(
            FormAction::create('doKopieren', 'Kopieren')
                ->setUseButtonTag(true)
                ->addExtraClass('btn btn-outline-secondary')
        );

        return $form;
    }

    public function doAusDateiFuellen($data, Form $form)
    {
        $record = $this->getRecord();
        $fehler = $record->ausDateiFuellen(true);

        if ($fehler) {
            $form->sessionMessage("Die Datei konnte nicht gelesen werden:\n  - "
                . implode("\n  - ", $fehler), 'bad');
        } else {
            $form->sessionMessage(sprintf('Aus der Datei übernommen: %d Teile, %d Varianten.',
                $record->getAnzahlTeile(), $record->getAnzahlVarianten()), 'good');
        }
        return $this->edit($this->getRequest());
    }

    public function doKopieren($data, Form $form)
    {
        $record = $this->getRecord();
        $kopie = $record->kopieren();
        $form->sessionMessage('Kopie angelegt: ' . $kopie->Title
            . ' — in der Übersicht zu finden.', 'good');
        return $this->edit($this->getRequest());
    }
}
