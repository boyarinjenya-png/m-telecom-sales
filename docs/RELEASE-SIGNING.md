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
4. Выложить APK в `backend/storage/downloads/mtelecom-sales.apk` (или путь, указанный в `ANDROID_APK_URL`) — **отдельным деплоем**, не из этого агента без явного запроса.
5. Записать строку в таблицу `app_versions` на сервере.

## Чего не делать

- Не коммитить `.jks`, `.keystore`, `keystore.properties`, пароли.
- Не печатать пароли в CI-логи и ответы ассистента.
- Не подписывать production-сборку debug-ключом для раздачи менеджерам.
- Не менять `applicationId` без миграционного плана.
