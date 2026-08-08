<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\CMS\Model\SiteTree;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\ProductList;
use Schrattenholz\Order\Preis;

/**
 * Gleicht einen Zerlegeplan mit einer Warengruppe ab.
 *
 * Zwei Betriebsarten:
 *   - vorschau()    ermittelt, was sich aendern wuerde -- schreibt nichts
 *   - uebernehmen() schreibt und liefert denselben Bericht
 *
 * Der Plan gibt die Struktur vor, der Katalog behaelt alles andere: Preise,
 * Bilder und Texte werden nie angefasst. Geloescht wird ebenfalls nichts -- an
 * den Produkten haengt Bestellhistorie. Teile, die im Plan fehlen, werden ueber
 * NotInPresale abgeschaltet und bleiben vollstaendig erhalten.
 */
class ZerlegeplanImporter
{
    /** Kategorien des Berichts. */
    public const NEU = 'neu';
    public const GEAENDERT = 'geaendert';
    public const UNVERAENDERT = 'unveraendert';
    public const ABGESCHALTET = 'abgeschaltet';
    public const WIEDER_AUFGENOMMEN = 'wiederAufgenommen';

    /**
     * @param Zerlegeplan $plan Die Vorlage
     * @param ProductList|null $ziel Warengruppe, die den Plan bekommt. Bewusst
     *        von aussen vorgegeben: derselbe Plan "Schwein" soll sich auf eine
     *        Warengruppe "Molkeschwein", "Weideschwein" oder wie auch immer
     *        benannt anwenden lassen.
     */
    public function __construct(
        private Zerlegeplan $plan,
        private ?ProductList $ziel = null
    ) {
    }

    public function setZiel(?ProductList $ziel): void
    {
        $this->ziel = $ziel;
    }

    public function vorschau(): ZerlegeplanBericht
    {
        return $this->abgleichen(false);
    }

    public function uebernehmen(): ZerlegeplanBericht
    {
        return $this->abgleichen(true);
    }

    /**
     * @param bool $schreiben false = Vorschau, es wird nichts veraendert
     */
    private function abgleichen(bool $schreiben): ZerlegeplanBericht
    {
        $bericht = new ZerlegeplanBericht();
        $bericht->bezeichnung = (string)$this->plan->Title;

        $liste = $this->ziel;
        if (!$liste || !$liste->exists()) {
            $bericht->fehler = ['Es ist keine Warengruppe ausgewählt, in die der Plan '
                . 'eingespielt werden soll.'];
            return $bericht;
        }
        if (!$this->plan->isInDB() || !$this->plan->Teile()->count()) {
            $bericht->fehler = ['Der Zerlegeplan „' . $this->plan->Title . '“ enthält '
                . 'keine Teile.'];
            return $bericht;
        }

        $bericht->warengruppe = $liste->Title;

        // Der Titel der Warengruppe wird bewusst nicht angetastet: der Plan
        // beschreibt die Tierart ("Schwein"), die Warengruppe traegt den Namen,
        // unter dem verkauft wird ("Molkeschwein").

        $imPlan = [];
        $sort = 0;
        foreach ($this->plan->Teile() as $teil) {
            $sort++;
            $imPlan[$teil->Segment] = true;

            $produkt = Product::get()->filter([
                'URLSegment' => $teil->Segment,
                'ParentID' => $liste->ID,
            ])->first();

            if (!$produkt) {
                $bericht->add(self::NEU, 'Produkt', $teil->Title, 'wird angelegt');
                if ($schreiben) {
                    $produkt = Product::create();
                    $produkt->Title = $teil->Title;
                    $produkt->URLSegment = $teil->Segment;
                    $produkt->ParentID = $liste->ID;
                    $produkt->Sort = $sort;
                    $produkt->write();
                    $produkt->publishSingle();
                }
            } elseif ($produkt->Title !== $teil->Title) {
                $bericht->add(self::GEAENDERT, 'Produkt', $teil->Title,
                    'Titel: "' . $produkt->Title . '" → "' . $teil->Title . '"');
                if ($schreiben) {
                    $produkt->Title = $teil->Title;
                    $produkt->write();
                    $produkt->publishSingle();
                }
            } else {
                $bericht->add(self::UNVERAENDERT, 'Produkt', $teil->Title, '');
            }

            if (!$produkt) {
                // Vorschau: Produkt gibt es noch nicht, Varianten sind alle neu
                foreach ($teil->Varianten() as $variante) {
                    $bericht->add(self::NEU, 'Variante',
                        $teil->Title . ' · ' . $variante->getTitle(),
                        'Anzahl pro Tier ' . (int)$variante->AnzahlProTier);
                }
                continue;
            }

            $this->varianten($teil, $produkt, $bericht, $schreiben);
        }

        $this->abschalten($liste, $imPlan, $bericht, $schreiben);
        return $bericht;
    }

    /** Varianten eines Teils abgleichen. */
    private function varianten(
        ZerlegeplanTeil $teil,
        Product $produkt,
        ZerlegeplanBericht $b,
        bool $schreiben
    ): void {
        $mengenImPlan = [];
        foreach ($teil->Varianten() as $variante) {
            $menge = (int)$variante->Menge;
            $anzahl = (int)$variante->AnzahlProTier;
            $mengenImPlan[$menge] = true;
            $name = $teil->Title . ' · ' . $variante->getTitle();

            $preis = Preis::get()->filter(['ProductID' => $produkt->ID, 'Amount' => $menge])->first();

            if (!$preis) {
                $b->add(self::NEU, 'Variante', $name, 'Anzahl pro Tier ' . $anzahl);
                if ($schreiben) {
                    $preis = Preis::create();
                    $preis->ProductID = $produkt->ID;
                    $preis->Amount = $menge;
                    $preis->Unit = $variante->Einheit;
                    $preis->PreSaleInventory = $anzahl;
                    $preis->write();
                }
                continue;
            }

            $aenderungen = [];
            if ((int)$preis->PreSaleInventory !== $anzahl) {
                $aenderungen[] = 'Anzahl pro Tier ' . (int)$preis->PreSaleInventory . ' → ' . $anzahl;
            }
            if ($preis->Unit !== $variante->Einheit) {
                $aenderungen[] = 'Einheit ' . $preis->Unit . ' → ' . $variante->Einheit;
            }
            if ($preis->NotInPresale) {
                $aenderungen[] = 'wieder aufgenommen';
            }

            if (!$aenderungen) {
                $b->add(self::UNVERAENDERT, 'Variante', $name, '');
                continue;
            }

            $kategorie = $preis->NotInPresale ? self::WIEDER_AUFGENOMMEN : self::GEAENDERT;
            $b->add($kategorie, 'Variante', $name, implode(', ', $aenderungen));
            if ($schreiben) {
                $preis->PreSaleInventory = $anzahl;
                $preis->Unit = $variante->Einheit;
                $preis->NotInPresale = false;
                $preis->write();
            }
        }

        // Varianten dieses Produkts, die im Plan fehlen
        foreach (Preis::get()->filter('ProductID', $produkt->ID) as $preis) {
            if (isset($mengenImPlan[(int)$preis->Amount]) || $preis->NotInPresale) {
                continue;
            }
            $b->add(self::ABGESCHALTET, 'Variante',
                $produkt->Title . ' · ' . (int)$preis->Amount . ' g',
                'nicht im Plan → vom Vorverkauf ausgeschlossen');
            if ($schreiben) {
                $preis->NotInPresale = true;
                $preis->write();
            }
        }
    }

    /**
     * Produkte der Warengruppe, die im Plan fehlen.
     *
     * Es wird nichts geloescht -- an den Produkten haengt Bestellhistorie.
     * Stattdessen werden ihre Varianten ueber NotInPresale abgeschaltet.
     */
    private function abschalten(ProductList $liste, array $imPlan, ZerlegeplanBericht $b, bool $schreiben): void
    {
        foreach (SiteTree::get()->filter('ParentID', $liste->ID) as $produkt) {
            if (isset($imPlan[$produkt->URLSegment])) {
                continue;
            }
            $varianten = Preis::get()->filter('ProductID', $produkt->ID);
            $offen = $varianten->filter('NotInPresale', false);

            if (!$varianten->count()) {
                // Produkt ohne Variante -- nichts abzuschalten, nur melden
                $b->add(self::ABGESCHALTET, 'Produkt', $produkt->Title,
                    'nicht im Plan, hat keine Varianten');
                continue;
            }
            if (!$offen->count()) {
                continue; // bereits vollstaendig abgeschaltet
            }

            $alle = $offen->count() === $varianten->count();
            $b->add(self::ABGESCHALTET, 'Produkt', $produkt->Title,
                $alle
                    ? 'nicht im Plan → vollständig abgeschaltet (' . $offen->count() . ' Varianten)'
                    : 'nicht im Plan → ' . $offen->count() . ' von ' . $varianten->count() . ' Varianten abgeschaltet');

            if ($schreiben) {
                foreach ($offen as $preis) {
                    $preis->NotInPresale = true;
                    $preis->write();
                }
            }
        }
    }
}
