# Анализ кода: Логические ошибки и уязвимости

**Проект:** demo-booking — Laravel API для управления бронированием переговорных комнат
**Дата анализа:** 2026-11-05

---

## Обзор приложения

Приложение представляет собой **API для управления бронированием переговорных комнат** в многоофисном пространстве. Пользователи могут создавать, просматривать и удалять бронирования комнат в разных офисах с разными часовыми поясами. Аутентификация — Laravel Sanctum (API-токены).

### Доменная модель

```
Office (1) ──< Room (N) ──< Booking (N)
                                     |
                                     └──> User (1)
```

### API-маршруты

| Метод   | Эндпоинт                                     | Метод контроллера      |
|---------|----------------------------------------------|------------------------|
| GET     | `/api/bookings`                              | `BookingController::index`   |
| POST    | `/api/bookings`                              | `BookingController::store`   |
| DELETE  | `/api/bookings/{id}`                         | `BookingController::destroy` |
| GET     | `/api/rooms/{room}/bookings/today`           | `BookingController::today`   |

---

## 🔴 Критические проблемы

### 1. SQL-инъекция в методе `store()`

**Файл:** `app/Http/Controllers/BookingController.php`

В методе `store()` проверка конфликтов бронирований выполняется через **сырой SQL с прямой подстановкой** значений из `$request`:

```php
$conflict = DB::select("SELECT id FROM bookings
    WHERE room_id = {$request->room_id}
    AND starts_at <= '{$request->ends_at}'
    AND ends_at >= '{$request->starts_at}'");
```

Значения `room_id`, `starts_at`, `ends_at` подставляются напрямую в SQL-строку без экранирования. Это позволяет злоумышленнику выполнить произвольный SQL-код.

**Рекомендация:** Использовать параметризованные запросы или Eloquent Query Builder:

```php
$conflict = Booking::where('room_id', $request->room_id)
    ->where('starts_at', '<=', $request->ends_at)
    ->where('ends_at', '>=', $request->starts_at)
    ->exists();
```

---

### 2. Клиент может подменить `user_id`

**Файл:** `app/Http/Controllers/BookingController.php` — метод `store()`

```php
$booking = Booking::create($request->all());
```

Модель `Booking` имеет `user_id` в массиве `$fillable`. Метод `store()` передаёт **все** данные из запроса без фильтрации. Злоумышленник может указать `user_id` любого пользователя и создать бронирование от его имени.

**Рекомендация:** Явно указывать `user_id` из аутентифицированного пользователя:

```php
$booking = Booking::create([
    'room_id' => $request->room_id,
    'starts_at' => $request->starts_at,
    'ends_at' => $request->ends_at,
    'title' => $request->title,
    'user_id' => $request->user()->id,
]);
```

---

### 3. Нет авторизации в `destroy()`

**Файл:** `app/Http/Controllers/BookingController.php` — метод `destroy()`

```php
$booking = Booking::findOrFail($id);
$booking->delete();
```

Любой авторизованный пользователь может **удалить любое бронирование**, без проверки, что оно принадлежит ему.

**Рекомендация:** Добавить проверку владения:

```php
$booking = Booking::findOrFail($id);

if ($booking->user_id !== $request->user()->id) {
    return response()->json(['message' => 'Unauthorized'], 403);
}

$booking->delete();
```

Или использовать Laravel Policies.

---

## 🟡 Средние проблемы

### 4. Нет валидации входных данных

**Файл:** `app/Http/Controllers/BookingController.php` — метод `store()`

Отсутствует `$request->validate()` или Form Request класс. Нет проверки:

- Обязательных полей (`title`, `room_id`, `starts_at`, `ends_at`)
- Формата дат (`starts_at`, `ends_at`)
- Типов данных (`room_id`, `user_id`)

Отсутствующее поле `title` приведёт к ошибке на уровне БД (NOT NULL constraint), но без понятного сообщения об ошибке для клиента.

**Рекомендация:** Использовать Form Request класс:

```php
$request->validate([
    'room_id' => 'required|exists:rooms,id',
    'starts_at' => 'required|date|after:now',
    'ends_at' => 'required|date|after:starts_at',
    'title' => 'required|string|max:255',
]);
```

---

### 5. `ends_at` не проверяется на больше `starts_at`

**Файл:** `app/Http/Controllers/BookingController.php` — метод `store()`

Клиент может создать бронирование, где время окончания **раньше** времени начала. Это приведёт к некорректным данным в БД и ошибкам при проверке конфликтов.

**Рекомендация:** Добавить валидацию:

```php
'ends_at' => 'required|date|after:starts_at',
```

---

### 6. Часовые пояса игнорируются

#### 6a. Конфликт бронирований не учитывает timezone офиса

**Файл:** `app/Http/Controllers/BookingController.php` — метод `store()`

Модель `Room` принадлежит `Office`, у которого есть поле `timezone`. Однако проверка конфликтов сравнивает `starts_at` и `ends_at` напрямую, без учёта timezone офиса. Два бронирования в одной комнате могут считаться непересекающимися, потому что хранятся в разных часовых поясах, хотя фактически пересекаются.

#### 6b. Проверка рабочих часов не учитывает timezone

```php
$start = Carbon::parse($request->starts_at);
if ($start->hour < 9 || $start->hour >= 20) {
    return response()->json(['message' => 'Bookings outside working hours'], 422);
}
```

Проверка использует `$start->hour` без конвертации в timezone офиса. Бронирование пользователя из другого часового пояса может быть неверно отклонено или принято.

#### 6c. Метод `today()` использует wrong timezone

```php
->whereDate('starts_at', today())
```

Функция `today()` использует timezone из `config/app.php`. Для офиса в `Asia/Novosibirsk` бронирования, которые являются «сегодня» по местному времени, могут не попасть в выборку.

**Рекомендация:** Конвертировать все временные метки в timezone офиса перед сравнением:

```php
$officeTimezone = $room->office->timezone;
$start = Carbon::parse($request->starts_at)->setTimezone($officeTimezone);
$end = Carbon::parse($request->ends_at)->setTimezone($officeTimezone);
```

---

### 7. Log context не применяется к каналу

**Файл:** `app/Http/Controllers/BookingController.php` — метод `store()`

```php
Log::withContext(['booking_id' => $booking->id]);
Log::channel('bookings')->info('Booking created');
```

`Log::withContext()` устанавливает контекст для **default**-канала, но `Log::channel('bookings')` пишет в **другой** канал. Контекст `booking_id` теряется, так как именованные каналы не наследуют глобальный контекст по умолчанию.

**Рекомендация:** Передавать контекст напрямую в метод логирования:

```php
Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);
```

---

## 🟢 Низкие проблемы

### 8. N+1 запрос в `today()`

**Файл:** `app/Http/Controllers/BookingController.php` — метод `today()`

```php
$bookings = Booking::where('room_id', $room->id)
    ->whereDate('starts_at', today())
    ->get()
    ->map(function ($b) {
        return [
            'id' => $b->id,
            'title' => $b->title,
            'user_name' => $b->user->name,  // N+1 query!
            'starts_at' => $b->starts_at,
            'ends_at' => $b->ends_at,
        ];
    });
```

Доступ к `$b->user->name` внутри `map()` вызывает отдельный SQL-запрос для каждого бронирования.

**Рекомендация:** Использовать eager loading:

```php
$bookings = Booking::where('room_id', $room->id)
    ->whereDate('starts_at', today())
    ->with('user')
    ->get()
    ->map(...);
```

---

### 9. Нет уникального ограничения на пересечение бронирований

**Файл:** `database/migrations/*_create_bookings_table.php`

В схеме БД отсутствует unique constraint или partial index, предотвращающий пересечение бронирований одной комнаты. Проверка конфликтов выполняется на уровне приложения (и то с багами, см. выше). При одновременных запросах race condition может создать пересекающиеся бронирования даже при исправленной логике.

**Рекомендация:** Добавить уникальный индекс на уровне БД или использовать database-level locking:

```php
// Пример через уникальный индекс (если время хранится в одном timezone)
$table->unique(['room_id', 'starts_at', 'ends_at']);
```

---

## Сводная таблица

| # | Проблема | Серьёзность | Файл |
|---|----------|-------------|------|
| 1 | SQL-инъекция в `store()` | 🔴 Критическая | `BookingController.php` |
| 2 | Подмена `user_id` клиентом | 🔴 Критическая | `BookingController.php` |
| 3 | Нет авторизации в `destroy()` | 🔴 Критическая | `BookingController.php` |
| 4 | Нет валидации входных данных | 🟡 Средняя | `BookingController.php` |
| 5 | `ends_at` не проверяется на больше `starts_at` | 🟡 Средняя | `BookingController.php` |
| 6a | Конфликт бронирований без учёта timezone | 🟡 Средняя | `BookingController.php` |
| 6b | Проверка рабочих часов без учёта timezone | 🟡 Средняя | `BookingController.php` |
| 6c | `today()` использует wrong timezone | 🟡 Средняя | `BookingController.php` |
| 7 | Log context теряется | 🟡 Средняя | `BookingController.php` |
| 8 | N+1 запрос в `today()` | 🟢 Низкая | `BookingController.php` |
| 9 | Нет DB-ограничения на пересечение | 🟢 Низкая | `*_create_bookings_table.php` |

---

## Покрытие тестами

**Файл:** `tests/Feature/BookingIndexTest.php`

| Тест | Описание |
|------|----------|
| `test_returns_only_own_bookings` | ✅ Пользователь видит только свои бронирования |
| `test_requires_authentication` | ✅ Неавторизованные запросы возвращают 401 |

**Отсутствует покрытие:**

- ❌ Тесты для `store()` (конфликты, рабочие часы, создание)
- ❌ Тесты для `destroy()` (авторизация)
- ❌ Тесты для `today()`
- ❌ Тесты на SQL-инъекцию
- ❌ Тесты на обработку часовых поясов

---

## Приоритеты исправления

1. **Срочно:** SQL-инъекция (#1) — угроза безопасности данных
2. **Срочно:** Подмена `user_id` (#2) — нарушение целостности данных
3. **Срочно:** Авторизация в `destroy()` (#3) — несанкционированное удаление
4. **В план спринта:** Валидация (#4), проверка `ends_at > starts_at` (#5)
5. **В план спринта:** Часовые пояса (#6)
6. **По возможности:** Логирование (#7), N+1 (#8), DB-ограничения (#9)
