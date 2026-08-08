<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\ORM\DataObject;
use SilverStripe\Assets\File;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use Symbiote\GridFieldExtensions\GridFieldNestedForm;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use SilverStripe\Security\Permission;

/**
 * Ein Zerlegeplan: welche Teilstuecke bei einer Tierart anfallen und in
 * welchen Portionsgroessen.
 *
 * Der Plan wird im CMS gepflegt -- er ist die Vorlage, aus der eine
 * Warengruppe im Verkauf ihre Produkte und Varianten bekommt. Eine YAML-Datei
 * dient nur der Erstbefuellung; danach ist der Datensatz massgeblich, damit
 * der Metzger seinen Plan ohne Umweg ueber Dateien anpassen kann.
 */
class Zerlegeplan extends DataObject
{
    private static $table_name = 'Zerlegeplan';
    private static $singular_name = 'Zerlegeplan';
    private static $plural_name = 'Zerlegepläne';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Notiz' => 'Text',
    ];

    private static $has_one = [
        // Nur fuer die Erstbefuellung -- siehe ausDateiFuellen()
        'Datei' => File::class,
    ];

    private static $has_many = [
        'Teile' => ZerlegeplanTeil::class,
    ];

    private static $owns = ['Datei'];
    private static $cascade_deletes = ['Teile'];

    private static $summary_fields = [
        'Title' => 'Zerlegeplan',
        'AnzahlTeile' => 'Teile',
        'AnzahlVarianten' => 'Varianten',
        'LastEdited.Nice' => 'Zuletzt geändert',
    ];

    private static $default_sort = 'Title ASC';

    /** Ordner fuer hochgeladene Dateien -- geschuetzt, nicht oeffentlich abrufbar. */
    public const ORDNER = 'Zerlegeplaene';

    public function getAnzahlTeile(): int
    {
        return $this->Teile()->count();
    }

    public function getAnzahlVarianten(): int
    {
        return ZerlegeplanVariante::get()
            ->filter('TeilID', $this->Teile()->column('ID') ?: [0])
            ->count();
    }

    public function getCMSFields()
    {
        $fields = FieldList::create();

        $fields->push(TextField::create('Title', 'Zerlegeplan')
            ->setDescription('Die Tierart, etwa „Rind“ oder „Schwein“. Die Warengruppe '
                . 'im Verkauf darf anders heißen — „Molkeschwein“ etwa.'));

        $fields->push(TextareaField::create('Notiz', 'Notiz')
            ->setRows(2)
            ->setDescription('Freiwillig — etwa woher die Zahlen stammen.'));

        if ($this->isInDB()) {
            $fields->push(GridField::create('Teile', 'Teile', $this->Teile(), $this->teileConfig()));
        } else {
            $fields->push(LiteralField::create('Hinweis',
                '<p class="message notice">Nach dem Speichern lassen sich die Teile '
                . 'anlegen — oder mit einer Zerlegeplan-Datei auf einen Schlag füllen.</p>'));
        }

        $upload = UploadField::create('Datei', 'Erstbefüllung aus Datei (YAML)');
        $upload->setFolderName(self::ORDNER);
        $upload->getValidator()->setAllowedExtensions(['yml', 'yaml']);
        $upload->setDescription(
            'Nur zum erstmaligen Befüllen eines leeren Plans. Ein bereits gefüllter '
            . 'Plan bleibt unangetastet — dafür gibt es „Aus Datei neu befüllen“.'
        );
        $fields->push($upload);

        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    /**
     * Die Teile-Tabelle: Titel und Segment direkt in der Zeile, die Varianten
     * eine Ebene darunter zum Aufklappen -- ohne die Ansicht zu verlassen.
     */
    private function teileConfig(): GridFieldConfig
    {
        $config = GridFieldConfig::create()
            ->addComponent(new GridFieldButtonRow('before'))
            ->addComponent(new GridFieldTitleHeader())
            ->addComponent($spalten = new GridFieldEditableColumns())
            ->addComponent(new GridFieldOrderableRows('Sort'))
            ->addComponent(new GridFieldAddNewInlineButton())
            ->addComponent(new GridFieldDeleteAction())
            ->addComponent($varianten = new GridFieldNestedForm('Varianten'));

        $spalten->setDisplayFields([
            'Title' => [
                'title' => 'Teil',
                'callback' => fn() => TextField::create('Title'),
            ],
            'Segment' => [
                'title' => 'Segment',
                'callback' => fn() => TextField::create('Segment')
                    ->setAttribute('placeholder', 'wird aus dem Titel gebildet'),
            ],
        ]);

        // Die Tabelle der aufgeklappten Varianten liefert ZerlegeplanVariante
        // selbst ueber getNestedConfig() -- dieselbe wie im Teil.
        $varianten->setRelationName('Varianten');
        $varianten->setInlineEditable(true);

        return $config;
    }

    /** Inhalt der hinterlegten Datei, oder null. */
    public function inhalt(): ?string
    {
        $datei = $this->Datei();
        if (!$datei || !$datei->exists()) {
            return null;
        }
        return $datei->getString();
    }

    /**
     * Fuellt den Plan aus der hinterlegten Datei.
     *
     * @param bool $ersetzen Vorhandene Teile vorher entfernen
     * @return string[] Fehler; leer bedeutet: hat geklappt
     */
    public function ausDateiFuellen(bool $ersetzen = false): array
    {
        $inhalt = $this->inhalt();
        if ($inhalt === null) {
            return ['Es ist keine Datei hinterlegt.'];
        }

        $datei = new ZerlegeplanDatei($inhalt);
        $fehler = $datei->pruefen();
        if ($fehler) {
            return $fehler;
        }

        if ($ersetzen) {
            foreach ($this->Teile() as $teil) {
                $teil->delete();
            }
        }

        $datei->fuellen($this);
        return [];
    }

    /** Vollstaendige Kopie samt Teilen und Varianten. */
    public function kopieren(): Zerlegeplan
    {
        $kopie = Zerlegeplan::create();
        $kopie->Title = $this->Title . ' (Kopie)';
        $kopie->Notiz = $this->Notiz;
        $kopie->write();

        foreach ($this->Teile() as $teil) {
            $neu = ZerlegeplanTeil::create();
            $neu->Title = $teil->Title;
            $neu->Segment = $teil->Segment;
            $neu->Sort = $teil->Sort;
            $neu->ZerlegeplanID = $kopie->ID;
            $neu->write();

            foreach ($teil->Varianten() as $variante) {
                $v = ZerlegeplanVariante::create();
                $v->Menge = $variante->Menge;
                $v->Einheit = $variante->Einheit;
                $v->AnzahlProTier = $variante->AnzahlProTier;
                $v->Sort = $variante->Sort;
                $v->TeilID = $neu->ID;
                $v->write();
            }
        }

        return $kopie;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->Title && $this->Datei()->exists()) {
            $this->Title = pathinfo($this->Datei()->Name, PATHINFO_FILENAME);
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        // Erstbefuellung: eine frisch hinterlegte Datei fuellt einen leeren
        // Plan von selbst. Ein gefuellter Plan bleibt unberuehrt -- die
        // Handarbeit des Metzgers wiegt schwerer als die Datei.
        if ($this->isChanged('DateiID') && !$this->Teile()->count()) {
            $this->ausDateiFuellen(false);
        }
    }

    public function canView($member = null)
    {
        return Permission::check('CMS_ACCESS_ZerlegeplanAdmin', 'any', $member);
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
