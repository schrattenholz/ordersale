<?php

namespace Schrattenholz\OrderSale\Api;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Environment;
use Schrattenholz\OrderProfileFeature\OrderProfileFeature_ClientOrder;

class OrdersApiController extends Controller
{
    private static $url_handlers = [
        'GET ' => 'index',
        'GET $ID!' => 'show',
        'POST $ID!/status' => 'updateStatus',
    ];

    private static $allowed_actions = [
        'index',
        'show',
        'updateStatus',
    ];

    private const ALLOWED_STATUSES = ['offen', 'inBearbeitung', 'abgeschlossen'];

    public function index(HTTPRequest $request)
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        $list = OrderProfileFeature_ClientOrder::get();

        if (!$request->getVar('includeModels')) {
            $list = $list->filter('IsModel', false);
        }

        $status = $request->getVar('status');
        if ($status) {
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                return $this->jsonError('Ungueltiger status-Filter. Erlaubt: ' . implode(', ', self::ALLOWED_STATUSES), 400);
            }
            $list = $list->filter('OrderStatus', $status);
        }

        $q = trim((string) $request->getVar('q'));
        if ($q !== '') {
            $list = $list->filterAny([
                'ClientContainer.Surname:PartialMatch' => $q,
                'ClientContainer.FirstName:PartialMatch' => $q,
                'ClientContainer.Company:PartialMatch' => $q,
            ]);
        }

        $limit = (int) ($request->getVar('limit') ?: 100);
        $limit = max(1, min($limit, 500));
        $offset = max(0, (int) $request->getVar('offset'));

        $total = $list->count();
        $list = $list->limit($limit, $offset);

        $orders = [];
        foreach ($list as $order) {
            $orders[] = $this->serializeOrder($order);
        }

        return $this->jsonResponse([
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'orders' => $orders,
        ]);
    }

    public function show(HTTPRequest $request)
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        $order = OrderProfileFeature_ClientOrder::get()->byID((int) $request->param('ID'));
        if (!$order) {
            return $this->jsonError('Bestellung nicht gefunden.', 404);
        }

        return $this->jsonResponse($this->serializeOrder($order, true));
    }

    public function updateStatus(HTTPRequest $request)
    {
        if ($denied = $this->denyUnlessAuthorized($request)) {
            return $denied;
        }

        $order = OrderProfileFeature_ClientOrder::get()->byID((int) $request->param('ID'));
        if (!$order) {
            return $this->jsonError('Bestellung nicht gefunden.', 404);
        }

        $payload = json_decode($request->getBody() ?: '', true) ?: [];
        $newStatus = $payload['status'] ?? null;

        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            return $this->jsonError('Ungueltiger Status. Erlaubt: ' . implode(', ', self::ALLOWED_STATUSES), 400);
        }

        $order->OrderStatus = $newStatus;
        $order->write();

        return $this->jsonResponse($this->serializeOrder($order));
    }

    private function serializeOrder(OrderProfileFeature_ClientOrder $order, bool $withProducts = false): array
    {
        $client = $order->ClientContainer();
        $deliveryType = $order->DeliveryType();
        $route = $order->Route();
        $collectionDay = $order->CollectionDay();

        $data = [
            'id' => $order->ID,
            'created' => $order->Created,
            'lastEdited' => $order->LastEdited,
            'status' => $order->OrderStatus,
            'title' => $order->Title ?: ('Bestellung #' . $order->ID),
            'additionalNotes' => $order->AdditionalNotes,
            'shippingDate' => $order->ShippingDate,
            'deliveryType' => ($deliveryType && $deliveryType->exists()) ? $deliveryType->Title : null,
            'route' => ($route && $route->exists()) ? $route->Title : null,
            'collectionDay' => ($collectionDay && $collectionDay->exists()) ? $collectionDay->Day : null,
            'customer' => [
                'firstName' => $client->FirstName,
                'surname' => $client->Surname,
                'company' => $client->Company,
                'phone' => $client->PhoneNumber,
                'email' => $client->Email,
                'street' => $client->Street,
                'zip' => $client->ZIP,
                'city' => $client->City,
            ],
            'productCount' => $order->ProductContainers()->count(),
        ];

        if ($withProducts) {
            $products = [];
            foreach ($order->ProductContainers() as $productContainer) {
                $product = $productContainer->Product();
                $products[] = [
                    'title' => ($product && $product->exists()) ? $product->Title : null,
                    'quantity' => $productContainer->Quantity,
                ];
            }
            $data['products'] = $products;
        }

        return $data;
    }

    private function denyUnlessAuthorized(HTTPRequest $request): ?HTTPResponse
    {
        $expected = Environment::getEnv('SOLA_APP_API_TOKEN');
        $provided = $request->getHeader('X-Api-Token');

        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return $this->jsonError('Nicht autorisiert.', 401);
        }

        return null;
    }

    private function jsonResponse(array $data, int $code = 200): HTTPResponse
    {
        $response = HTTPResponse::create(json_encode($data, JSON_UNESCAPED_UNICODE), $code);
        $response->addHeader('Content-Type', 'application/json; charset=utf-8');
        return $response;
    }

    private function jsonError(string $message, int $code): HTTPResponse
    {
        return $this->jsonResponse(['error' => $message], $code);
    }
}
