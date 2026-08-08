<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * Verbindet eine Warengruppe mit einem Zerlegeplan.
 *
 * Die Warengruppe merkt sich, nach welchem Plan sie aufgebaut ist. Damit laesst
 * sich ein spaeter geaenderter Plan jederzeit nachziehen, und im CMS ist auf
 * einen Blick zu sehen, woher die Produkte stammen.
 *
 * Ausgeloest wird ueber zwei Kaestchen und das normale „Speichern“ -- dieselbe
 * Bedienung wie beim vorhandenen „Vorverkauf beenden“, statt eigener
 * Schaltflaechen, die im Seitenbaum an anderer Stelle sitzen wuerden.
 */
class ProductListZerlegeplanExtension extends Extension
{
    private static $db = [
        'ZerlegeplanVorschau' => 'Boolean',
        'ZerlegeplanAnwenden' => 'Boolean',
        'ZerlegeplanProtokoll' => 'Text',
    ];

    private static $has_one = [
        'Zerlegeplan' => Zerlegeplan::class,
    ];

    public function updateCMSFields($fields)
    {
        $plaene = Zerlegeplan::get()->map('ID', 'Title')->toArray();

        $auswahl = DropdownField::create('ZerlegeplanID', 'Zerlegeplan', $plaene)
            ->setEmptyString('— keiner —')
            ->setDescription('Die Vorlage, nach der die Teile dieser Warengruppe '
                . 'angelegt werden. Gepflegt werden die Pläne links unter „Zerlegepläne“.');
        $fields->addFieldToTab('Root.Zerlegeplan', $auswahl);

        $fields->addFieldToTab('Root.Zerlegeplan',
            CheckboxField::create('ZerlegeplanVorschau', 'Vorschau erstellen')
                ->setDescription('Beim Speichern wird nur berichtet, was sich ändern würde. '
                    . 'Es wird nichts geschrieben.'));

        $fields->addFieldToTab('Root.Zerlegeplan',
            CheckboxField::create('ZerlegeplanAnwenden', 'Zerlegeplan übernehmen')
                ->setDescription('Beim Speichern werden die Teile angelegt beziehungsweise '
                    . 'aktualisiert. Nichts wird gelöscht — fehlende Teile werden nur vom '
                    . 'Vorverkauf ausgeschlossen.'));

        if ($this->getOwner()->ZerlegeplanProtokoll) {
            $fields->addFieldToTab('Root.Zerlegeplan', LiteralField::create('ZerlegeplanBerichtBlock',
                '<div class="zerlegeplan-block"><h4>Letzter Bericht</h4><pre>'
                . htmlspecialchars($this->getOwner()->ZerlegeplanProtokoll) . '</pre></div>'));
        }
    }

    /**
     * Der Abgleich laeuft nach dem Speichern, damit eine soeben getroffene
     * Auswahl schon gilt.
     */
    public function onAfterWrite()
    {
        $owner = $this->getOwner();
        $vorschau = (bool)$owner->getField('ZerlegeplanVorschau');
        $anwenden = (bool)$owner->getField('ZerlegeplanAnwenden');

        if (!$vorschau && !$anwenden) {
            return;
        }

        $plan = $owner->Zerlegeplan();
        if (!$plan || !$plan->exists()) {
            $this->festhalten('Es ist kein Zerlegeplan ausgewählt.');
            return;
        }

        $importer = new ZerlegeplanImporter($plan, $owner);
        // Übernehmen schlaegt Vorschau -- wer beides ankreuzt, meint das Schreiben.
        $bericht = $anwenden ? $importer->uebernehmen() : $importer->vorschau();

        $kopf = $anwenden
            ? 'ÜBERNOMMEN am ' . date('d.m.Y H:i')
            : 'VORSCHAU vom ' . date('d.m.Y H:i') . ' — es wurde nichts geschrieben';

        $this->festhalten($kopf . "\n\n" . $bericht->alsText());
    }

    /**
     * Schreibt den Bericht weg und raeumt die Kaestchen ab.
     *
     * Bewusst als SQL-Update: ein write() aus onAfterWrite heraus wuerde sich
     * selbst wieder aufrufen.
     */
    private function festhalten(string $text): void
    {
        $owner = $this->getOwner();
        $owner->setField('ZerlegeplanProtokoll', $text);
        $owner->setField('ZerlegeplanVorschau', false);
        $owner->setField('ZerlegeplanAnwenden', false);

        SQLUpdate::create('"ProductList"')
            ->addWhere(['"ID"' => $owner->ID])
            ->addAssignments([
                '"ZerlegeplanProtokoll"' => $text,
                '"ZerlegeplanVorschau"' => 0,
                '"ZerlegeplanAnwenden"' => 0,
            ])
            ->execute();
    }
}
