<?php

declare(strict_types=1);

namespace Tbank\Invest;

use Tbank\Invest\Exception\TInvestException;

final class MethodSpec
{
    public function __construct(
        public readonly string $service,
        public readonly string $method,
        public readonly string $description,
        public readonly bool $mutating = false,
        public readonly string $group = '',
    ) {
    }

    public function path(): string
    {
        return '/tinkoff.public.invest.api.contract.v1.' . $this->service . '/' . $this->method;
    }

    public function key(): string
    {
        return $this->service . '/' . $this->method;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'method' => $this->method,
            'key' => $this->key(),
            'description' => $this->description,
            'mutating' => $this->mutating,
            'group' => $this->group,
        ];
    }
}

final class Catalog
{
    /** @var list<MethodSpec> */
    private static array $methods = [];

    /** @var array<string, MethodSpec> */
    private static array $index = [];

    public const array INSTRUMENT_LIST_METHODS = [
        'share' => 'Shares',
        'bond' => 'Bonds',
        'etf' => 'Etfs',
        'currency' => 'Currencies',
        'future' => 'Futures',
        'option' => 'OptionsBy',
        'indicative' => 'Indicatives',
    ];

    public const array INSTRUMENT_BY_METHODS = [
        'instrument' => 'GetInstrumentBy',
        'share' => 'ShareBy',
        'bond' => 'BondBy',
        'etf' => 'EtfBy',
        'currency' => 'CurrencyBy',
        'future' => 'FutureBy',
        'option' => 'OptionBy',
    ];

    public const array CANDLE_INTERVALS = [
        '5s' => 'CANDLE_INTERVAL_5_SEC',
        '10s' => 'CANDLE_INTERVAL_10_SEC',
        '30s' => 'CANDLE_INTERVAL_30_SEC',
        '1m' => 'CANDLE_INTERVAL_1_MIN',
        '1min' => 'CANDLE_INTERVAL_1_MIN',
        '2m' => 'CANDLE_INTERVAL_2_MIN',
        '3m' => 'CANDLE_INTERVAL_3_MIN',
        '5m' => 'CANDLE_INTERVAL_5_MIN',
        '10m' => 'CANDLE_INTERVAL_10_MIN',
        '15m' => 'CANDLE_INTERVAL_15_MIN',
        '30m' => 'CANDLE_INTERVAL_30_MIN',
        '1h' => 'CANDLE_INTERVAL_HOUR',
        'hour' => 'CANDLE_INTERVAL_HOUR',
        '2h' => 'CANDLE_INTERVAL_2_HOUR',
        '4h' => 'CANDLE_INTERVAL_4_HOUR',
        '1d' => 'CANDLE_INTERVAL_DAY',
        'day' => 'CANDLE_INTERVAL_DAY',
        'd' => 'CANDLE_INTERVAL_DAY',
        '1w' => 'CANDLE_INTERVAL_WEEK',
        'week' => 'CANDLE_INTERVAL_WEEK',
        '1mo' => 'CANDLE_INTERVAL_MONTH',
        'month' => 'CANDLE_INTERVAL_MONTH',
    ];

    /** @return list<MethodSpec> */
    public static function methods(): array
    {
        self::boot();

        return self::$methods;
    }

    public static function resolve(string $service, string $method): MethodSpec
    {
        self::boot();
        $key = strtolower($service . '/' . $method);
        if (!isset(self::$index[$key])) {
            throw new TInvestException(
                "Unknown T-Invest method {$service}/{$method}. See GET /v1/catalog.",
                404,
                null,
                null,
                'unknown_method',
            );
        }

        return self::$index[$key];
    }

    public static function resolveInterval(string $interval): string
    {
        $raw = trim($interval);
        if (str_starts_with(strtoupper($raw), 'CANDLE_INTERVAL_')) {
            return strtoupper($raw);
        }
        $mapped = self::CANDLE_INTERVALS[strtolower($raw)] ?? null;
        if ($mapped === null) {
            throw new TInvestException(
                "Unknown candle interval '{$interval}'. Use 1m, 5m, 15m, 1h, day, week, month or a CANDLE_INTERVAL_* constant.",
                400,
                null,
                null,
                'invalid_interval',
            );
        }

        return $mapped;
    }

    private static function boot(): void
    {
        if (self::$methods !== []) {
            return;
        }

        $defs = [
            ['UsersService', 'GetAccounts', 'Список брокерских счетов'],
            ['UsersService', 'GetInfo', 'Информация о пользователе и статусе квалификации'],
            ['UsersService', 'GetUserTariff', 'Тариф и лимиты API'],
            ['UsersService', 'GetMarginAttributes', 'Маржинальные показатели счёта'],
            ['UsersService', 'GetBankAccounts', 'Список банковских счетов'],
            ['UsersService', 'CurrencyTransfer', 'Перевод валюты между счетами', true],
            ['InstrumentsService', 'FindInstrument', 'Поиск инструмента по тикеру, FIGI, ISIN, UID, названию'],
            ['InstrumentsService', 'GetInstrumentBy', 'Основная информация об инструменте'],
            ['InstrumentsService', 'ShareBy', 'Акция по идентификатору'],
            ['InstrumentsService', 'Shares', 'Список акций'],
            ['InstrumentsService', 'BondBy', 'Облигация по идентификатору'],
            ['InstrumentsService', 'Bonds', 'Список облигаций'],
            ['InstrumentsService', 'EtfBy', 'Фонд по идентификатору'],
            ['InstrumentsService', 'Etfs', 'Список фондов'],
            ['InstrumentsService', 'CurrencyBy', 'Валюта по идентификатору'],
            ['InstrumentsService', 'Currencies', 'Список валют'],
            ['InstrumentsService', 'FutureBy', 'Фьючерс по идентификатору'],
            ['InstrumentsService', 'Futures', 'Список фьючерсов'],
            ['InstrumentsService', 'OptionBy', 'Опцион по идентификатору'],
            ['InstrumentsService', 'OptionsBy', 'Список опционов с фильтром'],
            ['InstrumentsService', 'GetDividends', 'Дивиденды по инструменту'],
            ['InstrumentsService', 'GetBondCoupons', 'Купонный календарь облигации'],
            ['InstrumentsService', 'GetAccruedInterests', 'НКД облигации'],
            ['InstrumentsService', 'GetBondEvents', 'События облигации: купоны, оферты, погашение'],
            ['InstrumentsService', 'GetAssetFundamentals', 'Фундаментальные показатели актива'],
            ['InstrumentsService', 'GetForecastBy', 'Консенсус-прогнозы по инструменту'],
            ['InstrumentsService', 'GetConsensusForecasts', 'Лента консенсус-прогнозов'],
            ['InstrumentsService', 'GetAssetBy', 'Актив по идентификатору'],
            ['InstrumentsService', 'GetAssets', 'Список активов'],
            ['InstrumentsService', 'GetFuturesMargin', 'Гарантийное обеспечение фьючерса'],
            ['InstrumentsService', 'TradingSchedules', 'Расписание торгов'],
            ['InstrumentsService', 'GetFavorites', 'Избранные инструменты'],
            ['InstrumentsService', 'EditFavorites', 'Изменить избранное', true],
            ['InstrumentsService', 'GetFavoriteGroups', 'Группы избранного'],
            ['InstrumentsService', 'CreateFavoriteGroup', 'Создать группу избранного', true],
            ['InstrumentsService', 'DeleteFavoriteGroup', 'Удалить группу избранного', true],
            ['InstrumentsService', 'GetCountries', 'Справочник стран'],
            ['InstrumentsService', 'GetBrands', 'Список брендов'],
            ['InstrumentsService', 'GetBrandBy', 'Бренд по идентификатору'],
            ['InstrumentsService', 'GetRiskRates', 'Ставки риска'],
            ['InstrumentsService', 'GetInsiderDeals', 'Сделки инсайдеров'],
            ['InstrumentsService', 'GetAssetReports', 'Отчётность эмитента'],
            ['InstrumentsService', 'Indicatives', 'Индикативные инструменты'],
            ['MarketDataService', 'GetCandles', 'Исторические свечи'],
            ['MarketDataService', 'GetLastPrices', 'Цены последних сделок'],
            ['MarketDataService', 'GetOrderBook', 'Биржевой стакан'],
            ['MarketDataService', 'GetLastTrades', 'Лента обезличенных сделок'],
            ['MarketDataService', 'GetClosePrices', 'Цены закрытия'],
            ['MarketDataService', 'GetTradingStatus', 'Торговый статус инструмента'],
            ['MarketDataService', 'GetTradingStatuses', 'Торговые статусы нескольких инструментов'],
            ['MarketDataService', 'GetTechAnalysis', 'Технический анализ'],
            ['MarketDataService', 'GetMarketValues', 'Рыночные метрики'],
            ['OperationsService', 'GetPortfolio', 'Портфель счёта'],
            ['OperationsService', 'GetPositions', 'Позиции счёта'],
            ['OperationsService', 'GetOperations', 'Операции за период'],
            ['OperationsService', 'GetOperationsByCursor', 'Операции с пагинацией'],
            ['OperationsService', 'GetWithdrawLimits', 'Лимиты на вывод'],
            ['OperationsService', 'GetBrokerReport', 'Брокерский отчёт'],
            ['OperationsService', 'GetDividendsForeignIssuer', 'Дивиденды иностранных эмитентов'],
            ['OrdersService', 'GetOrders', 'Активные заявки'],
            ['OrdersService', 'GetOrderState', 'Статус заявки'],
            ['OrdersService', 'GetMaxLots', 'Доступное количество лотов'],
            ['OrdersService', 'GetOrderPrice', 'Расчёт цены заявки'],
            ['OrdersService', 'PostOrder', 'Выставить заявку', true],
            ['OrdersService', 'PostOrderAsync', 'Выставить заявку асинхронно', true],
            ['OrdersService', 'CancelOrder', 'Отменить заявку', true],
            ['OrdersService', 'ReplaceOrder', 'Изменить заявку', true],
            ['StopOrdersService', 'GetStopOrders', 'Стоп-заявки'],
            ['StopOrdersService', 'PostStopOrder', 'Выставить стоп-заявку', true],
            ['StopOrdersService', 'CancelStopOrder', 'Отменить стоп-заявку', true],
            ['SandboxService', 'GetSandboxAccounts', 'Счета песочницы'],
            ['SandboxService', 'OpenSandboxAccount', 'Открыть счёт песочницы', true],
            ['SandboxService', 'CloseSandboxAccount', 'Закрыть счёт песочницы', true],
            ['SandboxService', 'SandboxPayIn', 'Пополнить счёт песочницы', true],
            ['SandboxService', 'GetSandboxPortfolio', 'Портфель песочницы'],
            ['SandboxService', 'GetSandboxPositions', 'Позиции песочницы'],
            ['SandboxService', 'GetSandboxOperations', 'Операции песочницы'],
            ['SandboxService', 'GetSandboxOperationsByCursor', 'Операции песочницы с пагинацией'],
            ['SandboxService', 'GetSandboxOrders', 'Заявки песочницы'],
            ['SandboxService', 'GetSandboxOrderState', 'Статус заявки песочницы'],
            ['SandboxService', 'GetSandboxMaxLots', 'Доступные лоты в песочнице'],
            ['SandboxService', 'GetSandboxOrderPrice', 'Расчёт цены заявки в песочнице'],
            ['SandboxService', 'PostSandboxOrder', 'Заявка в песочнице', true],
            ['SandboxService', 'PostSandboxOrderAsync', 'Асинхронная заявка в песочнице', true],
            ['SandboxService', 'CancelSandboxOrder', 'Отмена заявки песочницы', true],
            ['SandboxService', 'ReplaceSandboxOrder', 'Изменение заявки песочницы', true],
            ['SandboxService', 'GetSandboxStopOrders', 'Стоп-заявки песочницы'],
            ['SandboxService', 'PostSandboxStopOrder', 'Стоп-заявка в песочнице', true],
            ['SandboxService', 'CancelSandboxStopOrder', 'Отмена стоп-заявки песочницы', true],
            ['SandboxService', 'GetSandboxWithdrawLimits', 'Лимиты вывода песочницы'],
            ['SignalService', 'GetSignals', 'Торговые сигналы'],
            ['SignalService', 'GetStrategies', 'Стратегии сигналов'],
        ];

        foreach ($defs as $def) {
            $service = $def[0];
            $method = $def[1];
            $description = $def[2];
            $mutating = $def[3] ?? false;
            $group = str_ends_with($service, 'Service') ? substr($service, 0, -7) : $service;
            $spec = new MethodSpec($service, $method, $description, (bool) $mutating, $group);
            self::$methods[] = $spec;
            self::$index[strtolower($spec->key())] = $spec;
        }
    }
}
