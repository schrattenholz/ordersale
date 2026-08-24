<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Dev\BuildTask;
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Legt die Teilnehmer vorhandener Kampagnen an.
 *
 * Einmalig nach der Umstellung noetig: bis dahin stand der Anfangsbestand nur
 * an der Variante. Der Task uebertraegt ihn in die Kampagne, ohne etwas zu
 * ueberschreiben -- vorhandene Teilnehmer bleiben, wie sie sind.
 */
class PreSaleItemsAnlegenTask extends BuildTask
{
    protected static string $commandName = 'presale-items-anlegen';
    protected static string $description = 'Teilnehmer vorhandener Vorverkaufs-Kampagnen anlegen';

    protected string $title = 'Vorverkauf: Teilnehmer nachtragen';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $kampagnen = PreSale::get();
        if (!$kampagnen->count()) {
            $output->writeln('Keine Kampagnen vorhanden.');
            return 0;
        }

        foreach ($kampagnen as $kampagne) {
            $neu = $kampagne->itemsAnlegen();
            $output->writeln(sprintf('  %-38s %2d Teilnehmer neu, %2d insgesamt, Anfangsbestand %d%s',
                $kampagne->Title,
                $neu,
                $kampagne->Items()->count(),
                $kampagne->StartInventory(),
                $kampagne->Active ? '  (laufend)' : ''));
        }
        return 0;
    }
}
