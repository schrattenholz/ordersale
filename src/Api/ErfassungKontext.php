<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\CMS\Controllers\ModelAsController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use Schrattenholz\Order\ProductList;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_Basket;

/**
 * Führt Shop-Methoden für einen Entwurf aus, als säße der Kunde selbst im Shop.
 *
 * Die Shop-Methoden (addToList, setCheckoutAddress, setCheckoutDelivery,
 * bestellungAbschliessen) holen ihren Warenkorb über 'basketid' in der
 * Sitzung des Injector-Requests und die Kundengruppe über
 * Security::getCurrentUser(). Für die Dauer eines Aufrufs stellt diese Klasse
 * beides auf den Entwurf und seinen Kunden um und setzt es danach zurück --
 * auch bei einem Fehler. Niemand wird dabei angemeldet; es gibt keine
 * Sitzung im Browser und kein Cookie.
 *
 * Die Shop-Erweiterungen hängen am ContentController, deshalb trägt ein
 * Seiten-Controller die Aufrufe (eine Warengruppe, sonst irgendeine Seite).
 */
class ErfassungKontext
{
    /**
     * @param callable $arbeit erhält den Seiten-Controller, auf dem die Shop-Methoden liegen
     * @return mixed was $arbeit zurückgibt
     */
    public static function ausfuehren(OrderProfileFeature_Basket $korb, ?Member $kunde, callable $arbeit)
    {
        $benutzerVorher = Security::getCurrentUser();
        $anfrage = new HTTPRequest('POST', '/');
        $anfrage->setSession(new Session(['basketid' => (int)$korb->ID]));

        // Eigene Injector-Schicht: der Request gilt nur bis zum unnest()
        Injector::nest();
        Injector::inst()->registerService($anfrage, HTTPRequest::class);
        Security::setCurrentUser($kunde);
        $controller = ModelAsController::controller_for(static::traegerSeite());
        $controller->setRequest($anfrage);
        $controller->pushCurrent();
        try {
            return $arbeit($controller);
        } finally {
            $controller->popCurrent();
            Security::setCurrentUser($benutzerVorher);
            Injector::unnest();
        }
    }

    private static function traegerSeite(): SiteTree
    {
        return Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::LIVE);
            return ProductList::get()->first() ?: SiteTree::get()->first();
        });
    }
}
