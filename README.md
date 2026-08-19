# tbank_mcp_and_api

Универсальный **REST API** и **MCP-сервер** на PHP для [T-Invest API](https://developer.tbank.ru/invest/intro/intro/) (T-Bank / Тинькофф Инвестиции).

Обёртка поверх официального REST-прокси `https://invest-public-api.tbank.ru/rest`: удобные методы для счетов, инструментов, свечей, портфеля и заявок плюс универсальный вызов любого метода T-Invest.

## Возможности

- Каталог **90+** методов T-Invest (`Users`, `Instruments`, `MarketData`, `Operations`, `Orders`, `StopOrders`, `Sandbox`, `Signal`)
- Высокоуровневые REST-эндпоинты: поиск инструментов, свечи, стакан, портфель, заявки, песочница
- MCP stdio (Cursor / Claude) и **Streamable HTTP** на `/mcp` (POST + GET SSE + DELETE сессии)
- Песочница и боевой контур, торговля выключена, пока не задан `TBANK_ALLOW_TRADING=true`
- Нормализация `Quotation` / `MoneyValue` в поле `value`
- Резолв инструмента по тикеру, FIGI, UID, ISIN или названию
- Общая очередь лимитов (1с / 1мин), чтобы не пробивать квоты T-Bank

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
| GET/POST/DELETE | `/mcp` | MCP Streamable HTTP |
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

### stdio

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

### Streamable HTTP

После `php bin/tbank-api` эндпоинт `http://127.0.0.1:8080/mcp` принимает транспорт [Streamable HTTP](https://modelcontextprotocol.io/specification/2025-03-26/basic/transports#streamable-http) (протокол 2025-03-26):

- `POST /mcp` — JSON-RPC запросы; `Accept: text/event-stream` даёт SSE, иначе JSON
- уведомления и ответы клиента — HTTP `202`
- `GET /mcp` — SSE-поток для сообщений сервера
- `DELETE /mcp` + `Mcp-Session-Id` — закрыть сессию
- после `initialize` в ответе приходит `Mcp-Session-Id`

```json
{
  "mcpServers": {
    "tbank-invest": {
      "url": "http://127.0.0.1:8080/mcp"
    }
  }
}
```

```bash
curl -sS -D - -X POST http://127.0.0.1:8080/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl"}}}'
```

Origin проверяется (защита от DNS rebinding). По умолчанию разрешены `localhost` / `127.0.0.1`; список задаётся через `TBANK_MCP_ALLOWED_ORIGINS`.

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

## Очередь лимитов T-Bank

T-Invest считает unary-квоты **в минуту** по сервису, плюс есть посекундные потолки (`PostOrder` — 15/с) и рекомендация не больше **50 запросов в секунду** с одного IP. С php-fpm каждый HTTP/MCP вызов — отдельный процесс, поэтому очередь общая: файл + `flock`.

Как это устроено:

1. Перед REST-вызовом процесс берёт exclusive-lock.
2. Смотрит скользящие окна 1с и 1мин (глобально и по методу/сервису).
3. Если слота нет — `usleep` до ближайшего свободного тика (остальные ждут на lock — это и есть очередь).
4. При ответе `429` / gRPC `8` ждёт `x-ratelimit-reset` и повторяет запрос.

Включено по умолчанию (`TBANK_RATE_LIMIT=true`). Чтобы ходить не чаще 1 запроса в секунду со всех воркеров:

```env
TBANK_RATE_RPS=1
```

Или сгладить пачки без жёсткого 1/с:

```env
TBANK_RATE_RPS=50
TBANK_RATE_MIN_INTERVAL_MS=50
```

Отключить: `TBANK_RATE_LIMIT=false`. Состояние очереди: `/tmp/tbank-rate-limit.json`.

## Безопасность

Токен даёт доступ к брокерскому счёту. Не коммитьте `.env`. Боевую торговлю включайте только осознанно (`TBANK_ALLOW_TRADING=true`). Для экспериментов используйте `TBANK_INVEST_ENV=sandbox`.
