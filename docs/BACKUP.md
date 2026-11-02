# Резервное копирование и восстановление

База Sales отдельная. Бэкап основного сайта mtelecom.kz эту систему не покрывает.

## Что копировать

1. База MySQL `mtelecom_sales` (или имя, заданное при установке).
2. Каталог `backend/storage/uploads` (скриншоты WhatsApp).
3. Файл `.env` хранить отдельно, не в git.

## Создание копии

### Панель Hoster.KZ

phpMyAdmin → нужная база → Экспорт → SQL.

### SSH, если доступен

```bash
php backend/bin/backup.php
```

или:

```bash
mysqldump --single-transaction -u USER -p DBNAME > backup-YYYYMMDD.sql
```

Копии кладите в `backend/storage/backups/` или скачивайте на защищённый диск руководителя. Срок хранения по умолчанию — 14 дней (настройка кабинета).

## Восстановление

1. Создайте пустую базу, если старая потеряна.
2. Импортируйте SQL в phpMyAdmin или `mysql DBNAME < backup.sql`.
3. Верните `.env` с теми же учётными данными.
4. Скопируйте `storage/uploads`.
5. Проверьте вход руководителя и `GET /api/health`.

Не восстанавливайте дамп Sales в базу основного сайта и наоборот.
