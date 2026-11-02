# M-TELECOM SALES

Внутренняя система контроля отдела продаж M-Telecom: нативное Android-приложение для менеджеров, PHP REST API и веб-кабинет руководителя.

Это рабочая система, а не макет. Цифры аналитики считаются из базы данных. Права доступа проверяются на сервере.

## Что входит в проект

| Часть | Назначение |
|---|---|
| `android/` | Нативное Kotlin-приложение (не WebView) |
| `backend/` | PHP 8.2+ REST API, PDO, общая база |
| `admin/` | Адаптивный кабинет руководителя |
| `database/` | Схема MySQL/MariaDB |
| `tests/` | Автотесты API |
| `docs/` | Установка, API, Android, резервные копии |
| `deployment/` | Сценарии для Hoster.KZ |

## Роли

- **ADMIN** — руководитель. Видит всех клиентов, менеджеров, звонки, WhatsApp, карту, отчёты, журнал. Может создавать и блокировать менеджеров, назначать клиентов, отзывать сессии.
- **MANAGER** — менеджер. Работает только со своими клиентами. Руководитель в лимит 5 менеджеров не входит.

Система закрытая: вход только по логину и паролю.

## Архитектура

```
Android (Kotlin)  ──HTTPS──►  PHP REST API  ──PDO──►  MySQL/MariaDB
Кабинет ADMIN     ──HTTPS──►  тот же API и та же база
```

Размещение рассчитано на обычный shared hosting Hoster.KZ. Не требуются Docker, Node.js-сервер или постоянно работающий Python.

Предпочтительный адрес: `https://sales.mtelecom.kz` (поддомен уже оплаченного `mtelecom.kz`).
Запасной вариант: `https://mtelecom.kz/sales` — отдельная директория, основной сайт не затрагивается.

## Минимальная версия Android

**Android 8.0 (API 26).**

Почему: EncryptedSharedPreferences и Android Keystore, каналы уведомлений, Call Log API для подтверждения длительности звонка, актуальный охват служебных телефонов. Сборка: compileSdk/targetSdk 34.

## Быстрый локальный запуск API (для проверки)

Нужен PHP 8.2+ с расширениями `pdo_sqlite` или `pdo_mysql`, `mbstring`, `openssl`.

```bash
# из корня репозитория
php tests/run.php
```

На этой машине разработки набор из **43 проверок API прошёл успешно** (SQLite, без production-данных).

Локальный HTTP-сервер после настройки `.env`:

```bash
php -S 0.0.0.0:8080 -t backend/public backend/public/router.php
```

Кабинет: `http://127.0.0.1:8080/`
API health: `http://127.0.0.1:8080/api/health`

## Публикация

1. Создайте **отдельную** базу MySQL, не связанную с сайтом mtelecom.kz.
2. Следуйте [docs/INSTALL.md](docs/INSTALL.md) и [deployment/hosting/INSTALL-HOSTERKZ.md](deployment/hosting/INSTALL-HOSTERKZ.md).
3. **Не выкладывайте систему в production без проверки конфигурации и отдельного подтверждения.**
4. **Не коммитьте `.env`, пароли и ключи в GitHub.**

Исходный код должен жить в отдельном GitHub-репозитории, не в деплое основного сайта.

## Сборка Android APK

На текущем рабочем месте нет Android SDK и JDK 17, поэтому APK здесь не собирался и фиктивный файл не создавался.

На компьютере с Android Studio:

```bash
cd android
# создать local.properties: sdk.dir=C:\\Users\\...\\AppData\\Local\\Android\\Sdk
gradlew.bat assembleDebug
```

Ожидаемый путь debug-APK:

`android/app/build/outputs/apk/debug/app-debug.apk`

Release: `gradlew.bat assembleRelease` (нужен keystore, не хранить пароль в git).

Подробности: [docs/ANDROID.md](docs/ANDROID.md).

## Тестовые данные

Демонстрационные клиенты создаются только автотестами во временной SQLite-базе. В production фиктивная статистика не используется.

## Лицензия и доступ

Внутренний продукт M-Telecom. Доступ только сотрудникам компании.
