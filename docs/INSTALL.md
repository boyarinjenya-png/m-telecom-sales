# Установка M-TELECOM SALES

Основной сайт `mtelecom.kz` не изменять. Используется отдельная база, отдельный каталог или поддомен, отдельный GitHub-репозиторий.

## Требования

- PHP 8.2 или новее
- Расширения: `pdo_mysql`, `mbstring`, `openssl`, `json`, `fileinfo`
- MySQL 5.7+ / MariaDB 10.3+
- HTTPS
- Права на запись в `backend/storage/`

## Сценарий A. Поддомен sales.mtelecom.kz

1. В панели Hoster.KZ создайте поддомен `sales` для домена `mtelecom.kz`.
2. Укажите корневую папку поддомена на каталог, куда будут загружены файлы `backend/public`.
3. Создайте **новую** базу и пользователя MySQL (не используйте базу основного сайта).
4. Загрузите проект так, чтобы:
   - document root = `backend/public`
   - рядом доступны `backend/src`, `backend/storage`, `admin`
   Типичная раскладка на хостинге:

```text
/home/USER/sales.mtelecom.kz/   ← document root (содержимое backend/public)
/home/USER/mtelecom-sales/      ← остальной код (backend/src, admin, .env)
```

Если панель позволяет указать корень на `.../backend/public` внутри полного клона репозитория — так и сделайте.

5. Откройте `https://sales.mtelecom.kz/install.php` и заполните форму.
6. После успеха `install.lock` блокирует повторную установку.
7. Войдите в кабинет логином руководителя.
8. Создайте менеджеров в разделе «Менеджеры».
9. В Android укажите адрес `https://sales.mtelecom.kz`.

## Сценарий B. Каталог https://mtelecom.kz/sales

Если поддомен на тарифе недоступен:

1. Создайте каталог `sales` **рядом** с файлами основного сайта, не перезаписывая их.
2. Скопируйте туда `backend/public/*`.
3. Код приложения разместите вне document root основного сайта либо в `sales/` так, чтобы `index.php` основного сайта не затрагивался.
4. В `.env` укажите:

```
APP_URL=https://mtelecom.kz/sales
APP_BASE_PATH=/sales
```

5. Убедитесь, что `.htaccess` основного сайта не перехватывает `/sales`. При необходимости добавьте исключение **только** для `/sales`, не меняя остальную логику сайта.

## После установки

- Смените пароль руководителя.
- Проверьте `GET /api/health`.
- Создайте 1–5 менеджеров.
- Соберите APK и раздайте сотрудникам.
- Настройте резервное копирование: [BACKUP.md](BACKUP.md).

## CLI (если есть SSH)

```bash
php backend/bin/migrate.php
php backend/bin/create-admin.php director 'НадёжныйПароль' 'ФИО руководителя'
php backend/bin/backup.php
```

## GitHub

Репозиторий Sales — отдельный. Не подключайте его как замену деплоя основного сайта. В git не должно быть `.env`, `install.lock`, APK с секретами, дампов базы.
