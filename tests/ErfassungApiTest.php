<?php

namespace Schrattenholz\OrderSale\Tests;

// Basisklasse liegt neben den Tests; vor dem Kernelstart kennt sie kein Autoloader
require_once __DIR__ . '/ErfassungTestBasis.php';

use Schrattenholz\Order\Ingredient;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use SilverStripe\Security\Member;

/**
 * Leseschnittstelle der Erfassung (api/erfassung): Kundensuche, Sortiment
 * je Kundengruppe, Lieferoptionen. Vorbereitung in ErfassungTestBasis.
 */
class ErfassungApiTest extends ErfassungTestBasis
{
    public function testOhneRichtigenTokenNichtAutorisiert(): void
    {
        foreach (['kunden?q=Stamm', 'sortiment', 'lieferoptionen'] as $pfad) {
            $this->assertSame(401, $this->abfrage($pfad, null)[0], "$pfad ohne Token");
            $this->assertSame(401, $this->abfrage($pfad, 'falsch')[0], "$pfad mit falschem Token");
        }
    }

    public function testKundensucheFindetNurKunden(): void
    {
        [$code, $daten] = $this->abfrage('kunden?q=Stamm');
        $this->assertSame(200, $code);
        $this->assertCount(1, $daten['customers'], 'der Verwalter heißt auch Stamm, ist aber kein Kunde');
        $kunde = $daten['customers'][0];
        $this->assertSame($this->idFromFixture(Member::class, 'stammkunde'), $kunde['id']);
        $this->assertSame('Simmern', $kunde['city']);
        $this->assertSame($this->idFromFixture(OrderCustomerGroup::class, 'wieder'), $kunde['group']['id']);
        $this->assertSame(1, $kunde['orderCount'], 'die Bestellvorlage zählt nicht');
        $this->assertSame($this->idFromFixture(OrderProfileFeature_ClientOrder::class, 'stammBestellung'), $kunde['lastOrder']['id']);
        $this->assertFalse($daten['more']);
    }

    public function testKundensucheVerlangtJedesWort(): void
    {
        $this->assertSame([$this->idFromFixture(Member::class, 'erna')],
            array_column($this->abfrage('kunden?q=' . rawurlencode('Erna Simmern'))[1]['customers'], 'id'));
        $this->assertSame([], $this->abfrage('kunden?q=' . rawurlencode('Erna Bingen'))[1]['customers']);
        $this->assertSame([$this->idFromFixture(Member::class, 'erna')],
            array_column($this->abfrage('kunden?q=222222')[1]['customers'], 'id'), 'auch über die Telefonnummer');
        $erna = $this->abfrage('kunden?q=Erna')[1]['customers'][0];
        $this->assertNull($erna['lastOrder']);
        $this->assertSame(0, $erna['orderCount']);
        $this->assertSame(400, $this->abfrage('kunden?q=E')[0], 'ein Zeichen ist zu wenig');
    }

    public function testSortimentFuerEinmalkunden(): void
    {
        [$code, $daten] = $this->abfrage('sortiment');
        $this->assertSame(200, $code);
        $this->assertNull($daten['customer']);
        $this->assertSame($this->idFromFixture(OrderCustomerGroup::class, 'privat'), $daten['group']['id']);
        $this->assertTrue($daten['group']['isDefault']);

        $this->assertCount(1, $daten['productGroups']);
        $this->assertSame('Verkauf', $daten['productGroups'][0]['path']);
        $this->assertSame(['Testkäse'], array_column($daten['productGroups'][0]['products'], 'title'), 'unveröffentlichte Produkte fehlen');

        $varianten = $this->variantenVon($daten);
        $stueck = $varianten[$this->idFromFixture(Preis::class, 'stueck')];
        $this->assertSame('250 gr', $stueck['title'], 'Bezeichnung des Shops (getFullTitle), wie in der Kommissionierliste');
        $this->assertSame(5.5, $stueck['price']);
        $this->assertSame(10, $stueck['available']);
        $this->assertTrue($stueck['orderable']);
        // JSON macht aus 20.0 eine 20, deshalb hier assertEquals
        $this->assertEquals(20.0, $varianten[$this->idFromFixture(Preis::class, 'kilo')]['price']);
        $ohneInhalt = $varianten[$this->idFromFixture(Preis::class, 'ohneInhalt')];
        $this->assertSame('130 gr', $ohneInhalt['title'], 'ohne Inhaltstext Menge mit Einheit, nie eine nackte Zahl');
        $this->assertSame('infinite', $ohneInhalt['state']);
    }

    public function testProduktHeisstWieImShop(): void
    {
        // Sorten unterscheiden sich auf dem Hof nur über die Zutat ("Wolke, ummantelt, Bärlauch")
        $zutat = Ingredient::create(['Title' => 'Bärlauch']);
        $zutat->write();
        $kaese = $this->objFromFixture(Product::class, 'kaese');
        $kaese->Ingredients()->add($zutat);
        $kaese->publishRecursive();

        $produkte = $this->abfrage('sortiment')[1]['productGroups'][0]['products'];
        $this->assertSame('Testkäse, Bärlauch', $produkte[0]['title'], 'getSummaryTitle() wie in der Kommissionierliste');
    }

    public function testSortimentFuerWiederverkaeufer(): void
    {
        $id = $this->idFromFixture(Member::class, 'stammkunde');
        [$code, $daten] = $this->abfrage('sortiment?kunde=' . $id);
        $this->assertSame(200, $code);
        $this->assertSame($id, $daten['customer']['id']);
        $this->assertSame($this->idFromFixture(OrderCustomerGroup::class, 'wieder'), $daten['group']['id']);

        $varianten = $this->variantenVon($daten);
        $this->assertEquals(4.0, $varianten[$this->idFromFixture(Preis::class, 'stueck')]['price'], 'Gruppenpreis des Kunden');
        $this->assertArrayNotHasKey($this->idFromFixture(Preis::class, 'kilo'), $varianten, 'für Wiederverkäufer abgeschaltet');
    }

    public function testSortimentNurFuerKunden(): void
    {
        $this->assertSame(404, $this->abfrage('sortiment?kunde=' . $this->idFromFixture(Member::class, 'verwalter'))[0]);
        $this->assertSame(404, $this->abfrage('sortiment?kunde=999999')[0]);
        $this->assertSame(404, $this->abfrage('lieferoptionen?kunde=999999')[0]);
    }

    public function testFreieMengeZiehtReservierungenAb(): void
    {
        // ein lebender Warenkorb eines anderen Kunden hält 3 Stück
        $korb = OrderProfileFeature_Basket::create();
        $korb->write();
        $position = OrderProfileFeature_ProductContainer::create();
        $position->ProductID = $this->idFromFixture(Product::class, 'kaese');
        $position->PriceBlockElementID = $this->idFromFixture(Preis::class, 'stueck');
        $position->Quantity = 3;
        $position->BasketID = $korb->ID;
        $position->write();

        $varianten = $this->variantenVon($this->abfrage('sortiment')[1]);
        $this->assertSame(7, $varianten[$this->idFromFixture(Preis::class, 'stueck')]['available']);
    }

    public function testLieferoptionenJeGruppe(): void
    {
        [$code, $privat] = $this->abfrage('lieferoptionen');
        $this->assertSame(200, $code);
        $this->assertSame(['collection'], array_column($privat['deliveryTypes'], 'type'), 'Lieferung ist für Privatkunden abgeschaltet');
        $this->assertSame(['Barzahlung bei Abholung'], array_column($privat['deliveryTypes'][0]['paymentMethods'], 'title'));
        $this->assertCount(1, $privat['collectionDays']);
        $freitag = $privat['collectionDays'][0];
        $this->assertSame('friday', $freitag['day']);
        $this->assertSame('14:00', $freitag['timeFrom']);
        $this->assertSame('18:00', $freitag['timeTo']);
        $this->assertSame(2, $freitag['deadline']['daysBefore']);
        $this->assertNull($privat['dates'], 'Termine kommen erst mit dem Entwurf');

        $wieder = $this->abfrage('lieferoptionen?kunde=' . $this->idFromFixture(Member::class, 'stammkunde'))[1];
        $this->assertSame(['collection', 'delivery'], array_column($wieder['deliveryTypes'], 'type'));
        $this->assertEquals(50.0, $wieder['deliveryTypes'][1]['minOrderValue']);
        $this->assertSame(1, $wieder['collectionDays'][0]['deadline']['daysBefore']);
    }
}
