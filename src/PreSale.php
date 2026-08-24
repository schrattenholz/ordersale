<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TreeDropdownField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use Schrattenholz\Order\ProductList;
use Schrattenholz\Order\Preis;
use SilverStripe\CMS\Model\SiteTree;
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
        'Items' => PreSale_Item::class,
        'ProductContainers' => OrderProfileFeature_ProductContainer::class . '.PreSale',
    ];

    // Die Teilnehmer gehoeren zur Kampagne. Die Bestellpositionen nicht -- sie
    // gehoeren zur Bestellung und muessen eine geloeschte Kampagne ueberleben.
    private static $cascade_deletes = ['Items'];

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
        $fields->removeByName(['ProductListID', 'Items']);
        $fields->addFieldToTab('Root.Main',
            TreeDropdownField::create('ProductListID', 'Warengruppe', ProductList::class)
                ->setDescription('Die Warengruppe im Verkauf, deren Teile vorverkauft werden.'),
            'PreSaleStart');

        if ($this->isInDB()) {
            $fields->addFieldToTab('Root.Main',
                LiteralField::create('Zwischenstand', $this->zwischenstandHtml()));
            $fields->addFieldToTab('Root.Main',
                GridField::create('Items', 'Teilstücke', $this->Items(), $this->itemConfig()));
        }
        return $fields;
    }

    /**
     * Die Teilstuecke der Kampagne: Anfangsbestand aenderbar, der Rest zum
     * Ansehen. Verkauft und reserviert werden gerechnet, nicht gespeichert.
     */
    private function itemConfig(): GridFieldConfig
    {
        $config = GridFieldConfig::create()
            ->addComponent(new GridFieldButtonRow('before'))
            ->addComponent(new GridFieldTitleHeader())
            ->addComponent($spalten = new GridFieldEditableColumns())
            ->addComponent(new GridFieldDeleteAction());

        $spalten->setDisplayFields([
            'Bezeichnung' => ['title' => 'Teilstück', 'field' => ReadonlyField::class],
            'StartInventory' => [
                'title' => 'Anfang',
                'callback' => fn() => NumericField::create('StartInventory')->setAttribute('size', 4),
            ],
            'Verkauft' => ['title' => 'verkauft', 'field' => ReadonlyField::class],
            'Reserviert' => ['title' => 'reserviert', 'field' => ReadonlyField::class],
            'Uebrig' => ['title' => 'Rest', 'field' => ReadonlyField::class],
            'Anteil' => ['title' => 'Anteil', 'field' => ReadonlyField::class],
        ]);

        return $config;
    }

    /** Die Kopfzahlen der Kampagne, ueber der Teilstueck-Tabelle. */
    private function zwischenstandHtml(): string
    {
        $kachel = function ($wert, $text) {
            return '<div style="min-width:130px"><div style="font-size:20px">'
                . $wert . '</div><div class="help">' . $text . '</div></div>';
        };

        return '<div class="form__field-holder"><div style="display:flex;gap:24px;flex-wrap:wrap">'
            . $kachel($this->StartInventory(), 'Anfangsbestand')
            . $kachel($this->SoldQuantity(), 'verkauft in ' . $this->OrderCount() . ' Bestellungen')
            . $kachel($this->ReservedQuantity(), 'reserviert')
            . $kachel($this->SoldPercentageOfStart() . ' %',
                'Schwelle ' . (int)$this->PreSaleEndPercentage . ' %'
                . ($this->EndThresholdReached() ? ' — erreicht' : ''))
            . '</div></div>';
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
        // Nur lebende Warenkoerbe -- ein abgebrochener Einkauf darf die Ware
        // nicht dauerhaft als reserviert ausweisen.
        return Reservierung::nurGueltige(
            OrderProfileFeature_ProductContainer::get()->filter('PreSaleID', $this->ID)
        );
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
        foreach ($this->Items() as $item) {
            $sum += (int)$item->StartInventory;
        }
        if ($sum > 0 || $this->Items()->count()) {
            return $sum;
        }

        // Rueckfall fuer Kampagnen, die noch keine Teilnehmer haben --
        // Bestaende von vor der Umstellung.
        foreach ($this->Variants() as $preis) {
            $sum += (int)$preis->PreSaleStartInventory;
        }
        return $sum;
    }

    /**
     * Die Teilnahme einer Variante an dieser Kampagne, oder null.
     */
    public function itemFuer($preis): ?PreSale_Item
    {
        $id = is_object($preis) ? (int)$preis->ID : (int)$preis;
        if (!$id || !$this->isInDB()) {
            return null;
        }
        return PreSale_Item::get()->filter([
            'PreSaleID' => $this->ID,
            'PreisID' => $id,
        ])->first();
    }

    /**
     * Legt die Teilnehmer dieser Kampagne an -- eine Zeile je Variante der
     * Warengruppe, mit dem Anfangsbestand, der beim Start gilt.
     *
     * Vom Vorverkauf ausgenommene Varianten (Preis.NotInPresale) bleiben
     * aussen vor: sie stehen nicht im Zerlegeplan und nehmen nicht teil.
     *
     * Vorhandene Zeilen werden nicht angetastet -- ein bereits laufender
     * Vorverkauf soll seinen Anfangsbestand behalten.
     *
     * @return int Zahl der neu angelegten Teilnehmer
     */
    public function itemsAnlegen(): int
    {
        if (!$this->isInDB()) {
            return 0;
        }
        $neu = 0;
        foreach ($this->Variants() as $preis) {
            if ($preis->NotInPresale) {
                continue;
            }
            if ($this->itemFuer($preis)) {
                continue;
            }
            $item = PreSale_Item::create();
            $item->PreSaleID = $this->ID;
            $item->PreisID = $preis->ID;
            // Der Anfangsbestand kommt aus dem Zerlegeplan: die Anzahl, die bei
            // einem Tier ueblicherweise anfaellt. Ein noch stehender Wert am
            // Preis stammt womoeglich vom vorigen Vorverkauf und taugt nicht.
            $item->StartInventory = (int)$preis->PreSaleInventory;
            $item->write();
            $neu++;
        }
        return $neu;
    }

    /**
     * Alle Preis-Varianten, die zur Warengruppe dieser Kampagne gehoeren.
     */
    public function Variants()
    {
        $productList = $this->ProductList();
        if (!$productList || !$productList->exists()) {
            return Preis::get()->filter('ID', 0);
        }

        // Bewusst ueber SiteTree statt ueber Children(): Children() filtert
        // nach canView(), und ohne angemeldeten Benutzer -- in Tasks, in der
        // API, im Cron -- faellt damit jedes Produkt heraus. Die Kampagne
        // haette dann einen Anfangsbestand von 0 und ihre Endschwelle waere
        // sofort erreicht.
        $produktIDs = SiteTree::get()->filter('ParentID', $productList->ID)->column('ID');
        if (!$produktIDs) {
            return Preis::get()->filter('ID', 0);
        }
        return Preis::get()->filter('ProductID', $produktIDs);
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
