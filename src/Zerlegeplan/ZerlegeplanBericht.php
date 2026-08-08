<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

/**
 * Ergebnis eines Abgleichs zwischen Zerlegeplan und Katalog.
 *
 * Haelt die Zeilen nach Kategorie getrennt, damit sich derselbe Bericht als
 * Tabelle im CMS, als Text auf der Kommandozeile und als Protokoll am
 * Datensatz ausgeben laesst.
 */
class ZerlegeplanBericht
{
    public string $warengruppe = '';
    public string $bezeichnung = '';
    public array $fehler = [];

    /** @var array<string, array<int, array{art:string, name:string, hinweis:string}>> */
    public array $zeilen = [];

    /** Reihenfolge und Beschriftung der Kategorien in der Ausgabe. */
    public const LABELS = [
        ZerlegeplanImporter::NEU => 'Neu',
        ZerlegeplanImporter::GEAENDERT => 'Geändert',
        ZerlegeplanImporter::WIEDER_AUFGENOMMEN => 'Wieder aufgenommen',
        ZerlegeplanImporter::ABGESCHALTET => 'Abgeschaltet',
        ZerlegeplanImporter::UNVERAENDERT => 'Unverändert',
    ];

    public function add(string $kategorie, string $art, string $name, string $hinweis): void
    {
        $this->zeilen[$kategorie][] = [
            'art' => $art,
            'name' => $name,
            'hinweis' => $hinweis,
        ];
    }

    public function anzahl(string $kategorie): int
    {
        return count($this->zeilen[$kategorie] ?? []);
    }

    public function hatAenderungen(): bool
    {
        foreach ([
            ZerlegeplanImporter::NEU,
            ZerlegeplanImporter::GEAENDERT,
            ZerlegeplanImporter::WIEDER_AUFGENOMMEN,
            ZerlegeplanImporter::ABGESCHALTET,
        ] as $k) {
            if ($this->anzahl($k)) {
                return true;
            }
        }
        return false;
    }

    /** Kurzfassung, etwa "3 neu · 2 geändert · 1 abgeschaltet". */
    public function zusammenfassung(): string
    {
        $teile = [];
        foreach (self::LABELS as $k => $label) {
            $n = $this->anzahl($k);
            if ($n) {
                $teile[] = $n . ' ' . mb_strtolower($label);
            }
        }
        return $teile ? implode(' · ', $teile) : 'nichts zu tun';
    }

    /** Vollstaendiger Bericht als Text. */
    public function alsText(): string
    {
        if ($this->fehler) {
            return "Die Datei konnte nicht gelesen werden:\n  - " . implode("\n  - ", $this->fehler);
        }

        $out = 'Warengruppe: ' . $this->warengruppe;
        if ($this->bezeichnung) {
            $out .= '   (Plan: ' . $this->bezeichnung . ')';
        }
        $out .= "\n" . $this->zusammenfassung() . "\n";

        foreach (self::LABELS as $k => $label) {
            $zeilen = $this->zeilen[$k] ?? [];
            if (!$zeilen) {
                continue;
            }
            // Unveraenderte nur zaehlen, nicht einzeln auflisten -- sonst
            // ersaeuft das Wesentliche in Rauschen.
            if ($k === ZerlegeplanImporter::UNVERAENDERT) {
                $out .= "\n" . $label . ': ' . count($zeilen) . " Einträge\n";
                continue;
            }
            $out .= "\n" . $label . ":\n";
            foreach ($zeilen as $z) {
                $out .= sprintf("  %-10s %-34s %s\n", $z['art'], $z['name'], $z['hinweis']);
            }
        }
        return $out;
    }
}
