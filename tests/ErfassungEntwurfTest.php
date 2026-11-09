<?php

namespace Schrattenholz\OrderSale\Tests;

// Basisklasse liegt neben den Tests; vor dem Kernelstart kennt sie kein Autoloader
require_once __DIR__ . '/ErfassungTestBasis.php';

use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientContainer;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\Payment\PaymentMethod_Collection;
use SilverStripe\Core\Environment;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Schreibende Schnittstelle der Erfassung: Entwurf anlegen, Positionen,
 * Lieferung, Abschluss -- über die Shop-Methoden im Kontext des Kunden.
 */
class ErfassungEntwurfTest extends ErfassungTestBasis
{
    private function einmalkunde(): array
    {
        return ['einmalkunde' => [
            'firstName' => 'Erna', 'surname' => 'Probe', 'phone' => '06761 333333',
            'email' => 'erna+telefon@example.invalid', 'city' => 'Simmern', 'zip' => '55469',
        ]];
    }

    private function neuerEntwurf(array $daten): array
    {
        [$code, $entwurf] = $this->schreiben('POST', 'entwurf', $daten);
        $this->assertSame(201, $code, json_encode($entwurf));
        return $entwurf;
    }

    /** Erster angebotener Abholtermin des Entwurfs. */
    private function abholtermin(int $entwurf): array
    {
        [$code, $optionen] = $this->schreiben('GET', "entwurf/$entwurf/lieferoptionen");
        $this->assertSame(200, $code);
        $this->assertNotEmpty($optionen['dates']['collection'], 'es gibt Abholtermine');
        return $optionen['dates']['collection'][0];
    }

    private function abholungFestlegen(int $entwurf): array
    {
        $termin = $this->abholtermin($entwurf);
        [$code, $daten] = $this->schreiben('POST', "entwurf/$entwurf/lieferung", [
            'lieferart' => 'collection',
            'abholtag' => $termin['collectionDayId'],
            'datum' => $termin['date'],
            'zahlart' => $this->idFromFixture(PaymentMethod_Collection::class, 'bar'),
        ]);
        $this->assertSame(200, $code, json_encode($daten));
        return $termin;
    }

    private function bestand(): int
    {
        return (int)Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'))->Inventory;
    }

    public function testSchreibenNurMitEigenemToken(): void
    {
        $this->assertSame(401, $this->schreiben('POST', 'entwurf', $this->einmalkunde(), null)[0]);
        $this->assertSame(401, $this->schreiben('POST', 'entwurf', $this->einmalkunde(), self::TOKEN)[0], 'der Lese-Token reicht nicht');
        Environment::setEnv('SOLA_APP_ERFASSUNG_TOKEN', '');
        $this->assertSame(403, $this->schreiben('POST', 'entwurf', $this->einmalkunde())[0], 'ohne Token in der .env ist die Erfassung aus');
        $this->assertCount(0, OrderProfileFeature_Basket::get());
    }

    public function testEinmalkundeVonAnfangBisBestellung(): void
    {
        $entwurf = $this->neuerEntwurf($this->einmalkunde() + ['notiz' => 'ruft zurück']);
        $id = $entwurf['id'];
        $this->assertNull($entwurf['customer']);
        $this->assertSame($this->idFromFixture(OrderCustomerGroup::class, 'privat'), $entwurf['group']['id']);
        $this->assertSame('erna+telefon@example.invalid', $entwurf['address']['email'], 'das + übersteht urldecode()');
        $this->assertNull($entwurf['address']['gender'], 'ohne Angabe keine erfundene Anrede');
        $this->assertSame('ruft zurück', $entwurf['notes']);

        $stueck = $this->idFromFixture(Preis::class, 'stueck');
        [$code, $entwurf] = $this->schreiben('POST', "entwurf/$id/position", ['variante' => $stueck, 'menge' => 3]);
        $this->assertSame(200, $code, json_encode($entwurf));
        $this->assertCount(1, $entwurf['positions']);
        $this->assertEquals(16.5, $entwurf['positions'][0]['price'], '3 × 5,50 im Preis der Standardgruppe');
        $this->assertFalse($entwurf['reduced']);

        // dieselbe Variante noch einmal: die Menge zählt dazu, keine zweite Position
        [, $entwurf] = $this->schreiben('POST', "entwurf/$id/position", ['variante' => $stueck, 'menge' => 2]);
        $this->assertCount(1, $entwurf['positions']);
        $this->assertSame(5, $entwurf['positions'][0]['quantity']);

        $pid = $entwurf['positions'][0]['id'];
        [, $entwurf] = $this->schreiben('PATCH', "entwurf/$id/position/$pid", ['menge' => 4]);
        $this->assertSame(4, $entwurf['positions'][0]['quantity']);
        $this->assertEquals(22.0, $entwurf['total']);

        $termin = $this->abholungFestlegen($id);
        [$code, $ergebnis] = $this->schreiben('POST', "entwurf/$id/abschliessen", [
            'quelle' => 'Telefon', 'erfasstVon' => 'Tablet Hofladen', 'notiz' => 'holt Freitag ab',
        ]);
        $this->assertSame(201, $code, json_encode($ergebnis));
        $this->assertSame('offen', $ergebnis['order']['status']);
        $this->assertSame('Telefon', $ergebnis['order']['source']);

        $bestellung = OrderProfileFeature_ClientOrder::get()->byID($ergebnis['order']['id']);
        $this->assertSame('Telefon', $bestellung->Quelle);
        $this->assertSame('Tablet Hofladen', $bestellung->ErfasstVon);
        $this->assertSame('holt Freitag ab', $bestellung->AdditionalNotes);
        $this->assertSame($termin['date'], $bestellung->ShippingDate);
        $this->assertEquals($termin['collectionDayId'], $bestellung->CollectionDayID);
        $this->assertEquals(0, $bestellung->ClientContainer()->ClientID, 'Einmalkunde ohne Konto');
        $this->assertSame('Probe', $bestellung->ClientContainer()->Surname);
        $this->assertSame(4, (int)$bestellung->ProductContainers()->first()->Quantity);
        $this->assertSame(6, $this->bestand());

        $this->assertNull($this->findEmail('erna+telefon@example.invalid'), 'Kundenmail nur auf Wunsch');
        $this->assertEmailSent('order@testhof.invalid', 'order@testhof.invalid', '/Neue Bestellung/');
        $this->assertSame(404, $this->schreiben('GET', "entwurf/$id")[0], 'der Entwurf ist aufgebraucht');
    }

    public function testBestandskundeMitGruppenpreisUndBestaetigung(): void
    {
        $kunde = $this->objFromFixture(Member::class, 'stammkunde');
        $entwurf = $this->neuerEntwurf(['kunde' => $kunde->ID]);
        $this->assertSame($kunde->ID, $entwurf['customer']['id']);
        $this->assertSame($this->idFromFixture(OrderCustomerGroup::class, 'wieder'), $entwurf['group']['id']);
        $this->assertSame('Hofweg 2', $entwurf['address']['street'], 'Adresse aus dem Kundenkonto');
        $this->assertNull(Security::getCurrentUser(), 'nach dem Aufruf ist niemand angemeldet');

        [, $entwurf] = $this->schreiben('POST', "entwurf/{$entwurf['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 2,
        ]);
        $this->assertEquals(8.0, $entwurf['positions'][0]['price'], '2 × 4,00 Wiederverkäuferpreis');

        $this->abholungFestlegen($entwurf['id']);
        [$code, $ergebnis] = $this->schreiben('POST', "entwurf/{$entwurf['id']}/abschliessen", [
            'quelle' => 'Mail', 'mailAnKunde' => true, 'mailAnHof' => false,
        ]);
        $this->assertSame(201, $code, json_encode($ergebnis));
        $bestellung = OrderProfileFeature_ClientOrder::get()->byID($ergebnis['order']['id']);
        $this->assertEquals($kunde->ID, $bestellung->ClientContainer()->ClientID, 'Bestellung hängt am Kundenkonto');
        $this->assertEmailSent('hans.stamm@example.invalid', null, '/Bestellbestätigung/');
        $this->assertNull($this->findEmail('order@testhof.invalid'), 'der Hof wollte keine Benachrichtigung');
        $this->assertNull(Security::getCurrentUser());
    }

    public function testShopWarenkorbIstNichtErreichbar(): void
    {
        $shopKorb = OrderProfileFeature_Basket::create();
        $shopKorb->write();
        $this->assertSame(404, $this->schreiben('GET', "entwurf/{$shopKorb->ID}")[0]);
        $this->assertSame(404, $this->schreiben('POST', "entwurf/{$shopKorb->ID}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 1,
        ])[0]);
        $this->assertSame(404, $this->schreiben('DELETE', "entwurf/{$shopKorb->ID}")[0]);
        $this->assertNotNull(OrderProfileFeature_Basket::get()->byID($shopKorb->ID));
    }

    public function testPositionEinesAnderenEntwurfsBleibtUnberuehrt(): void
    {
        $a = $this->neuerEntwurf($this->einmalkunde());
        $b = $this->neuerEntwurf($this->einmalkunde());
        [, $b] = $this->schreiben('POST', "entwurf/{$b['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 1,
        ]);
        $fremd = $b['positions'][0]['id'];
        $this->assertSame(404, $this->schreiben('DELETE', "entwurf/{$a['id']}/position/$fremd")[0]);
        $this->assertSame(404, $this->schreiben('PATCH', "entwurf/{$a['id']}/position/$fremd", ['menge' => 9])[0]);
        $this->assertSame(1, (int)OrderProfileFeature_ProductContainer::get()->byID($fremd)->Quantity);
    }

    public function testNurWasDieGruppeKaufenDarf(): void
    {
        $entwurf = $this->neuerEntwurf(['kunde' => $this->idFromFixture(Member::class, 'stammkunde')]);
        [$code] = $this->schreiben('POST', "entwurf/{$entwurf['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'kilo'), 'menge' => 1,
        ]);
        $this->assertSame(409, $code, 'für Wiederverkäufer abgeschaltet');
        [$code] = $this->schreiben('POST', "entwurf/{$entwurf['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'neu'), 'menge' => 1,
        ]);
        $this->assertSame(404, $code, 'unveröffentlichtes Produkt');
        $this->assertSame(400, $this->schreiben('POST', "entwurf/{$entwurf['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 0,
        ])[0]);
    }

    public function testMengeWirdAufDenBestandGekuerzt(): void
    {
        $entwurf = $this->neuerEntwurf($this->einmalkunde());
        [$code, $entwurf] = $this->schreiben('POST', "entwurf/{$entwurf['id']}/position", [
            'variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 15,
        ]);
        $this->assertSame(200, $code);
        $this->assertTrue($entwurf['reduced']);
        $this->assertSame(10, $entwurf['positions'][0]['quantity']);
    }

    public function testLieferungNurWieFreigeschaltet(): void
    {
        $entwurf = $this->neuerEntwurf($this->einmalkunde());
        $id = $entwurf['id'];
        $termin = $this->abholtermin($id);
        $bar = $this->idFromFixture(PaymentMethod_Collection::class, 'bar');

        [$code, $antwort] = $this->schreiben('POST', "entwurf/$id/lieferung", ['lieferart' => 'delivery', 'zahlart' => $bar]);
        $this->assertSame(400, $code, 'Lieferung ist für Privatkunden abgeschaltet');
        $this->assertStringContainsString('Lieferart', $antwort['error']);

        [$code] = $this->schreiben('POST', "entwurf/$id/lieferung", [
            'lieferart' => 'collection', 'abholtag' => $termin['collectionDayId'], 'datum' => '2020-01-03', 'zahlart' => $bar,
        ]);
        $this->assertSame(400, $code, 'Termin, der nicht angeboten wird');

        [$code] = $this->schreiben('POST', "entwurf/$id/lieferung", [
            'lieferart' => 'collection', 'abholtag' => $termin['collectionDayId'], 'datum' => $termin['date'], 'zahlart' => 999999,
        ]);
        $this->assertSame(400, $code, 'Zahlart, die nicht zur Lieferart gehört');

        $this->assertNull($this->schreiben('GET', "entwurf/$id")[1]['delivery']['type']);
    }

    public function testAbschlussVerlangtHerkunftLieferungUndPositionen(): void
    {
        $id = $this->neuerEntwurf($this->einmalkunde())['id'];
        $this->assertSame(400, $this->schreiben('POST', "entwurf/$id/abschliessen", ['quelle' => 'Shop'])[0], 'Shop setzt nur der Shop');
        $this->assertSame(409, $this->schreiben('POST', "entwurf/$id/abschliessen", ['quelle' => 'Telefon'])[0], 'keine Positionen');
        $this->schreiben('POST', "entwurf/$id/position", ['variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 1]);
        [$code, $antwort] = $this->schreiben('POST', "entwurf/$id/abschliessen", ['quelle' => 'Telefon']);
        $this->assertSame(409, $code);
        $this->assertStringContainsString('Lieferung', $antwort['error']);
        $this->assertCount(0, OrderProfileFeature_ClientOrder::get()->filter('Quelle', 'Telefon'));
    }

    public function testAbschlussPrueftDenBestandErneut(): void
    {
        $id = $this->neuerEntwurf($this->einmalkunde())['id'];
        $this->schreiben('POST', "entwurf/$id/position", ['variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 7]);
        $this->abholungFestlegen($id);

        // in der Zeit ist Ware weggegangen, z. B. über die Ladentheke
        $variante = Preis::get()->byID($this->idFromFixture(Preis::class, 'stueck'));
        $variante->Inventory = 5;
        $variante->write();

        [$code, $antwort] = $this->schreiben('POST', "entwurf/$id/abschliessen", ['quelle' => 'Telefon']);
        $this->assertSame(409, $code);
        $this->assertSame(5, $antwort['shortages'][0]['Verfuegbar']);
        $this->assertSame(7, $antwort['positions'][0]['quantity'], 'der Entwurf bleibt, damit die Menge angepasst werden kann');
        $this->assertSame(5, $this->bestand());
        $this->assertCount(0, OrderProfileFeature_ClientOrder::get()->filter('Quelle', 'Telefon'));
    }

    public function testVerwerfenGibtDieReservierungFrei(): void
    {
        $id = $this->neuerEntwurf($this->einmalkunde())['id'];
        $this->schreiben('POST', "entwurf/$id/position", ['variante' => $this->idFromFixture(Preis::class, 'stueck'), 'menge' => 4]);
        $varianten = $this->variantenVon($this->abfrage('sortiment')[1]);
        $this->assertSame(6, $varianten[$this->idFromFixture(Preis::class, 'stueck')]['available'], 'reserviert');

        $this->assertSame(200, $this->schreiben('DELETE', "entwurf/$id")[0]);
        $this->assertNull(OrderProfileFeature_Basket::get()->byID($id));
        $this->assertCount(0, OrderProfileFeature_ProductContainer::get()->filter('BasketID', $id));
        $this->assertCount(0, OrderProfileFeature_ClientContainer::get()->filter('Surname', 'Probe'));
        $varianten = $this->variantenVon($this->abfrage('sortiment')[1]);
        $this->assertSame(10, $varianten[$this->idFromFixture(Preis::class, 'stueck')]['available'], 'wieder frei');
    }
}
