<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Verbindet die Warengruppe mit ihrer Vorverkaufs-Kampagne.
 *
 * Der Metzger setzt das Haekchen "Vorverkauf" an der Warengruppe und speichert
 * -- daraufhin entsteht hier die Kampagne (falls noch keine laeuft) und ihre
 * Teilnehmer. Der Anfangsbestand kommt dabei aus dem Zerlegeplan.
 *
 * Haengt am Hook, den order beim Speichern der Warengruppe wirft. Der Weg ueber
 * den Hook statt ueber eigenen Code in order ist Absicht: ordersale kennt order,
 * aber nicht umgekehrt.
 */
class OrderSale_ProductListExtension extends Extension
{
    /**
     * Laeuft am Ende von Order_ProductListExtension::onAfterWrite().
     *
     * Zu diesem Zeitpunkt steht InPreSale noch auf true -- zurueckgesetzt wird
     * es erst danach per SQLUpdate. Das Feld ist also der Ausloeser: gesetzt
     * heisst "der Vorverkauf wurde gerade gestartet".
     */
    public function HOOK_Order_ProductListExtension_AfterWrite($productList)
    {
        if (!$productList || !$productList->InPreSale) {
            return;
        }

        $kampagne = PreSale::activeFor($productList->ID);
        if (!$kampagne) {
            $kampagne = PreSale::create();
            $kampagne->Title = $productList->Title . ' — '
                . DBDatetime::now()->Format('dd.MM.yyyy');
            $kampagne->ProductListID = $productList->ID;
            $kampagne->PreSaleStart = $productList->PreSaleStart;
            $kampagne->PreSaleEnd = $productList->PreSaleEnd;
            $kampagne->PreSaleEndPercentage = $productList->PreSaleEndPercentage;
            $kampagne->Active = true;
            $kampagne->write();
        }

        $kampagne->itemsAnlegen();
    }

    /**
     * Der Zwischenstand der laufenden Kampagne, im Reiter Produkte.
     *
     * Nur zum Ansehen -- gepflegt wird die Kampagne im Bereich "Vorverkaeufe".
     */
    public function updateCMSFields($fields)
    {
        $owner = $this->getOwner();
        if (!$owner->isInDB()) {
            return;
        }

        $kampagne = PreSale::activeFor($owner->ID);
        if (!$kampagne) {
            return;
        }

        $zeilen = '';
        foreach ($kampagne->Items() as $item) {
            $zeilen .= '<tr>'
                . '<td>' . htmlspecialchars($item->getBezeichnung()) . '</td>'
                . '<td style="text-align:right">' . (int)$item->StartInventory . '</td>'
                . '<td style="text-align:right">' . $item->VerkaufteMenge() . '</td>'
                . '<td style="text-align:right">' . $item->ReservierteMenge() . '</td>'
                . '<td style="text-align:right">' . $item->Rest() . '</td>'
                . '<td style="text-align:right">' . $item->getAnteil() . '</td>'
                . '</tr>';
        }

        if (!$zeilen) {
            $zeilen = '<tr><td colspan="6">Noch keine Teilstücke — '
                . 'sie entstehen beim Start des Vorverkaufs.</td></tr>';
        }

        $html = '<div class="form__field-holder">'
            . '<p><strong>' . htmlspecialchars($kampagne->Title) . '</strong> — '
            . $kampagne->SoldQuantity() . ' von ' . $kampagne->StartInventory()
            . ' verkauft (' . $kampagne->SoldPercentageOfStart() . ' %, Schwelle '
            . (int)$kampagne->PreSaleEndPercentage . ' %)</p>'
            . '<table class="table"><thead><tr>'
            . '<th>Teilstück</th><th style="text-align:right">Anfang</th>'
            . '<th style="text-align:right">verkauft</th>'
            . '<th style="text-align:right">reserviert</th>'
            . '<th style="text-align:right">Rest</th>'
            . '<th style="text-align:right">Anteil</th>'
            . '</tr></thead><tbody>' . $zeilen . '</tbody></table>'
            . '<p class="help">Gepflegt wird der Vorverkauf im Bereich '
            . '„Vorverkäufe“.</p></div>';

        $fields->addFieldToTab('Root.Produkte',
            LiteralField::create('LaufenderVorverkauf', $html));
    }
}
