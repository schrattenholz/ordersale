<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ProductContainer;

class PickingListApiController extends Controller
{
    private static $url_handlers = [
        'GET ' => 'index',
    ];

    private static $allowed_actions = [
        'index',
    ];

    public function index(HTTPRequest $request)
    {
        $expected = Environment::getEnv('SOLA_APP_API_TOKEN');
        $provided = $request->getHeader('X-Api-Token');
        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            $response = HTTPResponse::create(json_encode(['error' => 'Nicht autorisiert.']), 401);
            $response->addHeader('Content-Type', 'application/json; charset=utf-8');
            return $response;
        }

        $status = $request->getVar('status') ?: 'inBearbeitung';

        $productContainers = OrderProfileFeature_ProductContainer::get()->filter([
            'ClientOrder.OrderStatus' => $status,
            'ClientOrder.IsModel' => false,
        ]);

        $totals = [];
        $orderIds = [];
        // Dieselben Positionen noch einmal je Bestellung, für die Ansicht "Nach Kunde"
        $orders = [];

        foreach ($productContainers as $productContainer) {
            $product = $productContainer->Product();
            $variant = $productContainer->PriceBlockElement();

            $productId = ($product && $product->exists()) ? $product->ID : 0;
            $variantId = ($variant && $variant->exists()) ? $variant->ID : 0;
            $key = $productId . '-' . $variantId;
            $productTitle = ($product && $product->exists()) ? $product->getSummaryTitle() : 'Unbekanntes Produkt';
            $variantTitle = ($variant && $variant->exists()) ? $variant->getFullTitle(false) : null;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'productTitle' => $productTitle,
                    'variantTitle' => $variantTitle,
                    'quantity' => 0,
                    'orderIds' => [],
                ];
            }

            $totals[$key]['quantity'] += (int) $productContainer->Quantity;
            $totals[$key]['orderIds'][$productContainer->ClientOrderID] = true;
            $orderIds[$productContainer->ClientOrderID] = true;

            $orderId = (int) $productContainer->ClientOrderID;
            if (!isset($orders[$orderId])) {
                $orders[$orderId] = $this->orderSummary($productContainer->ClientOrder());
            }
            if (!isset($orders[$orderId]['items'][$key])) {
                $orders[$orderId]['items'][$key] = [
                    'productTitle' => $productTitle,
                    'variantTitle' => $variantTitle,
                    'quantity' => 0,
                ];
            }
            $orders[$orderId]['items'][$key]['quantity'] += (int) $productContainer->Quantity;
        }

        $items = array_values(array_map(function ($row) {
            return [
                'productTitle' => $row['productTitle'],
                'variantTitle' => $row['variantTitle'],
                'quantity' => $row['quantity'],
                'orderCount' => count($row['orderIds']),
            ];
        }, $totals));

        $byTitle = function ($a, $b) {
            $cmp = strcasecmp($a['productTitle'], $b['productTitle']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['variantTitle'], (string) $b['variantTitle']);
        };
        usort($items, $byTitle);

        foreach ($orders as &$order) {
            $order['items'] = array_values($order['items']);
            usort($order['items'], $byTitle);
        }
        unset($order);
        $orders = array_values($orders);
        // nach Kunde: Nachname, Vorname, Firma; bei gleichem Namen die ältere Bestellung zuerst
        usort($orders, function ($a, $b) {
            $cmp = strcasecmp($a['sortKey'], $b['sortKey']);
            return $cmp !== 0 ? $cmp : $a['id'] <=> $b['id'];
        });
        foreach ($orders as &$order) {
            unset($order['sortKey']);
        }
        unset($order);

        $body = json_encode([
            'status' => $status,
            'orderCount' => count($orderIds),
            'generatedAt' => date('c'),
            'items' => $items,
            'orders' => $orders,
        ], JSON_UNESCAPED_UNICODE);

        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    /** Kopf einer Bestellung für die Ansicht "Nach Kunde", Positionen kommen in der Schleife dazu. */
    private function orderSummary($order): array
    {
        $client = $order->ClientContainer();
        $deliveryType = $order->DeliveryType();
        return [
            'id' => (int) $order->ID,
            'customer' => [
                'firstName' => $client->FirstName,
                'surname' => $client->Surname,
                'company' => $client->Company,
            ],
            'shippingDate' => $order->ShippingDate,
            'deliveryType' => ($deliveryType && $deliveryType->exists()) ? $deliveryType->Title : null,
            'additionalNotes' => $order->AdditionalNotes,
            'items' => [],
            'sortKey' => implode(' ', array_filter([$client->Surname, $client->FirstName, $client->Company])),
        ];
    }
}
