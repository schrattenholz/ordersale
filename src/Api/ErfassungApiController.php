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
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Product;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;

/**
 * Lesende Schnittstelle, mit der die App Bestellungen für Kunden erfasst
 * (Telefon, Mail, Zettel, Hofladen). Das Schreiben folgt in Etappe 2.
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
 *     Bestellschluss. Noch ohne Termine: die hängen am Warenkorb
 *     (Vorverkaufsware, Sonderlieferung) und kommen mit dem Entwurf.
 */
class ErfassungApiController extends Controller
{
    private static $url_handlers = [
        'GET kunden' => 'kunden',
        'GET sortiment' => 'sortiment',
        'GET lieferoptionen' => 'lieferoptionen',
    ];

    private static $allowed_actions = [
        'kunden',
        'sortiment',
        'lieferoptionen',
    ];

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
            'title' => $produkt->Title,
            'additionalTitle' => $produkt->AdditionalTitle,
            'outOfStock' => (bool)$produkt->OutOfStock,
            'variants' => $varianten,
        ];
    }

    /**
     * Wie Preis::getTitle(), aber nie eine nackte Zahl: ohne Inhaltstext
     * (bei 111 von 184 Varianten auf dem Hof) die Menge mit Einheit.
     */
    private function variantenBezeichnung(Preis $variante): string
    {
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

        $lieferarten = [];
        foreach ($setup->DeliveryTypes()->sort('SortOrder') as $art) {
            // Freischaltung je Kundengruppe, wie getActiveDeliveryTypes() im Checkout
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
            $lieferarten[] = [
                'id' => (int)$art->ID,
                'title' => $art->Title,
                'type' => $art->Type,
                'price' => (float)$art->Price,
                'minOrderValue' => (float)$freigabe->MinOrderValue,
                'paymentMethods' => $zahlarten,
            ];
        }

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

        return $this->json([
            'group' => $this->gruppeDaten($gruppe),
            'deliveryTypes' => $lieferarten,
            'collectionDays' => $abholtage,
            // Termine folgen mit dem Entwurf (Etappe 2): sie hängen am Warenkorb
            'dates' => null,
        ]);
    }
}
