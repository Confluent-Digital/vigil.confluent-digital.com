#!/usr/bin/env bash
# PostToolUse(Edit|Write|MultiEdit) — runs `php -l` on the edited PHP file.
# Blocking: on a syntax error it exits 2 so Claude sees the parse error and
# fixes it immediately (same guarantee as the pre-commit hook, but live).
# Self-filters: no-op unless the edited target is a .php file.
set -uo pipefail

FILE="$(node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);process.stdout.write(j.tool_input?.file_path||"")}catch{process.stdout.write("")}})')"
[ -z "$FILE" ] && exit 0

case "$FILE" in
  *.php) ;;
  *) exit 0 ;;
esac
[ -f "$FILE" ] || exit 0
command -v php >/dev/null 2>&1 || exit 0

OUT="$(php -l "$FILE" 2>&1)"
if [ $? -ne 0 ]; then
  {
    echo "[php -l] erreur de syntaxe — corrige avant de continuer :"
    echo "$OUT" | grep -iE "error|parse" | head -10
  } >&2
  exit 2
fi
exit 0
