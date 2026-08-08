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

        foreach ($productContainers as $productContainer) {
            $product = $productContainer->Product();
            $variant = $productContainer->PriceBlockElement();

            $productId = ($product && $product->exists()) ? $product->ID : 0;
            $variantId = ($variant && $variant->exists()) ? $variant->ID : 0;
            $key = $productId . '-' . $variantId;

            if (!isset($totals[$key])) {
                $totals[$key] = [
                    'productTitle' => ($product && $product->exists()) ? $product->getSummaryTitle() : 'Unbekanntes Produkt',
                    'variantTitle' => ($variant && $variant->exists()) ? $variant->getFullTitle(false) : null,
                    'quantity' => 0,
                    'orderIds' => [],
                ];
            }

            $totals[$key]['quantity'] += (int) $productContainer->Quantity;
            $totals[$key]['orderIds'][$productContainer->ClientOrderID] = true;
            $orderIds[$productContainer->ClientOrderID] = true;
        }

        $items = array_values(array_map(function ($row) {
            return [
                'productTitle' => $row['productTitle'],
                'variantTitle' => $row['variantTitle'],
                'quantity' => $row['quantity'],
                'orderCount' => count($row['orderIds']),
            ];
        }, $totals));

        usort($items, function ($a, $b) {
            $cmp = strcasecmp($a['productTitle'], $b['productTitle']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['variantTitle'], (string) $b['variantTitle']);
        });

        $body = json_encode([
            'status' => $status,
            'orderCount' => count($orderIds),
            'generatedAt' => date('c'),
            'items' => $items,
        ], JSON_UNESCAPED_UNICODE);

        $response = HTTPResponse::create($body, 200);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }
}
