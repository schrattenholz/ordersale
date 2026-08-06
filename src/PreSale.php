<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\ReadonlyField;
use Schrattenholz\Order\ProductList;
use Schrattenholz\Order\Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\ArrayData;

/**
 * Eine Vorverkaufs-Kampagne.
 *
 * Bisher steckten die Vorverkaufs-Angaben ausschliesslich als Zustandsfelder am
 * Katalog (ProductList/Product/Preis). Dadurch liess sich nach einem Kauf nicht
 * mehr feststellen, ob eine Bestellposition aus einem Vorverkauf stammte -- die
 * einzige Zuordnung war eine Rueckrechnung ueber das Anlagedatum, die bei einem
 * Reset oder einem zweiten Vorverkauf zusammenbricht.
 *
 * Dieses DataObject macht die Kampagne zu einem eigenen Datensatz. Jede
 * Bestellposition haelt ueber PreSaleID fest, zu welcher Kampagne sie gehoert --
 * dauerhaft und unabhaengig davon, was spaeter im Katalog passiert.
 */
class PreSale extends DataObject
{
    private static $table_name = 'PreSale';
    private static $singular_name = 'Vorverkauf';
    private static $plural_name = 'Vorverkäufe';

    private static $db = [
        'Title' => 'Varchar(255)',
        'PreSaleStart' => 'Date',
        'PreSaleEnd' => 'Date',
        // Anteil des Gesamtbestands, der verkauft sein muss, damit der
        // Vorverkauf endet. Werte wie im bestehenden Feld auf ProductList.
        'PreSaleEndPercentage' => 'Enum("25,50,75,100","100")',
        'Active' => 'Boolean',
    ];

    private static $has_one = [
        'ProductList' => ProductList::class,
    ];

    private static $has_many = [
        'ProductContainers' => OrderProfileFeature_ProductContainer::class . '.PreSale',
    ];

    private static $summary_fields = [
        'Title' => 'Bezeichnung',
        'ProductList.Title' => 'Warengruppe',
        'PreSaleStart' => 'Start',
        'PreSaleEnd' => 'Ende',
        'PreSaleEndPercentage' => 'Endet bei %',
        'Active.Nice' => 'Aktiv',
    ];

    private static $default_sort = 'PreSaleStart DESC';

    // Die Bestellpositionen haengen als eigener Tab dran, nicht noetig sie
    // zusaetzlich automatisch zu scaffolden.
    private static array $scaffold_cms_fields_settings = [
        'ignoreRelations' => ['ProductContainers'],
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['ProductContainers']);
        $fields->addFieldToTab('Root.Main', new TextField('Title', 'Bezeichnung'));
        $fields->addFieldToTab('Root.Main', new DateField('PreSaleStart', 'Beginn'));
        $fields->addFieldToTab('Root.Main', new DateField('PreSaleEnd', 'Ende'));
        $fields->addFieldToTab('Root.Main', DropdownField::create(
            'PreSaleEndPercentage',
            'Vorverkauf endet, wenn verkauft (in Prozent)',
            singleton(PreSale::class)->dbObject('PreSaleEndPercentage')->enumValues()
        ));
        $fields->addFieldToTab('Root.Main', new CheckboxField('Active', 'Aktiv'));
        if ($this->isInDB()) {
            $fields->addFieldToTab('Root.Main', ReadonlyField::create(
                'SoldSummary',
                'Bisher verkauft',
                $this->SoldQuantity() . ' Stück in ' . $this->OrderCount() . ' Bestellungen'
            ));
        }
        return $fields;
    }

    /**
     * Die derzeit gueltige Kampagne einer Warengruppe.
     */
    public static function activeFor($productListID)
    {
        if (!$productListID) {
            return null;
        }
        return PreSale::get()->filter([
            'ProductListID' => (int)$productListID,
            'Active' => true,
        ])->sort('PreSaleStart DESC')->first();
    }

    /**
     * Alle Bestellpositionen dieser Kampagne, die wirklich gekauft wurden
     * (im Gegensatz zu denen, die noch in einem Warenkorb liegen).
     */
    public function SoldContainers()
    {
        return OrderProfileFeature_ProductContainer::get()->filter([
            'PreSaleID' => $this->ID,
            'ClientOrderID:GreaterThan' => 0,
        ]);
    }

    /**
     * Positionen, die aktuell reserviert sind (liegen in einem Warenkorb).
     */
    public function ReservedContainers()
    {
        return OrderProfileFeature_ProductContainer::get()->filter([
            'PreSaleID' => $this->ID,
            'BasketID:GreaterThan' => 0,
        ]);
    }

    public function SoldQuantity()
    {
        $sum = 0;
        foreach ($this->SoldContainers() as $pc) {
            $sum += (int)$pc->Quantity;
        }
        return $sum;
    }

    public function ReservedQuantity()
    {
        $sum = 0;
        foreach ($this->ReservedContainers() as $pc) {
            $sum += (int)$pc->Quantity;
        }
        return $sum;
    }

    public function OrderCount()
    {
        $ids = [];
        foreach ($this->SoldContainers() as $pc) {
            $ids[$pc->ClientOrderID] = true;
        }
        return count($ids);
    }

    /**
     * Gesamter Anfangsbestand aller Varianten der Warengruppe.
     */
    public function StartInventory()
    {
        $sum = 0;
        foreach ($this->Variants() as $preis) {
            $sum += (int)$preis->PreSaleStartInventory;
        }
        return $sum;
    }

    /**
     * Alle Preis-Varianten, die zur Warengruppe dieser Kampagne gehoeren.
     */
    public function Variants()
    {
        $list = ArrayList::create();
        $productList = $this->ProductList();
        if (!$productList || !$productList->exists()) {
            return $list;
        }
        foreach ($productList->Children() as $product) {
            foreach (Preis::get()->filter('ProductID', $product->ID) as $preis) {
                $list->push($preis);
            }
        }
        return $list;
    }

    /**
     * Verkaufsanteil am Anfangsbestand -- die Groesse, an der
     * PreSaleEndPercentage haengt.
     */
    public function SoldPercentageOfStart()
    {
        $start = $this->StartInventory();
        if ($start <= 0) {
            return 0;
        }
        return round($this->SoldQuantity() / $start * 100);
    }

    /**
     * Ist die konfigurierte Endschwelle erreicht?
     */
    public function EndThresholdReached()
    {
        return $this->SoldPercentageOfStart() >= (int)$this->PreSaleEndPercentage;
    }

    public function getTitle()
    {
        return $this->getField('Title') ?: ('Vorverkauf #' . $this->ID);
    }
}
