<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Forms\GridField\GridFieldDetailForm_ItemRequest;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\Form;

/**
 * Ergaenzt die Kampagne um "Teilnehmer aus dem Zerlegeplan uebernehmen".
 *
 * Eine von Hand angelegte Kampagne hat zunaechst keine Teilnehmer. Der Knopf
 * holt sie aus der Warengruppe -- eine Zeile je Variante, die nicht vom
 * Vorverkauf ausgenommen ist, mit dem Anfangsbestand aus dem Zerlegeplan.
 *
 * Vorhandene Zeilen bleiben unberuehrt: ein laufender Vorverkauf soll seinen
 * Anfangsbestand behalten, auch wenn der Zerlegeplan sich inzwischen geaendert
 * hat.
 */
class PreSaleItemRequest extends GridFieldDetailForm_ItemRequest
{
    private static $allowed_actions = [
        'edit',
        'view',
        'ItemEditForm',
        'doTeilnehmerUebernehmen',
    ];

    public function ItemEditForm()
    {
        $form = parent::ItemEditForm();
        $record = $this->getRecord();

        if (!$form || !$record || !$record->isInDB()) {
            return $form;
        }

        $warengruppe = $record->ProductList();
        if ($warengruppe && $warengruppe->exists()) {
            $form->Actions()->push(
                FormAction::create('doTeilnehmerUebernehmen', 'Teilnehmer aus dem Zerlegeplan übernehmen')
                    ->setUseButtonTag(true)
                    ->addExtraClass('btn btn-outline-primary')
            );
        }

        return $form;
    }

    public function doTeilnehmerUebernehmen($data, Form $form)
    {
        $record = $this->getRecord();
        $warengruppe = $record->ProductList();

        if (!$warengruppe || !$warengruppe->exists()) {
            $form->sessionMessage('Der Kampagne fehlt die Warengruppe.', 'bad');
            return $this->edit($this->getRequest());
        }

        $neu = $record->itemsAnlegen();

        $form->sessionMessage(
            $neu
                ? sprintf('%d Teilstücke übernommen, %d insgesamt.', $neu, $record->Items()->count())
                : 'Nichts zu übernehmen — alle Teilstücke sind bereits dabei.',
            'good'
        );
        return $this->edit($this->getRequest());
    }
}
