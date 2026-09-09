#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) — vide le cache Twig compilé dès qu'un
# template `.twig` est édité. Évite le piège récurrent « ma modif de template
# n'apparaît pas » (cf. CLAUDE.md / docker-commands.md). Non-bloquant.
set -uo pipefail
ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"

FILE="$(node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);process.stdout.write(j.tool_input?.file_path||"")}catch{process.stdout.write("")}})')"
case "$FILE" in
  *.twig) ;;
  *) exit 0 ;;
esac

[ -d "$ROOT/cache/twig" ] && rm -rf "$ROOT"/cache/twig/* 2>/dev/null || true
exit 0
