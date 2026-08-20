<?php

namespace Schrattenholz\OrderSale;

use SilverStripe\Dev\BuildTask;
use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;

/**
 * Entfernt aufgegebene Warenkoerbe samt ihrer Reservierungen.
 *
 * Gedacht fuer einen Cron-Eintrag, der alle paar Minuten laeuft:
 *
 *     cd /pfad/zum/projekt && php vendor/bin/sake tasks:warenkoerbe-aufraeumen
 *
 * Reines Aufraeumen. Welche Ware frei ist, entscheidet die Frist in der
 * Abfrage (Reservierung::nurGueltige()) -- faellt dieser Lauf aus, bleiben nur
 * Datensaetze liegen, gesperrt wird dadurch nichts.
 */
class WarenkoerbeAufraeumenTask extends BuildTask
{
    protected static string $commandName = 'warenkoerbe-aufraeumen';
    protected static string $description = 'Aufgegebene Warenkörbe und ihre Reservierungen entfernen';

    protected string $title = 'Aufgegebene Warenkörbe entfernen';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $output->writeln(sprintf('Frist: %d Minuten — alles vor %s gilt als aufgegeben.',
            Reservierung::dauer(), Reservierung::grenze()));

        $bericht = Reservierung::aufraeumen();

        $output->writeln(sprintf('  %d Warenkörbe entfernt', $bericht['warenkoerbe']));
        $output->writeln(sprintf('  %d Positionen darin entfernt', $bericht['positionen']));
        $output->writeln(sprintf('  %d verwaiste Positionen entfernt', $bericht['verwaiste']));

        if (!array_sum($bericht)) {
            $output->writeln('Nichts aufzuräumen.');
        }
        return 0;
    }
}
