<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class RevenueCatService
{
    protected string $apiKey;
    protected string $projectId;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.revenuecat.api_key', '');
        $this->projectId = (string) config('services.revenuecat.project_id', '');
        $this->baseUrl = rtrim((string) config('services.revenuecat.api_url', 'https://api.revenuecat.com/v2'), '/');
    }

    public function getCustomers(int $limit = 50, ?string $startingAfter = null): array
    {
        $query = ['limit' => min(max($limit, 1), 100)];

        if (!empty($startingAfter)) {
            $query['starting_after'] = $startingAfter;
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->get("{$this->baseUrl}/projects/{$this->projectId}/customers", $query);

        if ($response->failed()) {
            Log::error('RevenueCat Customers Fetch Error: ' . $response->body());
            return ['items' => []];
        }

        return $response->json();
    }

    public function getCustomerSubscriptions(string $customerId): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ])->get("{$this->baseUrl}/projects/{$this->projectId}/customers/" . rawurlencode($customerId) . "/subscriptions");

        if ($response->failed()) {
            Log::error("RevenueCat Customer Subscription Fetch Error for ID {$customerId}: " . $response->body());
            return ['items' => []];
        }

        return $response->json();
    }

    /** Fetch a complete, environment-scoped customer collection without treating API failure as an empty result. */
    public function fetchCustomerMembershipState(string $customerId, string $environment): array
    {
        if ($this->apiKey === '' || $this->projectId === '' || ! in_array($environment, ['production', 'sandbox'], true)) {
            return ['ok' => false, 'subscriptions' => [], 'purchases' => [], 'error' => 'RevenueCat is not configured for reconciliation.'];
        }

        try {
            $subscriptions = $this->fetchCustomerCollection($customerId, 'subscriptions', $environment);
            if (! $subscriptions['ok']) return ['ok' => false, 'subscriptions' => [], 'purchases' => [], 'error' => $subscriptions['error']];

            $purchases = $this->fetchCustomerCollection($customerId, 'purchases', $environment);
            if (! $purchases['ok']) return ['ok' => false, 'subscriptions' => [], 'purchases' => [], 'error' => $purchases['error']];

            // V2 product_id is a RevenueCat catalog ID, not the store SKU. Resolve it
            // through product metadata before membership code compares store identities.
            $products = [];
            $subscriptionItems = $this->resolveMembershipStoreProducts($subscriptions['items'], $products);
            $purchaseItems = $this->resolveMembershipStoreProducts($purchases['items'], $products);

            return ['ok' => true, 'subscriptions' => $subscriptionItems, 'purchases' => $purchaseItems];
        } catch (Throwable $exception) {
            Log::warning('RevenueCat membership reconciliation request failed.', [
                'resource' => 'customer_membership',
                'customer_id' => $customerId,
                'exception' => $exception::class,
            ]);

            return ['ok' => false, 'subscriptions' => [], 'purchases' => [], 'error' => 'RevenueCat could not verify current purchases.'];
        }
    }

    /** Receipt lookup only: a receipt does not establish a legacy pack's unspent balance. */
    public function fetchCustomerPackPurchases(string $customerId, string $environment): array
    {
        if ($this->apiKey === '' || $this->projectId === '' || ! in_array($environment, ['production', 'sandbox'], true)) {
            return ['ok' => false, 'items' => []];
        }
        try {
            $result = $this->fetchCustomerCollection($customerId, 'purchases', $environment);
            if (! $result['ok']) return $result;
            $products = [];
            return ['ok' => true, 'items' => $this->resolveMembershipStoreProducts($result['items'], $products)];
        } catch (Throwable $exception) {
            Log::warning('Legacy pack receipt lookup failed.', ['exception' => $exception::class]);
            return ['ok' => false, 'items' => []];
        }
    }

    private function resolveMembershipStoreProducts(array $items, array &$products): array
    {
        foreach ($items as &$item) {
            $productId = $item['product_id'] ?? null;
            if (! is_string($productId) || ! str_starts_with($productId, 'prod')) continue;

            if (! array_key_exists($productId, $products)) {
                $endpoint = "{$this->baseUrl}/projects/{$this->projectId}/products/" . rawurlencode($productId);
                $response = Http::connectTimeout(3)->timeout(8)->withHeaders($this->headers())->get($endpoint);
                $product = $response->json();
                if (! $response->successful() || ! is_array($product)
                    || ($product['id'] ?? null) !== $productId
                    || ! is_string($product['store_identifier'] ?? null)
                    || trim($product['store_identifier']) === '') {
                    // An incomplete verification must not retire a customer's existing access.
                    throw new \RuntimeException('RevenueCat membership product could not be verified.');
                }
                $products[$productId] = $product['store_identifier'];
            }
            $item['store_product_id'] = $products[$productId];
        }
        unset($item);

        return $items;
    }

    private function fetchCustomerCollection(string $customerId, string $resource, string $environment): array
    {
        $items = [];
        $startingAfter = null;
        $seenCursors = [];
        $endpoint = "{$this->baseUrl}/projects/{$this->projectId}/customers/" . rawurlencode($customerId) . "/{$resource}";

        for ($page = 0; $page < 20; $page++) {
            $query = ['environment' => $environment, 'limit' => 100];
            if ($startingAfter) $query['starting_after'] = $startingAfter;
            $response = Http::connectTimeout(3)->timeout(8)->withHeaders($this->headers())->get($endpoint, $query);
            if ($response->failed()) {
                Log::warning('RevenueCat membership reconciliation request failed.', [
                    'resource' => $resource, 'status' => $response->status(), 'customer_id' => $customerId,
                ]);
                return ['ok' => false, 'items' => [], 'error' => 'RevenueCat could not verify current purchases.'];
            }
            $body = $response->json();
            if (! is_array($body) || ! is_array($body['items'] ?? null)) {
                Log::warning('RevenueCat membership reconciliation returned an invalid response.', [
                    'resource' => $resource,
                    'customer_id' => $customerId,
                ]);
                return ['ok' => false, 'items' => [], 'error' => 'RevenueCat returned an invalid membership response.'];
            }
            foreach ($body['items'] as $item) {
                if (! is_array($item)) {
                    Log::warning('RevenueCat membership reconciliation returned an invalid record.', [
                        'resource' => $resource,
                        'customer_id' => $customerId,
                    ]);
                    return ['ok' => false, 'items' => [], 'error' => 'RevenueCat returned an invalid membership response.'];
                }
            }
            array_push($items, ...$body['items']);
            $next = $body['next_page'] ?? null;
            if ($next === null || $next === '') return ['ok' => true, 'items' => $items];
            if (! is_string($next)) {
                return ['ok' => false, 'items' => [], 'error' => 'RevenueCat returned an invalid pagination cursor.'];
            }
            parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);
            $nextCursor = $nextQuery['starting_after'] ?? null;
            if (! is_string($nextCursor) || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                return ['ok' => false, 'items' => [], 'error' => 'RevenueCat returned an invalid pagination cursor.'];
            }
            $seenCursors[$nextCursor] = true;
            $startingAfter = $nextCursor;
        }

        return ['ok' => false, 'items' => [], 'error' => 'RevenueCat returned too many membership records to reconcile safely.'];
    }

    public function getMetricsOverview(): array
    {
        try {
            if ($this->apiKey === '' || $this->projectId === '' || $this->baseUrl === '') {
                throw new \RuntimeException('RevenueCat API configuration is incomplete.');
            }

            $response = Http::connectTimeout(3)
                ->timeout(6)
                ->withHeaders($this->headers())
                ->get("{$this->baseUrl}/projects/{$this->projectId}/metrics/overview");

            if ($response->failed()) {
                Log::warning('RevenueCat metrics overview request failed.', [
                    'status' => $response->status(),
                    'project_id' => $this->projectId,
                ]);

                return ['ok' => false, 'metrics' => [], 'error' => 'RevenueCat metrics are unavailable.'];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                throw new \UnexpectedValueException('RevenueCat metrics overview response was invalid.');
            }

            return [
                'ok' => true,
                'metrics' => $this->normalizeOverviewMetrics($payload),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('RevenueCat metrics overview request failed.', [
                'project_id' => $this->projectId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ['ok' => false, 'metrics' => [], 'error' => 'RevenueCat metrics are unavailable.'];
        }
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    protected function getAllPages(string $url, array $query, string $resource): array
    {
        $items = [];
        $cursor = null;
        $seenCursors = [];

        do {
            $pageQuery = $query;
            if ($cursor !== null) {
                $pageQuery['starting_after'] = $cursor;
            }

            $page = $this->getPage($url, $pageQuery, $resource);
            $items = array_merge($items, $page['items']);
            $nextPage = $page['next_page'];
            if (! is_string($nextPage) || $nextPage === '') {
                break;
            }

            parse_str((string) parse_url($nextPage, PHP_URL_QUERY), $nextQuery);
            $nextCursor = $nextQuery['starting_after'] ?? null;
            if (! is_string($nextCursor) || $nextCursor === '' || isset($seenCursors[$nextCursor])) {
                break;
            }

            $seenCursors[$nextCursor] = true;
            $cursor = $nextCursor;
        } while (true);

        return $items;
    }

    protected function getPage(string $url, array $query, string $resource): array
    {
        if ($this->apiKey === '' || $this->projectId === '' || $this->baseUrl === '') {
            throw new \RuntimeException('RevenueCat API configuration is incomplete.');
        }

        $response = Http::connectTimeout(5)
            ->timeout(10)
            ->withHeaders($this->headers())
            ->get($url, $query);

        if ($response->failed()) {
            Log::warning('RevenueCat API request failed.', [
                'resource' => $resource,
                'status' => $response->status(),
                'url' => $url,
            ]);

            return ['items' => [], 'next_page' => null];
        }

        $json = $response->json();
        if (! is_array($json) || ! is_array($json['items'] ?? null)) {
            return ['items' => [], 'next_page' => null];
        }

        return [
            'items' => $json['items'],
            'next_page' => $json['next_page'] ?? null,
        ];
    }

    private function normalizeOverviewMetrics(array $payload): array
    {
        $aliases = [
            'active_subscriptions' => ['active_subscriptions', 'activeSubscriptions'],
            'mrr' => ['mrr', 'monthly_recurring_revenue', 'monthlyRecurringRevenue'],
            'revenue_28d' => ['revenue', 'revenue_28d', 'revenue_last_28_days', 'revenueLast28Days'],
            'new_customers_28d' => ['new_customers', 'new_customers_28d', 'new_customers_last_28_days', 'newCustomers'],
            'active_users_28d' => ['active_users', 'active_users_28d', 'active_users_last_28_days', 'activeUsers'],
        ];

        $metrics = [];
        foreach ($aliases as $name => $keys) {
            $metrics[$name] = $this->findMetricValue($payload, $keys);
        }

        return $metrics;
    }

    private function findMetricValue(array $payload, array $keys): int|float|string|null
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array($key, $keys, true)) {
                if (is_array($value)) {
                    $value = $value['value'] ?? $value['amount'] ?? $value['total'] ?? $value['display_value'] ?? null;
                }

                if (is_int($value) || is_float($value) || is_string($value)) {
                    return $value;
                }
            }

            if (is_array($value)) {
                $identifier = $value['id'] ?? $value['key'] ?? $value['name'] ?? $value['metric'] ?? null;
                if (is_string($identifier) && in_array($identifier, $keys, true)) {
                    $metricValue = $value['value'] ?? $value['amount'] ?? $value['total'] ?? $value['display_value'] ?? null;
                    if (is_int($metricValue) || is_float($metricValue) || is_string($metricValue)) {
                        return $metricValue;
                    }
                }

                $found = $this->findMetricValue($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
