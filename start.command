#!/usr/bin/env zsh
# Короткий запуск: то же, что LAUNCH.command (двойной клик или ./start.command)
cd "$(dirname "$0")" && exec ./LAUNCH.command "$@"
