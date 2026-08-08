<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\ORM\DataObject;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\View\Parsers\URLSegmentFilter;

/**
 * Ein Teilstueck im Zerlegeplan -- entspricht spaeter einem Produkt.
 *
 * Das Segment ist der stille Schluessel: an ihm erkennt der Abgleich ein
 * bereits vorhandenes Produkt wieder. Es wird aus dem Titel vorgeschlagen,
 * bleibt aber aenderbar -- wer ein Teil umbenennt ("Gulasch" -> "Gulasch vom
 * Bug"), will in aller Regel dasselbe Produkt behalten und nicht ein zweites
 * anlegen.
 */
class ZerlegeplanTeil extends DataObject
{
    private static $table_name = 'ZerlegeplanTeil';
    private static $singular_name = 'Teil';
    private static $plural_name = 'Teile';

    private static $db = [
        'Title' => 'Varchar(255)',
        'Segment' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static $has_one = [
        'Zerlegeplan' => Zerlegeplan::class,
    ];

    private static $has_many = [
        'Varianten' => ZerlegeplanVariante::class,
    ];

    private static $cascade_deletes = ['Varianten'];

    private static $default_sort = 'Sort ASC, ID ASC';

    private static $summary_fields = [
        'Title' => 'Teil',
        'Segment' => 'Segment',
        'VariantenKurz' => 'Varianten',
    ];

    private static $field_labels = [
        'Title' => 'Teil',
        'Segment' => 'Segment',
    ];

    /** Kurzfassung der Varianten fuer die Uebersicht, etwa "400 g · 800 g". */
    public function getVariantenKurz(): string
    {
        $teile = [];
        foreach ($this->Varianten() as $v) {
            $teile[] = $v->getTitle();
        }
        return $teile ? implode(' · ', $teile) : '—';
    }

    public function getCMSFields()
    {
        $fields = FieldList::create(
            TextField::create('Title', 'Teil'),
            TextField::create('Segment', 'Segment')
                ->setDescription('Wird aus dem Titel vorgeschlagen. Daran erkennt der '
                    . 'Abgleich ein vorhandenes Produkt wieder — nur ändern, wenn das '
                    . 'Produkt im Verkauf wirklich ein anderes sein soll.')
        );

        if ($this->isInDB()) {
            $fields->push(GridField::create('Varianten', 'Varianten',
                $this->Varianten(), ZerlegeplanVariante::gridConfig()));
        }

        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();
        if (!$this->Segment && $this->Title) {
            $this->Segment = URLSegmentFilter::create()->filter($this->Title);
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if (!trim((string)$this->Title)) {
            $result->addError('Das Teil braucht einen Titel.');
            return $result;
        }

        // Zwei Teile mit demselben Segment wuerden beim Uebernehmen auf dasselbe
        // Produkt zeigen -- das zweite wuerde das erste stillschweigend wieder
        // ueberschreiben.
        $segment = $this->Segment ?: URLSegmentFilter::create()->filter($this->Title);
        $doppelt = ZerlegeplanTeil::get()
            ->filter(['ZerlegeplanID' => $this->ZerlegeplanID, 'Segment' => $segment])
            ->exclude('ID', $this->ID ?: 0);
        if ($doppelt->count()) {
            $result->addError('Das Segment „' . $segment . '“ ist in diesem Zerlegeplan '
                . 'schon vergeben (' . $doppelt->first()->Title . ').');
        }

        return $result;
    }

    public function canView($member = null)
    {
        $plan = $this->Zerlegeplan();
        return $plan && $plan->exists() ? $plan->canView($member) : Zerlegeplan::singleton()->canView($member);
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
