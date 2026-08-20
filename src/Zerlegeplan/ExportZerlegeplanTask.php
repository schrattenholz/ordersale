<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Dev\BuildTask;
use SilverStripe\CMS\Model\SiteTree;
use Schrattenholz\Order\ProductList;
use Schrattenholz\Order\Preis;
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\InputOption;

/**
 * Schreibt eine Warengruppe als Zerlegeplan heraus.
 *
 * Gegenstueck zum Import: aus einer bestehenden Warengruppe entsteht eine
 * Datei, die sich anpassen und wieder einspielen laesst. Damit ist eine
 * gepflegte Warengruppe zugleich die Vorlage fuer die naechste Tierart.
 *
 * Bewusst nur Struktur und Vorverkaufs-Mengen -- Preise, Bilder und Texte
 * gehoeren nicht in den Plan, sie werden im CMS gepflegt und duerfen von
 * einem Plan-Update nicht ueberschrieben werden.
 *
 * Aufruf: sake tasks:ExportZerlegeplanTask --list=126
 */
class ExportZerlegeplanTask extends BuildTask
{
    protected static string $commandName = 'zerlegeplan-export';
    protected static string $description = 'Warengruppe als Zerlegeplan-Datei herausschreiben';

    protected string $title = 'Warengruppe als Zerlegeplan exportieren';

    public function getOptions(): array
    {
        return [
            new InputOption('list', null, InputOption::VALUE_REQUIRED,
                'ID der Warengruppe; ohne Angabe werden alle mit Produkten ausgegeben'),
            new InputOption('dir', null, InputOption::VALUE_REQUIRED,
                'Zielverzeichnis (Vorgabe: zerlegeplaene/ neben dem Projekt)'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $dir = $input->getOption('dir') ?: (dirname(BASE_PATH) . '/zerlegeplaene');
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            $output->writeln('<error>Verzeichnis nicht anlegbar: ' . $dir . '</error>');
            return 1;
        }

        $listId = $input->getOption('list');
        $lists = $listId
            ? ProductList::get()->filter('ID', (int)$listId)
            : ProductList::get();

        $anzahl = 0;
        foreach ($lists as $list) {
            $produkte = SiteTree::get()->filter('ParentID', $list->ID);
            if (!$produkte->count()) {
                continue;
            }
            $datei = $dir . '/' . $list->URLSegment . '.yml';
            file_put_contents($datei, $this->buildYaml($list, $produkte));
            @chmod($datei, 0666);
            $output->writeln(sprintf('  %-22s %2d Produkte  ->  %s',
                $list->Title, $produkte->count(), basename($datei)));
            $anzahl++;
        }

        if (!$anzahl) {
            $output->writeln('<comment>Keine Warengruppe mit Produkten gefunden.</comment>');
            return 0;
        }
        $output->writeln('');
        $output->writeln($anzahl . ' Zerlegeplan/-plaene geschrieben nach ' . $dir);
        return 0;
    }

    private function buildYaml(ProductList $list, $produkte): string
    {
        $y  = "# Zerlegeplan \"" . $list->Title . "\"\n";
        $y .= "# Erzeugt am " . date('d.m.Y H:i') . " aus der laufenden Installation.\n";
        $y .= "#\n";
        $y .= "# anzahlProTier ist die Stueckzahl, die bei einem Tier ueblicherweise\n";
        $y .= "# anfaellt. Sie wird beim Import nach PreSaleInventory geschrieben und\n";
        $y .= "# gilt beim Start eines Vorverkaufs als Anfangsbestand.\n";
        $y .= "#\n";
        $y .= "# Varianten, die hier fehlen, werden beim Import nicht geloescht,\n";
        $y .= "# sondern ueber NotInPresale abgeschaltet und bleiben erhalten.\n";
        $y .= "#\n";
        $y .= "# Die Bezeichnung beschreibt nur die Tierart. In welche Warengruppe der\n";
        $y .= "# Plan eingespielt wird, wird im CMS am Zerlegeplan ausgewaehlt --\n";
        $y .= "# derselbe Plan passt so auf \"Schwein\", \"Molkeschwein\" und andere.\n";
        $y .= "\n";
        $y .= "zerlegeplan:\n";
        $y .= "  bezeichnung: " . $this->q($list->Title) . "\n";
        $y .= "\n";
        $y .= "produkte:\n";

        foreach ($produkte->sort('Sort') as $p) {
            $y .= "  - titel: " . $this->q($p->Title) . "\n";
            $y .= "    segment: " . $this->q($p->URLSegment) . "\n";
            $varianten = Preis::get()->filter('ProductID', $p->ID)->sort('ID');
            if (!$varianten->count()) {
                $y .= "    varianten: []\n";
                continue;
            }
            $y .= "    varianten:\n";
            foreach ($varianten as $v) {
                $y .= "      - menge: " . (int)$v->Amount . "\n";
                $y .= "        einheit: " . $this->q($v->Unit ?: 'weight') . "\n";
                $y .= "        anzahlProTier: " . (int)$v->PreSaleInventory . "\n";
                if ($v->NotInPresale) {
                    $y .= "        # derzeit abgeschaltet (NotInPresale)\n";
                }
            }
        }
        return $y;
    }

    /** Einfaches Quoting -- reicht fuer Titel und Segmente. */
    private function q(?string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$s) . '"';
    }
}
