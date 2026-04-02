# Продакшен: деплой и эксплуатация

Короткий чеклист для выкладки дашбордов на сервер (VPS, Railway, shared hosting с PHP 8.1+).

## 1. Требования

| | |
|---|---|
| PHP | **8.1+** (рекомендуется 8.2/8.3) |
| Расширения | `curl`, `json`, `mbstring`, `openssl`, `session` |
| Веб-сервер | Nginx или Apache |
| Document root | Каталог **`dashboard/`** репозитория (где лежат `index.php`, `assets/`) |

Если корень сайта указывает на репозиторий целиком, настройте alias или перенесите содержимое `dashboard/` в публичный каталог хостинга.

## 2. Секреты и конфигурация

1. Скопируйте `config.sample.php` → `dashboard/config.php` **на сервере** (файл в `.gitignore`).
2. Либо задайте переменные окружения (см. **`.env.example`** в корне репозитория) — они имеют приоритет над `config.php` для PAT и авторизации.

Минимум для работы:

- **`AIRTABLE_PAT`** — токен с правами `data.records:read`, `schema.bases:read`.
- **`AIRTABLE_BASE_ID`** — при необходимости переопределить базу.

Продакшен с формой входа:

- **`DASHBOARD_AUTH_USERNAME`**, **`DASHBOARD_AUTH_PASSWORD_HASH`** (рекомендуется) или **`DASHBOARD_AUTH_PASSWORD`**.
- Опционально **`DASHBOARD_AUTH_ENABLED`** = `1` / `true` (если нужно явно включить).

Страница **AI-аналитики** (`ai_insights.php`) вызывает Google Gemini: задайте **`DASHBOARD_GEMINI_API_KEY`** (или `gemini_api_key` в `config.php`). Ключ не коммитьте.

Доступ к **JSON API** при включённой веб-авторизации (cron, интеграции без браузера):

- Задайте **`DASHBOARD_API_SECRET`** (или `api_secret` в `config.php`).
- Запросы: заголовок `Authorization: Bearer <секрет>` или `X-Api-Key: <секрет>` (параметр `api_secret` в URL допускается, но попадает в логи — хуже для продакшена).

## 3. Права на каталоги

| Путь | Назначение |
|------|------------|
| `dashboard/cache/` | Кэш отчётов — **должен быть доступен на запись** веб-пользователю |
| `dashboard/snapshots/` | Снимки отчётов — запись при использовании архива |
| `dashboard/cache/ai-insights-history.json` | История метрик для AI-аналитики (создаётся автоматически, не в git) |

После первого деплоя: `touch dashboard/cache/.gitkeep` уже в репо; убедитесь, что владелец процесса PHP может создавать файлы в `cache/`.

## 4. Проверка живости (балансировщик)

`dashboard/healthz.php` **не подключает** `bootstrap.php` и отвечает `200` + текст `ok` — используйте как HTTP-пробу. Путь в URL: `/healthz.php` относительно корня, где развернут `dashboard/`.

## 5. Отладочные страницы

`churn_fact_debug.php` — только для диагностики. В продакшене задайте **`DASHBOARD_DISABLE_DEBUG=1`**, чтобы скрипт отвечал `404` (файл можно оставить на диске).

## 6. Cron (прогрев кэша)

Из **CLI** (не через HTTP):

```bash
php /полный/путь/dashboard/cron_warmup.php
```

Расписание — см. комментарии в `cron_warmup.php`. Для HTTP-вызовов API при включённой авторизации используйте **`DASHBOARD_API_SECRET`**, не сессию браузера.

## 7. HTTPS и сессии

За reverse proxy (Nginx, Cloudflare) с `X-Forwarded-Proto: https` сессионные cookie помечаются **Secure** автоматически в `bootstrap.php`. Убедитесь, что прокси передаёт этот заголовок.

## 8. Пример фрагмента Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name dashboards.example.com;
    root /var/www/app/dashboard;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~ /(config\.php|\.git) {
        deny all;
    }
}
```

Подставьте путь к сокету PHP-FPM и корень под вашу установку.

## 9. Деплой из Git

Типичный цикл:

```bash
git fetch origin && git checkout main && git pull
# при необходимости: composer нет — только PHP
```

Зафиксируйте в панели хостинга **ветку `main`** или **полный SHA** коммита (короткий хеш некоторые системы не принимают как ref).

## 10. Node без PHP

`dashboard/serve.mjs` — запасной режим без PHP: **не полный** функционал (см. `README.md`). Для «боевого» сценария нужен **PHP** на сервере или в контейнере.
