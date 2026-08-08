<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use SilverStripe\CMS\Model\SiteTree;
use Schrattenholz\Order\Preis;
use Schrattenholz\OrderSale\PreSale;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;

/**
 * Schnittstelle für die Vorverkaufs-Auswertung der App.
 *
 * GET /api/presales
 *     Liste aller Kampagnen, gruppiert nach Warengruppe.
 *
 * GET /api/presales/$ID
 *     Eine Kampagne mit allen zugehörigen Bestellungen.
 *
 * GET /api/presales/productlist/$ID
 *     Auswertung über *alle* Kampagnen einer Warengruppe: was wurde je
 *     Produkt und Variante verkauft. Sortiert nach Verkaufsquote aufsteigend,
 *     damit die Ladenhüter oben stehen.
 */
class PreSalesApiController extends Controller
{
    private static $url_handlers = [
        'GET productlist/$ID!' => 'productList',
        'GET $ID!' => 'detail',
        'GET ' => 'index',
    ];

    private static $allowed_actions = [
        'index',
        'detail',
        'productList',
    ];

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

    /** Lesbare Bezeichnung einer Variante -- die Preis-Zeilen haben meist keinen Titel. */
    private function variantLabel(?Preis $preis): string
    {
        if (!$preis || !$preis->exists()) {
            return 'unbekannt';
        }
        if ($preis->Title) {
            return $preis->Title;
        }
        if ($preis->Amount) {
            $unit = $preis->Unit === 'weight' ? 'g' : ($preis->Unit ?: '');
            return trim($preis->Amount . ' ' . $unit);
        }
        return 'Variante ' . $preis->ID;
    }

    private function campaignSummary(PreSale $ps): array
    {
        $start = $ps->StartInventory();
        $sold = $ps->SoldQuantity();
        return [
            'id' => (int)$ps->ID,
            'title' => $ps->Title,
            'productListId' => (int)$ps->ProductListID,
            'productList' => $ps->ProductList() && $ps->ProductList()->exists()
                ? $ps->ProductList()->Title : null,
            'start' => $ps->PreSaleStart,
            'end' => $ps->PreSaleEnd,
            'endPercentage' => (int)$ps->PreSaleEndPercentage,
            'active' => (bool)$ps->Active,
            'startInventory' => $start,
            'sold' => $sold,
            'reserved' => $ps->ReservedQuantity(),
            'soldPercentage' => $ps->SoldPercentageOfStart(),
            'thresholdReached' => $ps->EndThresholdReached(),
            'orderCount' => $ps->OrderCount(),
        ];
    }

    public function index(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $groups = [];
        foreach (PreSale::get() as $ps) {
            $listId = (int)$ps->ProductListID;
            if (!isset($groups[$listId])) {
                $list = $ps->ProductList();
                $groups[$listId] = [
                    'productListId' => $listId,
                    'productList' => ($list && $list->exists()) ? $list->Title : 'Ohne Warengruppe',
                    'campaigns' => [],
                ];
            }
            $groups[$listId]['campaigns'][] = $this->campaignSummary($ps);
        }
        return $this->json(['groups' => array_values($groups)]);
    }

    public function detail(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $ps = PreSale::get()->byID((int)$request->param('ID'));
        if (!$ps) {
            return $this->json(['error' => 'Vorverkauf nicht gefunden.'], 404);
        }

        // Bestellungen dieser Kampagne, mit ihren Positionen
        $orders = [];
        foreach ($ps->SoldContainers() as $pc) {
            $orderId = (int)$pc->ClientOrderID;
            if (!isset($orders[$orderId])) {
                $order = $pc->ClientOrder();
                $client = $order ? $order->ClientContainer() : null;
                $orders[$orderId] = [
                    'id' => $orderId,
                    'title' => $order ? $order->Title : null,
                    'status' => $order ? $order->OrderStatus : null,
                    'created' => $order ? $order->Created : null,
                    'customer' => ($client && $client->exists())
                        ? trim($client->FirstName . ' ' . $client->Surname) : null,
                    'items' => [],
                    'quantity' => 0,
                ];
            }
            $product = $pc->Product();
            $orders[$orderId]['items'][] = [
                'product' => ($product && $product->exists()) ? $product->Title : 'unbekannt',
                'variant' => $this->variantLabel($pc->PriceBlockElement()),
                'quantity' => (int)$pc->Quantity,
            ];
            $orders[$orderId]['quantity'] += (int)$pc->Quantity;
        }

        return $this->json([
            'campaign' => $this->campaignSummary($ps),
            'orders' => array_values($orders),
        ]);
    }

    /**
     * Auswertung über alle Kampagnen einer Warengruppe.
     *
     * Die absolute Verkaufszahl allein sagt wenig -- 2 von 2 verkauft heißt
     * ausverkauft, 3 von 50 heißt Ladenhüter. Maßgeblich ist deshalb die
     * Verkaufsquote, aufsteigend sortiert.
     */
    public function productList(HTTPRequest $request)
    {
        if ($denied = $this->denied()) {
            return $denied;
        }
        $listId = (int)$request->param('ID');
        $list = SiteTree::get()->byID($listId);
        if (!$list) {
            return $this->json(['error' => 'Warengruppe nicht gefunden.'], 404);
        }

        $campaigns = PreSale::get()->filter('ProductListID', $listId);
        $campaignIds = $campaigns->column('ID');
        if (!count($campaignIds)) {
            return $this->json([
                'productList' => ['id' => $listId, 'title' => $list->Title],
                'campaigns' => [],
                'variants' => [],
            ]);
        }

        // Verkaufte Mengen je Variante über alle Kampagnen hinweg
        $soldByVariant = [];
        $containers = OrderProfileFeature_ProductContainer::get()->filter([
            'PreSaleID' => $campaignIds,
            'ClientOrderID:GreaterThan' => 0,
        ]);
        foreach ($containers as $pc) {
            $key = (int)$pc->PriceBlockElementID;
            if (!isset($soldByVariant[$key])) {
                $soldByVariant[$key] = ['qty' => 0, 'orders' => []];
            }
            $soldByVariant[$key]['qty'] += (int)$pc->Quantity;
            $soldByVariant[$key]['orders'][(int)$pc->ClientOrderID] = true;
        }

        // Alle Varianten der Warengruppe -- auch die, die sich nie verkauft
        // haben. Gerade die sind hier interessant.
        // Die Verkäufe summieren sich über alle Kampagnen, der Anfangsbestand
        // gilt aber je Kampagne. Bezugsgröße ist deshalb der Bestand mal der
        // Anzahl Kampagnen -- die Quote ist damit ein Durchschnitt je Kampagne.
        // (Ein exakter Bestand je Kampagne käme erst mit PreSale_Item, Stufe 2.)
        $campaignCount = count($campaignIds);

        $rows = [];
        foreach (SiteTree::get()->filter('ParentID', $listId)->sort('Title') as $product) {
            foreach (Preis::get()->filter('ProductID', $product->ID)->sort('ID') as $preis) {
                $sold = $soldByVariant[$preis->ID]['qty'] ?? 0;
                $orders = isset($soldByVariant[$preis->ID])
                    ? count($soldByVariant[$preis->ID]['orders']) : 0;
                $start = (int)$preis->PreSaleStartInventory;
                $reference = $start * $campaignCount;
                $rows[] = [
                    'variantId' => (int)$preis->ID,
                    'productId' => (int)$product->ID,
                    'product' => $product->Title,
                    'variant' => $this->variantLabel($preis),
                    'startInventory' => $start,
                    'referenceInventory' => $reference,
                    'sold' => $sold,
                    'orders' => $orders,
                    'inventory' => (int)$preis->Inventory,
                    // null bedeutet: kein Anfangsbestand hinterlegt, Quote nicht berechenbar
                    'soldPercentage' => $reference > 0 ? (int)round($sold / $reference * 100) : null,
                ];
            }
        }

        // Ladenhüter zuerst: kleinste Quote oben. Varianten ohne
        // Anfangsbestand ans Ende, da nicht bewertbar.
        usort($rows, function ($a, $b) {
            $pa = $a['soldPercentage'];
            $pb = $b['soldPercentage'];
            if ($pa === null && $pb === null) {
                return $a['sold'] <=> $b['sold'];
            }
            if ($pa === null) {
                return 1;
            }
            if ($pb === null) {
                return -1;
            }
            if ($pa === $pb) {
                return $a['sold'] <=> $b['sold'];
            }
            return $pa <=> $pb;
        });

        $campaignRows = [];
        foreach ($campaigns as $ps) {
            $campaignRows[] = $this->campaignSummary($ps);
        }

        return $this->json([
            'productList' => ['id' => $listId, 'title' => $list->Title],
            'campaigns' => $campaignRows,
            'variants' => $rows,
            'totals' => [
                'variants' => count($rows),
                'campaigns' => $campaignCount,
                'sold' => array_sum(array_column($rows, 'sold')),
                'startInventory' => array_sum(array_column($rows, 'startInventory')),
                'referenceInventory' => array_sum(array_column($rows, 'referenceInventory')),
            ],
        ]);
    }
}
