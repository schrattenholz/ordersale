<?php

namespace Schrattenholz\OrderSale\Tests;

use Schrattenholz\Delivery\CollectionDay;
use Schrattenholz\Delivery\DeliveryType;
use Schrattenholz\Order\Preis;
use Schrattenholz\Order\Product;
use Schrattenholz\Order\ProductList;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroup;
use Schrattenholz\OrderProfileFeature\OrderCustomerGroups_Preis;
use Schrattenholz\Payment\PaymentMethod_Collection;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\FunctionalTest;

/**
 * Gemeinsame Vorbereitung der Tests von api/erfassung: Hofladen mit zwei
 * Kundengruppen, Testkäse in drei Varianten, Abholung am Freitag.
 */
abstract class ErfassungTestBasis extends FunctionalTest
{
    protected static $fixture_file = ['CheckoutRegressionTest.yml', 'ErfassungApiTest.yml'];

    protected const TOKEN = 'erfassung-test-token';
    protected const SCHREIB_TOKEN = 'erfassung-schreib-token';

    private array $envVorher = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['SOLA_APP_API_TOKEN' => self::TOKEN, 'SOLA_APP_ERFASSUNG_TOKEN' => self::SCHREIB_TOKEN] as $name => $wert) {
            $this->envVorher[$name] = Environment::getEnv($name);
            Environment::setEnv($name, $wert);
        }

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
        foreach ($this->envVorher as $name => $wert) {
            Environment::setEnv($name, $wert);
        }
        parent::tearDown();
    }

    /** Lesender Aufruf; gibt [Status, JSON] zurück. */
    protected function abfrage(string $pfad, ?string $token = self::TOKEN): array
    {
        $antwort = $this->get('api/erfassung/' . $pfad, null, $token ? ['X-Api-Token' => $token] : []);
        return [$antwort->getStatusCode(), json_decode((string)$antwort->getBody(), true)];
    }

    /** Aufruf an die Entwürfe; gibt [Status, JSON] zurück. */
    protected function schreiben(string $methode, string $pfad, ?array $daten = null, ?string $token = self::SCHREIB_TOKEN): array
    {
        $antwort = $this->mainSession->sendRequest(
            $methode,
            'api/erfassung/' . $pfad,
            [],
            $token ? ['X-Erfassung-Token' => $token, 'Content-Type' => 'application/json'] : [],
            null,
            $daten === null ? null : json_encode($daten)
        );
        return [$antwort->getStatusCode(), json_decode((string)$antwort->getBody(), true)];
    }

    protected function variantenVon(array $sortiment): array
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
}
