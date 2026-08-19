# tbank_mcp_and_api

Универсальный **REST API** и **MCP-сервер** на PHP для [T-Invest API](https://developer.tbank.ru/invest/intro/intro/) (T-Bank / Тинькофф Инвестиции).

Обёртка поверх официального REST-прокси `https://invest-public-api.tbank.ru/rest`: удобные методы для счетов, инструментов, свечей, портфеля и заявок плюс универсальный вызов любого метода T-Invest.

## Возможности

- Каталог **90+** методов T-Invest (`Users`, `Instruments`, `MarketData`, `Operations`, `Orders`, `StopOrders`, `Sandbox`, `Signal`)
- Высокоуровневые REST-эндпоинты: поиск инструментов, свечи, стакан, портфель, заявки, песочница
- MCP stdio (Cursor / Claude / другие клиенты) и HTTP JSON-RPC на `POST /mcp`
- Песочница и боевой контур, торговля выключена, пока не задан `TBANK_ALLOW_TRADING=true`
- Нормализация `Quotation` / `MoneyValue` в поле `value`
- Резолв инструмента по тикеру, FIGI, UID, ISIN или названию

## Требования

- PHP 8.3+ с расширениями `curl` и `json`
- Composer
- Токен T-Invest: [настройки API](https://www.tbank.ru/invest/settings/api/)

## Установка

```bash
composer install
cp .env.example .env
```

В `.env`:

```env
TBANK_INVEST_TOKEN=t.your_token_here
TBANK_INVEST_ENV=sandbox          # или production
TBANK_ALLOW_TRADING=false
```

## HTTP API

```bash
composer api
# или: php bin/tbank-api
# или: php -S 0.0.0.0:8080 -t public public/index.php
```

| Метод | Путь | Описание |
| --- | --- | --- |
| GET | `/health` | Живость процесса |
| GET | `/v1/info` | Окружение и версия |
| GET | `/v1/catalog` | Каталог методов T-Invest |
| GET | `/v1/tools` | Список MCP-инструментов |
| POST | `/v1/tinvest/{service}/{method}` | Универсальный прокси |
| POST | `/mcp` | MCP JSON-RPC |
| GET | `/v1/accounts` | Счета |
| GET | `/v1/user` | Пользователь |
| GET | `/v1/instruments/search?q=SBER` | Поиск |
| GET | `/v1/instruments/{id}` | Карточка инструмента |
| GET | `/v1/market/candles?instrument=SBER&interval=1h` | Свечи |
| GET | `/v1/market/orderbook?instrument=SBER` | Стакан |
| GET | `/v1/accounts/{id}/portfolio` | Портфель |
| GET | `/v1/accounts/{id}/operations` | Операции |
| GET | `/v1/accounts/{id}/orders` | Заявки |
| POST | `/v1/accounts/{id}/orders` | Выставить заявку |
| DELETE | `/v1/accounts/{id}/orders/{order_id}` | Отменить заявку |
| GET/POST | `/v1/sandbox/accounts` | Счета песочницы |

Универсальный вызов:

```bash
curl -sS -X POST http://127.0.0.1:8080/v1/tinvest/UsersService/GetAccounts \
  -H 'Content-Type: application/json' \
  -d '{"body":{}}'
```

Торговые методы (`PostOrder`, `CancelOrder`, пополнение песочницы и т.д.) отвечают `403 trading_disabled`, пока `TBANK_ALLOW_TRADING=true`.

## MCP

```bash
php bin/tbank-mcp
```

Пример `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "tbank-invest": {
      "command": "php",
      "args": ["/absolute/path/to/bin/tbank-mcp"],
      "env": {
        "TBANK_INVEST_TOKEN": "t.your_token_here",
        "TBANK_INVEST_ENV": "sandbox",
        "TBANK_ALLOW_TRADING": "false"
      }
    }
  }
}
```

Основные инструменты:

- `tbank_call` — любой метод T-Invest (`service` + `method` + `body`)
- `tbank_accounts`, `tbank_user`, `tbank_portfolio`, `tbank_positions`, `tbank_operations`
- `tbank_search_instruments`, `tbank_get_instrument`, `tbank_candles`, `tbank_orderbook`, `tbank_last_prices`
- `tbank_post_order` / `tbank_cancel_order` (только при разрешённой торговле)
- `tbank_sandbox_*` — счета и пополнение песочницы

Полный список: `GET /v1/tools` или MCP `tools/list`.

## Библиотека

```php
use Tbank\Invest\Service;

$service = Service::fromEnv();
$accounts = $service->getAccounts();
$candles = $service->getCandles('SBER', interval: '1h');
$raw = $service->call('MarketDataService', 'GetLastPrices', [
    'instrumentId' => ['BBG004730N88'],
]);
```

## Тесты

```bash
composer test
```

Токен для unit-тестов не нужен: HTTP к T-Invest подменяется.

## Безопасность

Токен даёт доступ к брокерскому счёту. Не коммитьте `.env`. Боевую торговлю включайте только осознанно (`TBANK_ALLOW_TRADING=true`). Для экспериментов используйте `TBANK_INVEST_ENV=sandbox`.
