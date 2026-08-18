# Slot Booking API

Минимальный Laravel 12 API для коротких hold-броней слотов: идемпотентность, транзакционное подтверждение и защита от oversell.

## Требования

- PHP 8.2+
- Composer
- MySQL 8+
- `ext-pdo_mysql`

Кэш идёт через абстракцию Laravel Cache. В проекте по умолчанию `CACHE_STORE=database` и есть таблицы `cache` / `cache_locks`, поэтому атомарные локи работают без Redis.

Redis — естественный production-бэкенд (быстрее локи, лучше поведение при stampede под нагрузкой), но **не обязателен**, чтобы запустить это задание.

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Настройте `.env` на MySQL 8:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=logistics
DB_USERNAME=logistics
DB_PASSWORD=secret

CACHE_STORE=database
```

Затем:

```bash
php artisan migrate --seed
php artisan serve
```

В репозитории также есть Docker Compose (`mysql:8.4`, PHP 8.2, nginx). Если запускаете через него, внутри контейнера приложения `DB_HOST=mysql`.

## Postman

Импортируйте оба файла из `postman/`:

1. `Slot-Booking.postman_collection.json`
2. `local.postman_environment.json`

Выберите окружение **Slot Booking Local** (`http://localhost:8000`). Папки запускайте по порядку: Availability → Create hold → Confirm → Cancel → Validation → Conflicts. Запросы создания hold сами генерируют UUID и сохраняют `holdId`.

## Тесты

```bash
php artisan test
```

Feature-тесты ходят в MySQL (`logistics_testing`) и используют `array` cache store. Они проверяют generation-based invalidation и обычное поведение кэша в одном PHP-процессе.

Конкурентные booking-тесты в `ConcurrentSlotBookingTest` поднимают отдельные PHP-процессы: они бутят Laravel и одновременно бьют в HTTP kernel. Фикстуры коммитятся до старта воркеров (`DatabaseMigrations`, не оборачивающая транзакция), поэтому дочерние процессы видят строки slot/hold. Покрыто:

- пять разных ключей при `capacity = 1` (один `201`, четыре `409`);
- пять одинаковых `Idempotency-Key` (одна строка hold);
- два confirm на одно оставшееся место;
- конкурентные confirm/cancel, чтобы `remaining` не прыгнул дважды.

Межпроцессные атомарные cache lock требуют общего store (`database` или Redis). `array` cache этим процессам не шарится, поэтому отдельного конкурентного availability/stampede-теста с общим кэшем нет: реализация generation-lock есть в коде, но stampede между процессами этими тестами не доказывается.

Concurrent-тесты используют общую БД `logistics_testing`. Внутри одного теста запросы реально идут отдельными процессами, но несколько PHPUnit suites нельзя одновременно гонять против одной `logistics_testing`. Для параллельного запуска suites нужна отдельная БД на каждый процесс.

Один раз создайте тестовую БД и запускайте тесты:

```bash
docker compose exec mysql mysql -uroot -proot -e "CREATE DATABASE IF NOT EXISTS logistics_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON logistics_testing.* TO 'logistics'@'%';"
php artisan test
```

Если PHP работает внутри Docker:

```bash
docker compose exec app php artisan test
```

## Примеры API

Базовый URL: `http://localhost:8000`

### Availability — `200`

```bash
curl -sS -H "Accept: application/json" \
  http://localhost:8000/slots/availability
```

Сидер: слоты с capacity/remaining `10`, `5` и `1`.

### Создать hold — `201`

```bash
curl -sS -D - -H "Accept: application/json" \
  -H "Idempotency-Key: 11111111-1111-4111-8111-111111111111" \
  -X POST http://localhost:8000/slots/1/hold
```

### Повторить тот же запрос — `200`

Тот же `Idempotency-Key` возвращает исходный hold и не создаёт вторую строку:

```bash
curl -sS -D - -H "Accept: application/json" \
  -H "Idempotency-Key: 11111111-1111-4111-8111-111111111111" \
  -X POST http://localhost:8000/slots/1/hold
```

### Подтвердить hold — `200`

Подставьте `{id}` из ответа создания:

```bash
curl -sS -D - -H "Accept: application/json" \
  -X POST http://localhost:8000/holds/{id}/confirm
```

Повторный confirm уже confirmed hold тоже `200` и больше не уменьшает `remaining`.

### Отменить hold — `200`

```bash
curl -sS -D - -H "Accept: application/json" \
  -X DELETE http://localhost:8000/holds/{id}
```

Повторный DELETE — `200` и не увеличивает capacity дважды.

### Oversell / конфликт по вместимости — `409`

Слот `3` в сидере с capacity `1`. Второй hold отклоняется:

```bash
curl -sS -D - -H "Accept: application/json" \
  -H "Idempotency-Key: 22222222-2222-4222-8222-222222222222" \
  -X POST http://localhost:8000/slots/3/hold

curl -sS -D - -H "Accept: application/json" \
  -H "Idempotency-Key: 33333333-3333-4333-8333-333333333333" \
  -X POST http://localhost:8000/slots/3/hold
```

Ожидание: первый `201`, второй `409` с `{"message":"Slot is fully booked."}`.

Остальные HTTP-коды:

| Ситуация | Статус |
| --- | --- |
| Нет / невалидный `Idempotency-Key` | `422` |
| Неизвестный slot или hold | `404` |
| Confirm cancelled или просроченного hold | `409` |
| Тот же `Idempotency-Key` для другого слота | `409` |

## Архитектурные решения

1. **`slots.remaining` уменьшается при confirmation, а не при создании hold.** Hold — опцион на 5 минут, а не завершённая бронь. Единственный долговечный decrement — confirmed-потребление, поэтому отмена неподтверждённого hold не ломает счётчик.

2. **Эффективная доступность** — `max(0, slots.remaining - count(status=held AND expires_at > now()))`. Поле `remaining` в API — именно это значение: активные hold уменьшают то, что ещё можно зарезервировать, не трогая confirmed-счётчик.

3. **Строка slot блокируется (`SELECT ... FOR UPDATE`) при создании hold.** После этого лока активные hold считаются locking/current read (`SELECT id ... FOR UPDATE`), а не обычным `COUNT`. Иначе при MySQL `REPEATABLE READ` транзакция держала бы snapshot первого `SELECT` по идемпотентности, и обычный count после ожидания лока слота не увидел бы hold, уже закоммиченные другими транзакциями. Порядок блокировок всегда: строка slot, затем строки hold. UNIQUE на `idempotency_key` остаётся последним барьером для гонки одного ключа.

4. **Confirmation делает `UPDATE slots SET remaining = remaining - 1 WHERE remaining > 0`.** Даже если лишние `held`-строки уже есть, БД не даст `remaining` уйти ниже нуля. Вариант `if ($slot->remaining > 0)` плюс `save()` гоняется.

5. **`idempotency_key` UNIQUE.** Проверки `exists()` на уровне приложения недостаточно при конкуренции. Constraint — последний барьер: проигравший unique-race читает hold победителя (или получает `409`, если ключ уже использован для другого слота). После лока слота lookup идемпотентности тоже идёт через `SELECT ... FOR UPDATE`, чтобы увидеть последнюю закоммиченную строку.

6. **Кэш availability версионируется, а не только `Cache::forget()`.** Счётчик поколения (`slots:availability:generation`) создаётся через `Cache::add` и увеличивается `Cache::increment` после успешной мутации. Ключи данных: `slots:availability:v1:{generation}`; локи rebuild: `slots:availability:rebuild:{generation}`. Поздний rebuild старого поколения может ещё записать старый ключ, но читатели уже смотрят на новое поколение, поэтому stale-данные не отдаются. Старые ключи живут TTL 10 секунд. Если rebuild-lock не удалось взять, запрос читает БД и **не** пишет кэш.

7. **Инвалидация кэша — после `COMMIT`.** Если забыть ключ внутри открытой транзакции, соседний запрос может пересобрать кэш из данных до коммита. Мутации без смены состояния (идемпотентный retry, повторный confirm/cancel) кэш не трогают.

8. **Expiration ленивый.** Активный hold — это `held AND expires_at > now()`. Отдельного статуса `expired` и cleanup-джоба нет. Confirm просроченного hold даёт `409`; строка при этом запросе может перейти в `cancelled`.

TTL hold — пять минут. TTL кэша availability — 10 секунд на поколение.
