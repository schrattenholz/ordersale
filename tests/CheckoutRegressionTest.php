<?php

namespace Schrattenholz\OrderSale\Tests;

use Schrattenholz\Delivery\CollectionDay;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\Payment\PaymentMethod_Collection;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;

/**
 * Hält den heutigen Shop-Checkout fest, bevor die Bestell-App ihn mitbenutzt.
 *
 * Die Tests rufen dieselben Adressen auf wie die Shop-Seiten (addToList,
 * setCheckoutAddress, setCheckoutDelivery, makeOrder) und prüfen, was danach
 * in der Datenbank und im Postausgang steht. Ändert ein Umbau dieses
 * Verhalten, schlägt hier etwas an.
 */
class CheckoutRegressionTest extends FunctionalTest
{
    protected static $fixture_file = 'CheckoutRegressionTest.yml';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['verkauf' => 'Page', 'kaese' => Product::class] as $name => $klasse) {
            $this->objFromFixture($klasse, $name)->publishRecursive();
        }
        // Die vom Modul angelegten Gruppenpreiszeilen füllen, wie es im CMS geschieht
        $preise = ['privat' => 5.50, 'wieder' => 4.00];
        foreach ($preise as $gruppe => $preis) {
            $gruppenID = $this->idFromFixture(OrderCustomerGroup::class, $gruppe);
            foreach (OrderCustomerGroups_Preis::get()->filter([
                'PreisID' => $this->idFromFixture(Preis::class, 'stueck'),
                'OrderCustomerGroupID' => $gruppenID,
            ]) as $zeile) {
                $zeile->Price = $preis;
                $zeile->AutoCalc = false;
                $zeile->write();
            }
        }
    }

    private function produktLink(): string
    {
        return $this->objFromFixture(Product::class, 'kaese')->Link();
    }

    private function aufruf(string $aktion, array $parameter = []): array
    {
        $url = rtrim($this->produktLink(), '/') . '/' . $aktion;
        if ($parameter) {
            $url .= '?' . http_build_query($parameter);
        }
        $antwort = $this->get($url);
        $this->assertEquals(200, $antwort->getStatusCode(), "$aktion antwortet nicht mit 200: " . substr((string)$antwort->getBody(), 0, 300));
        return json_decode((string)$antwort->getBody(), true) ?: [];
    }

    private function inDenWarenkorb(int $menge): array
    {
        $variante = $this->objFromFixture(Preis::class, 'stueck');
        return $this->aufruf('addToList', [
            'orderedProduct' => json_encode([
                'id' => (string)$variante->ProductID,
                'title' => 'Testkäse',
                'productoptions' => [],
                'variant01' => (string)$variante->ID,
                'vac' => 'notinuse',
                'quantity' => $menge,
            ]),
            'action' => 'add',
        ]);
    }

    private function adresse(array $person): array
    {
        return $this->aufruf('setCheckoutAddress', ['person' => json_encode($person)]);
    }

    private function abholung(): array
    {
        return $this->aufruf('setCheckoutDelivery', ['delivery' => json_encode([
            'DeliveryType' => 'collection',
            'CollectionDay' => (string)$this->idFromFixture(CollectionDay::class, 'freitag'),
            'CollectionDate' => '2026-11-13',
            'PaymentMethodID' => (string)$this->idFromFixture(PaymentMethod_Collection::class, 'bar'),
        ])]);
    }

    private function gast(): array
    {
        return [
            'Gender' => 'Frau', 'FirstName' => 'Erna', 'Surname' => 'Probe', 'Company' => '',
            'Street' => 'Testweg 1', 'ZIP' => '55469', 'City' => 'Simmern',
            'PhoneNumber' => '06761 000000', 'Email' => 'erna.probe@example.invalid',
            'AdditionalNotes' => 'Regressionsprobe',
        ];
    }

    /**
     * Ruft bestellungAbschliessen() so auf, wie es die Schnittstelle der App
     * spaeter tut: ohne URL, im Kontext der Sitzung, in der der Warenkorb liegt.
     */
    private function abschliessen(array $optionen): array
    {
        $anfrage = new HTTPRequest('GET', '/');
        $anfrage->setSession($this->session());
        Injector::inst()->registerService($anfrage, HTTPRequest::class);
        $controller = ModelAsController::controller_for($this->objFromFixture(Product::class, 'kaese'));
        $controller->setRequest($anfrage);
        $controller->pushCurrent();
        try {
            return $controller->bestellungAbschliessen($optionen);
        } finally {
            $controller->popCurrent();
        }
    }

    private function bestand(): int
    {
        return (int)Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'))->Inventory;
    }

    public function testGastbestellungMitAbholung(): void
    {
        $warenkorb = $this->inDenWarenkorb(2);
        $this->assertSame('good', $warenkorb['Status'] ?? null);
        $this->assertSame('11,00 €', $warenkorb['Value']['ProductPrice'] ?? null, 'Gast zahlt den Preis der Standardgruppe (2 × 5,50)');
        $this->assertSame('good', $this->adresse($this->gast())['Status'] ?? null);
        $this->assertSame('good', $this->abholung()['Status'] ?? null);
        $this->get(rtrim($this->produktLink(), '/') . '/makeOrder');

        $bestellungen = OrderProfileFeature_ClientOrder::get();
        $this->assertCount(1, $bestellungen, 'genau eine Bestellung');
        $bestellung = $bestellungen->first();
        $this->assertSame('offen', $bestellung->OrderStatus);
        $this->assertSame('Regressionsprobe', $bestellung->AdditionalNotes);
        $this->assertSame('Shop', $bestellung->Quelle, 'der Shop setzt seine Herkunft ausdrücklich');
        $this->assertEmpty($bestellung->ErfasstVon);

        $kunde = $bestellung->ClientContainer();
        $this->assertSame('Probe', $kunde->Surname);
        $this->assertSame('erna.probe@example.invalid', $kunde->Email);
        $this->assertEquals(0, $kunde->ClientID, 'Gast hat kein Kundenkonto');
        $this->assertEquals($this->idFromFixture(OrderCustomerGroup::class, 'privat'), $kunde->OrderCustomerGroupID, 'Gast bekommt die Standardgruppe');

        $this->assertSame('collection', $bestellung->DeliveryType()->Type);
        $this->assertSame('2026-11-13', $bestellung->ShippingDate);
        $this->assertEquals($this->idFromFixture(CollectionDay::class, 'freitag'), $bestellung->CollectionDayID);
        $this->assertEquals($this->idFromFixture(PaymentMethod_Collection::class, 'bar'), $bestellung->PaymentMethodID);

        $positionen = $bestellung->ProductContainers();
        $this->assertCount(1, $positionen);
        $position = $positionen->first();
        $this->assertEquals(2, $position->Quantity);
        $this->assertEquals(0, $position->BasketID, 'Position ist aus dem Warenkorb in die Bestellung gewandert');

        $this->assertEquals(8, Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'))->Inventory, 'Bestand sinkt um die bestellte Menge');

        $this->assertEmailSent('erna.probe@example.invalid', 'order@testhof.invalid', '/Bestellbestätigung/');
        $this->assertEmailSent('order@testhof.invalid', 'order@testhof.invalid', '/Neue Bestellung/');
    }

    public function testMengeWirdAufDenBestandGekuerzt(): void
    {
        $antwort = $this->inDenWarenkorb(15);
        $this->assertSame('good', $antwort['Status'] ?? null);
        $this->assertStringContainsString('nicht mehr vorhanden', $antwort['Message'] ?? '');
        $this->adresse($this->gast());
        $this->abholung();
        $this->get(rtrim($this->produktLink(), '/') . '/makeOrder');
        $this->assertEquals(10, OrderProfileFeature_ClientOrder::get()->first()->ProductContainers()->first()->Quantity);
        $this->assertEquals(0, Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'))->Inventory);
    }

    public function testReservierungEinesAnderenWarenkorbsZaehlt(): void
    {
        $this->inDenWarenkorb(7);
        // zweiter Besucher: eigene Sitzung, gleiche Ware; 7 von 10 sind reserviert
        $zweiter = new \SilverStripe\Dev\TestSession();
        $variante = $this->objFromFixture(Preis::class, 'stueck');
        $antwort = $zweiter->get(rtrim($this->produktLink(), '/') . '/addToList?' . http_build_query([
            'orderedProduct' => json_encode([
                'id' => (string)$variante->ProductID, 'title' => 'Testkäse', 'productoptions' => [],
                'variant01' => (string)$variante->ID, 'vac' => 'notinuse', 'quantity' => 5,
            ]),
            'action' => 'add',
        ]));
        $daten = json_decode((string)$antwort->getBody(), true) ?: [];
        $this->assertStringContainsString('nicht mehr vorhanden', $daten['Message'] ?? '', 'der zweite Warenkorb bekommt nur den Rest');
    }

    public function testBestandskundeAngemeldet(): void
    {
        $kunde = $this->objFromFixture(Member::class, 'stammkunde');
        $this->logInAs($kunde);
        $warenkorb = $this->inDenWarenkorb(1);
        $this->assertSame('4,00 €', $warenkorb['Value']['ProductPrice'] ?? null, 'angemeldeter Wiederverkäufer bekommt seinen Gruppenpreis');
        $person = [
            'Gender' => 'Herr', 'FirstName' => $kunde->FirstName, 'Surname' => $kunde->Surname, 'Company' => '',
            'Street' => $kunde->Street, 'ZIP' => $kunde->ZIP, 'City' => $kunde->City,
            'PhoneNumber' => $kunde->PhoneNumber, 'Email' => $kunde->Email, 'AdditionalNotes' => '',
        ];
        $this->assertSame('good', $this->adresse($person)['Status'] ?? null);
        $this->abholung();
        $this->get(rtrim($this->produktLink(), '/') . '/makeOrder');

        $bestellung = OrderProfileFeature_ClientOrder::get()->first();
        $this->assertNotNull($bestellung);
        $this->assertEquals($kunde->ID, $bestellung->ClientContainer()->ClientID, 'Bestellung hängt am Kundenkonto');
        $this->assertEquals(9, Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'))->Inventory);
    }

    // --- Erweiterungen fuer die Bestell-App (Etappe 0) ---

    public function testAppErfassungOhneKundenmail(): void
    {
        $this->inDenWarenkorb(3);
        $this->adresse($this->gast());
        $this->abholung();
        $ergebnis = $this->abschliessen([
            'quelle' => 'Telefon', 'erfasstVon' => 'Tablet Hofladen',
            'mailAnKunde' => false, 'bestandPruefen' => true,
        ]);

        $this->assertSame([], $ergebnis['Fehlmengen']);
        $bestellung = OrderProfileFeature_ClientOrder::get()->byID($ergebnis['Bestellung']->ID);
        $this->assertSame('Telefon', $bestellung->Quelle);
        $this->assertSame('Tablet Hofladen', $bestellung->ErfasstVon);
        $this->assertEquals(3, $bestellung->ProductContainers()->first()->Quantity);
        $this->assertSame(7, $this->bestand());
        $this->assertNull($this->findEmail('erna.probe@example.invalid'), 'keine Bestätigung an den Kunden');
        $this->assertEmailSent('order@testhof.invalid', 'order@testhof.invalid', '/Neue Bestellung/');
    }

    public function testAppErfassungGanzOhneMails(): void
    {
        $this->inDenWarenkorb(1);
        $this->adresse($this->gast());
        $this->abholung();
        $ergebnis = $this->abschliessen(['quelle' => 'Zettel', 'mailAnKunde' => false, 'mailAnHof' => false]);

        $this->assertNotNull($ergebnis['Bestellung']);
        $this->assertNull($this->findEmail('erna.probe@example.invalid'));
        $this->assertNull($this->findEmail('order@testhof.invalid'));
    }

    public function testNachpruefungStopptWennDieReservierungAbgelaufenIst(): void
    {
        // Kunde A legt 7 von 10 hinein ...
        DBDatetime::set_mock_now('2026-11-03 10:00:00');
        $this->inDenWarenkorb(7);
        $this->adresse($this->gast());
        $this->abholung();

        // ... braucht dann 12 Minuten; seine Reservierung (11) ist abgelaufen,
        // und Kunde B bekommt in der Zeit 5 Stück
        DBDatetime::set_mock_now('2026-11-03 10:12:00');
        $variante = $this->objFromFixture(Preis::class, 'stueck');
        $zweiter = new \SilverStripe\Dev\TestSession();
        $antwort = json_decode((string)$zweiter->get(rtrim($this->produktLink(), '/') . '/addToList?' . http_build_query([
            'orderedProduct' => json_encode([
                'id' => (string)$variante->ProductID, 'title' => 'Testkäse', 'productoptions' => [],
                'variant01' => (string)$variante->ID, 'vac' => 'notinuse', 'quantity' => 5,
            ]),
            'action' => 'add',
        ]))->getBody(), true);
        $this->assertSame('good', $antwort['Status'] ?? null);
        $this->assertStringNotContainsString('nicht mehr vorhanden', $antwort['Message'] ?? '', 'B bekommt alle 5, weil A nicht mehr reserviert');

        $ergebnis = $this->abschliessen(['quelle' => 'Telefon', 'bestandPruefen' => true]);

        $this->assertNull($ergebnis['Bestellung'], 'keine Bestellung, wenn eine Position nicht reicht');
        $this->assertCount(1, $ergebnis['Fehlmengen']);
        $this->assertSame(7, $ergebnis['Fehlmengen'][0]['Bestellt']);
        $this->assertSame(5, $ergebnis['Fehlmengen'][0]['Verfuegbar']);
        // Die Variante heisst so, wie der Shop sie zeigt (Inhalt, nicht der Titel)
        $this->assertSame('Testkäse 250 g: bestellt 7, verfügbar 5',$ergebnis['Fehlmengen'][0]['Meldung']);
        $this->assertCount(0, OrderProfileFeature_ClientOrder::get());
        $this->assertSame(10, $this->bestand(), 'Bestand bleibt unberührt');
        $this->assertNull($this->findEmail('order@testhof.invalid'));
    }

    public function testNachpruefungZaehltMehrerePositionenDerselbenVariante(): void
    {
        $this->inDenWarenkorb(4);
        $this->adresse($this->gast());
        $this->abholung();
        // eine zweite Position derselben Variante, wie sie die App anlegen koennte
        $basket = OrderProfileFeature_Basket::get()->byID($this->session()->get('basketid'));
        $erste = $basket->ProductContainers()->first();
        $zweite = $erste->duplicate(false);
        $zweite->Quantity = 7;
        $zweite->write();

        $ergebnis = $this->abschliessen(['bestandPruefen' => true]);

        $this->assertNull($ergebnis['Bestellung']);
        $this->assertCount(1, $ergebnis['Fehlmengen'], 'nur die zweite Position reicht nicht mehr');
        $this->assertSame(6, $ergebnis['Fehlmengen'][0]['Verfuegbar']);
    }
}
