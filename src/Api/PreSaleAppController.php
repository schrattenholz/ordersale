<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;

/**
 * Liefert die Bestell-App aus dem Modul aus.
 *
 * Die Dateien liegen im Modul, erreichbar bleiben sie aber unter /pwa/. Das
 * ist kein Schoenheitsfehler, sondern Bedingung: der Geltungsbereich eines
 * Service Workers ist sein eigener Pfad, und eine auf dem Telefon
 * installierte App merkt sich ihre Startadresse. Ein Umzug nach
 * /_resources/vendor/... wuerde beide brechen.
 */
class PreSaleAppController extends Controller
{
    private static $allowed_actions = ['datei'];

    /** Bis zu drei Ebenen -- reicht fuer icons/… und fonts/… */
    private static $url_handlers = ['$A/$B/$C' => 'datei'];

    private const ORDNER = '/vendor/schrattenholz/ordersale/pwa/';

    /** Nur diese Endungen werden ausgeliefert. */
    private const TYPEN = [
        'html' => 'text/html; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'webmanifest' => 'application/manifest+json',
    ];

    public function datei(HTTPRequest $request): HTTPResponse
    {
        $teile = array_filter([
            $request->param('A'),
            $request->param('B'),
            $request->param('C'),
        ], fn($t) => $t !== null && $t !== '');

        // Silverstripe trennt die Dateiendung vom letzten Pfadstueck ab und
        // haelt sie getrennt vor -- ohne sie waere aus "app.js" nur "app".
        $relativ = implode('/', $teile);
        $endung = strtolower((string)$request->getExtension());
        if ($relativ === '') {
            $relativ = 'index';
            $endung = 'html';
        }
        if ($endung !== '') {
            $relativ .= '.' . $endung;
        }

        // Ausbruch aus dem Ordner verhindern -- die Pfadteile kommen aus der URL
        foreach ($teile as $t) {
            if ($t === '..' || str_contains($t, '\\') || str_contains($t, "\0")) {
                return $this->fehler(400, 'Ungültiger Pfad.');
            }
        }

        if (!isset(self::TYPEN[$endung])) {
            return $this->fehler(404, 'Nicht gefunden.');
        }

        $wurzel = realpath(BASE_PATH . self::ORDNER);
        $echt = realpath(BASE_PATH . self::ORDNER . $relativ);
        if (!$echt || !$wurzel || !str_starts_with($echt, $wurzel) || !is_file($echt)) {
            return $this->fehler(404, 'Nicht gefunden.');
        }

        $antwort = HTTPResponse::create(file_get_contents($echt));
        $antwort->addHeader('Content-Type', self::TYPEN[$endung]);

        // Der Service Worker und die Startseite duerfen nie aus dem
        // Zwischenspeicher kommen, sonst bleibt eine alte Fassung der App auf
        // dem Geraet haengen.
        if ($relativ === 'sw.js' || $relativ === 'index.html') {
            $antwort->addHeader('Cache-Control', 'no-cache, must-revalidate');
        } else {
            $antwort->addHeader('Cache-Control', 'public, max-age=3600');
        }

        return $antwort;
    }

    private function fehler(int $code, string $text): HTTPResponse
    {
        $antwort = HTTPResponse::create($text, $code);
        $antwort->addHeader('Content-Type', 'text/plain; charset=utf-8');
        return $antwort;
    }
}
