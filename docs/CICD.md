# CI/CD — GitHub Actions → Hoster.KZ `/sales`

Цель: после успешного CI доверенный PR владельца squash-мержится в `main`, затем CRM на `https://mtelecom.kz/sales/` обновляется по FTPS. Основной сайт `https://mtelecom.kz/` не затрагивается.

## Workflows

| Файл | Когда | Что делает |
|---|---|---|
| `.github/workflows/ci.yml` | `pull_request` → `main`, `push` → `main` | PHP lint, migration guard, security check, `php tests/run.php`; Android unit tests + assembleDebug/Release + проверка package/versionCode |
| `.github/workflows/auto-merge-owner.yml` | PR владельца в `main` | Включает GitHub Auto-Merge (squash). Merge ждёт обязательные checks |
| `.github/workflows/deploy-production.yml` | После успешного **CI на push в main** | Allowlist FTPS deploy, backup, migrate, health, rollback файлов; отдельно APK только при наличии signing secrets |

На `pull_request` **production-секреты не используются**.

## GitHub Secrets (Settings → Secrets and variables → Actions)

### Обязательны для web-deploy

| Secret | Значение |
|---|---|
| `FTP_HOST` | `185.98.5.120` |
| `FTP_USER` | `sales_deploy` |
| `FTP_PASSWORD` | пароль FTPS |
| `FTP_PORT` | `21` (опционально) |
| `FTP_REMOTE_ROOT` | `/` (корень FTP = `/httpdocs/sales`) |

### Для миграций на production

| Secret | Значение |
|---|---|
| `DEPLOY_MIGRATE_TOKEN` | длинный случайный токен |

Тот же токен добавить в **production `.env`** на сервере:

```env
DEPLOY_MIGRATE_TOKEN=...тот же...
```

Эндпоинт: `POST /api/system/migrate` + заголовок `X-Deploy-Token`. Без токена в `.env` отвечает 404. Выполняет только идемпотентный `Migrator::up()` (без DROP/TRUNCATE).

### Опционально — smoke login

| Secret | Назначение |
|---|---|
| `SMOKE_LOGIN` | тестовый логин менеджера/админа |
| `SMOKE_PASSWORD` | пароль |

### Для публикации APK поверх установленных менеджерских приложений

| Secret | Назначение |
|---|---|
| `ANDROID_DEBUG_KEYSTORE_BASE64` | base64 файла `debug.keystore` с SHA-256 `22B0A65B…AE71` |
| `ANDROID_KEY_ALIAS` | обычно `androiddebugkey` |
| `ANDROID_KEYSTORE_PASSWORD` | пароль store |
| `ANDROID_KEY_PASSWORD` | пароль key |

Пока secrets нет — job `deploy-apk` **пропускается**, CRM не откатывается.

Подготовка base64 (на машине, где есть правильный keystore):

```bash
base64 -w0 debug.keystore > debug.keystore.b64
# вставить содержимое файла в секрет ANDROID_DEBUG_KEYSTORE_BASE64
```

## Что владелец должен включить в GitHub (админ-права)

Cursor/`gh` агента **не имеет** admin на репозитории (`allow_auto_merge=false`, branch protection API → 403). Нужно вручную:

1. **Settings → General → Pull Requests → Allow auto-merge** — включить.
2. **Settings → Branches → Add branch protection rule** (или Ruleset) для `main`:
   - Require a pull request before merging (по желанию)
   - **Require status checks to pass**: `backend`, `android`
   - Do not allow force pushes
   - Do not allow deletions
3. **Settings → Actions → General**: разрешить GitHub Actions для репозитория; Workflow permissions: Read and write (нужно для auto-merge workflow).
4. Добавить Secrets из таблиц выше.
5. В production `.env` прописать `DEPLOY_MIGRATE_TOKEN`.

После этого: новый PR владельца → CI → auto-merge squash → deploy.

## Резервное копирование и откат

- Перед каждой заменой файла FTPS скачивает текущую remote-копию в `artifacts/deploy/backup/<UTC-timestamp>/`.
- Секреты (`.env`, uploads, БД) **не** входят в пакет и **не** бэкапятся этим job.
- Если health / migrate / smoke / статика падают — скрипт заливает обратно только забэкапленные файлы.
- БД автоматически разрушительными SQL **не** откатывается.

## Защита данных

Allowlist upload. Нет `--delete`. Не загружаются: `.env`, `install.lock`, `seed.local.php`, содержимое `storage/uploads|logs|backups`, keystore. `seed-local.php` CI/deploy не вызывает. Migration guard блокирует DROP/TRUNCATE в исходниках миграций.

## APK

Менеджеры сейчас на `kz.mtelecom.sales.debug` + cert `22B0…`. Debug APK с тем же ключом и `versionCode > 1` обновляется поверх. Release `kz.mtelecom.sales` — отдельный процесс с корпоративным ключом.

## Ручной запуск

Actions → **Deploy production** → Run workflow (только после появления workflow на `main`).
