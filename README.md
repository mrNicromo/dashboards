# AnyQuery Dashboards

Локальные дашборды (дебиторка, Churn, факт потерь) поверх **Airtable**. Запуск без отдельного веб-сервера: встроенный PHP-сервер и браузер.

**Полная документация:** [dashboard/GUIDE.md](dashboard/GUIDE.md)

### Самый короткий путь (macOS)

1. Положите папку проекта куда удобно (например `~/Desktop/airtable`).
2. Один раз в **Терминале** выполните (подставьте свой путь к папке):

   ```bash
   cd ~/Desktop/airtable && xattr -rd com.apple.quarantine . 2>/dev/null; chmod +x LAUNCH.command start.command; ./LAUNCH.command
   ```

3. Если спросят токен Airtable — вставьте PAT. Либо заранее: `export AIRTABLE_PAT='pat_…'` и снова `./LAUNCH.command` (или двойной клик по **`start.command`** / **`LAUNCH.command`** в Finder).

Дальше обычно достаточно **двойного клика** по `start.command` или `LAUNCH.command` — отдельно `cd` и `xattr` не нужны.

---

## macOS: без дополнительных установок в систему

В типичном сценарии **ничего ставить через установщик не нужно**: Xcode, Homebrew, MAMP — опционально.

1. В macOS уже есть **curl** и обычно **python3** (`/usr/bin/python3`).
2. `LAUNCH.command` скачивает **PHP только в папку проекта** `portable/php` (~30 МБ, один раз, нужен интернет).
3. **Пароль администратора** понадобится **только если** автоматическое скачивание в `portable/php` не удалось и скрипт предложит **запасной путь через Homebrew** (или если вы сами задали `AQ_USE_HOMEBREW_FIRST=1`).

Чтобы **никогда не вызывать Homebrew**, запускайте так:

```bash
AQ_SKIP_HOMEBREW=1 ./LAUNCH.command
```

Чтобы **сначала** ставить PHP через Homebrew (пароль), а не portable:

```bash
AQ_USE_HOMEBREW_FIRST=1 ./LAUNCH.command
```

---

## Что нужно заранее

| | macOS | Windows |
|---|--------|---------|
| **Интернет** | Да, при первом запуске (скачивание PHP в `portable/php` или Brew) | Да, если нет своего PHP |
| **Права администратора** | Обычно **не нужны**; пароль Mac — только если сработал **Homebrew** | Зависит от политики ПК |
| **Airtable** | Personal Access Token (PAT) с правами `data.records:read` и `schema.bases:read` | То же |

---

## Первый запуск на macOS

### Шаг 1. Расположи папку проекта

Скопируй весь каталог (например `airtable`) на Mac. Запомни **полный путь** к папке, где лежат `LAUNCH.command` и папка `dashboard/`.

Пример: `/Users/имя/Desktop/airtable`

### Шаг 2. Открой «Терминал»

Finder → **Программы** → **Утилиты** → **Терминал**.

### Шаг 3. Один раз подготовь запуск

Вставь команду **одной строкой**, подставь **свой путь** вместо примера, нажми **Enter**:

```bash
cd /Users/имя/Desktop/airtable && xattr -rd com.apple.quarantine . && chmod +x LAUNCH.command && ./LAUNCH.command
```

Зачем это:

- `cd …` — переход в папку проекта  
- `xattr … quarantine` — снимает блокировку macOS с файлов из архива/интернета  
- `chmod +x` — право на запуск `LAUNCH.command`  
- `./LAUNCH.command` — старт лаунчера  

Если файлы `.command` приехали с Windows с окончаниями строк CRLF, можно дополнительно выполнить:

```bash
find . -name "*.command" -exec sed -i '' 's/\r$//' {} \;
```

### Шаг 4. Дождись появления PHP

Скрипт по очереди пробует:

1. Уже установленный PHP 8.1+ (PATH, Herd, MAMP и т.д.)  
2. **Переносимый PHP** в `portable/php` внутри проекта (~30 МБ, **без установки в систему** и без пароля админа)  
3. Если п.2 не вышел и терминал интерактивный — **Homebrew** (установщик + `brew install php`, может запросить **пароль администратора**)

Если **PHP так и не появился**, но установлен **Node.js 18+**, `LAUNCH.command` поднимет тот же порт через `dashboard/serve.mjs` (главный дашборд и API; часть страниц — в упрощённом виде, см. лог в терминале).

Если намеренно идёте через Brew — см. `AQ_USE_HOMEBREW_FIRST=1` в разделе выше.

### Шаг 5. Укажи токен Airtable (если попросит)

При первом запуске создаётся `dashboard/config.php`. Если PAT пустой, в терминале можно **ввести токен** (ввод скрыт) или нажать Enter и позже прописать вручную в `dashboard/config.php`.

**Альтернатива без ввода в терминал:**

```bash
export AIRTABLE_PAT='pat_ВАШ_ТОКЕН'
./LAUNCH.command
```

Токен запишется в `config.php`. Права токена: **data.records:read**, **schema.bases:read**.

Создать токен: [airtable.com](https://airtable.com) → Account → Developer Hub → Personal access tokens.

### Шаг 6. Браузер

Должен открыться адрес **http://127.0.0.1:9876** . Если нет — открой его вручную.

### Дальнейшие запуски

Достаточно **двойного клика** по `LAUNCH.command` в Finder (или снова `./LAUNCH.command` из той же папки).

Остановка сервера: в окне терминала **Ctrl+C**.

---

## Первый запуск на Windows

1. Скопируй папку проекта на ПК (лучше путь **без пробелов**, например `C:\tools\airtable`).  
2. **Дважды щёлкни** `LAUNCH.bat` в корне проекта.  
3. Если система попросит PHP — следуй сообщениям лаунчера (или поставь PHP/XAMPP по подсказке).  
4. В браузере откроется дашборд (порт смотри в окне лаунчера, часто **9876**).

Подробнее для Windows: [dashboard/GUIDE.md](dashboard/GUIDE.md) (раздел «Установка на Windows»).

---

## Полезные переменные окружения (macOS)

| Переменная | Назначение |
|------------|------------|
| `AIRTABLE_PAT` | Токен Airtable — запишется в `config.php` при запуске |
| `AIRTABLE_BASE_ID` | ID базы (если отличается от значения по умолчанию в шаблоне) |
| `AQ_SKIP_HOMEBREW=1` | Не вызывать Homebrew (только системный PHP, portable, Docker) |
| `AQ_USE_HOMEBREW_FIRST=1` | Сначала Homebrew, потом portable |
| `AQ_SKIP_AUTO_PHP=1` | Не скачивать portable; нужен уже установленный PHP |
| `AQ_USE_DOCKER=1` | Запуск через Docker, если PHP не удалось поставить иначе |
| `AQ_NONINTERACTIVE=1` | Без вопросов; Homebrew не используется (portable / Docker / свой PHP) |

---

## Авторизация без БД

Добавлена встроенная страница входа `dashboard/login.php` и выход `dashboard/logout.php`.

Авторизация включается автоматически, если заданы:

- `auth_username`
- `auth_password` **или** `auth_password_hash`

в `dashboard/config.php`, либо через env:

- `DASHBOARD_AUTH_USERNAME`
- `DASHBOARD_AUTH_PASSWORD` (или `DASHBOARD_AUTH_PASSWORD_HASH`)
- `DASHBOARD_AUTH_ENABLED` (`true`/`false`, опционально)

Когда auth включена, все PHP-страницы и API закрыты сессией; для API при неавторизованном доступе отдаётся `401 JSON`.

---

## Деплой "просто отдать сайт"

Добавлен самый простой шаблон деплоя под бесплатный VPS:

- `Dockerfile`
- `docker-compose.yml`
- `deploy/oracle-free-setup.sh`
- `DEPLOY.md` (пошаговый запуск)

Практически это: поднять Ubuntu VM (Oracle Free), выполнить команды из `DEPLOY.md`, открыть `http://PUBLIC_IP/index.php`.

---

## Деплой на Railway (самый быстрый облачный вариант)

В проект добавлен `railway.json`, поэтому Railway автоматически соберет контейнер из `Dockerfile`.

1. Залей проект в GitHub (или GitLab).
2. В Railway: **New Project** -> **Deploy from GitHub repo** -> выбери этот репозиторий.
3. В сервисе открой **Variables** и добавь:
   - `AIRTABLE_PAT=pat_...`
   - `AIRTABLE_BASE_ID=appEAS1rPKpevoIel` (или ваш base ID)
4. Нажми **Deploy**.
5. Вкладка **Settings** -> **Domains** -> **Generate Domain**.
6. Открой `https://<your-domain>/index.php`.

Если используете пароль на уровне `index.php`, домен можно просто отдавать коллегам.

---

## Если что-то пошло не так

| Симптом | Что проверить |
|---------|----------------|
| «Не открывается» .command | Шаг 3: `xattr`, `chmod`, запуск из Терминала один раз |
| Пустые данные в дашборде | `dashboard/config.php` → поле `airtable_pat`, права токена |
| Порт занят | Закрой старый терминал с сервером или смени порт в `LAUNCH.command` (`PORT=…`) |
| Корпоративный Mac | Фаервол может резать установку Homebrew или `portable/php`; тогда IT или предустановленный PHP 8.1+ |

**Краткая шпаргалка без Markdown:** см. файл [README.txt](README.txt).

---

## Структура каталогов (важное)

```
airtable/
  LAUNCH.command      # macOS
  LAUNCH.bat          # Windows
  README.md           # этот файл
  README.txt          # краткая текстовая версия
  dashboard/          # код приложения
    config.php        # создаётся при запуске; не коммитить с секретами
    config.sample.php # шаблон конфига
    cache/            # кэш JSON (создаётся автоматически)
    GUIDE.md          # полная инструкция
    airtable-fields.md   # какие поля Airtable использует дашборд
    local-daily-use.md     # локальный ежедневный сценарий
    open-dashboard.command # быстрый запуск из dashboard/ (macOS)
  portable/           # macOS: переносимый PHP (может появиться после первого запуска)
```

Файл `config.php` содержит секреты — **не выкладывай** его в открытый репозиторий.
