<?php

namespace Schrattenholz\OrderSale\Tests;

use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientContainer;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;

/**
 * Kommissionierliste (api/picking-list): Gesamtsummen wie bisher, dazu je
 * Bestellung die eigenen Positionen für die Ansicht "Nach Kunde".
 */
class PickingListApiTest extends FunctionalTest
{
    protected static $fixture_file = 'PickingListApiTest.yml';

    private const TOKEN = 'picking-test-token';

    private $tokenVorher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenVorher = Environment::getEnv('SOLA_APP_API_TOKEN');
        Environment::setEnv('SOLA_APP_API_TOKEN', self::TOKEN);
    }

    protected function tearDown(): void
    {
        Environment::setEnv('SOLA_APP_API_TOKEN', $this->tokenVorher);
        parent::tearDown();
    }

    /** Bestellung mit Positionen [Variante => Menge]. */
    private function bestellung(string $vorname, string $nachname, string $status, array $positionen, string $notiz = ''): OrderProfileFeature_ClientOrder
    {
        $kunde = OrderProfileFeature_ClientContainer::create(['FirstName' => $vorname, 'Surname' => $nachname]);
        $kunde->write();
        $bestellung = OrderProfileFeature_ClientOrder::create([
            'ClientContainerID' => $kunde->ID, 'OrderStatus' => $status, 'AdditionalNotes' => $notiz,
        ]);
        $bestellung->write();
        foreach ($positionen as $variante => $menge) {
            OrderProfileFeature_ProductContainer::create([
                'ClientOrderID' => $bestellung->ID,
                'ProductID' => $this->idFromFixture(Product::class, 'kaese'),
                'PriceBlockElementID' => $this->idFromFixture(Preis::class, $variante),
                'Quantity' => $menge,
            ])->write();
        }
        return $bestellung;
    }

    private function liste(): array
    {
        $antwort = $this->get('api/picking-list', null, ['X-Api-Token' => self::TOKEN]);
        $this->assertSame(200, $antwort->getStatusCode());
        return json_decode((string)$antwort->getBody(), true);
    }

    public function testGesamtlisteUndBestellungenNachKunde(): void
    {
        $zimmer = $this->bestellung('Zora', 'Zimmer', 'inBearbeitung', ['stueck' => 2, 'kilo' => 1]);
        $adam = $this->bestellung('Anna', 'Adam', 'inBearbeitung', ['stueck' => 3], 'in Papier');
        $this->bestellung('Otto', 'Offen', 'offen', ['stueck' => 9]);

        $liste = $this->liste();

        // Gesamtliste unverändert: je Variante eine Zeile, Summe über alle Bestellungen in Bearbeitung
        $this->assertSame(2, $liste['orderCount']);
        $mengen = array_column($liste['items'], 'quantity');
        sort($mengen);
        $this->assertSame([1, 5], $mengen, 'Stück 2 + 3, Kilo 1; die offene Bestellung zählt nicht');
        $stueck = array_values(array_filter($liste['items'], fn ($zeile) => $zeile['quantity'] === 5))[0];
        $this->assertSame(2, $stueck['orderCount'], 'Stück steckt in zwei Bestellungen');

        // Nach Kunde: jede Bestellung einmal, nach Nachname sortiert, mit ihren eigenen Mengen
        $this->assertSame([$adam->ID, $zimmer->ID], array_column($liste['orders'], 'id'));
        [$a, $z] = $liste['orders'];
        $this->assertSame(['firstName' => 'Anna', 'surname' => 'Adam', 'company' => null], $a['customer']);
        $this->assertSame('in Papier', $a['additionalNotes']);
        $this->assertSame([3], array_column($a['items'], 'quantity'));
        $mengen = array_column($z['items'], 'quantity');
        sort($mengen);
        $this->assertSame([1, 2], $mengen);
        $this->assertArrayNotHasKey('sortKey', $a, 'Hilfsfeld bleibt intern');
        $this->assertSame($stueck['variantTitle'], $a['items'][0]['variantTitle'], 'gleiche Bezeichnung wie in der Gesamtliste');
    }

    public function testOhneBestellungenLeer(): void
    {
        $this->bestellung('Otto', 'Offen', 'offen', ['stueck' => 1]);
        $liste = $this->liste();
        $this->assertSame([], $liste['items']);
        $this->assertSame([], $liste['orders']);
    }
}
