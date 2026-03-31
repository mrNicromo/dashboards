#!/usr/bin/env bash
# ╔══════════════════════════════════════════════════════════════╗
# ║        AnyQuery Dashboards — macOS Launcher v1.0            ║
# ║  Устанавливает зависимости и запускает сервер одной командой ║
# ╚══════════════════════════════════════════════════════════════╝
set -euo pipefail

# ── Цвета и символы ────────────────────────────────────────────
RED='\033[0;31m';   GREEN='\033[0;32m';  YELLOW='\033[1;33m'
BLUE='\033[0;34m';  CYAN='\033[0;36m';   MAGENTA='\033[0;35m'
BOLD='\033[1m';     DIM='\033[2m';        RESET='\033[0m'
OK="${GREEN}✓${RESET}";  FAIL="${RED}✗${RESET}";  WAIT="${YELLOW}◌${RESET}"
ARROW="${CYAN}→${RESET}"

PORT=8080
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/server.log"

# ── Баннер ─────────────────────────────────────────────────────
clear
echo ""
echo -e "${BOLD}${BLUE}  ╔══════════════════════════════════════════════════════╗${RESET}"
echo -e "${BOLD}${BLUE}  ║        AnyQuery Dashboards — macOS Launcher         ║${RESET}"
echo -e "${BOLD}${BLUE}  ╚══════════════════════════════════════════════════════╝${RESET}"
echo ""

# ── Прогресс-бар ───────────────────────────────────────────────
STEPS=6
CURRENT=0

progress_bar() {
  local label="$1"
  local pct=$(( CURRENT * 100 / STEPS ))
  local filled=$(( CURRENT * 30 / STEPS ))
  local empty=$(( 30 - filled ))
  local bar=""
  for ((i=0; i<filled; i++)); do bar+="█"; done
  for ((i=0; i<empty;  i++)); do bar+="░"; done
  printf "\r  ${CYAN}[${bar}]${RESET} ${BOLD}%3d%%${RESET}  ${DIM}%s${RESET}          " "$pct" "$label"
}

step_ok() {
  local msg="$1"
  printf "\r  ${OK}  %-55s\n" "$msg"
  (( CURRENT++ )) || true
}

step_fail() {
  local msg="$1"
  printf "\r  ${FAIL}  %-55s\n" "$msg"
}

step_info() {
  local msg="$1"
  printf "      ${DIM}%s${RESET}\n" "$msg"
}

spinner() {
  local pid=$1
  local msg="$2"
  local frames=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
  local i=0
  while kill -0 "$pid" 2>/dev/null; do
    printf "\r  ${YELLOW}%s${RESET}  ${DIM}%s${RESET}     " "${frames[$((i % 10))]}" "$msg"
    sleep 0.1
    (( i++ )) || true
  done
  printf "\r"
}

echo -e "  ${DIM}Рабочая папка: $SCRIPT_DIR${RESET}"
echo ""

# ════════════════════════════════════════════════════════════════
# ШАГ 1 — Homebrew
# ════════════════════════════════════════════════════════════════
progress_bar "Проверка Homebrew..."

if command -v brew &>/dev/null; then
  step_ok "Homebrew уже установлен  $(brew --version | head -1)"
else
  printf "\n"
  echo -e "  ${WAIT}  Homebrew не найден — устанавливаем..."
  step_info "Это может занять 3–5 минут"
  (
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" </dev/null &>/dev/null
  ) &
  spinner $! "Установка Homebrew..."

  # Добавить brew в PATH для Apple Silicon
  if [[ -f /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
    echo 'eval "$(/opt/homebrew/bin/brew shellenv)"' >> ~/.zprofile 2>/dev/null || true
  fi

  if command -v brew &>/dev/null; then
    step_ok "Homebrew установлен успешно"
  else
    step_fail "Не удалось установить Homebrew"
    echo -e "\n  ${ARROW} Установите вручную: https://brew.sh\n"
    exit 1
  fi
fi

# ════════════════════════════════════════════════════════════════
# ШАГ 2 — PHP
# ════════════════════════════════════════════════════════════════
progress_bar "Проверка PHP..."

if command -v php &>/dev/null; then
  PHP_VER=$(php -r 'echo PHP_VERSION;')
  PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;')
  if [[ "$PHP_MAJOR" -ge 8 ]]; then
    step_ok "PHP $PHP_VER уже установлен  (>= 8.0 ✓)"
  else
    step_fail "PHP $PHP_VER слишком старый (нужен >= 8.1)"
    echo -e "  ${ARROW} Обновляем через Homebrew..."
    brew install php &>/dev/null &
    spinner $! "Установка PHP 8.x..."
    step_ok "PHP $(php -r 'echo PHP_VERSION;') обновлён"
  fi
else
  printf "\r  ${WAIT}  PHP не найден — устанавливаем...                    \n"
  step_info "Это займёт 1–3 минуты"
  (brew install php &>/dev/null) &
  spinner $! "Установка PHP через Homebrew..."

  # Обновить PATH
  if [[ -f /opt/homebrew/bin/php ]]; then
    export PATH="/opt/homebrew/bin:$PATH"
  fi

  if command -v php &>/dev/null; then
    step_ok "PHP $(php -r 'echo PHP_VERSION;') установлен"
  else
    step_fail "Не удалось установить PHP"
    echo -e "\n  ${ARROW} Попробуйте: brew install php\n"
    exit 1
  fi
fi

# ════════════════════════════════════════════════════════════════
# ШАГ 3 — PHP-расширения
# ════════════════════════════════════════════════════════════════
progress_bar "Проверка расширений PHP..."

MISSING_EXT=()
for ext in curl json mbstring; do
  if ! php -r "if(!extension_loaded('$ext')) exit(1);" 2>/dev/null; then
    MISSING_EXT+=("$ext")
  fi
done

if [[ ${#MISSING_EXT[@]} -eq 0 ]]; then
  step_ok "Расширения PHP: curl, json, mbstring — все активны"
else
  step_fail "Отсутствуют расширения: ${MISSING_EXT[*]}"
  step_info "Попробуйте: brew install php (они идут в комплекте)"
  exit 1
fi

# ════════════════════════════════════════════════════════════════
# ШАГ 4 — Папка кэша
# ════════════════════════════════════════════════════════════════
progress_bar "Подготовка папки кэша..."

CACHE_DIR="$SCRIPT_DIR/cache"
if [[ -d "$CACHE_DIR" ]]; then
  step_ok "Папка cache/ существует"
else
  mkdir -p "$CACHE_DIR"
  step_ok "Папка cache/ создана"
fi

chmod 775 "$CACHE_DIR" 2>/dev/null || true

# ════════════════════════════════════════════════════════════════
# ШАГ 5 — config.php
# ════════════════════════════════════════════════════════════════
progress_bar "Проверка конфигурации..."

CONFIG="$SCRIPT_DIR/config.php"
SAMPLE="$SCRIPT_DIR/config.sample.php"

if [[ -f "$CONFIG" ]]; then
  # Проверить что PAT заполнен
  PAT_FILLED=$(php -r "
    \$c = require '$CONFIG';
    echo (isset(\$c['airtable_pat']) && strlen(\$c['airtable_pat']) > 10) ? 'yes' : 'no';
  " 2>/dev/null || echo "no")

  if [[ "$PAT_FILLED" == "yes" ]]; then
    step_ok "config.php настроен  (AIRTABLE_PAT заполнен ✓)"
  else
    step_fail "config.php есть, но AIRTABLE_PAT пустой"
    printf "\n"
    echo -e "  ${ARROW} Введите ваш Airtable Personal Access Token:"
    echo -e "  ${DIM}  (получить: airtable.com → Account → Developer Hub → PAT)${RESET}"
    printf "  ${CYAN}PAT:${RESET} "
    read -r USER_PAT

    if [[ -n "$USER_PAT" ]]; then
      # Вставить PAT в config.php через sed
      sed -i.bak "s/'airtable_pat'[[:space:]]*=>[[:space:]]*''/'airtable_pat' => '$USER_PAT'/" "$CONFIG"
      rm -f "${CONFIG}.bak"
      step_ok "AIRTABLE_PAT сохранён в config.php"
    else
      echo -e "  ${YELLOW}⚠  PAT не введён — дашборды будут показывать пустые данные${RESET}"
    fi
    printf "\n"
  fi
else
  if [[ -f "$SAMPLE" ]]; then
    cp "$SAMPLE" "$CONFIG"
    step_fail "config.php создан из шаблона — нужно заполнить PAT"
    printf "\n"
    echo -e "  ${ARROW} Введите ваш Airtable Personal Access Token:"
    echo -e "  ${DIM}  (получить: airtable.com → Account → Developer Hub → PAT)${RESET}"
    printf "  ${CYAN}PAT:${RESET} "
    read -r USER_PAT

    if [[ -n "$USER_PAT" ]]; then
      sed -i.bak "s/'airtable_pat'[[:space:]]*=>[[:space:]]*''/'airtable_pat' => '$USER_PAT'/" "$CONFIG"
      rm -f "${CONFIG}.bak"
      step_ok "config.php настроен и AIRTABLE_PAT сохранён"
    else
      echo -e "  ${YELLOW}⚠  PAT не введён${RESET}"
    fi
    printf "\n"
  else
    step_fail "config.sample.php не найден — проверьте папку проекта"
    exit 1
  fi
fi

# ════════════════════════════════════════════════════════════════
# ШАГ 6 — Проверить занятость порта / запустить сервер
# ════════════════════════════════════════════════════════════════
progress_bar "Запуск сервера..."

# Убить старый процесс на том же порту
if lsof -ti:"$PORT" &>/dev/null; then
  OLD_PID=$(lsof -ti:"$PORT")
  kill "$OLD_PID" 2>/dev/null || true
  sleep 0.5
fi

# Запустить PHP built-in server в фоне
cd "$SCRIPT_DIR"
php -S "localhost:$PORT" > "$LOG_FILE" 2>&1 &
SERVER_PID=$!

# Дать серверу 2 секунды на старт
sleep 1

if kill -0 "$SERVER_PID" 2>/dev/null; then
  step_ok "PHP сервер запущен  PID=$SERVER_PID → http://localhost:$PORT"
else
  step_fail "Не удалось запустить сервер — смотрите server.log"
  cat "$LOG_FILE"
  exit 1
fi

# ════════════════════════════════════════════════════════════════
# Финальный экран
# ════════════════════════════════════════════════════════════════
echo ""
echo -e "${BOLD}${GREEN}  ════════════════════════════════════════════════${RESET}"
echo -e "${BOLD}${GREEN}  ✓  Всё готово! Открываем браузер...${RESET}"
echo -e "${BOLD}${GREEN}  ════════════════════════════════════════════════${RESET}"
echo ""
echo -e "  ${ARROW}  Главная:        ${BOLD}http://localhost:$PORT/${RESET}"
echo -e "  ${ARROW}  Угроза Churn:   ${BOLD}http://localhost:$PORT/churn.php${RESET}"
echo -e "  ${ARROW}  Потери выручки: ${BOLD}http://localhost:$PORT/churn_fact.php${RESET}"
echo -e "  ${ARROW}  Дебиторка:      ${BOLD}http://localhost:$PORT/manager.php${RESET}"
echo ""
echo -e "  ${DIM}Лог сервера: $LOG_FILE${RESET}"
echo -e "  ${DIM}Остановить:  Ctrl+C  или  kill $SERVER_PID${RESET}"
echo ""

# Открыть браузер
sleep 0.5
open "http://localhost:$PORT/" 2>/dev/null || true

# Показывать лог сервера в реальном времени
echo -e "  ${DIM}── Лог сервера (Ctrl+C для выхода) ──────────────${RESET}"
tail -f "$LOG_FILE" &
TAIL_PID=$!

# Ждать Ctrl+C и корректно завершить
trap "echo ''; echo -e '  ${YELLOW}Останавливаем сервер...${RESET}'; kill $SERVER_PID 2>/dev/null; kill $TAIL_PID 2>/dev/null; echo -e '  ${OK}  Сервер остановлен.'; exit 0" INT TERM

wait $SERVER_PID 2>/dev/null || true
kill $TAIL_PID 2>/dev/null || true
