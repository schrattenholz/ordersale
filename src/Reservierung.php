<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\FieldType\DBDatetime;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientContainer;

/**
 * Wie lange Ware im Warenkorb reserviert bleibt -- und was daraus folgt.
 *
 * Legt ein Kunde etwas in den Warenkorb, ist die Ware fuer andere gesperrt.
 * Bricht er den Einkauf ab, muss sie wieder frei werden. Das geschieht
 * absichtlich **rechnerisch** und nicht durch Aufraeumen: eine Reservierung
 * gilt nur, solange ihr Warenkorb juenger ist als die hier festgelegte Frist.
 *
 * Damit haengt die Richtigkeit an der Abfrage und nicht an einem Cron. Faellt
 * das Aufraeumen aus, bleiben nur unaufgeraeumte Datensaetze zurueck -- nie
 * faelschlich gesperrte Ware.
 *
 * Die Frist laesst sich je Installation setzen:
 *
 *     Schrattenholz\OrderSale\Reservierung:
 *       dauer_in_minuten: 20
 */
class Reservierung
{
    use Configurable;

    /**
     * Frist in Minuten, gerechnet ab der letzten Aenderung am Warenkorb.
     */
    private static int $dauer_in_minuten = 11;

    public static function dauer(): int
    {
        return (int)static::config()->get('dauer_in_minuten');
    }

    /**
     * Warenkoerbe, die seit diesem Zeitpunkt nicht mehr angefasst wurden,
     * gelten als aufgegeben.
     */
    public static function grenze(): string
    {
        // Ueber DBDatetime, damit sich die Zeit in Tests verschieben laesst.
        $jetzt = DBDatetime::now()->getTimestamp();
        return date('Y-m-d H:i:s', strtotime('-' . static::dauer() . ' minutes', $jetzt));
    }

    /**
     * Aus einer Liste von Bestellpositionen nur die noch gueltigen
     * Reservierungen -- also die, die in einem lebenden Warenkorb liegen.
     */
    public static function nurGueltige(DataList $positionen): DataList
    {
        return $positionen->filter([
            'BasketID:GreaterThan' => 0,
            'Basket.LastEdited:GreaterThanOrEqual' => static::grenze(),
        ]);
    }

    /**
     * Alle derzeit gueltigen Reservierungen, ueber alle Warenkoerbe hinweg.
     */
    public static function alle(): DataList
    {
        return static::nurGueltige(OrderProfileFeature_ProductContainer::get());
    }

    /**
     * Gueltige Reservierungen einer Variante beziehungsweise eines Produkts.
     *
     * @param int $produktID
     * @param int|null $varianteID null fuer Produkte ohne Varianten
     */
    public static function fuer(int $produktID, ?int $varianteID = null): DataList
    {
        $filter = ['ProductID' => $produktID];
        if ($varianteID) {
            $filter['PriceBlockElementID'] = $varianteID;
        }
        return static::alle()->filter($filter);
    }

    /** Summe der reservierten Stueckzahl. */
    public static function menge(DataList $positionen): int
    {
        $summe = 0;
        foreach ($positionen as $position) {
            $summe += (int)$position->Quantity;
        }
        return $summe;
    }

    /**
     * Entfernt aufgegebene Warenkoerbe samt ihrer Positionen.
     *
     * Reines Aufraeumen: welche Ware frei ist, entscheidet die Frist in der
     * Abfrage (siehe nurGueltige()). Faellt dieser Lauf aus, bleiben nur
     * Datensaetze liegen -- gesperrt wird dadurch nichts.
     *
     * @return array{warenkoerbe:int, positionen:int, verwaiste:int}
     */
    public static function aufraeumen(): array
    {
        $grenze = static::grenze();
        $bericht = ['warenkoerbe' => 0, 'positionen' => 0, 'verwaiste' => 0];

        foreach (OrderProfileFeature_Basket::get()->filter('LastEdited:LessThan', $grenze) as $korb) {
            foreach ($korb->ProductContainers() as $position) {
                $position->delete();
                $bericht['positionen']++;
            }
            if ($korb->ClientContainerID > 0) {
                $kunde = OrderProfileFeature_ClientContainer::get()->byID($korb->ClientContainerID);
                if ($kunde) {
                    $kunde->delete();
                }
            }
            $korb->delete();
            $bericht['warenkoerbe']++;
        }

        // Positionen, deren Warenkorb es nicht mehr gibt und die zu keiner
        // Bestellung gehoeren -- sonst blieben sie unauffindbar liegen.
        $verwaiste = OrderProfileFeature_ProductContainer::get()->filter([
            'LastEdited:LessThan' => $grenze,
            'ClientOrderID' => 0,
        ]);
        foreach ($verwaiste as $position) {
            if (!OrderProfileFeature_Basket::get()->byID($position->BasketID)) {
                $position->delete();
                $bericht['verwaiste']++;
            }
        }

        return $bericht;
    }
}
