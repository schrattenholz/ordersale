<?php

namespace Schrattenholz\OrderSale\Tests;

use Schrattenholz\Delivery\CollectionDay;
use Schrattenholz\Delivery\DeliveryType;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\ProductList;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use Schrattenholz\Payment\PaymentMethod_Collection;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Member;

/**
 * Leseschnittstelle der Erfassung (api/erfassung): Kundensuche, Sortiment
 * je Kundengruppe, Lieferoptionen.
 */
class ErfassungApiTest extends FunctionalTest
{
    protected static $fixture_file = ['CheckoutRegressionTest.yml', 'ErfassungApiTest.yml'];

    private const TOKEN = 'erfassung-test-token';

    private $tokenVorher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenVorher = Environment::getEnv('SOLA_APP_API_TOKEN');
        Environment::setEnv('SOLA_APP_API_TOKEN', self::TOKEN);

        // "unveroeffentlicht" bleibt absichtlich im Entwurf
        foreach (['verkauf' => ProductList::class, 'kaese' => Product::class] as $name => $klasse) {
            $this->objFromFixture($klasse, $name)->publishRecursive();
        }

        // Gruppenpreise in die vom Modul angelegten Zeilen; null = für die Gruppe abgeschaltet
        $preise = [
            'stueck' => ['privat' => 5.50, 'wieder' => 4.00],
            'kilo' => ['privat' => 20.00, 'wieder' => null],
            'ohneInhalt' => ['privat' => 3.00, 'wieder' => 2.50],
        ];
        foreach ($preise as $variante => $jeGruppe) {
            foreach ($jeGruppe as $gruppe => $preis) {
                foreach (OrderCustomerGroups_Preis::get()->filter([
                    'PreisID' => $this->idFromFixture(Preis::class, $variante),
                    'OrderCustomerGroupID' => $this->idFromFixture(OrderCustomerGroup::class, $gruppe),
                ]) as $zeile) {
                    $zeile->Price = $preis ?? 0;
                    $zeile->Active = $preis !== null;
                    $zeile->AutoCalc = false;
                    $zeile->write();
                }
            }
        }

        // Freischaltung der Lieferarten je Gruppe, wie im CMS
        $privat = $this->idFromFixture(OrderCustomerGroup::class, 'privat');
        $wieder = $this->idFromFixture(OrderCustomerGroup::class, 'wieder');
        $abholung = $this->objFromFixture(DeliveryType::class, 'abholung');
        $abholung->OrderCustomerGroups()->add($privat, ['IsActive' => 1, 'MinOrderValue' => 0]);
        $abholung->OrderCustomerGroups()->add($wieder, ['IsActive' => 1, 'MinOrderValue' => 0]);
        $abholung->PaymentMethods()->add($this->objFromFixture(PaymentMethod_Collection::class, 'bar'));
        $lieferung = $this->objFromFixture(DeliveryType::class, 'lieferung');
        $lieferung->OrderCustomerGroups()->add($privat, ['IsActive' => 0, 'MinOrderValue' => 0]);
        $lieferung->OrderCustomerGroups()->add($wieder, ['IsActive' => 1, 'MinOrderValue' => 50]);

        // Bestellschluss in die vom Abholtag angelegten Zeilen
        $freitag = $this->objFromFixture(CollectionDay::class, 'freitag');
        foreach ([$privat => 2, $wieder => 1] as $gruppe => $tage) {
            foreach ($freitag->Deadlines()->filter('OrderCustomerGroupID', $gruppe) as $schluss) {
                $schluss->DaysBefore = $tage;
                $schluss->write();
            }
        }
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SOLA_APP_API_TOKEN', $this->tokenVorher);
        parent::tearDown();
    }

    private function abfrage(string $pfad, ?string $token = self::TOKEN): array
    {
        $antwort = $this->get('api/erfassung/' . $pfad, null, $token ? ['X-Api-Token' => $token] : []);
        return [$antwort->getStatusCode(), json_decode((string)$antwort->getBody(), true)];
    }

    private function variantenVon(array $sortiment): array
    {
        $varianten = [];
        foreach ($sortiment['productGroups'] as $warengruppe) {
            foreach ($warengruppe['products'] as $produkt) {
                foreach ($produkt['variants'] as $variante) {
                    $varianten[$variante['id']] = $variante;
                }
            }
        }
        return $varianten;
    }

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
        $this->assertSame('250 g', $stueck['title']);
        $this->assertSame(5.5, $stueck['price']);
        $this->assertSame(10, $stueck['available']);
        $this->assertTrue($stueck['orderable']);
        // JSON macht aus 20.0 eine 20, deshalb hier assertEquals
        $this->assertEquals(20.0, $varianten[$this->idFromFixture(Preis::class, 'kilo')]['price']);
        $ohneInhalt = $varianten[$this->idFromFixture(Preis::class, 'ohneInhalt')];
        $this->assertSame('130 g', $ohneInhalt['title'], 'ohne Inhaltstext Menge mit Einheit, nie eine nackte Zahl');
        $this->assertSame('infinite', $ohneInhalt['state']);
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
