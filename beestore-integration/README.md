# BeeStore Integration for WooCommerce

Плагин WordPress + WooCommerce для интеграции с BeeStore (Sirio Informatica).

## Возможности

- **Импорт каталога** из ZIP/CSV-выгрузки BeeStore (FTP или ручная загрузка)
- **Вариативные товары** WooCommerce: модель → вариации по цвету и размеру
- **Картинки**: автоматическое скачивание URLImg1..10 в Media Library
- **Цены**: PrezzoIvato (regular) + PrezzoScontatoIvato (sale) + скидка в %
- **Остатки**: Disponibilita → stock WooCommerce
- **Заказы** WC → BeeStore через SOAP `fInserimentoPrenotazione`
- **Статусы заказов**: cron-опрос `fStatoPrenotazioni`, обновление tracking/invoice
- **Отмена/доплата**: `fModificaPrenotazione` при отмене заказа в WC
- **Очередь ретраев** для неудачных попыток передачи заказа (до 5 попыток)
- **Логирование** всех операций в БД + страница логов с фильтрами
- **Диагностика**: тест SOAP/FTP-подключения, проверка PHP-расширений и cron

## Требования к серверу

- PHP ≥ 7.4 (рекомендуется 8.0+)
- WordPress ≥ 6.2
- WooCommerce ≥ 7.0
- PHP-расширения: **SoapClient**, **ZipArchive**, **ftp** (или ssh2 для SFTP), **cURL**
- `memory_limit` ≥ 512M (для обработки больших CSV)
- `max_execution_time` ≥ 300 (для импорта 50k+ строк)

## Установка

### Способ 1: через админку WordPress

1. Зайдите в **Plugins → Add New → Upload Plugin**
2. Выберите файл `beestore-integration.zip`
3. Нажмите **Install Now**, затем **Activate**
4. Убедитесь, что WooCommerce уже активирован

### Способ 2: через FTP/SSH

1. Распакуйте архив
2. Скопируйте папку `beestore-integration` в `/wp-content/plugins/`
3. Активируйте плагин в админке WordPress

## Настройка

После активации откройте **BeeStore → Настройки**:

### Вкладка «FTP»
- Хост, порт, пользователь, пароль для FTP/SFTP BeeStore
- Путь к каталогу, куда Sirio выкладывает ZIP-файлы `COMPANY_*.zip`
- Если используете SFTP — поставьте галочку «Использовать SFTP»

### Вкладка «SOAP / BeeStore»
- **WSDL URL** — адрес WSDL BeeStore (даётся Sirio)
- **Пользователь/пароль SOAP** — учётка для всех SOAP-вызовов
- **IGU Negozio** — ваш код магазина (из выгрузки: 540 для BAY)
- **IGU Cliente** — уникальный клиент-призрак маркетплейса (даётся Sirio)
- **Cod IVA по умолчанию** — обычно 22
- **Ставка НДС** — 22 (Италия) или ваша

### Вкладка «Синхронизация»
- Включите передачу заказов (если нужно)
- Включите опрос статусов
- При необходимости включите снятие с публикации отсутствующих товаров
- Настройте частоту cron-задач

### Вкладка «Платежи»
Для каждого способа оплаты WooCommerce выберите соответствие с `IDTipoIncasso` BeeStore:
- 2 = чек
- 3 = карта/PayPal/Stripe
- 20 = депозит
- 21 = продажа в кредит (оплата при доставке)

## Диагностика

**BeeStore → Диагностика** позволяет:

1. Проверить наличие всех PHP-расширений
2. Запустить тест SOAP (вызывает `fDisponibilita` с тестовым кодом — должен вернуть либо число, либо ошибку 103 «Product not found», но без ошибок соединения)
3. Запустить тест FTP (должен показать список ZIP-файлов BeeStore)
4. Проверить статус WP-Cron задач

## Cron-задачи

Плагин регистрирует три cron-задачи:

| Hook | По умолчанию | Что делает |
|------|--------------|------------|
| `bsi_cron_import_catalog` | hourly | Опрашивает FTP, скачивает и импортирует свежий ZIP |
| `bsi_cron_status_sync` | hourly | Запрашивает статусы заказов из BeeStore |
| `bsi_cron_process_queue` | every5min | Обрабатывает очередь ретраев неудачных отправок |

Если в `wp-config.php` стоит `define('DISABLE_WP_CRON', true);` — настройте системный cron на вызов `wp-cron.php` каждые 5 минут.

## Маппинг полей BeeStore → WooCommerce

| BeeStore (CSV) | WooCommerce |
|----------------|-------------|
| `IGUArticolo` | postmeta `_bsi_igu_articolo` (parent ID) |
| `CodArticolo` | SKU variation + postmeta `_bsi_cod_articolo` |
| `BarCode` | postmeta `_bsi_barcode` |
| `EAN` | postmeta `_bsi_ean` |
| `DSArticoloWeb` / `DSArticolo` | Название товара |
| `ArticoloDescrizionePers` + `Nota` | Описание |
| `DSArticoloAggWeb` | Краткое описание |
| `PrezzoIvato` | Regular price |
| `PrezzoScontatoIvato` (если < PrezzoIvato) | Sale price |
| `Sconto` (% если нет PrezzoScontatoIvato) | Расчёт sale price |
| `Disponibilita` | Stock quantity + stock status |
| `Peso` | Weight |
| `URLImg1` | Featured image |
| `URLImg2..10` | Gallery |
| `DSColore` | Атрибут «Colore» |
| `Taglia` | Атрибут «Taglia» |
| `DSCategoriaMerceologica(Web)` | Категория товара `product_cat` |
| `DSReparto(Web)` | Подкатегория |
| `DSMarca(Web)` | Бренд (`product_brand` или `pa_brand`) |
| `DSStagione(Web)` | Атрибут-таксономия `pa_stagione` |

## Маппинг заказа WC → BeeStore testata

| WC | BeeStore |
|----|----------|
| `order_id` | `numPrenotazione` |
| `order_number` | `numPrenotazioneChr` |
| `billing_first_name + last_name` | `nominativo`, `nome`, `cognome` |
| `billing_address_1` | `indirizzo` |
| `billing_city` | `citta` |
| `billing_postcode` | `cap` |
| `billing_state` | `provincia` |
| `billing_phone` | `telefono` |
| `billing_country` | `codiceStato` |
| `billing_email` | `emailPren` |
| `shipping_*` | `nominativoSped`, `indirizzoSped`, ... |
| `total` (если is_paid) | `accontoVersato` |
| `payment_method` | `idTipoIncasso` (через mapping) |
| `customer_note` | `note` |

## Логи и отладка

Все события пишутся в таблицу `{prefix}_beestore_logs`. Просмотр:

**BeeStore → Логи** — фильтры по уровню и источнику.

Уровни:
- `debug` — детали SOAP-запросов (включать только для отладки)
- `info` — стандартный (по умолчанию)
- `warning` — некритичные проблемы (нет картинки, конфликт SKU)
- `error` — критичные (SOAP-фолт, FTP-недоступность)

## FAQ

**Q: При импорте 50k+ строк процесс обрывается по таймауту.**
A: Уменьшите «Размер пакета импорта» в настройках до 50–100. Также увеличьте `max_execution_time` до 600. Если используете shared-хостинг — переключитесь на VPS или запускайте импорт через WP-CLI (плагин поддерживает ajax-запрос, можно дёргать `wp eval`).

**Q: Картинки не скачиваются — ошибка 500.**
A: Сервер BeeStore (`http://93.46.41.5:8080/`) должен быть доступен с вашего WordPress. Проверьте через `curl -I http://93.46.41.5:8080/GEB/2000019668213_1.jpg` с вашего сервера. Если заблокирован файрволом — отключите «Скачивать картинки» в настройках (плагин использует hotlink URL).

**Q: SOAP возвращает ошибку 2 «Invalid IGUCliente».**
A: Получите корректный `IGUCliente` у Sirio и впишите в настройки. Это уникальный клиент-призрак, под которым маркетплейс создаёт заказы.

**Q: Дубликаты товаров после повторного импорта.**
A: Плагин идентифицирует товары по мете `_bsi_igu_articolo`. Если у вас уже были такие товары без этой меты — плагин создаст дубли. Перед первым импортом очистите каталог WC или вручную проставьте мету существующим товарам.

**Q: Как переключиться на SFTP?**
A: Попросите хостинг установить расширение PHP `ssh2`. Затем поставьте галочку «Использовать SFTP» в настройках и измените порт на 22.

## Поддержка

- Логи: **BeeStore → Логи**
- Тесты подключения: **BeeStore → Диагностика**
- Проверка cron: **BeeStore → Диагностика → WP-Cron статусы**

## Лицензия

GPL-2.0+
