# GitHub

Исходный код Sales хранится в **отдельном** репозитории. Репозиторий и GitHub-деплой основного сайта mtelecom.kz не изменять.

## Что не попадает в git

См. `.gitignore`: `.env`, ключи, `install.lock`, APK, дампы SQLite, `local.properties`.

## Первая публикация исходников (когда подтвердите)

```bash
git init
git add .
git status
git commit -m "Initial M-TELECOM SALES system"
git branch -M main
git remote add origin git@github.com:ORG/mtelecom-sales.git
git push -u origin main
```

Не выполняйте push и не подключайте этот remote к хостингу основного сайта без отдельного подтверждения.

## Production

Выкладка на Hoster.KZ — только после проверки `.env`, отдельной БД и HTTPS. Автоматический деплой в ту же папку, что и mtelecom.kz, запрещён.
