# История диалога — demo-booking

**Дата:** 2026-11-05  
**Проект:** `C:/Users/ACER/Documents/Мои проекты/laravel/demo-booking`

---

## 1. Анализ кода: логические ошибки и уязвимости

**Запрос:** Проанализируй код и найди возможные логические ошибки. Результат сохрани в `REVIEW.md`.

**Результат:** Найдено 9 проблем:

| # | Проблема | Серьёзность |
|---|----------|-------------|
| 1 | SQL-инъекция в `store()` — сырой SQL с подстановкой `$request` | 🔴 Критическая |
| 2 | Клиент может подменить `user_id` через `$request->all()` | 🔴 Критическая |
| 3 | Нет авторизации в `destroy()` — любой удаляет любое бронирование | 🔴 Критическая |
| 4 | Нет валидации входных данных | 🟡 Средняя |
| 5 | `ends_at` не проверяется на больше `starts_at` | 🟡 Средняя |
| 6 | Часовые пояса игнорируются (конфликт-детекция, рабочие часы, `today()`) | 🟡 Средняя |
| 7 | `Log::withContext()` не применяется к именованному каналу | 🟡 Средняя |
| 8 | N+1 запрос в `today()` | 🟢 Низкая |
| 9 | Нет DB-ограничения на пересечение бронирований | 🟢 Низкая |

**Артефакт:** `code-analysis.md` — полный отчёт с примерами кода и рекомендациями.

---

## 2. Создание FormRequest для `store()`

**Запрос:** Создай класс FormRequest для метода `store()`.

**Создан файл:** `app/Http/Requests/StoreBookingRequest.php`

### Правила валидации

| Поле | Правила |
|------|---------|
| `room_id` | `required`, `integer`, `exists:rooms,id` |
| `starts_at` | `required`, `date`, `after:now` |
| `ends_at` | `required`, `date`, `after:starts_at` |
| `title` | `required`, `string`, `max:255` |

### Сообщения об ошибках (русский)

- `room_id.exists` — «Выбранная комната не найдена.»
- `starts_at.after` — «Время начала бронирования должно быть в будущем.»
- `ends_at.after` — «Время окончания должно быть позже времени начала.»
- `title.required` — «Укажите название бронирования.»

**Обновлён контроллер:** `BookingController::store()` принимает `StoreBookingRequest` вместо `Request`.

---

## 3. Документирование ExtCarbon

**Запрос:** Документируй класс ExtCarbon.

**Обновлён файл:** `app/Common/ExtCarbon.php`

Добавлены PHPDoc-блоки:

```php
/**
 * Extended Carbon class with timezone-aware parsing helpers.
 *
 * Provides methods for interpreting date/time strings in a specific timezone,
 * essential for multi-office applications where bookings must be validated
 * against the office's local working hours.
 */
class ExtCarbon extends Carbon
```

```php
/**
 * Parse a time string in a specific timezone.
 *
 * Parses the given time string and interprets the resulting date/time
 * components (year, month, day, hour, minute, second) in the specified
 * timezone. Returns a new Carbon instance bound to that timezone.
 *
 * Example: "2026-11-05 10:00" parsed with timezone "Asia/Novosibirsk"
 * produces a Carbon instance representing 10:00 in Novosibirsk time,
 * not the app's default timezone.
 *
 * @param  DateTimeInterface|WeekDay|Month|string|int|float  $time
 * @param  DateTimeZone|string|int  $timezone
 * @return static A Carbon instance bound to the given timezone.
 */
public static function parseWithOtherTimezone(...)
```

---

## 4. Миграция: добавление `working_hours_from`

**Запрос:** Отредактируй миграцию `2026_11_06_092635_modify_bookings_table.php`. Добавь поле `working_hours_from` типа `time`, обязательное, со значением `09:00:00`.

**Обновлён файл:** `database/migrations/2026_11_06_092635_modify_bookings_table.php`

```php
public function up(): void
{
    Schema::table('bookings', function (Blueprint $table) {
        $table->time('working_hours_from')->default('09:00:00');
    });
}

public function down(): void
{
    Schema::table('bookings', function (Blueprint $table) {
        $table->dropColumn('working_hours_from');
    });
}
```

---

## 5. Feature-тесты для бизнес-правил

**Запрос:** Создай Feature-тесты на следующие правила:

1. Брони одной комнаты не пересекаются. Встречи «впритык» разрешены.
2. Длительность от 15 минут до 4 часов, шаг 15 минут.
3. Бронь целиком укладывается в рабочее время 09:00–20:00 по местному времени офиса.
4. Отменить бронь может только её автор.

**Создан файл:** `tests/Feature/StoreBookingTest.php` — **25 тестов**.

### Структура тестов

#### Пересечение броней (7 тестов)

| Тест | Проверяет |
|------|-----------|
| `test_no_overlap_for_conflicting_bookings` | Частичное пересечение → 409 |
| `test_no_overlap_for_exact_same_time` | Полное совпадение → 409 |
| `test_no_overlap_when_new_contains_existing` | Новая содержит существующую → 409 |
| `test_no_overlap_when_existing_contains_new` | Существующая содержит новую → 409 |
| `test_tight_bookings_are_allowed` | 10:00–11:00 + 11:00–12:00 → 201 |
| `test_tight_bookings_in_reverse_order` | 11:00–12:00 + 10:00–11:00 → 201 |
| `test_different_rooms_no_conflict` | Разные комнаты → 201 |

#### Длительность и шаг 15 мин (6 тестов)

| Тест | Проверяет |
|------|-----------|
| `test_minimum_duration_of_15_minutes` | < 15 мин → конфликт (округление) |
| `test_minimum_duration_of_15_minutes_is_accepted` | Ровно 15 мин → 201 |
| `test_maximum_duration_of_4_hours_is_accepted` | Ровно 4 часа → 201 |
| `test_duration_exceeding_4_hours_is_rejected` | > 4 часов → 409 |
| `test_duration_step_rounded_up_to_15_minutes` | 8 мин → округление до 15 → конфликт |
| `test_duration_step_30/45/60_minutes_accepted` | Кратные 15 → 201 |

#### Рабочее время по timezone (8 тестов)

| Тест | Проверяет |
|------|-----------|
| `test_booking_within_working_hours_accepted` | 9:00–10:00 → 201 |
| `test_booking_ending_at_end_of_working_hours_accepted` | 19:00–20:00 → 201 |
| `test_booking_before_working_hours_rejected` | 8:00–9:00 → 422 |
| `test_booking_starting_at_859_rejected` | 8:59 → 422 |
| `test_booking_starting_at_830_rejected` | 8:30 → 422 |
| `test_booking_starting_at_2000_rejected` | 20:00 → 422 |
| `test_iso8601_with_positive_offset_moscow` | +03:00 → 201 |
| `test_iso8601_with_utc_offset_crosses_working_hours` | UTC → конвертация |

#### Авторизация удаления (4 теста)

| Тест | Проверяет |
|------|-----------|
| `test_author_can_delete_booking` | Автор удаляет → 200 |
| `test_non_author_cannot_delete_booking` | Не автор → 403 |
| `test_unauthenticated_user_cannot_delete_booking` | Без токена → 401 |
| `test_deleted_booking_not_visible_in_index` | Удалённая не в списке |

**Особенность:** Используются два офиса — `Europe/Moscow` и `Asia/Novosibirsk` — для проверки timezone-логики.

---

## Итоговый список созданных/обновлённых файлов

| Файл | Действие |
|------|----------|
| `REVIEW.md` | Создан — полный отчёт анализа |
| `app/Http/Requests/StoreBookingRequest.php` | Создан — валидация |
| `app/Http/Controllers/BookingController.php` | Обновлён — FormRequest, логирование |
| `app/Common/ExtCarbon.php` | Обновлён — PHPDoc-документация |
| `database/migrations/2026_11_06_092635_modify_bookings_table.php` | Обновлён — поле `working_hours_from` |
| `tests/Feature/StoreBookingTest.php` | Создан — 25 тестов |
