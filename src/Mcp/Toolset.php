<?php

declare(strict_types=1);

namespace Tbank\Invest\Mcp;

use Tbank\Invest\Exception\TInvestException;
use Tbank\Invest\Service;

final class Toolset
{
    public function __construct(private readonly Service $service)
    {
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        $defs = [];
        foreach ($this->specs() as $spec) {
            $defs[] = [
                'name' => $spec['name'],
                'description' => $spec['description'],
                'inputSchema' => $spec['schema'],
            ];
        }

        return $defs;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|list<mixed>
     */
    public function call(string $name, array $arguments): array
    {
        foreach ($this->specs() as $spec) {
            if ($spec['name'] === $name) {
                $result = ($spec['handler'])($arguments);

                return is_array($result) ? $result : ['value' => $result];
            }
        }

        throw new TInvestException("Unknown MCP tool '{$name}'", 404, null, null, 'unknown_tool');
    }

    /**
     * @return list<array{name: string, description: string, schema: array<string, mixed>, handler: callable}>
     */
    private function specs(): array
    {
        $s = $this->service;
        $str = ['type' => 'string'];
        $int = ['type' => 'integer'];
        $num = ['type' => 'number'];
        $bool = ['type' => 'boolean'];
        $obj = static fn (array $props, array $required = []) => array_filter([
            'type' => 'object',
            'properties' => $props,
            'required' => $required,
            'additionalProperties' => true,
        ], static fn ($v) => $v !== []);

        return [
            [
                'name' => 'tbank_info',
                'description' => 'Информация о сервере, окружении (sandbox/production) и токене.',
                'schema' => $obj([]),
                'handler' => fn () => $s->serverInfo(),
            ],
            [
                'name' => 'tbank_catalog',
                'description' => 'Каталог методов T-Invest REST. Фильтры: group, mutating.',
                'schema' => $obj(['group' => $str, 'mutating' => $bool]),
                'handler' => fn (array $a) => $s->catalog($a['group'] ?? null, isset($a['mutating']) ? (bool) $a['mutating'] : null),
            ],
            [
                'name' => 'tbank_call',
                'description' => 'Универсальный вызов любого метода T-Invest: UsersService/GetAccounts и т.п.',
                'schema' => $obj([
                    'service' => $str,
                    'method' => $str,
                    'body' => ['type' => 'object', 'additionalProperties' => true],
                    'allow_unknown' => $bool,
                ], ['service', 'method']),
                'handler' => fn (array $a) => $s->call(
                    (string) $a['service'],
                    (string) $a['method'],
                    is_array($a['body'] ?? null) ? $a['body'] : [],
                    (bool) ($a['allow_unknown'] ?? false),
                ),
            ],
            [
                'name' => 'tbank_accounts',
                'description' => 'Список брокерских счетов.',
                'schema' => $obj(['status' => $str]),
                'handler' => fn (array $a) => $s->getAccounts($a['status'] ?? null),
            ],
            [
                'name' => 'tbank_user',
                'description' => 'Информация о пользователе и квалификации.',
                'schema' => $obj([]),
                'handler' => fn () => $s->getUserInfo(),
            ],
            [
                'name' => 'tbank_user_tariff',
                'description' => 'Тариф и лимиты API.',
                'schema' => $obj([]),
                'handler' => fn () => $s->getUserTariff(),
            ],
            [
                'name' => 'tbank_bank_accounts',
                'description' => 'Банковские счета.',
                'schema' => $obj([]),
                'handler' => fn () => $s->getBankAccounts(),
            ],
            [
                'name' => 'tbank_margin',
                'description' => 'Маржинальные показатели счёта.',
                'schema' => $obj(['account_id' => $str]),
                'handler' => fn (array $a) => $s->getMarginAttributes($a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_search_instruments',
                'description' => 'Поиск инструмента по тикеру, FIGI, ISIN, UID или названию.',
                'schema' => $obj([
                    'query' => $str,
                    'kind' => $str,
                    'api_trade_available' => $bool,
                    'limit' => $int,
                ], ['query']),
                'handler' => fn (array $a) => $s->findInstrument(
                    (string) $a['query'],
                    $a['kind'] ?? null,
                    array_key_exists('api_trade_available', $a) ? (bool) $a['api_trade_available'] : true,
                    isset($a['limit']) ? (int) $a['limit'] : 20,
                ),
            ],
            [
                'name' => 'tbank_get_instrument',
                'description' => 'Карточка инструмента. Можно передать тикер, FIGI, UID или ISIN.',
                'schema' => $obj([
                    'instrument' => $str,
                    'class_code' => $str,
                    'instrument_type' => $str,
                ], ['instrument']),
                'handler' => fn (array $a) => $s->getInstrument(
                    (string) $a['instrument'],
                    $a['class_code'] ?? null,
                    (string) ($a['instrument_type'] ?? 'instrument'),
                ),
            ],
            [
                'name' => 'tbank_list_instruments',
                'description' => 'Список инструментов типа share/bond/etf/currency/future/option.',
                'schema' => $obj([
                    'type' => $str,
                    'status' => $str,
                    'query' => $str,
                    'limit' => $int,
                ]),
                'handler' => fn (array $a) => $s->listInstruments(
                    (string) ($a['type'] ?? 'share'),
                    (string) ($a['status'] ?? 'INSTRUMENT_STATUS_BASE'),
                    isset($a['limit']) ? (int) $a['limit'] : 100,
                    $a['query'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_dividends',
                'description' => 'Дивиденды по инструменту.',
                'schema' => $obj(['instrument' => $str, 'from' => $str, 'to' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getDividends($a['instrument'], $a['from'] ?? null, $a['to'] ?? null, $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_coupons',
                'description' => 'Купонный календарь облигации.',
                'schema' => $obj(['instrument' => $str, 'from' => $str, 'to' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getBondCoupons($a['instrument'], $a['from'] ?? null, $a['to'] ?? null, $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_accrued',
                'description' => 'НКД облигации.',
                'schema' => $obj(['instrument' => $str, 'from' => $str, 'to' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getAccruedInterests($a['instrument'], $a['from'] ?? null, $a['to'] ?? null, $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_bond_events',
                'description' => 'События облигации: купоны, оферты, погашение.',
                'schema' => $obj(['instrument' => $str, 'from' => $str, 'to' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getBondEvents($a['instrument'], $a['from'] ?? null, $a['to'] ?? null, $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_fundamentals',
                'description' => 'Фундаментальные показатели актива.',
                'schema' => $obj(['instrument' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getAssetFundamentals([(string) $a['instrument']]),
            ],
            [
                'name' => 'tbank_forecasts',
                'description' => 'Консенсус-прогнозы по инструменту.',
                'schema' => $obj(['instrument' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getForecasts((string) $a['instrument'], $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_schedules',
                'description' => 'Расписание торгов.',
                'schema' => $obj(['exchange' => $str, 'from' => $str, 'to' => $str]),
                'handler' => fn (array $a) => $s->getTradingSchedules($a['exchange'] ?? null, $a['from'] ?? null, $a['to'] ?? null),
            ],
            [
                'name' => 'tbank_favorites',
                'description' => 'Избранные инструменты.',
                'schema' => $obj([]),
                'handler' => fn () => $s->getFavorites(),
            ],
            [
                'name' => 'tbank_candles',
                'description' => 'Исторические свечи. Интервал: 1m, 5m, 15m, 1h, day, week, month.',
                'schema' => $obj([
                    'instrument' => $str,
                    'interval' => $str,
                    'from' => $str,
                    'to' => $str,
                    'limit' => $int,
                    'class_code' => $str,
                ], ['instrument']),
                'handler' => fn (array $a) => $s->getCandles(
                    (string) $a['instrument'],
                    (string) ($a['interval'] ?? '1h'),
                    $a['from'] ?? null,
                    $a['to'] ?? null,
                    isset($a['limit']) ? (int) $a['limit'] : null,
                    $a['class_code'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_last_prices',
                'description' => 'Цены последних сделок по списку инструментов.',
                'schema' => $obj([
                    'instruments' => ['type' => 'array', 'items' => $str],
                    'class_code' => $str,
                ], ['instruments']),
                'handler' => fn (array $a) => $s->getLastPrices(self::strings($a['instruments'] ?? []), $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_orderbook',
                'description' => 'Биржевой стакан.',
                'schema' => $obj(['instrument' => $str, 'depth' => $int, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getOrderBook(
                    (string) $a['instrument'],
                    isset($a['depth']) ? (int) $a['depth'] : 10,
                    $a['class_code'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_trades',
                'description' => 'Лента обезличенных сделок.',
                'schema' => $obj(['instrument' => $str, 'from' => $str, 'to' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getLastTrades(
                    (string) $a['instrument'],
                    $a['from'] ?? null,
                    $a['to'] ?? null,
                    $a['class_code'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_close_prices',
                'description' => 'Цены закрытия.',
                'schema' => $obj([
                    'instruments' => ['type' => 'array', 'items' => $str],
                    'class_code' => $str,
                ], ['instruments']),
                'handler' => fn (array $a) => $s->getClosePrices(self::strings($a['instruments'] ?? []), $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_trading_status',
                'description' => 'Торговый статус инструмента.',
                'schema' => $obj(['instrument' => $str, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getTradingStatus((string) $a['instrument'], $a['class_code'] ?? null),
            ],
            [
                'name' => 'tbank_portfolio',
                'description' => 'Портфель счёта.',
                'schema' => $obj(['account_id' => $str, 'currency' => $str]),
                'handler' => fn (array $a) => $s->getPortfolio($a['account_id'] ?? null, (string) ($a['currency'] ?? 'RUB')),
            ],
            [
                'name' => 'tbank_positions',
                'description' => 'Позиции счёта.',
                'schema' => $obj(['account_id' => $str]),
                'handler' => fn (array $a) => $s->getPositions($a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_operations',
                'description' => 'Операции счёта с пагинацией.',
                'schema' => $obj([
                    'account_id' => $str,
                    'from' => $str,
                    'to' => $str,
                    'instrument' => $str,
                    'cursor' => $str,
                    'limit' => $int,
                    'state' => $str,
                ]),
                'handler' => fn (array $a) => $s->getOperations(
                    $a['account_id'] ?? null,
                    $a['from'] ?? null,
                    $a['to'] ?? null,
                    $a['instrument'] ?? null,
                    $a['cursor'] ?? null,
                    isset($a['limit']) ? (int) $a['limit'] : 100,
                    $a['state'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_withdraw_limits',
                'description' => 'Лимиты на вывод.',
                'schema' => $obj(['account_id' => $str]),
                'handler' => fn (array $a) => $s->getWithdrawLimits($a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_orders',
                'description' => 'Активные заявки.',
                'schema' => $obj(['account_id' => $str]),
                'handler' => fn (array $a) => $s->getOrders($a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_order_state',
                'description' => 'Статус заявки.',
                'schema' => $obj(['order_id' => $str, 'account_id' => $str], ['order_id']),
                'handler' => fn (array $a) => $s->getOrderState((string) $a['order_id'], $a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_max_lots',
                'description' => 'Доступное количество лотов.',
                'schema' => $obj(['instrument' => $str, 'account_id' => $str, 'price' => $num, 'class_code' => $str], ['instrument']),
                'handler' => fn (array $a) => $s->getMaxLots(
                    (string) $a['instrument'],
                    $a['account_id'] ?? null,
                    $a['class_code'] ?? null,
                    isset($a['price']) ? (float) $a['price'] : null,
                ),
            ],
            [
                'name' => 'tbank_post_order',
                'description' => 'Выставить заявку. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj([
                    'instrument' => $str,
                    'quantity' => $int,
                    'direction' => $str,
                    'order_type' => $str,
                    'price' => $num,
                    'account_id' => $str,
                    'order_id' => $str,
                    'class_code' => $str,
                ], ['instrument', 'quantity', 'direction']),
                'handler' => fn (array $a) => $s->postOrder(
                    (string) $a['instrument'],
                    (int) $a['quantity'],
                    (string) $a['direction'],
                    (string) ($a['order_type'] ?? 'limit'),
                    $a['account_id'] ?? null,
                    isset($a['price']) ? (float) $a['price'] : null,
                    $a['order_id'] ?? null,
                    $a['class_code'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_cancel_order',
                'description' => 'Отменить заявку. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj(['order_id' => $str, 'account_id' => $str], ['order_id']),
                'handler' => fn (array $a) => $s->cancelOrder((string) $a['order_id'], $a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_stop_orders',
                'description' => 'Стоп-заявки.',
                'schema' => $obj(['account_id' => $str]),
                'handler' => fn (array $a) => $s->getStopOrders($a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_post_stop_order',
                'description' => 'Выставить стоп-заявку. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj([
                    'instrument' => $str,
                    'quantity' => $int,
                    'direction' => $str,
                    'stop_order_type' => $str,
                    'price' => $num,
                    'stop_price' => $num,
                    'account_id' => $str,
                    'expire_date' => $str,
                    'class_code' => $str,
                ], ['instrument', 'quantity', 'direction', 'stop_order_type']),
                'handler' => fn (array $a) => $s->postStopOrder(
                    (string) $a['instrument'],
                    (int) $a['quantity'],
                    (string) $a['direction'],
                    (string) $a['stop_order_type'],
                    $a['account_id'] ?? null,
                    isset($a['price']) ? (float) $a['price'] : null,
                    isset($a['stop_price']) ? (float) $a['stop_price'] : null,
                    $a['expire_date'] ?? null,
                    $a['class_code'] ?? null,
                ),
            ],
            [
                'name' => 'tbank_cancel_stop_order',
                'description' => 'Отменить стоп-заявку. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj(['stop_order_id' => $str, 'account_id' => $str], ['stop_order_id']),
                'handler' => fn (array $a) => $s->cancelStopOrder((string) $a['stop_order_id'], $a['account_id'] ?? null),
            ],
            [
                'name' => 'tbank_sandbox_accounts',
                'description' => 'Счета песочницы.',
                'schema' => $obj([]),
                'handler' => fn () => $s->getSandboxAccounts(),
            ],
            [
                'name' => 'tbank_sandbox_open',
                'description' => 'Открыть счёт песочницы. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj(['name' => $str]),
                'handler' => fn (array $a) => $s->openSandboxAccount($a['name'] ?? null),
            ],
            [
                'name' => 'tbank_sandbox_close',
                'description' => 'Закрыть счёт песочницы. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj(['account_id' => $str], ['account_id']),
                'handler' => fn (array $a) => $s->closeSandboxAccount((string) $a['account_id']),
            ],
            [
                'name' => 'tbank_sandbox_pay_in',
                'description' => 'Пополнить счёт песочницы. Требует TBANK_ALLOW_TRADING=true.',
                'schema' => $obj(['account_id' => $str, 'amount' => $num, 'currency' => $str], ['account_id', 'amount']),
                'handler' => fn (array $a) => $s->sandboxPayIn(
                    (string) $a['account_id'],
                    (float) $a['amount'],
                    (string) ($a['currency'] ?? 'rub'),
                ),
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($item) => $item !== ''));
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
