<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\TInvestException;

final class Service
{
    /** @var array<string, string> */
    private array $instrumentCache = [];

    public function __construct(
        public readonly Client $client,
        public readonly Config $config,
    ) {
    }

    public static function fromEnv(?Transport $transport = null): self
    {
        $config = Config::fromEnv();
        $client = new Client($config, $transport ?? new CurlTransport());

        return new self($client, $config);
    }

    /** @return array<string, mixed> */
    public function serverInfo(): array
    {
        return [
            'name' => Version::NAME,
            'version' => Version::NUMBER,
            'environment' => $this->config->environment,
            'rest_base_url' => $this->config->restBaseUrl(),
            'allow_trading' => $this->config->allowTrading,
            'app_name' => $this->config->appName,
            'token' => $this->config->maskedToken(),
            'methods' => count(Catalog::methods()),
            'mcp' => [
                'stdio' => true,
                'streamable_http' => '/mcp',
            ],
        ];
    }

    /**
     * @return array{count: int, methods: list<array<string, mixed>>}
     */
    public function catalog(?string $group = null, ?bool $mutating = null): array
    {
        $items = Catalog::methods();
        if ($group) {
            $needle = strtolower($group);
            $items = array_values(array_filter(
                $items,
                static fn (MethodSpec $spec) => str_contains(strtolower($spec->group), $needle)
                    || str_contains(strtolower($spec->service), $needle),
            ));
        }
        if ($mutating !== null) {
            $items = array_values(array_filter(
                $items,
                static fn (MethodSpec $spec) => $spec->mutating === $mutating,
            ));
        }

        return [
            'count' => count($items),
            'methods' => array_map(static fn (MethodSpec $spec) => $spec->toArray(), $items),
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    public function call(string $service, string $method, ?array $body = null, bool $allowUnknown = false): array
    {
        return $this->client->call($service, $method, $body, true, $allowUnknown);
    }

    /** @return array<string, mixed> */
    public function getAccounts(?string $status = null): array
    {
        $body = [];
        if ($status) {
            $body['status'] = $status;
        }

        return $this->client->call('UsersService', 'GetAccounts', $body);
    }

    /** @return array<string, mixed> */
    public function getUserInfo(): array
    {
        return $this->client->call('UsersService', 'GetInfo', []);
    }

    /** @return array<string, mixed> */
    public function getUserTariff(): array
    {
        return $this->client->call('UsersService', 'GetUserTariff', []);
    }

    /** @return array<string, mixed> */
    public function getMarginAttributes(?string $accountId = null): array
    {
        return $this->client->call('UsersService', 'GetMarginAttributes', [
            'accountId' => $this->accountId($accountId),
        ]);
    }

    /** @return array<string, mixed> */
    public function getBankAccounts(): array
    {
        return $this->client->call('UsersService', 'GetBankAccounts', []);
    }

    /** @return array<string, mixed> */
    public function findInstrument(
        string $query,
        ?string $instrumentKind = null,
        ?bool $apiTradeAvailable = true,
        ?int $limit = 20,
    ): array {
        $body = ['query' => $query];
        if ($instrumentKind) {
            $body['instrumentKind'] = $this->instrumentKind($instrumentKind);
        }
        if ($apiTradeAvailable !== null) {
            $body['apiTradeAvailableFlag'] = $apiTradeAvailable;
        }
        $data = $this->client->call('InstrumentsService', 'FindInstrument', $body);
        $instruments = $data['instruments'] ?? [];
        if (!is_array($instruments)) {
            $instruments = [];
        }
        if ($limit !== null) {
            $instruments = array_slice($instruments, 0, max($limit, 0));
        }
        $data['instruments'] = $instruments;
        $data['count'] = count($instruments);

        return $data;
    }

    /** @return array<string, mixed> */
    public function getInstrument(string $instrument, ?string $classCode = null, string $instrumentType = 'instrument'): array
    {
        $method = Catalog::INSTRUMENT_BY_METHODS[strtolower($instrumentType)] ?? 'GetInstrumentBy';
        $ref = InstrumentId::classify($instrument, $classCode);
        if ($ref->kind === InstrumentIdKind::QUERY) {
            $found = $this->findInstrument($instrument, limit: 1);
            $matches = $found['instruments'] ?? [];
            if (!is_array($matches) || $matches === []) {
                throw new TInvestException(
                    "Instrument '{$instrument}' was not found",
                    404,
                    null,
                    null,
                    'instrument_not_found',
                );
            }
            $first = $matches[0];
            $ref = InstrumentId::classify((string) ($first['uid'] ?? $first['figi'] ?? $instrument));
        } elseif ($ref->kind === InstrumentIdKind::TICKER && !$ref->classCode) {
            $resolved = $this->resolveInstrumentId($instrument, $classCode);
            $ref = InstrumentId::classify($resolved);
        }

        return $this->client->call('InstrumentsService', $method, InstrumentId::byRequest($ref));
    }

    /** @return array<string, mixed> */
    public function listInstruments(
        string $instrumentType,
        string $instrumentStatus = 'INSTRUMENT_STATUS_BASE',
        ?int $limit = 100,
        ?string $query = null,
    ): array {
        $method = Catalog::INSTRUMENT_LIST_METHODS[strtolower($instrumentType)] ?? null;
        if ($method === null) {
            throw new TInvestException(
                "Unknown instrument type '{$instrumentType}'. Use one of: " . implode(', ', array_keys(Catalog::INSTRUMENT_LIST_METHODS)) . '.',
                400,
                null,
                null,
                'invalid_instrument_type',
            );
        }
        $data = $this->client->call('InstrumentsService', $method, ['instrumentStatus' => $instrumentStatus]);
        $instruments = is_array($data['instruments'] ?? null) ? $data['instruments'] : [];
        if ($query) {
            $needle = strtolower($query);
            $instruments = array_values(array_filter($instruments, static function ($item) use ($needle) {
                if (!is_array($item)) {
                    return false;
                }
                foreach (['ticker', 'name', 'figi', 'isin'] as $field) {
                    if (str_contains(strtolower((string) ($item[$field] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }
        $total = count($instruments);
        if ($limit !== null) {
            $instruments = array_slice($instruments, 0, max($limit, 0));
        }
        $data['instruments'] = $instruments;
        $data['count'] = count($instruments);
        $data['total'] = $total;

        return $data;
    }

    /** @return array<string, mixed> */
    public function getDividends(string $instrument, ?string $from = null, ?string $to = null, ?string $classCode = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->client->call('InstrumentsService', 'GetDividends', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'from' => self::dt($from, $now->sub(new \DateInterval('P3Y'))),
            'to' => self::endDt($to, $now->add(new \DateInterval('P1Y'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function getBondCoupons(string $instrument, ?string $from = null, ?string $to = null, ?string $classCode = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->client->call('InstrumentsService', 'GetBondCoupons', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'from' => self::dt($from, $now->sub(new \DateInterval('P30D'))),
            'to' => self::endDt($to, $now->add(new \DateInterval('P1Y'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function getAccruedInterests(string $instrument, ?string $from = null, ?string $to = null, ?string $classCode = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->client->call('InstrumentsService', 'GetAccruedInterests', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'from' => self::dt($from, $now->sub(new \DateInterval('P7D'))),
            'to' => self::endDt($to, $now->add(new \DateInterval('P7D'))),
        ]);
    }

    /** @return array<string, mixed> */
    public function getBondEvents(string $instrument, ?string $from = null, ?string $to = null, ?string $classCode = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->client->call('InstrumentsService', 'GetBondEvents', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'from' => self::dt($from, $now->sub(new \DateInterval('P30D'))),
            'to' => self::endDt($to, $now->add(new \DateInterval('P1Y'))),
        ]);
    }

    /**
     * @param list<string> $assets
     * @return array<string, mixed>
     */
    public function getAssetFundamentals(array $assets): array
    {
        $resolved = [];
        foreach ($assets as $asset) {
            try {
                $details = $this->getInstrument($asset);
                $instrument = is_array($details['instrument'] ?? null) ? $details['instrument'] : [];
                $resolved[] = (string) ($instrument['assetUid'] ?? $asset);
            } catch (TInvestException) {
                $resolved[] = $asset;
            }
        }

        return $this->client->call('InstrumentsService', 'GetAssetFundamentals', ['assets' => $resolved]);
    }

    /** @return array<string, mixed> */
    public function getForecasts(string $instrument, ?string $classCode = null): array
    {
        return $this->client->call('InstrumentsService', 'GetForecastBy', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
        ]);
    }

    /** @return array<string, mixed> */
    public function getTradingSchedules(?string $exchange = null, ?string $from = null, ?string $to = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body = [
            'from' => self::dt($from, $now),
            'to' => self::endDt($to, $now->add(new \DateInterval('P7D'))),
        ];
        if ($exchange) {
            $body['exchange'] = $exchange;
        }

        return $this->client->call('InstrumentsService', 'TradingSchedules', $body);
    }

    /** @return array<string, mixed> */
    public function getFavorites(): array
    {
        return $this->client->call('InstrumentsService', 'GetFavorites', []);
    }

    /** @return array<string, mixed> */
    public function getFuturesMargin(string $instrument, ?string $classCode = null): array
    {
        return $this->client->call('InstrumentsService', 'GetFuturesMargin', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
        ]);
    }

    public function resolveInstrumentId(string $instrument, ?string $classCode = null): string
    {
        $cacheKey = strtoupper($instrument . '|' . ($classCode ?? ''));
        if (isset($this->instrumentCache[$cacheKey])) {
            return $this->instrumentCache[$cacheKey];
        }

        $ref = InstrumentId::classify($instrument, $classCode);
        if (in_array($ref->kind, [InstrumentIdKind::UID, InstrumentIdKind::FIGI], true)) {
            return $this->instrumentCache[$cacheKey] = $ref->instrumentId;
        }
        if ($ref->kind === InstrumentIdKind::TICKER && $ref->classCode) {
            return $this->instrumentCache[$cacheKey] = $ref->instrumentId;
        }

        $found = $this->findInstrument($instrument, limit: 5);
        $matches = is_array($found['instruments'] ?? null) ? $found['instruments'] : [];
        if ($classCode) {
            $filtered = array_values(array_filter(
                $matches,
                static fn ($item) => is_array($item) && ($item['classCode'] ?? null) === $classCode,
            ));
            if ($filtered !== []) {
                $matches = $filtered;
            }
        }
        if ($matches === []) {
            throw new TInvestException(
                "Could not resolve instrument '{$instrument}'",
                404,
                null,
                null,
                'instrument_not_found',
            );
        }
        $chosen = $matches[0];
        $instrumentId = (string) ($chosen['uid'] ?? $chosen['figi'] ?? $instrument);

        return $this->instrumentCache[$cacheKey] = $instrumentId;
    }

    /** @return array<string, mixed> */
    public function getCandles(
        string $instrument,
        string $interval = '1h',
        ?string $from = null,
        ?string $to = null,
        ?int $limit = null,
        ?string $classCode = null,
        ?string $candleSource = null,
    ): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body = [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'interval' => Catalog::resolveInterval($interval),
            'from' => self::dt($from, $now->sub(new \DateInterval('P7D'))),
            'to' => self::endDt($to, $now),
        ];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        if ($candleSource) {
            $body['candleSourceType'] = $candleSource;
        }

        return $this->client->call('MarketDataService', 'GetCandles', $body);
    }

    /**
     * @param list<string> $instruments
     * @return array<string, mixed>
     */
    public function getLastPrices(array $instruments, ?string $classCode = null, ?string $lastPriceType = null): array
    {
        $ids = array_map(fn (string $item) => $this->resolveInstrumentId($item, $classCode), $instruments);
        $body = ['instrumentId' => $ids];
        if ($lastPriceType) {
            $body['lastPriceType'] = $lastPriceType;
        }

        return $this->client->call('MarketDataService', 'GetLastPrices', $body);
    }

    /** @return array<string, mixed> */
    public function getOrderBook(string $instrument, int $depth = 10, ?string $classCode = null): array
    {
        return $this->client->call('MarketDataService', 'GetOrderBook', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'depth' => $depth,
        ]);
    }

    /** @return array<string, mixed> */
    public function getLastTrades(string $instrument, ?string $from = null, ?string $to = null, ?string $classCode = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->client->call('MarketDataService', 'GetLastTrades', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'from' => self::dt($from, $now->sub(new \DateInterval('PT30M'))),
            'to' => self::endDt($to, $now),
        ]);
    }

    /**
     * @param list<string> $instruments
     * @return array<string, mixed>
     */
    public function getClosePrices(array $instruments, ?string $classCode = null): array
    {
        $ids = array_map(fn (string $item) => $this->resolveInstrumentId($item, $classCode), $instruments);

        return $this->client->call('MarketDataService', 'GetClosePrices', [
            'instruments' => array_map(static fn (string $id) => ['instrumentId' => $id], $ids),
        ]);
    }

    /** @return array<string, mixed> */
    public function getTradingStatus(string $instrument, ?string $classCode = null): array
    {
        return $this->client->call('MarketDataService', 'GetTradingStatus', [
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
        ]);
    }

    /** @return array<string, mixed> */
    public function getPortfolio(?string $accountId = null, string $currency = 'RUB'): array
    {
        return $this->client->call('OperationsService', 'GetPortfolio', [
            'accountId' => $this->accountId($accountId),
            'currency' => $currency,
        ]);
    }

    /** @return array<string, mixed> */
    public function getPositions(?string $accountId = null): array
    {
        return $this->client->call('OperationsService', 'GetPositions', [
            'accountId' => $this->accountId($accountId),
        ]);
    }

    /** @return array<string, mixed> */
    public function getOperations(
        ?string $accountId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $instrument = null,
        ?string $cursor = null,
        int $limit = 100,
        ?string $state = null,
        ?string $classCode = null,
    ): array {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $body = [
            'accountId' => $this->accountId($accountId),
            'from' => self::dt($from, $now->sub(new \DateInterval('P30D'))),
            'to' => self::endDt($to, $now),
            'limit' => $limit,
        ];
        if ($instrument) {
            $body['instrumentId'] = $this->resolveInstrumentId($instrument, $classCode);
        }
        if ($cursor) {
            $body['cursor'] = $cursor;
        }
        if ($state) {
            $body['state'] = $state;
        }

        return $this->client->call('OperationsService', 'GetOperationsByCursor', $body);
    }

    /** @return array<string, mixed> */
    public function getWithdrawLimits(?string $accountId = null): array
    {
        return $this->client->call('OperationsService', 'GetWithdrawLimits', [
            'accountId' => $this->accountId($accountId),
        ]);
    }

    /** @return array<string, mixed> */
    public function getOrders(?string $accountId = null): array
    {
        return $this->client->call('OrdersService', 'GetOrders', [
            'accountId' => $this->accountId($accountId),
        ]);
    }

    /** @return array<string, mixed> */
    public function getOrderState(string $orderId, ?string $accountId = null, ?string $priceType = null): array
    {
        $body = [
            'accountId' => $this->accountId($accountId),
            'orderId' => $orderId,
        ];
        if ($priceType) {
            $body['priceType'] = $priceType;
        }

        return $this->client->call('OrdersService', 'GetOrderState', $body);
    }

    /** @return array<string, mixed> */
    public function getMaxLots(string $instrument, ?string $accountId = null, ?string $classCode = null, int|float|null $price = null): array
    {
        $body = [
            'accountId' => $this->accountId($accountId),
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
        ];
        if ($price !== null) {
            $body['price'] = Money::fromNumber($price);
        }

        return $this->client->call('OrdersService', 'GetMaxLots', $body);
    }

    /** @return array<string, mixed> */
    public function postOrder(
        string $instrument,
        int $quantity,
        string $direction,
        string $orderType,
        ?string $accountId = null,
        int|float|null $price = null,
        ?string $orderId = null,
        ?string $classCode = null,
        ?string $timeInForce = null,
        ?bool $confirmMarginTrade = null,
    ): array {
        $body = [
            'accountId' => $this->accountId($accountId),
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'quantity' => (string) $quantity,
            'direction' => self::orderDirection($direction),
            'orderType' => self::orderType($orderType),
            'orderId' => $orderId ?: self::uuid4(),
        ];
        if ($price !== null) {
            $body['price'] = Money::fromNumber($price);
        }
        if ($timeInForce) {
            $body['timeInForce'] = $timeInForce;
        }
        if ($confirmMarginTrade !== null) {
            $body['confirmMarginTrade'] = $confirmMarginTrade;
        }

        return $this->client->call('OrdersService', 'PostOrder', $body);
    }

    /** @return array<string, mixed> */
    public function cancelOrder(string $orderId, ?string $accountId = null): array
    {
        return $this->client->call('OrdersService', 'CancelOrder', [
            'accountId' => $this->accountId($accountId),
            'orderId' => $orderId,
        ]);
    }

    /** @return array<string, mixed> */
    public function getStopOrders(?string $accountId = null): array
    {
        return $this->client->call('StopOrdersService', 'GetStopOrders', [
            'accountId' => $this->accountId($accountId),
        ]);
    }

    /** @return array<string, mixed> */
    public function postStopOrder(
        string $instrument,
        int $quantity,
        string $direction,
        string $stopOrderType,
        ?string $accountId = null,
        int|float|null $price = null,
        int|float|null $stopPrice = null,
        ?string $expireDate = null,
        ?string $classCode = null,
        ?string $exchangeOrderType = null,
    ): array {
        $body = [
            'accountId' => $this->accountId($accountId),
            'instrumentId' => $this->resolveInstrumentId($instrument, $classCode),
            'quantity' => (string) $quantity,
            'direction' => self::orderDirection($direction),
            'stopOrderType' => $stopOrderType,
        ];
        if ($price !== null) {
            $body['price'] = Money::fromNumber($price);
        }
        if ($stopPrice !== null) {
            $body['stopPrice'] = Money::fromNumber($stopPrice);
        }
        if ($expireDate !== null) {
            $body['expireDate'] = self::dt($expireDate);
        }
        if ($exchangeOrderType) {
            $body['exchangeOrderType'] = $exchangeOrderType;
        }

        return $this->client->call('StopOrdersService', 'PostStopOrder', $body);
    }

    /** @return array<string, mixed> */
    public function cancelStopOrder(string $stopOrderId, ?string $accountId = null): array
    {
        return $this->client->call('StopOrdersService', 'CancelStopOrder', [
            'accountId' => $this->accountId($accountId),
            'stopOrderId' => $stopOrderId,
        ]);
    }

    /** @return array<string, mixed> */
    public function getSandboxAccounts(): array
    {
        return $this->client->call('SandboxService', 'GetSandboxAccounts', []);
    }

    /** @return array<string, mixed> */
    public function openSandboxAccount(?string $name = null): array
    {
        $body = [];
        if ($name) {
            $body['name'] = $name;
        }

        return $this->client->call('SandboxService', 'OpenSandboxAccount', $body);
    }

    /** @return array<string, mixed> */
    public function closeSandboxAccount(string $accountId): array
    {
        return $this->client->call('SandboxService', 'CloseSandboxAccount', ['accountId' => $accountId]);
    }

    /** @return array<string, mixed> */
    public function sandboxPayIn(string $accountId, int|float $amount, string $currency = 'rub'): array
    {
        $quotation = Money::fromNumber($amount);

        return $this->client->call('SandboxService', 'SandboxPayIn', [
            'accountId' => $accountId,
            'amount' => [
                'currency' => strtolower($currency),
                'units' => $quotation['units'],
                'nano' => $quotation['nano'],
            ],
        ]);
    }

    public function accountId(?string $accountId): string
    {
        $resolved = $accountId ?: $this->config->defaultAccountId;
        if ($resolved) {
            return $resolved;
        }
        $accounts = $this->getAccounts();
        $items = is_array($accounts['accounts'] ?? null) ? $accounts['accounts'] : [];
        if ($items === []) {
            $sandbox = $this->getSandboxAccounts();
            $items = is_array($sandbox['accounts'] ?? null) ? $sandbox['accounts'] : [];
        }
        if ($items === []) {
            throw new TInvestException(
                'No account id provided and no accounts were found',
                400,
                null,
                null,
                'account_required',
            );
        }
        $opened = array_values(array_filter(
            $items,
            static fn ($item) => is_array($item) && str_ends_with((string) ($item['status'] ?? ''), 'OPEN'),
        ));
        $chosen = $opened[0] ?? $items[0];

        return (string) $chosen['id'];
    }

    private static function instrumentKind(string $value): string
    {
        $raw = strtoupper(trim($value));
        $mapping = [
            'SHARE' => 'INSTRUMENT_TYPE_SHARE',
            'STOCK' => 'INSTRUMENT_TYPE_SHARE',
            'BOND' => 'INSTRUMENT_TYPE_BOND',
            'ETF' => 'INSTRUMENT_TYPE_ETF',
            'CURRENCY' => 'INSTRUMENT_TYPE_CURRENCY',
            'FUTURE' => 'INSTRUMENT_TYPE_FUTURES',
            'FUTURES' => 'INSTRUMENT_TYPE_FUTURES',
            'OPTION' => 'INSTRUMENT_TYPE_OPTION',
            'SP' => 'INSTRUMENT_TYPE_SP',
            'INDEX' => 'INSTRUMENT_TYPE_INDEX',
        ];
        if (str_starts_with($raw, 'INSTRUMENT_TYPE_')) {
            return $raw;
        }
        if (!isset($mapping[$raw])) {
            throw new TInvestException("Unknown instrument kind '{$value}'", 400, null, null, 'invalid_instrument_kind');
        }

        return $mapping[$raw];
    }

    private static function orderDirection(string $value): string
    {
        $raw = strtoupper(trim($value));
        $mapping = [
            'BUY' => 'ORDER_DIRECTION_BUY',
            'SELL' => 'ORDER_DIRECTION_SELL',
        ];
        if (str_starts_with($raw, 'ORDER_DIRECTION_')) {
            return $raw;
        }
        if (!isset($mapping[$raw])) {
            throw new TInvestException("Unknown order direction '{$value}'. Use buy or sell.", 400, null, null, 'invalid_direction');
        }

        return $mapping[$raw];
    }

    private static function orderType(string $value): string
    {
        $raw = strtoupper(trim($value));
        $mapping = [
            'LIMIT' => 'ORDER_TYPE_LIMIT',
            'MARKET' => 'ORDER_TYPE_MARKET',
            'BESTPRICE' => 'ORDER_TYPE_BESTPRICE',
            'BEST_PRICE' => 'ORDER_TYPE_BESTPRICE',
        ];
        if (str_starts_with($raw, 'ORDER_TYPE_')) {
            return $raw;
        }
        if (!isset($mapping[$raw])) {
            throw new TInvestException(
                "Unknown order type '{$value}'. Use limit, market or bestprice.",
                400,
                null,
                null,
                'invalid_order_type',
            );
        }

        return $mapping[$raw];
    }

    private static function dt(?string $value, ?\DateTimeImmutable $default = null): ?string
    {
        if ($value === null || trim($value) === '') {
            return $default?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }
        $text = trim($value);
        if (str_ends_with($text, 'Z') || preg_match('/[+-]\d\d:\d\d$/', $text) || preg_match('/[+-]\d{4}$/', $text)) {
            return $text;
        }
        if (strlen($text) === 10) {
            return $text . 'T00:00:00Z';
        }

        return $text;
    }

    private static function endDt(?string $value, ?\DateTimeImmutable $default = null): ?string
    {
        if (is_string($value) && strlen(trim($value)) === 10) {
            return trim($value) . 'T23:59:59Z';
        }

        return self::dt($value, $default);
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
