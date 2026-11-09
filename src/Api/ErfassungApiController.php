<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\ProductList;
use Schrattenholz\Delivery\DeliverySetup;
use Schrattenholz\Delivery\DeliveryType;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Product;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderSale\Reservierung;

/**
 * Schnittstelle, mit der die App Bestellungen für Kunden erfasst
 * (Telefon, Mail, Zettel, Hofladen).
 *
 * Lesen (Header X-Api-Token = SOLA_APP_API_TOKEN):
 *
 * GET /api/erfassung/kunden?q=...
 *     Bestandskunden suchen. Jedes Wort muss in Name, Firma, Ort, PLZ,
 *     Telefon oder E-Mail vorkommen. Je Treffer Kundengruppe und letzte
 *     Bestellung.
 *
 * GET /api/erfassung/sortiment?kunde=ID
 *     Warengruppen mit Produkten und Varianten, Preis in der Gruppe dieses
 *     Kunden, freie Menge und Vorverkaufszustand. Ohne kunde: Standardgruppe,
 *     also das, was ein Einmalkunde zahlt.
 *
 * GET /api/erfassung/lieferoptionen?kunde=ID
 *     Lieferarten mit ihren Zahlarten, Abholtage mit Zeitfenster und
 *     Bestellschluss. Ohne Termine: die hängen am Warenkorb (Vorverkaufsware,
 *     Sonderlieferung) und stehen unter entwurf/ID/lieferoptionen.
 *
 * Entwürfe (Header X-Erfassung-Token = SOLA_APP_ERFASSUNG_TOKEN; ist er nicht
 * gesetzt, ist die Erfassung auf dem Server abgeschaltet). Ein Entwurf ist
 * ein Warenkorb; jede Änderung läuft über die Shop-Methode im Kontext des
 * Kunden (ErfassungKontext), damit Preise, Reservierung und Kürzung genau
 * wie im Shop gelten.
 *
 *   POST   entwurf                      {kunde} | {einmalkunde}, notiz?
 *   GET    entwurf/ID
 *   DELETE entwurf/ID                   verwerfen, Reservierungen frei
 *   POST   entwurf/ID/position          {variante, menge}
 *   PATCH  entwurf/ID/position/PID      {menge}; 0 entfernt
 *   DELETE entwurf/ID/position/PID
 *   GET    entwurf/ID/lieferoptionen    mit Abhol- und Lieferterminen
 *   POST   entwurf/ID/lieferung         {lieferart, zahlart, abholtag+datum | liefertermin+route}
 *   POST   entwurf/ID/abschliessen      {quelle, notiz?, erfasstVon?, mailAnKunde?, mailAnHof?}
 */
class ErfassungApiController extends Controller
{
    private static $url_handlers = [
        'GET kunden' => 'kunden',
        'GET sortiment' => 'sortiment',
        'GET lieferoptionen' => 'lieferoptionen',
        'POST entwurf/$ID!/position' => 'positionNeu',
        'PATCH entwurf/$ID!/position/$PID!' => 'positionAendern',
        'DELETE entwurf/$ID!/position/$PID!' => 'positionLoeschen',
        'GET entwurf/$ID!/lieferoptionen' => 'entwurfLieferoptionen',
        'POST entwurf/$ID!/lieferung' => 'lieferung',
        'POST entwurf/$ID!/abschliessen' => 'abschliessen',
        'GET entwurf/$ID!' => 'entwurfZeigen',
        'DELETE entwurf/$ID!' => 'entwurfVerwerfen',
        'POST entwurf' => 'entwurfNeu',
    ];

    private static $allowed_actions = [
        'kunden',
        'sortiment',
        'lieferoptionen',
        'entwurfNeu',
        'entwurfZeigen',
        'entwurfVerwerfen',
        'positionNeu',
        'positionAendern',
        'positionLoeschen',
        'entwurfLieferoptionen',
        'lieferung',
        'abschliessen',
    ];

    /** Herkünfte, die die App setzen darf; 'Shop' setzt nur der Shop selbst. */
    private static $quellen = ['Telefon', 'Mail', 'Zettel', 'Hofladen'];

    /** Höchstzahl Treffer der Kundensuche; darüber soll genauer gesucht werden. */
    private static $max_treffer = 20;

    private function json($data, int $code = 200): HTTPResponse
    {
        $response = HTTPResponse::create(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $code
        );
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    private function denied(): ?HTTPResponse
    {
        $expected = Environment::getEnv('SOLA_APP_API_TOKEN');
        $provided = $this->getRequest()->getHeader('X-Api-Token');
        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return $this->json(['error' => 'Nicht autorisiert.'], 401);
        }
        return null;
    }

    /** Die Benutzergruppen, an denen eine Kundengruppe hängt -- wer darin ist, ist Kunde. */
    private function kundenGruppenIDs(): array
    {
        return OrderCustomerGroup::get()->exclude('GroupID', 0)->column('GroupID');
    }

    /**
     * Kundengruppe eines Mitglieds, nach derselben Regel wie im Shop
     * (OrderProfileFeature_PriceCalculation_Extension::CurrentGroup):
     * die erste passende, sonst die Standardgruppe.
     */
    private function gruppeFuer(?Member $kunde): ?OrderCustomerGroup
    {
        if ($kunde) {
            foreach (OrderCustomerGroup::get() as $gruppe) {
                if ($gruppe->GroupID && $kunde->inGroup($gruppe->GroupID)) {
                    return $gruppe;
                }
            }
        }
        return OrderCustomerGroup::get()->filter('IsDefault', true)->first();
    }

    /**
     * Liest ?kunde=. Gibt [Mitglied|null, Fehlerantwort|null] zurück; ein
     * Mitglied, das kein Kunde ist (z. B. ein Admin), gilt als nicht gefunden.
     */
    private function kundeAusAnfrage(HTTPRequest $request): array
    {
        $id = (int)$request->getVar('kunde');
        if (!$id) {
            return [null, null];
        }
        $kunde = Member::get()->filter(['ID' => $id, 'Groups.ID' => $this->kundenGruppenIDs() ?: [-1]])->first();
        if (!$kunde) {
            return [null, $this->json(['error' => 'Kunde nicht gefunden.'], 404)];
        }
        return [$kunde, null];
    }

    private function gruppeDaten(?OrderCustomerGroup $gruppe): ?array
    {
        if (!$gruppe) {
            return null;
        }
        return [
            'id' => (int)$gruppe->ID,
            'title' => $gruppe->Title(),
            'isDefault' => (bool)$gruppe->IsDefault,
            'vatExcluded' => (bool)$gruppe->VatExluded,
        ];
    }

    public function kunden(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $suche = trim((string)$request->getVar('q'));
        if (mb_strlen($suche) < 2) {
            return $this->json(['error' => 'Bitte mindestens zwei Zeichen eingeben.'], 400);
        }

        $kunden = Member::get()->filter('Groups.ID', $this->kundenGruppenIDs() ?: [-1]);
        foreach (preg_split('/\s+/', $suche) as $wort) {
            $kunden = $kunden->filterAny([
                'FirstName:PartialMatch' => $wort,
                'Surname:PartialMatch' => $wort,
                'Company:PartialMatch' => $wort,
                'City:PartialMatch' => $wort,
                'ZIP:StartsWith' => $wort,
                'PhoneNumber:PartialMatch' => $wort,
                'Email:PartialMatch' => $wort,
            ]);
        }
        $max = (int)static::config()->get('max_treffer');
        $treffer = $kunden->sort(['Surname' => 'ASC', 'FirstName' => 'ASC'])->limit($max + 1)->toArray();

        $liste = [];
        foreach (array_slice($treffer, 0, $max) as $kunde) {
            $bestellungen = OrderProfileFeature_ClientOrder::get()
                ->filter('ClientContainer.ClientID', $kunde->ID)
                ->exclude('IsModel', true);
            $letzte = $bestellungen->sort('Created', 'DESC')->first();
            $liste[] = [
                'id' => (int)$kunde->ID,
                'firstName' => $kunde->FirstName,
                'surname' => $kunde->Surname,
                'company' => $kunde->Company,
                'street' => $kunde->Street,
                'zip' => $kunde->ZIP,
                'city' => $kunde->City,
                'phone' => $kunde->PhoneNumber,
                'email' => $kunde->Email,
                'group' => $this->gruppeDaten($this->gruppeFuer($kunde)),
                'orderCount' => $bestellungen->count(),
                'lastOrder' => $letzte ? [
                    'id' => (int)$letzte->ID,
                    'created' => $letzte->Created,
                    'status' => $letzte->OrderStatus,
                ] : null,
            ];
        }
        return $this->json([
            'customers' => $liste,
            'more' => count($treffer) > $max,
        ]);
    }

    public function sortiment(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        [$kunde, $fehler] = $this->kundeAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $gruppe = $this->gruppeFuer($kunde);
        if (!$gruppe) {
            return $this->json(['error' => 'Keine Standard-Kundengruppe eingerichtet.'], 500);
        }

        // Nur, was im Shop veröffentlicht ist
        $warengruppen = Versioned::withVersionedMode(function () use ($gruppe) {
            Versioned::set_stage(Versioned::LIVE);
            $ergebnis = [];
            $wurzeln = ProductList::get()->exclude('ParentID', ProductList::get()->column('ID') ?: [-1])->sort('Sort');
            foreach ($wurzeln as $liste) {
                $this->warengruppeSammeln($liste, [], $gruppe, $ergebnis);
            }
            return $ergebnis;
        });

        return $this->json([
            'customer' => $kunde ? ['id' => (int)$kunde->ID, 'name' => trim($kunde->FirstName . ' ' . $kunde->Surname)] : null,
            'group' => $this->gruppeDaten($gruppe),
            'productGroups' => $warengruppen,
        ]);
    }

    /** Warengruppen in der Reihenfolge des Seitenbaums, Untergruppen gleich nach ihrer Obergruppe. */
    private function warengruppeSammeln(ProductList $liste, array $pfad, OrderCustomerGroup $gruppe, array &$ergebnis): void
    {
        $pfad[] = $liste->Title;
        $produkte = [];
        foreach (Product::get()->filter('ParentID', $liste->ID)->sort('Sort') as $produkt) {
            if ($daten = $this->produktDaten($produkt, $gruppe)) {
                $produkte[] = $daten;
            }
        }
        if ($produkte) {
            $ergebnis[] = [
                'id' => (int)$liste->ID,
                'title' => $liste->Title,
                'path' => implode(' › ', $pfad),
                'products' => $produkte,
            ];
        }
        foreach (ProductList::get()->filter('ParentID', $liste->ID)->sort('Sort') as $unterliste) {
            $this->warengruppeSammeln($unterliste, $pfad, $gruppe, $ergebnis);
        }
    }

    /** Ein Produkt mit den Varianten, die diese Gruppe kaufen darf; null, wenn es keine gibt. */
    private function produktDaten(Product $produkt, OrderCustomerGroup $gruppe): ?array
    {
        $produktPreis = OrderCustomerGroups_Product::get()
            ->filter(['ProductID' => $produkt->ID, 'OrderCustomerGroupID' => $gruppe->ID])->first();
        if ($produktPreis && !$produktPreis->Active) {
            return null;
        }
        $varianten = [];
        foreach ($produkt->Preise()->sort('SortOrder') as $variante) {
            if ($daten = $this->variantenDaten($variante, $gruppe, $produktPreis)) {
                $varianten[] = $daten;
            }
        }
        if (!$varianten) {
            return null;
        }
        return [
            'id' => (int)$produkt->ID,
            // wie Kommissionierliste und Shop: Titel mit Zusatz und Zutat ("Wolke, ummantelt, Bärlauch")
            'title' => $produkt->getSummaryTitle(),
            'additionalTitle' => $produkt->AdditionalTitle,
            'outOfStock' => (bool)$produkt->OutOfStock,
            'variants' => $varianten,
        ];
    }

    /**
     * Die Bezeichnung des Shops (Preis::getFullTitle ohne Grundpreis), wie in
     * der Kommissionierliste. Liefert sie nichts, Inhalt oder Menge mit
     * Einheit -- nie eine nackte Zahl.
     */
    private function variantenBezeichnung(Preis $variante): string
    {
        $shop = trim((string)$variante->getFullTitle(false));
        if ($shop !== '') {
            return $shop;
        }
        if ($variante->Content) {
            return (string)$variante->Content;
        }
        if ($variante->Amount) {
            return $variante->Amount . ($variante->Unit === 'piece' ? ' Stück' : ' g');
        }
        return 'Variante ' . $variante->ID;
    }

    private function variantenDaten(Preis $variante, OrderCustomerGroup $gruppe, ?OrderCustomerGroups_Product $produktPreis): ?array
    {
        $gruppenPreis = OrderCustomerGroups_Preis::get()
            ->filter(['PreisID' => $variante->ID, 'OrderCustomerGroupID' => $gruppe->ID])->first();
        // Wie Preis::IsActive() im Shop: ohne aktive Zeile bietet der Shop die Variante dieser Gruppe nicht an
        if (!$gruppenPreis || !$gruppenPreis->Active) {
            return null;
        }
        $verfuegbar = $variante->AvailabilityInfo();
        $daten = [
            'id' => (int)$variante->ID,
            'title' => $this->variantenBezeichnung($variante),
            'unit' => $variante->Unit,
            'amount' => (int)$variante->Amount,
            'caPrice' => (bool)$variante->CaPrice,
            'discountScale' => $variante->DiscountElements()->count() > 0,
            'state' => $verfuegbar->State,
            'available' => $verfuegbar->Quantity,
            'orderable' => (bool)$verfuegbar->IsOrderable,
        ];
        if ($variante->Portionable) {
            // BasePrice() ginge über den angemeldeten Benutzer; hier zählt die Gruppe des Kunden
            $daten['portionable'] = [
                'pricePerKg' => $produktPreis ? (float)$produktPreis->Price : null,
                'portion' => (int)$variante->Portion,
                'min' => (int)$variante->PortionMin,
                'max' => (int)$variante->PortionMax,
            ];
            $daten['price'] = null;
        } else {
            $daten['price'] = (float)$gruppenPreis->Price;
        }
        return $daten;
    }

    public function lieferoptionen(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        [$kunde, $fehler] = $this->kundeAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $gruppe = $this->gruppeFuer($kunde);
        $setup = DeliverySetup::get()->filter('IsDefault', true)->first();
        if (!$gruppe || !$setup) {
            return $this->json(['error' => 'Kein Standard-Liefersetup oder keine Standardgruppe eingerichtet.'], 500);
        }
        return $this->json([
            'group' => $this->gruppeDaten($gruppe),
            'deliveryTypes' => array_column($this->lieferartenFuer($setup, $gruppe), 'daten'),
            'collectionDays' => $this->abholtageFuer($setup, $gruppe),
            // Termine gibt es nur zum Entwurf (GET entwurf/ID/lieferoptionen): sie hängen am Warenkorb
            'dates' => null,
        ]);
    }

    /**
     * Lieferarten des Setups, die für diese Gruppe freigeschaltet sind, wie
     * getActiveDeliveryTypes() im Checkout. Je Eintrag 'art' (DeliveryType)
     * und 'daten' (Antwort für die App).
     */
    private function lieferartenFuer(DeliverySetup $setup, OrderCustomerGroup $gruppe): array
    {
        $lieferarten = [];
        foreach ($setup->DeliveryTypes()->sort('SortOrder') as $art) {
            $freigabe = $art->OrderCustomerGroups()->byID($gruppe->ID);
            if (!$freigabe || !$freigabe->IsActive) {
                continue;
            }
            $zahlarten = [];
            foreach ($art->PaymentMethods()->sort('SortOrder') as $zahlart) {
                $zahlarten[] = [
                    'id' => (int)$zahlart->ID,
                    'title' => $zahlart->Title,
                    'type' => $zahlart->ClassName,
                ];
            }
            $lieferarten[] = ['art' => $art, 'daten' => [
                'id' => (int)$art->ID,
                'title' => $art->Title,
                'type' => $art->Type,
                'price' => (float)$art->Price,
                'minOrderValue' => (float)$freigabe->MinOrderValue,
                'paymentMethods' => $zahlarten,
            ]];
        }
        return $lieferarten;
    }

    private function abholtageFuer(DeliverySetup $setup, OrderCustomerGroup $gruppe): array
    {
        $abholtage = [];
        foreach ($setup->CollectionDays() as $tag) {
            $schluss = $tag->Deadlines()->filter('OrderCustomerGroupID', $gruppe->ID)->first();
            $abholtage[] = [
                'id' => (int)$tag->ID,
                'day' => $tag->Day,
                'dayTranslated' => $tag->DayTranslated(),
                'timeFrom' => $tag->TimeFrom ? substr($tag->TimeFrom, 0, 5) : null,
                'timeTo' => $tag->TimeTo ? substr($tag->TimeTo, 0, 5) : null,
                'deadline' => $schluss ? [
                    'daysBefore' => (int)$schluss->DaysBefore,
                    'time' => $schluss->Time ? substr($schluss->Time, 0, 5) : null,
                    'active' => (bool)$schluss->Active,
                ] : null,
            ];
        }
        return $abholtage;
    }

    // ------------------------------------------------------------------
    // Entwürfe (schreibend). Ein Entwurf ist ein Warenkorb mit Erfassung=1.
    // ------------------------------------------------------------------

    private function schreibenVerweigert(): ?HTTPResponse
    {
        $expected = Environment::getEnv('SOLA_APP_ERFASSUNG_TOKEN');
        if (!$expected) {
            return $this->json(['error' => 'Die Erfassung ist auf diesem Server nicht eingerichtet.'], 403);
        }
        $provided = $this->getRequest()->getHeader('X-Erfassung-Token');
        if (!$provided || !hash_equals($expected, $provided)) {
            return $this->json(['error' => 'Nicht autorisiert.'], 401);
        }
        return null;
    }

    private function eingabe(HTTPRequest $request): array
    {
        $daten = json_decode((string)$request->getBody(), true);
        return is_array($daten) ? $daten : [];
    }

    /** [Korb|null, Fehlerantwort|null]; nur Entwürfe der App, nie ein Shop-Warenkorb. */
    private function entwurfAusAnfrage(HTTPRequest $request): array
    {
        $korb = OrderProfileFeature_Basket::get()
            ->filter(['ID' => (int)$request->param('ID'), 'Erfassung' => true])->first();
        if (!$korb) {
            return [null, $this->json(['error' => 'Entwurf nicht gefunden oder abgelaufen.'], 404)];
        }
        return [$korb, null];
    }

    private function kundeDesEntwurfs(OrderProfileFeature_Basket $korb): ?Member
    {
        $mitgliedID = (int)$korb->ClientContainer()->ClientID;
        return $mitgliedID ? Member::get()->byID($mitgliedID) : null;
    }

    /**
     * Jeder schreibende Aufruf zählt als Bearbeitung, wie expandBasketLiveTime()
     * im Shop: sonst liefe die Reservierung 11 Minuten nach dem Anlegen ab, auch
     * wenn am Telefon noch erfasst wird.
     */
    private function anfassen(OrderProfileFeature_Basket $korb): void
    {
        $korb->TimeStamp = (string)microtime(true);
        $korb->write();
    }

    /** Antwort einer Shop-Methode (JSON-Text) als Array. */
    private function shopAntwort($antwort): array
    {
        $daten = is_string($antwort) ? json_decode($antwort, true) : null;
        return is_array($daten) ? $daten : ['Status' => 'error', 'Message' => 'Unerwartete Antwort des Shops.'];
    }

    private function personAusMitglied(Member $kunde): array
    {
        return [
            'Gender' => in_array($kunde->Gender, ['Herr', 'Frau'], true) ? $kunde->Gender : null,
            'FirstName' => (string)$kunde->FirstName,
            'Surname' => (string)$kunde->Surname,
            'Company' => (string)$kunde->Company,
            'Street' => (string)$kunde->Street,
            'ZIP' => (string)$kunde->ZIP,
            'City' => (string)$kunde->City,
            'PhoneNumber' => (string)$kunde->PhoneNumber,
            'Email' => (string)$kunde->Email,
        ];
    }

    /**
     * Einmalkunde aus der App. Pflicht sind Nachname und Telefon; die Adresse
     * braucht erst eine Lieferung, die E-Mail nur eine Bestätigung.
     * Gibt [person, Fehlertext|null] zurück.
     */
    private function personAusEingabe(array $e): array
    {
        $wert = fn ($schluessel) => trim((string)($e[$schluessel] ?? ''));
        $person = [
            // ohne Angabe bleibt die Anrede leer -- nicht stillschweigend "Herr"
            'Gender' => in_array($wert('gender'), ['Herr', 'Frau'], true) ? $wert('gender') : null,
            'FirstName' => $wert('firstName'),
            'Surname' => $wert('surname'),
            'Company' => $wert('company'),
            'Street' => $wert('street'),
            'ZIP' => $wert('zip'),
            'City' => $wert('city'),
            'PhoneNumber' => $wert('phone'),
            'Email' => $wert('email'),
        ];
        if ($person['Surname'] === '' || $person['PhoneNumber'] === '') {
            return [$person, 'Für einen Einmalkunden sind Nachname und Telefon nötig.'];
        }
        if ($person['Email'] !== '' && !filter_var($person['Email'], FILTER_VALIDATE_EMAIL)) {
            return [$person, 'Die E-Mail-Adresse ist ungültig.'];
        }
        return [$person, null];
    }

    /**
     * POST entwurf  {kunde: ID} oder {einmalkunde: {...}}, optional notiz
     * Legt Warenkorb und Kundendaten an, über setCheckoutAddress() wie im Shop.
     */
    public function entwurfNeu(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        $eingabe = $this->eingabe($request);
        $kunde = null;
        if (!empty($eingabe['kunde'])) {
            $kunde = Member::get()->filter([
                'ID' => (int)$eingabe['kunde'],
                'Groups.ID' => $this->kundenGruppenIDs() ?: [-1],
            ])->first();
            if (!$kunde) {
                return $this->json(['error' => 'Kunde nicht gefunden.'], 404);
            }
            $person = $this->personAusMitglied($kunde);
        } elseif (!empty($eingabe['einmalkunde']) && is_array($eingabe['einmalkunde'])) {
            [$person, $fehler] = $this->personAusEingabe($eingabe['einmalkunde']);
            if ($fehler) {
                return $this->json(['error' => $fehler], 400);
            }
        } else {
            return $this->json(['error' => 'Bitte einen Bestandskunden (kunde) oder einen Einmalkunden (einmalkunde) angeben.'], 400);
        }
        $person['AdditionalNotes'] = trim((string)($eingabe['notiz'] ?? ''));

        $korb = OrderProfileFeature_Basket::create();
        $korb->Erfassung = true;
        $korb->write();
        $antwort = ErfassungKontext::ausfuehren($korb, $kunde, function ($shop) use ($person) {
            // setCheckoutAddress() dekodiert mit urldecode(): rawurlencode hält "+" und Leerzeichen heil
            return $this->shopAntwort($shop->setCheckoutAddress(['person' => rawurlencode(json_encode($person))]));
        });
        if (($antwort['Status'] ?? '') !== 'good') {
            $korb->delete();
            return $this->json(['error' => $antwort['Message'] ?: 'Kundendaten konnten nicht gespeichert werden.'], 500);
        }
        return $this->json($this->entwurfDaten($korb), 201);
    }

    /** GET entwurf/ID */
    public function entwurfZeigen(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        return $fehler ?: $this->json($this->entwurfDaten($korb));
    }

    /** DELETE entwurf/ID -- verwirft den Entwurf und gibt die Reservierungen frei. */
    public function entwurfVerwerfen(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        foreach ($korb->ProductContainers()->filter('ClientOrderID', 0) as $position) {
            $position->delete();
        }
        $kundendaten = $korb->ClientContainer();
        if ($kundendaten->exists() && !OrderProfileFeature_ClientOrder::get()->filter('ClientContainerID', $kundendaten->ID)->exists()) {
            $kundendaten->delete();
        }
        $korb->delete();
        return $this->json(['deleted' => true]);
    }

    /**
     * Prüft, ob die App diese Variante für diese Gruppe anbieten darf: im Shop
     * veröffentlicht, für die Gruppe aktiv, bestellbar. [Preis|null, Fehlerantwort|null]
     */
    private function bestellbareVariante(int $id, OrderCustomerGroup $gruppe): array
    {
        $variante = $id ? Preis::get()->byID($id) : null;
        $produkt = $variante ? Versioned::get_by_stage(Product::class, Versioned::LIVE)->byID($variante->ProductID) : null;
        if (!$variante || !$produkt) {
            return [null, $this->json(['error' => 'Variante nicht gefunden.'], 404)];
        }
        if (!$this->variantenDaten($variante, $gruppe, OrderCustomerGroups_Product::get()
            ->filter(['ProductID' => $produkt->ID, 'OrderCustomerGroupID' => $gruppe->ID])->first())) {
            return [null, $this->json(['error' => 'Diese Variante gibt es für die Kundengruppe nicht.'], 409)];
        }
        if (!$variante->AvailabilityInfo()->IsOrderable) {
            return [null, $this->json(['error' => 'Diese Variante ist zur Zeit nicht bestellbar.'], 409)];
        }
        return [$variante, null];
    }

    /** Ruft addToList() wie die Produktseite auf: 'list' zählt dazu, 'add' setzt die Menge. */
    private function mengeImShop(OrderProfileFeature_Basket $korb, Preis $variante, int $menge, string $art): array
    {
        return ErfassungKontext::ausfuehren($korb, $this->kundeDesEntwurfs($korb), function ($shop) use ($variante, $menge, $art) {
            return $this->shopAntwort($shop->addToList([
                'orderedProduct' => json_encode([
                    'id' => (string)$variante->ProductID,
                    'productoptions' => [],
                    'variant01' => (string)$variante->ID,
                    'quantity' => $menge,
                ]),
                'action' => $art,
            ]));
        });
    }

    /** Antwort nach einer Mengenänderung: Entwurf und, falls gekürzt, die Meldung des Shops. */
    private function nachMengenAenderung(OrderProfileFeature_Basket $korb, array $antwort)
    {
        $this->anfassen($korb);
        $daten = $this->entwurfDaten($korb);
        $daten['message'] = $antwort['Message'] ?? null;
        $gekuerzt = is_string($daten['message']) && strpos($daten['message'], 'nicht mehr vorhanden') !== false;
        $daten['reduced'] = $gekuerzt;
        if (($antwort['Status'] ?? '') === 'error') {
            return $this->json(['error' => $antwort['Message'] ?: 'Die Menge konnte nicht gespeichert werden.'] + $daten, 409);
        }
        return $this->json($daten);
    }

    /** POST entwurf/ID/position  {variante: ID, menge: n} -- liegt die Variante schon im Entwurf, zählt die Menge dazu. */
    public function positionNeu(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $eingabe = $this->eingabe($request);
        $menge = (int)($eingabe['menge'] ?? 0);
        if ($menge < 1) {
            return $this->json(['error' => 'Bitte eine Menge ab 1 angeben.'], 400);
        }
        [$variante, $fehler] = $this->bestellbareVariante((int)($eingabe['variante'] ?? 0), $this->gruppeFuer($this->kundeDesEntwurfs($korb)));
        if ($fehler) {
            return $fehler;
        }
        return $this->nachMengenAenderung($korb, $this->mengeImShop($korb, $variante, $menge, 'list'));
    }

    /** [Position|null, Fehlerantwort|null]; nur Positionen dieses Entwurfs. */
    private function positionAusAnfrage(OrderProfileFeature_Basket $korb, HTTPRequest $request): array
    {
        $position = $korb->ProductContainers()->byID((int)$request->param('PID'));
        if (!$position) {
            return [null, $this->json(['error' => 'Position nicht gefunden.'], 404)];
        }
        return [$position, null];
    }

    /** PATCH entwurf/ID/position/PID  {menge: n} -- setzt die Menge; 0 entfernt die Position. */
    public function positionAendern(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        [$position, $fehler] = $this->positionAusAnfrage($korb, $request);
        if ($fehler) {
            return $fehler;
        }
        $eingabe = $this->eingabe($request);
        if (!isset($eingabe['menge']) || (int)$eingabe['menge'] < 0) {
            return $this->json(['error' => 'Bitte eine Menge ab 0 angeben.'], 400);
        }
        if ((int)$eingabe['menge'] === 0) {
            return $this->positionEntfernen($korb, $position);
        }
        return $this->nachMengenAenderung($korb, $this->mengeImShop($korb, $position->PriceBlockElement(), (int)$eingabe['menge'], 'add'));
    }

    /** DELETE entwurf/ID/position/PID */
    public function positionLoeschen(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        [$position, $fehler] = $this->positionAusAnfrage($korb, $request);
        return $fehler ?: $this->positionEntfernen($korb, $position);
    }

    private function positionEntfernen(OrderProfileFeature_Basket $korb, $position)
    {
        ErfassungKontext::ausfuehren($korb, $this->kundeDesEntwurfs($korb), function ($shop) use ($position) {
            return $shop->removeProductFromBasketByID(['id' => $position->ID]);
        });
        $this->anfassen($korb);
        return $this->json($this->entwurfDaten($korb));
    }

    /**
     * Abholtermine für diesen Entwurf, wie das Auswahlfeld im Checkout
     * (Collection.ss -> CollectionDay::getNextDates mit dem Liefersetup des Korbs).
     */
    private function abholtermine($shop, OrderCustomerGroup $gruppe): array
    {
        $setup = $shop->DeliverySetup();
        $termine = [];
        foreach ($setup->CollectionDays() as $tag) {
            // getNextDates() stolpert, wenn es keinen nächsten Termin gibt (NoNextDeliveryDate)
            if (!$tag->getNextDate($gruppe->ID, $setup, 0, 0)) {
                continue;
            }
            foreach ($tag->getNextDates($gruppe->ID, $setup, $setup->WeeksToShow, 0, 0) as $termin) {
                $termine[] = [
                    'collectionDayId' => (int)$tag->ID,
                    'day' => $tag->Day,
                    'dayTranslated' => $tag->DayTranslated(),
                    'date' => str_replace('.', '-', $termin->Eng),
                    'timeFrom' => $tag->TimeFrom ? substr($tag->TimeFrom, 0, 5) : null,
                    'timeTo' => $tag->TimeTo ? substr($tag->TimeTo, 0, 5) : null,
                ];
            }
        }
        usort($termine, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $termine;
    }

    /**
     * Liefertermine für die Adresse des Entwurfs (DeliveryDatesForCity wie im
     * Checkout). Auf dem Hof derzeit nicht im Standard-Setup, daher ungetestet.
     */
    private function liefertermine($shop, OrderCustomerGroup $gruppe, OrderProfileFeature_Basket $korb): array
    {
        $adresse = $korb->ClientContainer();
        if (!$adresse->ZIP || !$adresse->City) {
            return [];
        }
        $ort = $shop->DeliveryDatesForCity($gruppe->ID, $adresse->ZIP, $adresse->City);
        if (!$ort || !$ort->Dates) {
            return [];
        }
        $termine = [];
        foreach ($ort->Dates as $termin) {
            $termine[] = array_filter($termin->toMap(), 'is_scalar');
        }
        return $termine;
    }

    /** GET entwurf/ID/lieferoptionen -- wie lieferoptionen, dazu die Termine dieses Entwurfs. */
    public function entwurfLieferoptionen(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $kunde = $this->kundeDesEntwurfs($korb);
        $gruppe = $this->gruppeFuer($kunde);
        return $this->json(ErfassungKontext::ausfuehren($korb, $kunde, function ($shop) use ($gruppe, $korb) {
            $setup = $shop->DeliverySetup();
            $lieferarten = array_column($this->lieferartenFuer($setup, $gruppe), 'daten');
            return [
                'group' => $this->gruppeDaten($gruppe),
                'deliveryTypes' => $lieferarten,
                'collectionDays' => $this->abholtageFuer($setup, $gruppe),
                'dates' => [
                    'collection' => $this->abholtermine($shop, $gruppe),
                    'delivery' => in_array('delivery', array_column($lieferarten, 'type'), true)
                        ? $this->liefertermine($shop, $gruppe, $korb) : [],
                ],
            ];
        }));
    }

    /**
     * POST entwurf/ID/lieferung
     *   {lieferart: 'collection', abholtag: ID, datum: 'Y-m-d', zahlart: ID}
     *   {lieferart: 'delivery', liefertermin: 'Y-m-d', route: ID, zahlart: ID}
     * Prüft gegen die für die Gruppe freigeschalteten Lieferarten, Zahlarten
     * und angebotenen Abholtermine; speichert über setCheckoutDelivery().
     */
    public function lieferung(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $eingabe = $this->eingabe($request);
        $kunde = $this->kundeDesEntwurfs($korb);
        $gruppe = $this->gruppeFuer($kunde);

        $antwort = ErfassungKontext::ausfuehren($korb, $kunde, function ($shop) use ($eingabe, $gruppe, $korb) {
            $art = null;
            foreach ($this->lieferartenFuer($shop->DeliverySetup(), $gruppe) as $eintrag) {
                if ($eintrag['daten']['type'] === ($eingabe['lieferart'] ?? null)) {
                    $art = $eintrag;
                }
            }
            if (!$art) {
                return ['Status' => 'error', 'Code' => 400, 'Message' => 'Diese Lieferart gibt es für die Kundengruppe nicht.'];
            }
            if (!in_array((int)($eingabe['zahlart'] ?? 0), array_column($art['daten']['paymentMethods'], 'id'), true)) {
                return ['Status' => 'error', 'Code' => 400, 'Message' => 'Diese Zahlart passt nicht zur Lieferart.'];
            }
            $lieferung = [
                'DeliveryType' => $art['daten']['type'],
                'PaymentMethodID' => (string)(int)$eingabe['zahlart'],
            ];
            if ($art['daten']['type'] === 'collection') {
                $angeboten = false;
                foreach ($this->abholtermine($shop, $gruppe) as $termin) {
                    if ($termin['collectionDayId'] === (int)($eingabe['abholtag'] ?? 0) && $termin['date'] === ($eingabe['datum'] ?? '')) {
                        $angeboten = true;
                    }
                }
                if (!$angeboten) {
                    return ['Status' => 'error', 'Code' => 400, 'Message' => 'Dieser Abholtermin wird nicht angeboten.'];
                }
                $lieferung['CollectionDay'] = (string)(int)$eingabe['abholtag'];
                $lieferung['CollectionDate'] = $eingabe['datum'];
            } elseif ($art['daten']['type'] === 'delivery') {
                if (!$korb->ClientContainer()->ZIP || !$korb->ClientContainer()->City) {
                    return ['Status' => 'error', 'Code' => 400, 'Message' => 'Für eine Lieferung fehlt die Adresse.'];
                }
                $lieferung['DeliveryDate'] = (string)($eingabe['liefertermin'] ?? '');
                $lieferung['DeliveryRoute'] = (string)(int)($eingabe['route'] ?? 0);
            }
            return $this->shopAntwort($shop->setCheckoutDelivery(['delivery' => rawurlencode(json_encode($lieferung))]));
        });

        if (($antwort['Status'] ?? '') !== 'good') {
            return $this->json(['error' => $antwort['Message'] ?: 'Die Lieferung konnte nicht gespeichert werden.'], $antwort['Code'] ?? 409);
        }
        $this->anfassen($korb);
        return $this->json($this->entwurfDaten($korb));
    }

    /**
     * POST entwurf/ID/abschliessen
     *   {quelle: 'Telefon'|'Mail'|'Zettel'|'Hofladen', notiz?, erfasstVon?, mailAnKunde?, mailAnHof?}
     * Prüft den Bestand erneut und macht über bestellungAbschliessen() die
     * Bestellung. Kundenmail nur auf ausdrücklichen Wunsch; der Hof bekommt
     * seine Benachrichtigung wie bisher, solange die App nichts anderes sagt.
     */
    public function abschliessen(HTTPRequest $request)
    {
        if ($verweigert = $this->schreibenVerweigert()) {
            return $verweigert;
        }
        [$korb, $fehler] = $this->entwurfAusAnfrage($request);
        if ($fehler) {
            return $fehler;
        }
        $eingabe = $this->eingabe($request);
        $quellen = static::config()->get('quellen');
        if (!in_array($eingabe['quelle'] ?? null, $quellen, true)) {
            return $this->json(['error' => 'Bitte die Herkunft angeben: ' . implode(', ', $quellen) . '.'], 400);
        }
        if (!$korb->ClientContainerID) {
            return $this->json(['error' => 'Dem Entwurf fehlen die Kundendaten.'], 409);
        }
        if (!$korb->ProductContainers()->exists()) {
            return $this->json(['error' => 'Der Entwurf hat keine Positionen.'], 409);
        }
        if (!$korb->DeliveryTypeID || !$korb->PaymentMethodID) {
            return $this->json(['error' => 'Lieferung und Zahlart fehlen noch.'], 409);
        }
        if (array_key_exists('notiz', $eingabe)) {
            $korb->AdditionalNotes = trim((string)$eingabe['notiz']);
            $korb->write();
        }

        $kunde = $this->kundeDesEntwurfs($korb);
        $ergebnis = ErfassungKontext::ausfuehren($korb, $kunde, function ($shop) use ($eingabe) {
            return $shop->bestellungAbschliessen([
                'quelle' => $eingabe['quelle'],
                'erfasstVon' => trim((string)($eingabe['erfasstVon'] ?? '')),
                'mailAnKunde' => !empty($eingabe['mailAnKunde']),
                'mailAnHof' => !array_key_exists('mailAnHof', $eingabe) || !empty($eingabe['mailAnHof']),
                'bestandPruefen' => true,
            ]);
        });
        if (!$ergebnis['Bestellung']) {
            return $this->json([
                'error' => 'Nicht alles ist noch vorrätig.',
                'shortages' => $ergebnis['Fehlmengen'],
            ] + $this->entwurfDaten($korb), 409);
        }
        // Positionen und Kundendaten gehören jetzt der Bestellung; der leere Entwurf geht
        $korb->delete();
        // frisch lesen: Vorgabewerte wie OrderStatus='offen' setzt erst die Datenbank
        $bestellung = OrderProfileFeature_ClientOrder::get()->byID($ergebnis['Bestellung']->ID);
        return $this->json([
            'order' => [
                'id' => (int)$bestellung->ID,
                'status' => $bestellung->OrderStatus,
                'source' => $bestellung->Quelle,
                'enteredBy' => $bestellung->ErfasstVon,
            ],
        ], 201);
    }

    /** Der Entwurf, wie die App ihn zeigt; Preise in der Gruppe des Kunden. */
    private function entwurfDaten(OrderProfileFeature_Basket $korb): array
    {
        $korb = OrderProfileFeature_Basket::get()->byID($korb->ID);
        $kunde = $this->kundeDesEntwurfs($korb);
        return ErfassungKontext::ausfuehren($korb, $kunde, function () use ($korb, $kunde) {
            $positionen = [];
            foreach ($korb->ProductContainers()->sort('ID') as $position) {
                $preis = $position->CompletePrice();
                $positionen[] = [
                    'id' => (int)$position->ID,
                    'productId' => (int)$position->ProductID,
                    'product' => $position->Product()->getSummaryTitle(),
                    'variantId' => (int)$position->PriceBlockElementID,
                    'variant' => $position->PriceBlockElementID ? $this->variantenBezeichnung($position->PriceBlockElement()) : null,
                    'quantity' => (int)$position->Quantity,
                    'price' => (float)$preis->Price,
                    'caPrice' => (bool)$preis->CaPrice,
                ];
            }
            $summe = $korb->TotalPrice();
            $adresse = $korb->ClientContainer();
            return [
                'id' => (int)$korb->ID,
                'customer' => $kunde ? ['id' => (int)$kunde->ID, 'name' => trim($kunde->FirstName . ' ' . $kunde->Surname)] : null,
                'group' => $this->gruppeDaten($this->gruppeFuer($kunde)),
                'address' => $adresse->exists() ? [
                    'gender' => $adresse->Gender ?: null,
                    'firstName' => $adresse->FirstName,
                    'surname' => $adresse->Surname,
                    'company' => $adresse->Company,
                    'street' => $adresse->Street,
                    'zip' => $adresse->ZIP,
                    'city' => $adresse->City,
                    'phone' => $adresse->PhoneNumber,
                    'email' => $adresse->Email,
                ] : null,
                'positions' => $positionen,
                'total' => (float)$summe->Price,
                'totalCaPrice' => (bool)$summe->CaPrice,
                'delivery' => [
                    'type' => $korb->DeliveryTypeID ? DeliveryType::get()->byID($korb->DeliveryTypeID)?->Type : null,
                    'collectionDayId' => (int)$korb->CollectionDayID ?: null,
                    'date' => $korb->ShippingDate ?: null,
                    'routeId' => (int)$korb->RouteID ?: null,
                    'paymentMethodId' => (int)$korb->PaymentMethodID ?: null,
                ],
                'notes' => $korb->AdditionalNotes,
                'reservedUntil' => date('Y-m-d H:i:s', strtotime($korb->LastEdited . ' +' . Reservierung::dauer() . ' minutes')),
            ];
        });
    }
}
