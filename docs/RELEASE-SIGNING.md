# Выпуск подписанного Release APK (корпоративная подпись)

Этот документ описывает безопасную процедуру подписи. **Ключ подписи, пароли и `keystore.properties` в Git не коммитятся.**

## Зачем один и тот же ключ

Android требует, чтобы обновления ставились поверх предыдущей версии только при совпадении:

- `applicationId` (`kz.mtelecom.sales`)
- сертификата подписи (один и тот же keystore / alias)

Смена ключа без Play App Signing ломает обновление: пользователям придётся удалять старое приложение.

## Что должен предоставить владелец проекта (один раз)

1. Файл keystore (например `mtelecom-sales-release.jks` или `.keystore`), хранимый **вне репозитория**.
2. Значения (передать агенту/CI только через секретное хранилище, не в чат и не в git):
   - `STORE_FILE` — абсолютный путь к keystore на машине сборки
   - `STORE_PASSWORD`
   - `KEY_ALIAS`
   - `KEY_PASSWORD`
3. Подтверждение, что это **тот же** ключ, которым уже подписаны установленные у менеджеров APK (если обновление поверх существующих).

Если корпоративный ключ ещё не создан, владелец генерирует его локально:

```bash
keytool -genkeypair -v \
  -keystore /secure/path/mtelecom-sales-release.jks \
  -alias mtelecom_sales \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storetype JKS
```

Пароли и файл keystore сохраняются в корпоративном сейфе (1Password / Vault и т.п.).

## Локальная / CI сборка без записи паролей в файлы репозитория

Предпочтительно — переменные окружения (не печатать в логи):

```bash
export MTS_STORE_FILE=/secure/path/mtelecom-sales-release.jks
export MTS_STORE_PASSWORD='…'
export MTS_KEY_ALIAS=mtelecom_sales
export MTS_KEY_PASSWORD='…'

cd android
./gradlew :app:assembleRelease
```

Опциональный локальный файл (уже в `.gitignore`): `android/keystore.properties`

```properties
storeFile=/secure/path/mtelecom-sales-release.jks
storePassword=…
keyAlias=mtelecom_sales
keyPassword=…
```

Сборка читает сначала env `MTS_*`, затем `keystore.properties`, если файл есть. Без них `assembleRelease` даёт **unsigned** APK.

## После сборки

1. Проверить подпись: `apksigner verify --print-certs app-release.apk`
2. Сравнить SHA-256 сертификата с предыдущим релизом.
3. Увеличить `versionCode` / `versionName` в `android/app/build.gradle.kts` перед каждым новым релизом.
4. Выложить APK в `backend/storage/downloads/m-telecom-sales.apk` (или путь из `ANDROID_APK_URL`) — **отдельным деплоем**, не из этого агента без явного запроса.
5. Записать строку в таблицу `app_versions` на сервере.

## Аудит (ноябрь 2026) — без секретов

| Источник | Найден? | Примечание |
|---|---|---|
| Корпоративный `.jks` / `.keystore` в репо / CI env | **Нет** | Только gitignore-шаблоны |
| `android/keystore.properties` | **Нет** | Файл отсутствует (ожидаемо) |
| Env `MTS_STORE_FILE`, `MTS_KEY_ALIAS`, пароли | **Не заданы** | Release собирается **unsigned** |
| `~/.android/debug.keystore` (машина сборки) | Да | Только Debug; не для production |

### Package name

| Build type | `applicationId` |
|---|---|
| Debug | `kz.mtelecom.sales.debug` |
| Release | `kz.mtelecom.sales` |

### Сертификаты известных APK (SHA-256, публичный отпечаток)

| APK | Package | Подпись | SHA-256 (начало) |
|---|---|---|---|
| Live `https://mtelecom.kz/sales/downloads/m-telecom-sales.apk` | `kz.mtelecom.sales.debug` | Android Debug | `22b0a65b…d495ae71` |
| Локальная копия `backend/storage/downloads/m-telecom-sales.apk` | то же | тот же Debug | `22b0a65b…` (совпадает с live) |
| Локальный `app-debug.apk` (эта VM) | `kz.mtelecom.sales.debug` | Android Debug **этой** машины | `3c88fc3b…6767ac55` (**другой**) |
| Локальный `app-release-unsigned.apk` | `kz.mtelecom.sales` | нет подписи | — |

**Вывод:** ранее выпущенный/раздаваемый APK — Debug-пакет на чужом debug-ключе. Корпоративного Release-ключа в проекте нет. Новый ключ **не создавался** до согласования с владельцем.

## Матрица обновления без удаления

Обновление «поверх» возможно только при **одинаковом** `applicationId` **и** **одинаковом** сертификате подписи, плюс `versionCode` ≥ установленного.

| Уже установлено у менеджера | Новый APK | Без удаления? |
|---|---|---|
| Hosted debug (`…sales.debug`, SHA `22b0…`) | Новый APK с тем же debug-ключом `22b0…` | Да |
| Hosted debug (`22b0…`) | Debug с этой/другой машины (`3c88…` и т.п.) | **Нет** (другой debug-cert) |
| Hosted debug (`…sales.debug`) | Release `kz.mtelecom.sales` (любой ключ) | **Нет** (другой package) |
| Release, ключ A | Release, ключ A, больший `versionCode` | Да |
| Release, ключ A | Release, ключ B | **Нет** |

Практический план первого корпоративного релиза: менеджеры **удаляют** debug-приложение `kz.mtelecom.sales.debug` и ставят подписанный Release `kz.mtelecom.sales`. Дальше обновления — только тем же корпоративным ключом.

## Если корпоративного ключа ещё нет (не выполнять без согласования)

1. Владелец подтверждает письменно: «создаём первый корпоративный keystore».
2. Генерация **на защищённой машине владельца** (не в чате агента, не в CI без vault):

```bash
keytool -genkeypair -v \
  -keystore /secure/path/mtelecom-sales-release.jks \
  -alias mtelecom_sales \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storetype JKS
```

3. Резерв: файл `.jks` + пароли store/key + alias → корпоративный сейф (1Password / Vault), минимум 2 доверенных хранителя, без git/Slack/email.
4. Передать агенту/CI только через секреты окружения `MTS_*` или локальный gitignored `keystore.properties`.
5. Собрать Release, сверить `apksigner verify --print-certs`, раздать как первую «чистую» установку.

## Чего не делать

- Не коммитить `.jks`, `.keystore`, `keystore.properties`, пароли.
- Не печатать пароли в CI-логи и ответы ассистента.
- Не подписывать production-сборку debug-ключом для раздачи менеджерам.
- Не менять `applicationId` без миграционного плана.
- Не создавать новый корпоративный ключ, пока владелец не подтвердил отсутствие старого.
