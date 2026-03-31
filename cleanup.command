#!/usr/bin/env bash
# cleanup.command — убирает лишние файлы (запускать один раз)
cd "$(dirname "$0")"

echo ""
echo "Убираем лишние файлы в папку _old/ ..."
mkdir -p _old

# Старые лаунчеры
for f in "ЗАПУСК-РУКОВОДИТЕЛЬ.bat" "ЗАПУСК-РУКОВОДИТЕЛЬ.command" \
         "Запуск дашборда.command" "запуск руководитель.bat" \
         "запуск руководитель.command"; do
  [[ -f "$f" ]] && mv "$f" _old/ && echo "  переместили: $f"
done

# Старые версии дашборда
for f in "дашборд.mjs" "дашборд.py" "dashboard_run.mjs" "build_dz_report.py"; do
  [[ -f "$f" ]] && mv "$f" _old/ && echo "  переместили: $f"
done

# Отчёты / доки
for f in "отчет-дз.html" "возможные-отчёты.md" ".env.example"; do
  [[ -f "$f" ]] && mv "$f" _old/ && echo "  переместили: $f"
done

# Playwright
for d in node_modules test-results tests; do
  [[ -d "$d" ]] && mv "$d" _old/ && echo "  переместили папку: $d"
done
for f in package.json package-lock.json playwright.config.ts .gitignore .DS_Store; do
  [[ -f "$f" ]] && mv "$f" _old/ && echo "  переместили: $f"
done

echo ""
echo "Готово! Можешь удалить папку _old/ если всё работает."
echo ""
read -r -p "Нажми Enter..."
