#!/usr/bin/env zsh
# ╔══════════════════════════════════════════════════════════════════╗
# ║           AnyQuery Dashboards — macOS Launcher                  ║
# ║                                                                  ║
# ║  ЕСЛИ ФАЙЛ НЕ ОТКРЫВАЕТСЯ (macOS заблокировал):                 ║
# ║  Открой файл  ОТКРЫТЬ-СНАЧАЛА.html  в браузере —               ║
# ║  там есть пошаговая инструкция с кнопкой "Копировать".          ║
# ║                                                                  ║
# ║  Первый раз из Терминала (подставь свой путь к папке с проектом): ║
# ║    cd /путь/к/airtable                                             ║
# ║    xattr -dr com.apple.quarantine . && chmod +x LAUNCH.command     ║
# ║    ./LAUNCH.command   или   ./start.command  (то же самое)          ║
# ║  Дальше можно открывать LAUNCH.command / start.command двойным кликом. ║
# ║                                                                   ║
# ║  Без установки в систему: сначала PHP в portable/php (~30 МБ, curl+python3 из macOS). ║
# ║  Если PHP 8.1+ нет — при наличии Node.js 18+ запускается dashboard/serve.mjs.     ║
# ║  Если не вышло и есть терминал — запасной вариант Homebrew (пароль админа). ║
# ║  AQ_SKIP_HOMEBREW=1 — не вызывать Homebrew; AQ_SKIP_AUTO_PHP=1 — не качать portable. ║
# ║  AQ_USE_HOMEBREW_FIRST=1 — сначала Homebrew, потом portable.                ║
# ║  Секреты: AIRTABLE_PAT в окружении → config.php. AQ_NONINTERACTIVE=1 — без вопросов.║
# ╚══════════════════════════════════════════════════════════════════╝

set -o pipefail
# zsh: не падать на glob без совпадений (MacPorts и т.д.)
[[ -n ${ZSH_VERSION-} ]] && setopt NO_NOMATCH 2>/dev/null

# С TTY — можно спросить PAT; без TTY / AQ_NONINTERACTIVE=1 — только автоматика
AQ_INTERACTIVE=0
if [[ -t 0 && -t 1 ]] && [[ "${AQ_NONINTERACTIVE:-}" != "1" ]]; then
  AQ_INTERACTIVE=1
fi
[[ "${AQ_FORCE_INTERACTIVE:-}" == "1" ]] && AQ_INTERACTIVE=1

pause_exit() {
  local code="${1:-1}"
  (( AQ_INTERACTIVE )) && read -r "?  Нажмите Enter для выхода..."
  exit "$code"
}

# ── Пути ─────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
APP_DIR="${SCRIPT_DIR}/dashboard"
CACHE_DIR="${APP_DIR}/cache"
CONFIG_FILE="${APP_DIR}/config.php"
MARKER_FILE="${APP_DIR}/.aq_setup_done"
LOG_FILE="${APP_DIR}/.local-server.log"
# Переносимый PHP только внутри папки проекта (не HOME, не /Applications)
AQ_PHP_HOME="${SCRIPT_DIR}/portable/php"
PORT=9876
URL="http://127.0.0.1:${PORT}/index.php"
USE_DOCKER=false
USE_NODE=0
USE_DOCKER_FLAG="${AQ_USE_DOCKER:-}"

# ── Цвета ─────────────────────────────────────────────────────────────
R='\033[0;31m' G='\033[0;32m' Y='\033[1;33m' B='\033[0;34m'
C='\033[0;36m' M='\033[0;35m' W='\033[1;37m' D='\033[2m' N='\033[0m'
OK="${G}✓${N}" FAIL="${R}✗${N}" WAIT="${Y}◌${N}" INFO="${C}→${N}"

TOTAL_STEPS=4
CUR_STEP=0

draw_bar() {
  local label="$1"
  local pct=$(( CUR_STEP * 100 / TOTAL_STEPS ))
  local fill=$(( CUR_STEP * 32 / TOTAL_STEPS ))
  local empty=$(( 32 - fill ))
  local bar="" i
  for (( i=0; i<fill;  i++ )); do bar+="█"; done
  for (( i=0; i<empty; i++ )); do bar+="░"; done
  printf "\r  ${C}[${bar}]${N} ${W}%3d%%${N}  ${D}%-40s${N}  " "$pct" "$label"
}

ok_step()   { printf "\r  ${OK}  %-60s\n" "$1"; CUR_STEP=$(( CUR_STEP + 1 )); }
fail_step() { printf "\r  ${FAIL}  %-60s\n" "$1"; }
info_line() { printf "      ${D}%s${N}\n" "$1"; }

spinner() {
  local pid=$1 msg="$2"
  local spin=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
  local i=1
  while kill -0 "$pid" 2>/dev/null; do
    printf "\r  ${Y}%s${N}  ${D}%-55s${N}" "${spin[$i]}" "$msg"
    sleep 0.12
    i=$(( i % 10 + 1 ))
  done
  printf "\r"
}

# ── Шаг 0: quarantine (Finder/архив помечает файлы — иначе macOS блокирует запуск)
xattr -rd com.apple.quarantine "${SCRIPT_DIR}" 2>/dev/null || true
xattr -rd com.apple.quarantine "${SCRIPT_DIR}/portable" 2>/dev/null || true
chmod +x "${SCRIPT_DIR}/LAUNCH.command" 2>/dev/null || true

# ── Баннер ────────────────────────────────────────────────────────────
clear
printf "\n"
printf "  ${B}╔══════════════════════════════════════════════════════════╗${N}\n"
printf "  ${B}║          AnyQuery Dashboards — macOS Launcher            ║${N}\n"
printf "  ${B}║                                                          ║${N}\n"
if [[ -f "$MARKER_FILE" ]]; then
  printf "  ${B}║              Повторный запуск — быстрый старт            ║${N}\n"
else
  printf "  ${B}║      Первый запуск — PHP в portable/php (без установки в ОС)       ║${N}\n"
fi
printf "  ${B}╚══════════════════════════════════════════════════════════╝${N}\n"
printf "\n  ${D}Папка: %s${N}\n\n" "$SCRIPT_DIR"

# ════════════════════════════════════════════════════════════════════════
# ШАГ 1 — PHP
# Приоритет: portable/php → системный PHP → скачать в portable/php → Docker
# ════════════════════════════════════════════════════════════════════════
draw_bar "Поиск PHP..."

# Требование проекта: PHP 8.1+ (см. dashboard/GUIDE.md)
_php_ok() {
  local maj min
  maj=$("$1" -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null) || return 1
  min=$("$1" -r 'echo PHP_MINOR_VERSION;' 2>/dev/null) || return 1
  (( maj >= 9 )) && return 0
  (( maj == 8 && min >= 1 )) && return 0
  return 1
}

find_php() {
  local p

  # 1. Переносимый PHP в папке проекта (рабочий ПК / USB без установки в систему)
  p="${AQ_PHP_HOME}/bin/php"
  [[ -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0

  # 2. Всё что в PATH
  while IFS= read -r p; do
    [[ -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
  done < <(which -a php 2>/dev/null)

  # 3. Стандартные пути
  for p in /usr/bin/php /usr/local/bin/php \
            /opt/homebrew/bin/php /opt/homebrew/opt/php/bin/php \
            /usr/local/opt/php/bin/php /opt/local/bin/php; do
    [[ -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
  done

  # 4. MAMP / MAMP PRO
  for mamp_base in "/Applications/MAMP/bin/php" "/Applications/MAMP PRO/bin/php"; do
    if [[ -d "$mamp_base" ]]; then
      p=$(find "$mamp_base" -name "php" -path "*/bin/php" 2>/dev/null | sort -rV | head -1)
      [[ -n "$p" && -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
    fi
  done

  # 5. Laravel Herd
  for p in "${HOME}/Library/Application Support/Herd/bin/php" \
            "${HOME}/.config/herd/bin/php"; do
    [[ -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
  done

  # 6. XAMPP / MacPorts (find вместо php* — иначе zsh: no matches found, если MacPorts не стоит)
  for p in /Applications/XAMPP/bin/php /opt/lampp/bin/php; do
    [[ -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
  done
  if [[ -d /opt/local/bin ]]; then
    p=$(find /opt/local/bin -maxdepth 1 -type f \( -name 'php' -o -name 'php[0-9]*' \) 2>/dev/null | sort -rV | head -1)
    [[ -n "$p" && -x "$p" ]] && _php_ok "$p" && echo "$p" && return 0
  fi

  return 1
}

# ── Запасной сервер: Node.js 18+ (dashboard/serve.mjs) ────────────────
_node_serve_ok() {
  command -v node &>/dev/null || return 1
  local maj
  maj=$(node -p "parseInt(process.versions.node,10)" 2>/dev/null) || return 1
  (( maj >= 18 )) || return 1
  [[ -f "${APP_DIR}/serve.mjs" ]] || return 1
  return 0
}

_merge_config_from_env_node() {
  [[ -n "${AIRTABLE_PAT:-}" || -n "${AIRTABLE_BASE_ID:-}" ]] || return 0
  ( cd "$APP_DIR" || return 1
    export AQ_MERGE_PAT="${AIRTABLE_PAT:-}"
    export AQ_MERGE_BASE="${AIRTABLE_BASE_ID:-}"
    node <<'NODEPATCH'
const fs = require("fs");
const pat = process.env.AQ_MERGE_PAT || "";
const baseId = process.env.AQ_MERGE_BASE || "";
let s = fs.readFileSync("config.php", "utf8");
function q(str) {
  return String(str).replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}
if (pat) s = s.replace(/'airtable_pat'\s*=>\s*'[^']*'/, `'airtable_pat' => '${q(pat)}'`);
if (baseId) {
  s = s.replace(
    /'airtable_base_id'\s*=>\s*'[^']*'/,
    `'airtable_base_id' => '${q(baseId)}'`
  );
}
fs.writeFileSync("config.php", s);
NODEPATCH
  )
}

# ── Автоустановка PHP из Homebrew bottles (без sudo, ~30 МБ) ─────────
_auto_install_php() {
  local arch macos tag tmp PYTHON_CMD CURL_CMD
  CURL_CMD="$(command -v curl 2>/dev/null || echo /usr/bin/curl)"
  PYTHON_CMD="$(command -v python3 2>/dev/null)"
  [[ -n "$PYTHON_CMD" && -x "$PYTHON_CMD" ]] || PYTHON_CMD="/usr/bin/python3"
  [[ -x "$PYTHON_CMD" ]] || return 1
  [[ -x "$CURL_CMD" ]] || return 1

  arch=$(uname -m)
  macos=$(sw_vers -productVersion | cut -d. -f1)

  # Теги bottle Homebrew под версию macOS (неизвестные — ближайший fallback в Python ниже)
  if [[ "$arch" == "arm64" ]]; then
    case $macos in
      16|17) tag="arm64_tahoe";; 15) tag="arm64_sequoia";; 14) tag="arm64_sonoma";;
      13) tag="arm64_ventura";; *) tag="arm64_sequoia";; esac
  else
    case $macos in
      16|17) tag="tahoe";; 15) tag="sequoia";; 14) tag="sonoma";;
      13) tag="ventura";; *) tag="sequoia";; esac
  fi

  tmp=$(mktemp -d /tmp/aq_php_XXXXXX)
  mkdir -p "${AQ_PHP_HOME}/bin" "${AQ_PHP_HOME}/lib"

  # Загрузить один флакон по имени формулы
  _dl_bottle() {
    local name="$1" outfile="$2"
    local api_name="${name//@/%40}"          # openssl@3 → openssl%403 для URL
    local ghcr_scope="${name//@/-}"          # openssl@3 → openssl-3 для ghcr scope
    local info token url

    info=$("$CURL_CMD" -fsSL --retry 3 --retry-delay 2 --max-time 30 \
      "https://formulae.brew.sh/api/formula/${api_name}.json" 2>/dev/null) || return 1

    token=$("$CURL_CMD" -fsSL --retry 3 --retry-delay 2 --max-time 15 \
      "https://ghcr.io/token?service=ghcr.io&scope=repository:homebrew/core/${ghcr_scope}:pull" \
      2>/dev/null | "$PYTHON_CMD" -c \
      "import sys,json; print(json.load(sys.stdin).get('token',''))" 2>/dev/null)
    [[ -z "$token" ]] && return 1

    url=$(echo "$info" | "$PYTHON_CMD" -c "
import sys, json
d = json.load(sys.stdin)
files = d.get('bottle', {}).get('stable', {}).get('files', {})
t = '${tag}'
if t in files:
    print(files[t]['url'])
else:
    for k, v in sorted(files.items(), reverse=True):
        print(v['url']); break
" 2>/dev/null)
    [[ -z "$url" ]] && return 1

    "$CURL_CMD" -fsSL --retry 3 --retry-delay 2 --max-time 300 \
      -H "Authorization: Bearer $token" "$url" -o "$outfile" 2>/dev/null
  }

  printf "\r  ${WAIT}  PHP не найден — скачиваем (~30 МБ, только один раз)...\n\n"

  # Скачиваем параллельно
  (
    _dl_bottle "php"        "${tmp}/php.tar.gz" &
    _dl_bottle "openssl@3"  "${tmp}/openssl.tar.gz" &
    _dl_bottle "pcre2"      "${tmp}/pcre2.tar.gz" &
    _dl_bottle "oniguruma"  "${tmp}/oniguruma.tar.gz" &
    wait
  ) &
  spinner $! "Скачиваем PHP + зависимости"

  if [[ ! -s "${tmp}/php.tar.gz" ]]; then
    rm -rf "$tmp"; return 1
  fi

  # Распаковываем все скачанные флаконы (find — без zsh-glob на пустой набор)
  find "$tmp" -maxdepth 1 -name '*.tar.gz' -type f 2>/dev/null | while IFS= read -r f; do
    [[ -s "$f" ]] && tar xf "$f" -C "$tmp" 2>/dev/null || true
  done

  # Находим и копируем PHP-бинарник
  local php_bin
  php_bin=$(find "$tmp" -type f -name "php" 2>/dev/null | grep -E '/bin/php$' | head -1)
  [[ -z "$php_bin" ]] && { rm -rf "$tmp"; return 1; }

  cp "$php_bin" "${AQ_PHP_HOME}/bin/php"
  chmod +x "${AQ_PHP_HOME}/bin/php"

  # Копируем все .dylib из флаконов
  find "$tmp" -name "*.dylib" 2>/dev/null | while IFS= read -r lib; do
    cp -n "$lib" "${AQ_PHP_HOME}/lib/" 2>/dev/null || true
  done

  # Правим захардкоженные пути /opt/homebrew → наш каталог
  local php_dest="${AQ_PHP_HOME}/bin/php"
  if command -v otool &>/dev/null && command -v install_name_tool &>/dev/null; then
    # Правим бинарник
    otool -L "$php_dest" 2>/dev/null | awk 'NR>1{print $1}' | \
    grep -E '/(opt/homebrew|Cellar|usr/local/opt)/' | \
    while IFS= read -r old; do
      local libname; libname=$(basename "$old")
      [[ -f "${AQ_PHP_HOME}/lib/${libname}" ]] && \
        install_name_tool -change "$old" "${AQ_PHP_HOME}/lib/${libname}" "$php_dest" 2>/dev/null || true
    done

    # Правим dylibs (find — если *.dylib нет, glob в zsh падает)
    find "${AQ_PHP_HOME}/lib" -maxdepth 1 -name '*.dylib' -type f 2>/dev/null | while IFS= read -r dylib; do
      otool -L "$dylib" 2>/dev/null | awk 'NR>1{print $1}' | \
      grep -E '/(opt/homebrew|Cellar|usr/local/opt)/' | \
      while IFS= read -r old; do
        local libname; libname=$(basename "$old")
        [[ -f "${AQ_PHP_HOME}/lib/${libname}" ]] && \
          install_name_tool -change "$old" "${AQ_PHP_HOME}/lib/${libname}" "$dylib" 2>/dev/null || true
      done
    done

    # Переподписываем (убирает hardened runtime, иначе DYLD не работает)
    codesign --force --sign - "$php_dest" 2>/dev/null || true
  fi

  rm -rf "$tmp"

  chmod +x "${AQ_PHP_HOME}/bin/php" 2>/dev/null || true
  xattr -rd com.apple.quarantine "${AQ_PHP_HOME}" 2>/dev/null || true

  _php_ok "${AQ_PHP_HOME}/bin/php" && echo "${AQ_PHP_HOME}/bin/php" && return 0
  rm -rf "${AQ_PHP_HOME}"   # почистить если не работает
  return 1
}

# ── Homebrew: установка PHP с паролем администратора (только интерактивный терминал)
_brew_shellenv() {
  if [[ -x /opt/homebrew/bin/brew ]]; then
    eval "$(/opt/homebrew/bin/brew shellenv)"
  elif [[ -x /usr/local/bin/brew ]]; then
    eval "$(/usr/local/bin/brew shellenv)"
  fi
}

_install_php_via_homebrew() {
  (( AQ_INTERACTIVE )) || return 1
  [[ "${AQ_SKIP_HOMEBREW:-}" == "1" ]] && return 1
  { command -v curl &>/dev/null || [[ -x /usr/bin/curl ]]; } || return 1

  _brew_shellenv

  if ! command -v brew &>/dev/null; then
    printf "\n  ${WAIT}  Homebrew не установлен.\n"
    printf "  ${W}Сейчас запустится официальный установщик — введите пароль администратора Mac,${N}\n"
    printf "  ${W}когда macOS или установщик попросит (это нормально для установки инструментов).${N}\n\n"
    read -r "?  Нажмите Enter чтобы начать, Ctrl+C — отмена и переход к другому способу PHP..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)" || return 1
    _brew_shellenv
  fi
  command -v brew &>/dev/null || return 1

  printf "\n  ${WAIT}  ${W}brew install php${N} — может занять 5–15 минут, нужен интернет.\n\n"
  brew install php || return 1
  _brew_shellenv
  hash -r 2>/dev/null || true
  return 0
}

# ── Основной поиск PHP ────────────────────────────────────────────────
PHP_CMD=""

if PHP_CMD=$(find_php); then
  PHP_VER=$("$PHP_CMD" -r 'echo PHP_VERSION;' 2>/dev/null)
  ok_step "PHP ${PHP_VER}  (${PHP_CMD})"

elif [[ "${AQ_USE_HOMEBREW_FIRST:-}" == "1" ]] && (( AQ_INTERACTIVE )) && [[ "${AQ_SKIP_HOMEBREW:-}" != "1" ]] \
  && _install_php_via_homebrew && PHP_CMD=$(find_php); then
  PHP_VER=$("$PHP_CMD" -r 'echo PHP_VERSION;' 2>/dev/null)
  ok_step "PHP ${PHP_VER} — Homebrew (${PHP_CMD})"

elif [[ "${AQ_SKIP_AUTO_PHP:-}" != "1" ]] && { command -v curl &>/dev/null || [[ -x /usr/bin/curl ]]; } \
  && { command -v python3 &>/dev/null || [[ -x /usr/bin/python3 ]]; } \
  && PHP_CMD=$(_auto_install_php); then
  PHP_VER=$("$PHP_CMD" -r 'echo PHP_VERSION;' 2>/dev/null)
  ok_step "PHP ${PHP_VER} — portable ${AQ_PHP_HOME} (без установки в macOS)"

elif (( AQ_INTERACTIVE )) && [[ "${AQ_SKIP_HOMEBREW:-}" != "1" ]] && _install_php_via_homebrew && PHP_CMD=$(find_php); then
  PHP_VER=$("$PHP_CMD" -r 'echo PHP_VERSION;' 2>/dev/null)
  ok_step "PHP ${PHP_VER} — Homebrew, запасной вариант (${PHP_CMD})"

elif [[ "$USE_DOCKER_FLAG" == "1" ]] && command -v docker &>/dev/null && docker info &>/dev/null 2>&1; then
  USE_DOCKER=true
  ok_step "PHP не найден → Docker (AQ_USE_DOCKER=1)"

elif _node_serve_ok; then
  USE_NODE=1
  PHP_CMD=""
  ok_step "PHP 8.1+ не найден → Node.js serve.mjs (порт ${PORT}, нужен Node 18+)"

else
  fail_step "Не удалось получить PHP 8.1+ и нет Node.js 18+ с dashboard/serve.mjs."
  printf "\n  ${W}Обычный порядок:${N} системный PHP → ${C}portable/php${N} в папке проекта (без Brew) → Homebrew (пароль) → Docker → ${C}Node.js${N}.\n\n"
  printf "  ${INFO}  Сначала Homebrew: ${W}AQ_USE_HOMEBREW_FIRST=1 ./LAUNCH.command${N}\n"
  printf "  ${INFO}  Без Homebrew вообще: ${W}AQ_SKIP_HOMEBREW=1 ./LAUNCH.command${N}\n"
  printf "  ${INFO}  Свой PHP: ${W}AQ_SKIP_AUTO_PHP=1${N} · Docker: ${W}AQ_USE_DOCKER=1${N}\n\n"
  pause_exit 1
fi

# ════════════════════════════════════════════════════════════════════════
# ШАГ 2 — PHP расширения
# ════════════════════════════════════════════════════════════════════════
draw_bar "Проверка расширений..."

if [[ "${USE_NODE:-0}" == "1" ]]; then
  ok_step "Режим Node.js — проверка расширений PHP не нужна"
elif [[ "$USE_DOCKER" == false ]]; then
  MISSING=()
  for ext in curl json mbstring; do
    "$PHP_CMD" -r "if(!extension_loaded('$ext')){exit(1);}" 2>/dev/null || MISSING+=("$ext")
  done
  if (( ${#MISSING[@]} == 0 )); then
    ok_step "Расширения PHP: curl ✓  json ✓  mbstring ✓"
  else
    if _node_serve_ok; then
      USE_NODE=1
      PHP_CMD=""
      ok_step "Расширения PHP неполные → Node.js serve.mjs (порт ${PORT})"
    else
      fail_step "Отсутствуют расширения: ${MISSING[*]}"
      info_line "Попробуйте Laravel Herd или MAMP — они включают все расширения"
      info_line "Или установите Node.js 18+ — тогда сработает запасной сервер serve.mjs"
      pause_exit 1
    fi
  fi
else
  ok_step "Расширения — Docker образ php:8.2-cli содержит всё необходимое"
fi

# PHP для шага конфига: локальный бинарник или одноразовый docker run (если AQ_USE_DOCKER=1)
php_cli() {
  if [[ "${USE_NODE:-0}" == "1" ]]; then
    return 127
  fi
  if [[ -n "${PHP_CMD:-}" ]]; then
    ( cd "$APP_DIR" || exit 1; env AQ_TMP_PAT="${AQ_TMP_PAT:-}" "$PHP_CMD" "$@" )
  elif [[ "$USE_DOCKER" == true ]]; then
    docker run --rm \
      -e "AQ_TMP_PAT=${AQ_TMP_PAT:-}" \
      -v "${APP_DIR}:/var/www" \
      -w /var/www \
      php:8.2-cli php "$@"
  else
    return 127
  fi
}

# ════════════════════════════════════════════════════════════════════════
# ШАГ 3 — Кэш и конфиг
# ════════════════════════════════════════════════════════════════════════
draw_bar "Подготовка..."

if [[ ! -d "$APP_DIR" ]]; then
  fail_step "Папка dashboard/ не найдена — проверьте структуру проекта"
  printf "  ${INFO}  Ожидается: ${W}%s/dashboard/${N}\n" "$SCRIPT_DIR"
  pause_exit 1
fi

mkdir -p "$CACHE_DIR"
chmod 775 "$CACHE_DIR" 2>/dev/null || true

SAMPLE_FILE="${APP_DIR}/config.sample.php"
if [[ ! -f "$CONFIG_FILE" ]]; then
  if [[ ! -f "$SAMPLE_FILE" ]]; then
    fail_step "Нет config.sample.php — восстановите шаблон в dashboard/"
    pause_exit 1
  fi
  cp "$SAMPLE_FILE" "$CONFIG_FILE"
  ok_step "config.php создан из шаблона, cache/ готова"
else
  ok_step "config.php ✓  cache/ ✓"
fi

# Секреты из окружения (развёртывание на другом Mac без ручного редактирования файла)
if [[ -n "${AIRTABLE_PAT:-}" || -n "${AIRTABLE_BASE_ID:-}" ]]; then
  if [[ "${USE_NODE:-0}" == "1" ]]; then
    _merge_config_from_env_node 2>/dev/null && printf "  ${OK}  Из окружения записано в dashboard/config.php\n\n"
  else
    AQ_TMP_PAT="${AIRTABLE_PAT:-}" AQ_TMP_BASE="${AIRTABLE_BASE_ID:-}" php_cli -r '
$c = require "config.php";
if (getenv("AQ_TMP_PAT") !== false && getenv("AQ_TMP_PAT") !== "") {
    $c["airtable_pat"] = (string) getenv("AQ_TMP_PAT");
}
if (getenv("AQ_TMP_BASE") !== false && getenv("AQ_TMP_BASE") !== "") {
    $c["airtable_base_id"] = (string) getenv("AQ_TMP_BASE");
}
file_put_contents(
  "config.php",
  "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n"
);
' 2>/dev/null && printf "  ${OK}  Из окружения записано в dashboard/config.php\n\n"
  fi
fi

# PAT в файле — спросить только в интерактиве, если пусто
if [[ "${USE_NODE:-0}" == "1" ]]; then
  _pat_filled="$( ( cd "$APP_DIR" && node -e "const fs=require('fs');const s=fs.readFileSync('config.php','utf8');const m=s.match(/'airtable_pat'\\s*=>\\s*'([^']*)'/);const p=(m&&m[1])||'';process.stdout.write(p.length>12?'1':'0');" ) 2>/dev/null )"
  [[ "$_pat_filled" == "1" ]] || _pat_filled="0"
else
  _pat_filled=$(php_cli -r '
$c = @include "config.php";
echo (is_array($c) && !empty($c["airtable_pat"]) && strlen((string)$c["airtable_pat"]) > 12) ? "1" : "0";
' 2>/dev/null) || _pat_filled="0"
fi

if [[ "$_pat_filled" != "1" ]]; then
  if (( AQ_INTERACTIVE )); then
    printf "\n  ${INFO}  ${W}Airtable PAT${N} не задан в config.php.\n"
    printf "      ${D}(или задайте ${W}export AIRTABLE_PAT=...${D} перед запуском)${N}\n\n"
    read -rs 'USER_PAT?PAT: '
    echo ""
    if [[ -n "$USER_PAT" ]]; then
      if [[ "${USE_NODE:-0}" == "1" ]]; then
        ( cd "$APP_DIR" || exit 1
          export AQ_MERGE_PAT="$USER_PAT"
          node <<'NODEPAT'
const fs = require("fs");
const pat = process.env.AQ_MERGE_PAT || "";
let s = fs.readFileSync("config.php", "utf8");
function q(str) {
  return String(str).replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}
if (pat) s = s.replace(/'airtable_pat'\s*=>\s*'[^']*'/, `'airtable_pat' => '${q(pat)}'`);
fs.writeFileSync("config.php", s);
NODEPAT
        ) || { fail_step "Не удалось записать config.php"; pause_exit 1; }
      else
        AQ_TMP_PAT="$USER_PAT" php_cli -r '
$c = require "config.php";
$c["airtable_pat"] = (string)(getenv("AQ_TMP_PAT") ?: "");
file_put_contents(
  "config.php",
  "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($c, true) . ";\n"
);
' || { fail_step "Не удалось записать config.php"; pause_exit 1; }
      fi
      printf "  ${OK}  PAT сохранён в dashboard/config.php\n\n"
    fi
  else
    printf "\n  ${D}PAT не задан: дашборд откроется без данных Airtable. Задайте AIRTABLE_PAT в окружении или отредактируйте dashboard/config.php${N}\n\n"
  fi
fi
unset AQ_TMP_PAT AQ_TMP_BASE 2>/dev/null || true

# ════════════════════════════════════════════════════════════════════════
# ШАГ 4 — Запуск сервера
# ════════════════════════════════════════════════════════════════════════
draw_bar "Запуск сервера..."

# Освободить порт
local_pids=$(lsof -ti:"$PORT" 2>/dev/null) || true
if [[ -n "$local_pids" ]]; then
  echo "$local_pids" | xargs kill 2>/dev/null || true
  sleep 0.4
fi

if [[ "$USE_DOCKER" == true ]]; then
  docker rm -f "aq_dashboard" 2>/dev/null || true

  printf "\r  ${WAIT}  Загружаем PHP-образ Docker (только первый раз)...\n"
  (docker pull php:8.2-cli 2>/dev/null) &
  spinner $! "Загрузка php:8.2-cli"

  docker run -d --rm --name "aq_dashboard" \
    -p "${PORT}:9876" \
    -e "AIRTABLE_PAT=${AIRTABLE_PAT:-}" \
    -e "AIRTABLE_BASE_ID=${AIRTABLE_BASE_ID:-}" \
    -v "${APP_DIR}:/var/www" \
    php:8.2-cli php -S "0.0.0.0:9876" -t /var/www > "$LOG_FILE" 2>&1

  sleep 1.5
  if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "aq_dashboard"; then
    ok_step "Сервер запущен (Docker) → ${URL}"
    touch "$MARKER_FILE"
  else
    fail_step "Docker контейнер не запустился"
    docker logs aq_dashboard 2>/dev/null | tail -5
    pause_exit 1
  fi

elif [[ "${USE_NODE:-0}" == "1" ]]; then
  env PORT="$PORT" HOST="127.0.0.1" AIRTABLE_PAT="${AIRTABLE_PAT:-}" AIRTABLE_BASE_ID="${AIRTABLE_BASE_ID:-}" \
    node "$APP_DIR/serve.mjs" > "$LOG_FILE" 2>&1 &
  SERVER_PID=$!
  sleep 1.2

  if kill -0 "$SERVER_PID" 2>/dev/null; then
    ok_step "Сервер запущен (Node.js) PID=${SERVER_PID}  →  ${URL}"
    touch "$MARKER_FILE"
  else
    fail_step "Node.js сервер не запустился"
    [[ -f "$LOG_FILE" ]] && tail -5 "$LOG_FILE"
    pause_exit 1
  fi

else
  # PHP видит AIRTABLE_* из bootstrap — передаём в дочерний процесс
  env AIRTABLE_PAT="${AIRTABLE_PAT:-}" AIRTABLE_BASE_ID="${AIRTABLE_BASE_ID:-}" \
    "$PHP_CMD" -S "127.0.0.1:${PORT}" -t "$APP_DIR" > "$LOG_FILE" 2>&1 &
  SERVER_PID=$!
  sleep 1.2

  if kill -0 "$SERVER_PID" 2>/dev/null; then
    ok_step "Сервер запущен (PHP) PID=${SERVER_PID}  →  ${URL}"
    touch "$MARKER_FILE"
  else
    fail_step "Сервер (PHP) не запустился"
    [[ -f "$LOG_FILE" ]] && tail -5 "$LOG_FILE"
    if _node_serve_ok; then
      printf "  ${INFO}  Пробуем запасной вариант: Node.js serve.mjs…\n"
      env PORT="$PORT" HOST="127.0.0.1" AIRTABLE_PAT="${AIRTABLE_PAT:-}" AIRTABLE_BASE_ID="${AIRTABLE_BASE_ID:-}" \
        node "$APP_DIR/serve.mjs" > "$LOG_FILE" 2>&1 &
      SERVER_PID=$!
      sleep 1.2
      if kill -0 "$SERVER_PID" 2>/dev/null; then
        USE_NODE=1
        ok_step "Сервер запущен (Node.js) PID=${SERVER_PID}  →  ${URL}"
        touch "$MARKER_FILE"
      else
        fail_step "Node.js сервер тоже не запустился"
        [[ -f "$LOG_FILE" ]] && tail -5 "$LOG_FILE"
        pause_exit 1
      fi
    else
      pause_exit 1
    fi
  fi
fi

# ════════════════════════════════════════════════════════════════════════
# Финал
# ════════════════════════════════════════════════════════════════════════
printf "\n"
printf "  ${G}══════════════════════════════════════════════════════════${N}\n"
printf "  ${G}  ✓  Всё готово! Открываем браузер...${N}\n"
printf "  ${G}══════════════════════════════════════════════════════════${N}\n"
printf "\n"
printf "  ${INFO}  Главная:        ${W}%s${N}\n" "$URL"
printf "  ${INFO}  Угроза Churn:   ${W}http://127.0.0.1:${PORT}/churn.php${N}\n"
printf "  ${INFO}  Потери выручки: ${W}http://127.0.0.1:${PORT}/churn_fact.php${N}\n"
printf "  ${INFO}  Дебиторка:      ${W}http://127.0.0.1:${PORT}/manager.php${N}\n"
if [[ "${USE_NODE:-0}" == "1" ]]; then
  printf "\n  ${D}Сейчас сервер Node.js: полный manager.php/weekly.php — при запуске с PHP; Churn/факт — из cache/*.json, если файлы уже есть.${N}\n"
fi
printf "\n  ${D}Остановить: Ctrl+C${N}\n\n"
printf "  ${D}── Лог ──────────────────────────────────────────────────${N}\n\n"

(sleep 1.5; open "$URL" 2>/dev/null || true) &

if [[ "$USE_DOCKER" == true ]]; then
  trap "
    printf '\n\n  ${Y}Останавливаем...${N}\n'
    docker rm -f aq_dashboard 2>/dev/null || true
    printf '  ${OK}  Готово.\n\n'; exit 0
  " INT TERM
  docker logs -f aq_dashboard 2>/dev/null &
  TAIL_PID=$!
  while docker ps --format '{{.Names}}' 2>/dev/null | grep -q "aq_dashboard"; do sleep 2; done
  kill "$TAIL_PID" 2>/dev/null || true
else
  trap "
    printf '\n\n  ${Y}Останавливаем...${N}\n'
    kill ${SERVER_PID} 2>/dev/null || true
    printf '  ${OK}  Готово.\n\n'; exit 0
  " INT TERM
  tail -f "$LOG_FILE" 2>/dev/null &
  TAIL_PID=$!
  wait "$SERVER_PID" 2>/dev/null || true
  kill "$TAIL_PID" 2>/dev/null || true
fi

printf "\n  ${R}Сервер завершился.${N}\n"
(( AQ_INTERACTIVE )) && read -r "?  Нажмите Enter для выхода..."
