# REST API

Базовый префикс: `/api`. Формат: JSON. Авторизация: `Authorization: Bearer <token>` или cookie-сессия кабинета.

Идемпотентность создания: поля `uuid` / `idempotency_key`. Повтор с тем же ключом возвращает исходную запись, а не дубль.

## Служебные

- `GET /api/health`
- `GET /api/catalog`
- `GET /api/app/version`

## Авторизация

- `POST /api/auth/login` `{login, password, platform, device}`
- `POST /api/auth/logout`
- `GET /api/auth/me`

Лимит попыток входа: 5 неудачных за 15 минут с одного IP+логина.

## Пользователи (ADMIN)

- `GET /api/users`
- `POST /api/users` — только роль manager
- `PATCH /api/users/{id}`
- `POST /api/users/{id}/block`
- `POST /api/users/{id}/unblock`
- `POST /api/users/{id}/revoke-sessions`

## Клиенты

- `GET /api/clients`
- `POST /api/clients/check-duplicates`
- `POST /api/clients` — при возможных дублях HTTP 409, создание после подтверждения `force: true`
- `GET /api/clients/{id}`
- `PUT /api/clients/{id}` — конфликт по `base_updated_at` → 409, сервер не затирает молча
- `POST /api/clients/{id}/status`
- `POST /api/clients/{id}/application` — `{comment?, idempotency_key}` → заметка + статус `application_received` (идемпотентно; sync kind `client.application`)
- `POST /api/clients/{id}/assign` (ADMIN)
- `POST /api/clients/{id}/contacts|phones|objects`
- `GET /api/clients/{id}/timeline`

## Взаимодействия

- `POST /api/clients/{id}/calls`
- `POST /api/clients/{id}/whatsapp`
- `POST /api/clients/{id}/interactions`
- `POST /api/interactions/{id}/amend` — исходная запись не удаляется
- `GET /api/calls`
- `GET /api/whatsapp`

Источник: `system` или `manager`. Длительность звонка классифицируется на сервере.

## Задачи, аналитика, карта, отчёты

- `GET|POST /api/tasks`, `PATCH /api/tasks/{id}`
- `GET /api/dashboard`
- `GET /api/analytics/overview`
- `GET /api/analytics/managers` (ADMIN)
- `GET /api/map/markers` — OpenStreetMap, без платного ключа
- `GET /api/reports/export?type=clients|managers|calls|duration|whatsapp|applications|connections|rejected|objects&format=csv|xlsx`
- `GET /api/audit` (ADMIN)
- `GET|PUT /api/settings` (ADMIN)
- `GET /api/sync/pull`, `POST /api/sync/push`

Менеджер получает только свои данные. Скрытие кнопок в UI не заменяет серверную проверку.

### Заявка

`POST /api/clients/{id}/application` — тело: `comment`, обязательный `idempotency_key`. Атомарно создаёт заметку и переводит статус в `application_received`. Используется Android online и sync kind `client.application`.
