AnyQuery Dashboards
===================

Подробная пошаговая инструкция первого запуска — в файле README.md


WINDOWS
-------
Дважды кликни LAUNCH.bat
Всё остальное — автоматически.


MACOS — ПЕРВЫЙ ЗАПУСК
---------------------
1. Открой Terminal (Finder → Программы → Утилиты → Terminal)

2. Вставь команду целиком и нажми Enter:

   cd ~/Desktop/airtable && find . -name "*.command" -exec sed -i '' 's/\r$//' {} \; && chmod +x LAUNCH.command && xattr -rd com.apple.quarantine . && ./LAUNCH.command

   (замени ~/Desktop/airtable на путь к папке, если она не на Рабочем столе)

3. После первого запуска — просто дважды кликай LAUNCH.command в Finder.


ЧТО ДЕЛАЮТ ЛАУНЧЕРЫ
--------------------
Шаг 1  PHP: система → portable/php в папке проекта (~30 МБ, без установки в macOS) →
       при неудаче и интерактиве — Homebrew (пароль админа). AQ_SKIP_HOMEBREW=1 — без Brew
Шаг 2  Проверяет расширения curl, json, mbstring
Шаг 3  Создаёт папку cache/
Шаг 4  config.php из шаблона; PAT можно задать переменной AIRTABLE_PAT (запись в файл)
       или ввести вручную в интерактивном терминале
Шаг 5  Запускает PHP-сервер на порту 9876
Шаг 6  Открывает браузер: http://127.0.0.1:9876


ЕСЛИ БРАУЗЕР НЕ ОТКРЫЛСЯ
-------------------------
Открой вручную: http://127.0.0.1:9876


ЕСЛИ ДАННЫЕ НЕ ЗАГРУЖАЮТСЯ
---------------------------
Проверь Airtable PAT в файле: dashboard/config.php
Получить новый PAT: airtable.com → Account → Developer Hub → Personal access tokens
Нужны права: data.records:read  schema.bases:read


СТРУКТУРА ПАПОК
---------------
LAUNCH.bat         — запуск на Windows
LAUNCH.command     — запуск на macOS
dashboard/         — PHP-приложение (не трогать)
dashboard/cache/   — кэш данных (создаётся автоматически)
dashboard/config.php — твой Airtable PAT (создаётся при первом запуске)
README.txt         — этот файл
