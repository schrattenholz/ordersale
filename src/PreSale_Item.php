<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\ORM\DataObject;
use Schrattenholz\Order\Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;

/**
 * Die Teilnahme einer Variante an einer Vorverkaufs-Kampagne.
 *
 * Der Anfangsbestand ist **je Kampagne** verschieden: dasselbe Rind bringt im
 * Herbst andere Stueckzahlen als im Fruehjahr. Bisher stand er als
 * Preis.PreSaleStartInventory an der Variante -- also nur einmal, ueberschrieben
 * vom naechsten Vorverkauf, ohne Historie.
 *
 * Hier gehoert er hin: ein Datensatz je Kampagne und Variante. Damit ueberlebt
 * der Anfangsbestand einer abgelaufenen Kampagne den Start der naechsten, und
 * Auswertungen ueber mehrere Vorverkaeufe hinweg werden ueberhaupt erst
 * moeglich.
 *
 * Preis.PreSaleStartInventory bleibt als abgeleiteter Zwischenwert bestehen und
 * wird von hier aus gepflegt -- rund ein Dutzend Fundstellen lesen ihn, viele
 * davon in Templates. Er ist ab jetzt Abbild, nicht Wahrheit.
 */
class PreSale_Item extends DataObject
{
    private static $table_name = 'PreSale_Item';
    private static $singular_name = 'Teilnehmende Variante';
    private static $plural_name = 'Teilnehmende Varianten';

    private static $db = [
        'StartInventory' => 'Int',
    ];

    private static $has_one = [
        'PreSale' => PreSale::class,
        'Preis' => Preis::class,
    ];

    private static $indexes = [
        'PreSale_Preis' => ['type' => 'unique', 'columns' => ['PreSaleID', 'PreisID']],
    ];

    private static $summary_fields = [
        'Bezeichnung' => 'Teilstück',
        'StartInventory' => 'Anfang',
        'Verkauft' => 'verkauft',
        'Reserviert' => 'reserviert',
        'Uebrig' => 'Rest',
        'Anteil' => 'Anteil',
    ];

    private static $default_sort = 'PreisID ASC';

    public function getBezeichnung(): string
    {
        $preis = $this->Preis();
        if (!$preis || !$preis->exists()) {
            return '(entfallen)';
        }
        $produkt = $preis->Product();
        $name = ($produkt && $produkt->exists()) ? $produkt->Title : 'unbekannt';
        return $name . ' · ' . (int)$preis->Amount . ' g';
    }

    public function getTitle(): string
    {
        return $this->getBezeichnung();
    }

    /**
     * Die Bestellpositionen dieser Variante in dieser Kampagne.
     */
    public function Positionen()
    {
        return OrderProfileFeature_ProductContainer::get()->filter([
            'PreSaleID' => $this->PreSaleID,
            'PriceBlockElementID' => $this->PreisID,
        ]);
    }

    /** Bereits verkauft -- Positionen, die zu einer Bestellung gehoeren. */
    public function VerkaufteMenge(): int
    {
        $summe = 0;
        foreach ($this->Positionen()->filter('ClientOrderID:GreaterThan', 0) as $position) {
            $summe += (int)$position->Quantity;
        }
        return $summe;
    }

    /** Derzeit in einem lebenden Warenkorb -- siehe Reservierung::dauer(). */
    public function ReservierteMenge(): int
    {
        return Reservierung::menge(Reservierung::nurGueltige($this->Positionen()));
    }

    /** Was noch zu haben ist. */
    public function Rest(): int
    {
        return max(0, (int)$this->StartInventory - $this->VerkaufteMenge() - $this->ReservierteMenge());
    }

    /**
     * Verkaufter Anteil am Anfangsbestand, in Prozent.
     *
     * Die Groesse, an der sich Ladenhueter erkennen lassen: 2 von 2 verkauft
     * ist ausverkauft, 3 von 50 ist ein Ladenhueter.
     */
    public function AnteilVerkauft(): int
    {
        $start = (int)$this->StartInventory;
        if ($start <= 0) {
            return 0;
        }
        return (int)round($this->VerkaufteMenge() / $start * 100);
    }

    /** Fuer die Uebersicht: „78 %" */
    public function getAnteil(): string
    {
        return $this->AnteilVerkauft() . ' %';
    }

    public function getVerkauft(): int
    {
        return $this->VerkaufteMenge();
    }

    public function getReserviert(): int
    {
        return $this->ReservierteMenge();
    }

    public function getUebrig(): int
    {
        return $this->Rest();
    }

    /**
     * Haelt den abgeleiteten Wert an der Variante nach.
     *
     * Solange ein Dutzend Fundstellen Preis.PreSaleStartInventory liest, muss
     * er stimmen. Geschrieben wird nur, wenn diese Kampagne die laufende ist --
     * sonst wuerde eine nachtraegliche Korrektur an einer alten Kampagne den
     * aktuellen Vorverkauf verstellen.
     */
    public function onAfterWrite()
    {
        parent::onAfterWrite();

        $preSale = $this->PreSale();
        $preis = $this->Preis();
        if (!$preSale || !$preSale->exists() || !$preis || !$preis->exists()) {
            return;
        }
        if (!$preSale->Active) {
            return;
        }
        if ((int)$preis->PreSaleStartInventory === (int)$this->StartInventory) {
            return;
        }
        $preis->PreSaleStartInventory = (int)$this->StartInventory;
        $preis->write();
    }

    public function canView($member = null)
    {
        return PreSale::singleton()->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return $this->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }
}
