<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use SilverStripe\Dev\BuildTask;


use Schrattenholz\Order\ProductList;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Zerlegeplan von der Kommandozeile auf eine Warengruppe anwenden.
 *
 * Entspricht im CMS der Auswahl am Warengruppen-Seitentyp. Ohne
 * --uebernehmen wird nur die Vorschau gezeigt, geschrieben wird nichts.
 *   sake tasks:ImportZerlegeplanTask --plan=1 --list=151
 *   sake tasks:ImportZerlegeplanTask --plan=1 --list=151 --uebernehmen
 */
class ImportZerlegeplanTask extends BuildTask
{
    protected static string $commandName = 'zerlegeplan-anwenden';
    protected static string $description = 'Zerlegeplan auf eine Warengruppe anwenden';
    protected string $title = 'Zerlegeplan auf eine Warengruppe anwenden';

    public function getOptions(): array
    {
        return [
            new InputOption('plan', null, InputOption::VALUE_REQUIRED,
                'ID des Zerlegeplans'),
            new InputOption('list', null, InputOption::VALUE_REQUIRED,
                'ID der Warengruppe, die den Plan bekommt'),
            new InputOption('uebernehmen', null, InputOption::VALUE_NONE,
                'Änderungen wirklich schreiben'),
        ];
    }

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $plan = Zerlegeplan::get()->byID((int)$input->getOption('plan'));
        if (!$plan) {
            $output->writeln('<error>--plan fehlt oder ist unbekannt</error>');
            $this->auswahlZeigen($output);
            return 1;
        }

        $liste = ProductList::get()->byID((int)$input->getOption('list'));
        if (!$liste) {
            $output->writeln('<error>--list fehlt oder ist unbekannt</error>');
            $this->auswahlZeigen($output);
            return 1;
        }

        $output->writeln(sprintf('Plan: %s (%d Teile) → Warengruppe: %s (#%d)',
            $plan->Title, $plan->getAnzahlTeile(), $liste->Title, $liste->ID));

        $schreiben = (bool)$input->getOption('uebernehmen');
        $importer = new ZerlegeplanImporter($plan, $liste);
        $bericht = $schreiben ? $importer->uebernehmen() : $importer->vorschau();

        $output->writeln('');
        $output->writeln($schreiben ? '--- ÜBERNOMMEN ---' : '--- VORSCHAU (nichts geschrieben) ---');
        $output->writeln($bericht->alsText());
        return $bericht->fehler ? 1 : 0;
    }

    /** Hilfe bei fehlenden oder falschen IDs. */
    private function auswahlZeigen(PolyOutput $output): void
    {
        $output->writeln('');
        $output->writeln('Zerlegepläne:');
        foreach (Zerlegeplan::get() as $p) {
            $output->writeln(sprintf('  --plan=%-4d %s (%d Teile)', $p->ID, $p->Title, $p->getAnzahlTeile()));
        }
        $output->writeln('');
        $output->writeln('Warengruppen:');
        foreach (ProductList::get()->sort('Title') as $l) {
            $output->writeln(sprintf('  --list=%-4d %s', $l->ID, $l->Title));
        }
    }
}
