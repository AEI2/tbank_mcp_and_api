<?php

declare(strict_types=1);

namespace Tbank\Invest\Http;

use Tbank\Invest\Exception\TInvestException;
use Tbank\Invest\Mcp\StreamableHttp;
use Tbank\Invest\Mcp\Toolset;
use Tbank\Invest\Service;

final class Routes
{
    public static function register(Router $router, Service $service, Toolset $toolset, StreamableHttp $streamable): void
    {
        $router->get('/', fn () => $service->serverInfo() + [
            'docs' => [
                'health' => '/health',
                'catalog' => '/v1/catalog',
                'tools' => '/v1/tools',
                'proxy' => 'POST /v1/tinvest/{service}/{method}',
                'mcp' => 'GET|POST|DELETE /mcp (Streamable HTTP)',
            ],
        ]);
        $router->get('/health', fn () => [
            'status' => 'ok',
            'environment' => $service->config->environment,
            'allow_trading' => $service->config->allowTrading,
        ]);
        $router->get('/v1/info', fn () => $service->serverInfo());
        $router->get('/v1/catalog', function (Request $req) use ($service) {
            $mutating = $req->query('mutating');
            $mutatingFlag = $mutating === null ? null : filter_var($mutating, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $service->catalog(
                $req->query('group') !== null ? (string) $req->query('group') : null,
                is_bool($mutatingFlag) ? $mutatingFlag : null,
            );
        });
        $router->get('/v1/tools', fn () => [
            'count' => count($toolset->definitions()),
            'tools' => $toolset->definitions(),
        ]);

        $router->post('/v1/tinvest/{service}/{method}', function (Request $req) use ($service) {
            $payload = $req->body ?? [];
            $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;
            $allowUnknown = (bool) ($payload['allow_unknown'] ?? false);

            return $service->call(
                (string) $req->param('service'),
                (string) $req->param('method'),
                is_array($body) ? $body : [],
                $allowUnknown,
            );
        });
        $mcp = static fn (Request $req) => $streamable->handle($req);
        $router->get('/mcp', $mcp);
        $router->post('/mcp', $mcp);
        $router->delete('/mcp', $mcp);
        $router->options('/mcp', $mcp);

        $router->get('/v1/accounts', fn (Request $req) => $service->getAccounts(
            $req->query('status') !== null ? (string) $req->query('status') : null,
        ));
        $router->get('/v1/user', fn () => $service->getUserInfo());
        $router->get('/v1/user/tariff', fn () => $service->getUserTariff());
        $router->get('/v1/user/bank-accounts', fn () => $service->getBankAccounts());
        $router->get('/v1/accounts/{account_id}/margin', fn (Request $req) => $service->getMarginAttributes((string) $req->param('account_id')));

        $router->get('/v1/instruments/search', function (Request $req) use ($service) {
            $q = trim((string) $req->query('q', ''));
            if ($q === '') {
                throw new TInvestException('Query parameter q is required', 400, null, null, 'invalid_query');
            }
            $apiTrade = $req->query('api_trade_available');
            $apiTradeFlag = $apiTrade === null ? true : filter_var($apiTrade, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $service->findInstrument(
                $q,
                $req->query('kind') !== null ? (string) $req->query('kind') : null,
                is_bool($apiTradeFlag) ? $apiTradeFlag : true,
                (int) $req->query('limit', 20),
            );
        });
        $router->get('/v1/instruments', fn (Request $req) => $service->listInstruments(
            (string) $req->query('type', 'share'),
            (string) $req->query('status', 'INSTRUMENT_STATUS_BASE'),
            (int) $req->query('limit', 100),
            $req->query('q') !== null ? (string) $req->query('q') : null,
        ));
        $router->get('/v1/instruments/{instrument}', fn (Request $req) => $service->getInstrument(
            (string) $req->param('instrument'),
            $req->query('class_code') !== null ? (string) $req->query('class_code') : null,
            (string) $req->query('instrument_type', 'instrument'),
        ));
        $router->get('/v1/instruments/{instrument}/dividends', fn (Request $req) => $service->getDividends(
            (string) $req->param('instrument'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/instruments/{instrument}/coupons', fn (Request $req) => $service->getBondCoupons(
            (string) $req->param('instrument'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/instruments/{instrument}/accrued', fn (Request $req) => $service->getAccruedInterests(
            (string) $req->param('instrument'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/instruments/{instrument}/events', fn (Request $req) => $service->getBondEvents(
            (string) $req->param('instrument'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/instruments/{instrument}/fundamentals', fn (Request $req) => $service->getAssetFundamentals([(string) $req->param('instrument')]));
        $router->get('/v1/instruments/{instrument}/forecasts', fn (Request $req) => $service->getForecasts(
            (string) $req->param('instrument'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/instruments/{instrument}/futures-margin', fn (Request $req) => $service->getFuturesMargin(
            (string) $req->param('instrument'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/schedules', fn (Request $req) => $service->getTradingSchedules(
            self::opt($req, 'exchange'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
        ));
        $router->get('/v1/favorites', fn () => $service->getFavorites());

        $router->get('/v1/market/candles', fn (Request $req) => $service->getCandles(
            self::req($req, 'instrument'),
            (string) $req->query('interval', '1h'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            $req->query('limit') !== null ? (int) $req->query('limit') : null,
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/market/last-prices', fn (Request $req) => $service->getLastPrices(
            self::listParam($req, 'instruments'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/market/orderbook', fn (Request $req) => $service->getOrderBook(
            self::req($req, 'instrument'),
            (int) $req->query('depth', 10),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/market/trades', fn (Request $req) => $service->getLastTrades(
            self::req($req, 'instrument'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/market/close-prices', fn (Request $req) => $service->getClosePrices(
            self::listParam($req, 'instruments'),
            self::opt($req, 'class_code'),
        ));
        $router->get('/v1/market/status', fn (Request $req) => $service->getTradingStatus(
            self::req($req, 'instrument'),
            self::opt($req, 'class_code'),
        ));

        $router->get('/v1/accounts/{account_id}/portfolio', fn (Request $req) => $service->getPortfolio(
            (string) $req->param('account_id'),
            (string) $req->query('currency', 'RUB'),
        ));
        $router->get('/v1/accounts/{account_id}/positions', fn (Request $req) => $service->getPositions((string) $req->param('account_id')));
        $router->get('/v1/accounts/{account_id}/operations', fn (Request $req) => $service->getOperations(
            (string) $req->param('account_id'),
            self::opt($req, 'from'),
            self::opt($req, 'to'),
            self::opt($req, 'instrument'),
            self::opt($req, 'cursor'),
            (int) $req->query('limit', 100),
            self::opt($req, 'state'),
        ));
        $router->get('/v1/accounts/{account_id}/withdraw-limits', fn (Request $req) => $service->getWithdrawLimits((string) $req->param('account_id')));
        $router->get('/v1/accounts/{account_id}/orders', fn (Request $req) => $service->getOrders((string) $req->param('account_id')));
        $router->get('/v1/accounts/{account_id}/orders/{order_id}', fn (Request $req) => $service->getOrderState(
            (string) $req->param('order_id'),
            (string) $req->param('account_id'),
        ));
        $router->get('/v1/accounts/{account_id}/max-lots', fn (Request $req) => $service->getMaxLots(
            self::req($req, 'instrument'),
            (string) $req->param('account_id'),
            self::opt($req, 'class_code'),
            $req->query('price') !== null ? (float) $req->query('price') : null,
        ));
        $router->post('/v1/accounts/{account_id}/orders', function (Request $req) use ($service) {
            $p = $req->body ?? [];

            return $service->postOrder(
                (string) ($p['instrument'] ?? ''),
                (int) ($p['quantity'] ?? 0),
                (string) ($p['direction'] ?? ''),
                (string) ($p['order_type'] ?? $p['orderType'] ?? 'limit'),
                (string) ($p['account_id'] ?? $req->param('account_id')),
                isset($p['price']) ? (float) $p['price'] : null,
                isset($p['order_id']) ? (string) $p['order_id'] : (isset($p['orderId']) ? (string) $p['orderId'] : null),
                isset($p['class_code']) ? (string) $p['class_code'] : null,
                isset($p['time_in_force']) ? (string) $p['time_in_force'] : null,
                isset($p['confirm_margin_trade']) ? (bool) $p['confirm_margin_trade'] : null,
            );
        });
        $router->delete('/v1/accounts/{account_id}/orders/{order_id}', fn (Request $req) => $service->cancelOrder(
            (string) $req->param('order_id'),
            (string) $req->param('account_id'),
        ));
        $router->get('/v1/accounts/{account_id}/stop-orders', fn (Request $req) => $service->getStopOrders((string) $req->param('account_id')));
        $router->post('/v1/accounts/{account_id}/stop-orders', function (Request $req) use ($service) {
            $p = $req->body ?? [];

            return $service->postStopOrder(
                (string) ($p['instrument'] ?? ''),
                (int) ($p['quantity'] ?? 0),
                (string) ($p['direction'] ?? ''),
                (string) ($p['stop_order_type'] ?? $p['stopOrderType'] ?? ''),
                (string) ($p['account_id'] ?? $req->param('account_id')),
                isset($p['price']) ? (float) $p['price'] : null,
                isset($p['stop_price']) ? (float) $p['stop_price'] : (isset($p['stopPrice']) ? (float) $p['stopPrice'] : null),
                isset($p['expire_date']) ? (string) $p['expire_date'] : null,
                isset($p['class_code']) ? (string) $p['class_code'] : null,
            );
        });
        $router->delete('/v1/accounts/{account_id}/stop-orders/{stop_order_id}', fn (Request $req) => $service->cancelStopOrder(
            (string) $req->param('stop_order_id'),
            (string) $req->param('account_id'),
        ));

        $router->get('/v1/sandbox/accounts', fn () => $service->getSandboxAccounts());
        $router->post('/v1/sandbox/accounts', fn (Request $req) => $service->openSandboxAccount(
            isset(($req->body ?? [])['name']) ? (string) $req->body['name'] : null,
        ));
        $router->delete('/v1/sandbox/accounts/{account_id}', fn (Request $req) => $service->closeSandboxAccount((string) $req->param('account_id')));
        $router->post('/v1/sandbox/accounts/{account_id}/pay-in', function (Request $req) use ($service) {
            $p = $req->body ?? [];

            return $service->sandboxPayIn(
                (string) $req->param('account_id'),
                (float) ($p['amount'] ?? 0),
                (string) ($p['currency'] ?? 'rub'),
            );
        });
    }

    private static function opt(Request $req, string $key): ?string
    {
        $value = $req->query($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function req(Request $req, string $key): string
    {
        $value = trim((string) $req->query($key, ''));
        if ($value === '') {
            throw new TInvestException("Query parameter {$key} is required", 400, null, null, 'invalid_query');
        }

        return $value;
    }

    /** @return list<string> */
    private static function listParam(Request $req, string $key): array
    {
        $value = $req->query($key);
        if ($value === null || $value === '') {
            throw new TInvestException("Query parameter {$key} is required", 400, null, null, 'invalid_query');
        }
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), static fn ($item) => $item !== ''));
    }
}
