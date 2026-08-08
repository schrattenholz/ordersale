<?php

namespace Schrattenholz\OrderSale\Zerlegeplan;

use Symfony\Component\Yaml\Yaml;

/**
 * Liest eine Zerlegeplan-Datei und fuellt daraus einen Plan.
 *
 * Die Datei ist ausdruecklich nur ein Startpunkt: sie bringt einen leeren Plan
 * auf einen Schlag zu Teilen und Varianten, danach wird im CMS weitergepflegt.
 * Deshalb prueft diese Klasse streng, schreibt aber nur in den Plan und nie in
 * den Katalog.
 */
class ZerlegeplanDatei
{
    private array $plan = [];
    private array $fehler = [];

    public function __construct(private string $inhalt)
    {
    }

    /** Bezeichnung der Tierart laut Datei. */
    public function bezeichnung(): string
    {
        return (string)($this->plan['zerlegeplan']['bezeichnung'] ?? '');
    }

    /**
     * Liest die Datei und prueft die Struktur.
     * @return string[] Liste der Fehler; leer bedeutet in Ordnung.
     */
    public function pruefen(): array
    {
        $this->fehler = [];
        $this->plan = [];

        try {
            $daten = Yaml::parse($this->inhalt);
        } catch (\Throwable $e) {
            return ['Die Datei ist kein gültiges YAML: ' . $e->getMessage()];
        }

        if (!is_array($daten)) {
            return ['Die Datei ist leer oder enthält keine Struktur.'];
        }

        $kopf = $daten['zerlegeplan'] ?? null;
        if (!is_array($kopf) || empty($kopf['bezeichnung'])) {
            $this->fehler[] = 'Abschnitt "zerlegeplan" mit "bezeichnung" fehlt.';
        }

        $produkte = $daten['produkte'] ?? null;
        if (!is_array($produkte) || !count($produkte)) {
            $this->fehler[] = 'Abschnitt "produkte" fehlt oder ist leer.';
            $produkte = [];
        }

        $segmente = [];
        foreach ($produkte as $i => $p) {
            $nr = 'Produkt ' . ($i + 1);
            if (!is_array($p)) {
                $this->fehler[] = $nr . ': kein gültiger Eintrag.';
                continue;
            }
            if (empty($p['titel'])) {
                $this->fehler[] = $nr . ': "titel" fehlt.';
            }
            $seg = $p['segment'] ?? null;
            if (empty($seg)) {
                $this->fehler[] = $nr . ' (' . ($p['titel'] ?? '?') . '): "segment" fehlt.';
            } elseif (isset($segmente[$seg])) {
                $this->fehler[] = $nr . ': Segment "' . $seg . '" kommt mehrfach vor.';
            } else {
                $segmente[$seg] = true;
            }

            $varianten = $p['varianten'] ?? [];
            if (!is_array($varianten)) {
                $this->fehler[] = $nr . ': "varianten" muss eine Liste sein.';
                continue;
            }
            $mengen = [];
            foreach ($varianten as $j => $v) {
                $vn = $nr . ', Variante ' . ($j + 1);
                if (!is_array($v) || !isset($v['menge'])) {
                    $this->fehler[] = $vn . ': "menge" fehlt.';
                    continue;
                }
                if (!is_numeric($v['menge']) || (int)$v['menge'] <= 0) {
                    $this->fehler[] = $vn . ': "menge" muss eine Zahl größer 0 sein.';
                }
                $anzahl = $this->anzahl($v);
                if ($anzahl !== null && (!is_numeric($anzahl) || (int)$anzahl < 0)) {
                    $this->fehler[] = $vn . ': "anzahlProTier" muss eine Zahl ab 0 sein.';
                }
                $m = (int)($v['menge'] ?? 0);
                if (isset($mengen[$m])) {
                    $this->fehler[] = $vn . ': Menge ' . $m . ' kommt bei diesem Produkt mehrfach vor.';
                }
                $mengen[$m] = true;
            }
        }

        if (!$this->fehler) {
            $this->plan = $daten;
        }
        return $this->fehler;
    }

    /**
     * Legt Teile und Varianten am Plan an.
     *
     * Vorhandene Teile bleiben stehen und werden nur ergaenzt -- Aufraeumen ist
     * Sache des Aufrufers, der weiss, ob ersetzt oder ergaenzt werden soll.
     */
    public function fuellen(Zerlegeplan $ziel): void
    {
        if (!$this->plan && $this->pruefen()) {
            return;
        }

        $sort = 0;
        foreach ($this->plan['produkte'] as $p) {
            $sort++;
            $segment = $p['segment'];

            $teil = ZerlegeplanTeil::get()
                ->filter(['ZerlegeplanID' => $ziel->ID, 'Segment' => $segment])
                ->first();
            if (!$teil) {
                $teil = ZerlegeplanTeil::create();
                $teil->ZerlegeplanID = $ziel->ID;
                $teil->Segment = $segment;
            }
            $teil->Title = $p['titel'];
            $teil->Sort = $sort;
            $teil->write();

            $vsort = 0;
            foreach (($p['varianten'] ?? []) as $v) {
                $vsort++;
                $menge = (int)$v['menge'];
                $variante = ZerlegeplanVariante::get()
                    ->filter(['TeilID' => $teil->ID, 'Menge' => $menge])
                    ->first();
                if (!$variante) {
                    $variante = ZerlegeplanVariante::create();
                    $variante->TeilID = $teil->ID;
                    $variante->Menge = $menge;
                }
                $variante->Einheit = ($v['einheit'] ?? 'weight') === 'piece' ? 'piece' : 'weight';
                $variante->AnzahlProTier = (int)($this->anzahl($v) ?? 0);
                $variante->Sort = $vsort;
                $variante->write();
            }
        }
    }

    /**
     * Stueckzahl je Tier aus einer Varianten-Zeile.
     *
     * Frueher hiess das Feld "anfallJeTier". Aeltere Dateien sollen sich
     * weiterhin einlesen lassen, statt beim Metzger als Null anzukommen.
     */
    private function anzahl(array $v): int|string|null
    {
        return $v['anzahlProTier'] ?? $v['anfallJeTier'] ?? null;
    }
}
