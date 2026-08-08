<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;

/**
 * Eine Portionsgroesse eines Teilstuecks -- entspricht spaeter einem Preis.
 *
 * "Anzahl pro Tier" ist die Stueckzahl dieser Portionsgroesse, die bei einem
 * Tier ueblicherweise anfaellt. Sie wird beim Uebernehmen nach
 * Preis.PreSaleInventory geschrieben und gilt beim Start eines Vorverkaufs als
 * Anfangsbestand.
 */
class ZerlegeplanVariante extends DataObject
{
    private static $table_name = 'ZerlegeplanVariante';
    private static $singular_name = 'Variante';
    private static $plural_name = 'Varianten';

    private static $db = [
        'Menge' => 'Int',
        'Einheit' => 'Enum("weight,piece","weight")',
        'AnzahlProTier' => 'Int',
        'Sort' => 'Int',
    ];

    private static $has_one = [
        'Teil' => ZerlegeplanTeil::class,
    ];

    private static $default_sort = 'Sort ASC, Menge ASC';

    private static $summary_fields = [
        'Title' => 'Portion',
        'AnzahlProTier' => 'Anzahl pro Tier',
    ];

    private static $field_labels = [
        'Menge' => 'Menge',
        'Einheit' => 'Einheit',
        'AnzahlProTier' => 'Anzahl pro Tier',
    ];

    /** Einheiten, wie sie am Preis gefuehrt werden. */
    public const EINHEITEN = [
        'weight' => 'Gramm',
        'piece' => 'Stück',
    ];

    public function getTitle(): string
    {
        $menge = (int)$this->Menge;
        return $this->Einheit === 'piece'
            ? $menge . ' Stück'
            : $menge . ' g';
    }

    public function getCMSFields()
    {
        $fields = FieldList::create(
            NumericField::create('Menge', 'Menge'),
            DropdownField::create('Einheit', 'Einheit', self::EINHEITEN),
            NumericField::create('AnzahlProTier', 'Anzahl pro Tier')
                ->setDescription('Wie viele Portionen dieser Größe bei einem Tier anfallen.')
        );
        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    /**
     * Tabelle der Varianten -- alle Felder direkt in der Zeile bearbeitbar.
     *
     * Wird sowohl im Teil selbst als auch aufgeklappt in der Teile-Tabelle
     * verwendet, damit beide Ansichten sich gleich bedienen.
     */
    public static function gridConfig(): GridFieldConfig
    {
        $config = GridFieldConfig::create()
            ->addComponent(new GridFieldButtonRow('before'))
            ->addComponent(new GridFieldTitleHeader())
            ->addComponent($spalten = new GridFieldEditableColumns())
            ->addComponent(new GridFieldOrderableRows('Sort'))
            ->addComponent(new GridFieldAddNewInlineButton())
            ->addComponent(new GridFieldDeleteAction())
            // GridFieldNestedForm setzt am Detailformular einen Rücksprung --
            // ohne die Komponente laeuft es beim Aufklappen ins Leere.
            ->addComponent(new GridFieldDetailForm());

        $spalten->setDisplayFields([
            'Menge' => [
                'title' => 'Menge',
                'callback' => fn() => NumericField::create('Menge')->setAttribute('size', 6),
            ],
            'Einheit' => [
                'title' => 'Einheit',
                'callback' => fn() => DropdownField::create('Einheit', '', self::EINHEITEN),
            ],
            'AnzahlProTier' => [
                'title' => 'Anzahl pro Tier',
                'callback' => fn() => NumericField::create('AnzahlProTier')->setAttribute('size', 4),
            ],
        ]);

        return $config;
    }

    /**
     * Wird von GridFieldNestedForm beim Aufklappen einer Teil-Zeile geholt --
     * so sehen die Varianten dort genauso aus wie im Teil selbst.
     */
    public function getNestedConfig($parentClass, $relationName): GridFieldConfig
    {
        return self::gridConfig();
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if ((int)$this->Menge <= 0) {
            $result->addError('Die Menge muss größer als 0 sein.');
        }
        if ((int)$this->AnzahlProTier < 0) {
            $result->addError('„Anzahl pro Tier“ kann nicht negativ sein.');
        }

        // Zwei gleiche Mengen am selben Teil wuerden beim Uebernehmen auf
        // denselben Preis zeigen.
        $doppelt = ZerlegeplanVariante::get()
            ->filter(['TeilID' => $this->TeilID, 'Menge' => (int)$this->Menge])
            ->exclude('ID', $this->ID ?: 0);
        if ($this->TeilID && $doppelt->count()) {
            $result->addError('Die Menge ' . (int)$this->Menge . ' kommt bei diesem Teil '
                . 'schon vor.');
        }

        return $result;
    }

    public function canView($member = null)
    {
        $teil = $this->Teil();
        return $teil && $teil->exists() ? $teil->canView($member) : Zerlegeplan::singleton()->canView($member);
    }

    public function canEdit($member = null)
    {
        return $this->canView($member);
    }

    public function canCreate($member = null, $context = [])
    {
        return Zerlegeplan::singleton()->canView($member);
    }

    public function canDelete($member = null)
    {
        return $this->canView($member);
    }
}
