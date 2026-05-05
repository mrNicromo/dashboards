# Деплой dashboard → Beget по push в main

Автодеплой настраивается один раз. После этого любой `git push origin main` через ~30 секунд оказывается на сервере.

## 0. Что должно быть на Beget

- **Хостинг или VPS** с PHP 8.1+ и `php-curl`.
- SSH-доступ (на shared-хостинге Beget включается в панели → «SSH»).
- Папка под сайт, в которой будет лежать репозиторий, например:
  `/home/<login>/<домен>/public_html` (shared) или `/var/www/dashboard` (VPS).
- Поддомен или домен, поднятый на эту папку.

## 1. SSH-ключ для деплоя

На своей машине **(не на Beget!)**:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy" -f ~/.ssh/beget_deploy -N ""
```

Получили два файла: `beget_deploy` (приват, его в GitHub) и `beget_deploy.pub` (паблик, его на Beget).

Положить паблик-ключ на сервер:

```bash
ssh-copy-id -i ~/.ssh/beget_deploy.pub <ваш_ssh_user>@<host>
# либо вручную: добавить содержимое .pub в ~/.ssh/authorized_keys на сервере
```

Снять отпечаток сервера для known_hosts:

```bash
ssh-keyscan -t ed25519,rsa -p 22 <host>
# скопировать вывод полностью — его в секрет BEGET_KNOWN_HOSTS
```

## 2. Секреты в GitHub

Открыть `https://github.com/mrNicromo/dashboards/settings/secrets/actions` и завести:

| Имя | Что положить |
|---|---|
| `BEGET_HOST` | хост, например `dimaarh.beget.tech` или IP VPS |
| `BEGET_USER` | SSH-логин (на shared-хостинге это логин из панели Beget) |
| `BEGET_PORT` | `22` (можно опустить — по умолчанию 22) |
| `BEGET_SSH_KEY` | содержимое **приватного** ключа `~/.ssh/beget_deploy` целиком (вместе с `-----BEGIN/END-----`) |
| `BEGET_KNOWN_HOSTS` | вывод `ssh-keyscan` |
| `BEGET_PATH` | абсолютный путь к папке проекта на сервере, без слэша в конце |
| `BEGET_HEALTHZ_URL` | (опционально) `https://ваш-домен/dashboard/healthz.php` |
| `BEGET_PUBLIC_URL` | (опционально) `https://ваш-домен/dashboard/` |

Также в Settings → Environments создать environment `production` (workflow на него ссылается). Там можно повесить protection rule «Required reviewers» — тогда перед каждым деплоем потребуется approve в GitHub.

## 3. Первый деплой

1. Сделать `git pull` локально, чтобы у тебя был свежий main с `.github/workflows/deploy-beget.yml`.
2. Запустить вручную: GitHub → Actions → «Deploy to Beget» → Run workflow.
3. После успеха проверить:
   - `https://ваш-домен/dashboard/healthz.php` → `ok`
   - `https://ваш-домен/dashboard/http_check.php` → JSON с PHP-версией
   - `https://ваш-домен/dashboard/index.php` → дашборд

Дальше любой push в `main` автоматически триггерит деплой.

## 4. Конфиг на сервере

`dashboard/config.php` **в репо не уезжает** (в `--exclude` workflow). На сервере его создаёте один раз, пример рядом — `dashboard/config.sample.php`. Минимум:

```php
<?php
return [
    'airtable_pat'     => 'pat_…',
    'airtable_base_id' => 'app…',
    'api_secret'       => '<строка для cron, чем длиннее, тем лучше>',
    'auth_username'    => 'admin',
    'auth_password_hash' => password_hash('ВАШ_ПАРОЛЬ', PASSWORD_DEFAULT),
];
```

После загрузки config-а: Settings page → Test Airtable. Должна получиться зелёная галочка.

## 5. Cron на сервере (опционально)

Чтобы кэш Google Sheets («Потери») и Airtable («Угроза», «ДЗ») были тёплыми к началу рабочего дня, поставьте крон:

```cron
0 19 * * *  cd <BEGET_PATH>/dashboard && /usr/bin/php cron_warmup.php >> /tmp/cron_warmup.log 2>&1
```

(19:00 UTC = 22:00 МСК.)

На shared-хостинге Beget кроны заводятся через панель: «Хостинг → Cron-задания».

## 6. Откат

`Actions → выбрать прошлый успешный run → Re-run all jobs`. Если этого мало — `git revert` плохого коммита и пуш в main, workflow прокатит фикс.
