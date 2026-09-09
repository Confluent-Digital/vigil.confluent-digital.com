#!/usr/bin/env bash
# Stop hook — `php -l` over every PHP file changed on this branch before Claude
# finishes. On a parse error it exits 2 (blocking) so Claude keeps working and
# fixes it. Guarded against Stop-hook loops via stop_hook_active: once it has
# blocked, the next Stop is allowed through so a genuinely-stuck run can end.
set -uo pipefail
ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"

ACTIVE="$(node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);process.stdout.write(String(j.stop_hook_active||false))}catch{process.stdout.write("false")}})')"
[ "$ACTIVE" = "true" ] && exit 0
command -v php >/dev/null 2>&1 || exit 0

cd "$ROOT" || exit 0

# Modified + staged + untracked PHP files vs the merge base with main.
BASE="$(git merge-base HEAD main 2>/dev/null || echo HEAD)"
FILES="$( { git diff --name-only "$BASE" 2>/dev/null; git diff --name-only --cached 2>/dev/null; git ls-files --others --exclude-standard 2>/dev/null; } | grep '\.php$' | sort -u )"
[ -z "$FILES" ] && exit 0

FAILED=""
while IFS= read -r f; do
  [ -f "$f" ] || continue
  if ! OUT="$(php -l "$f" 2>&1)"; then
    FAILED+="$(echo "$OUT" | grep -iE 'error|parse' | head -3)"$'\n'
  fi
done <<< "$FILES"

if [ -n "$FAILED" ]; then
  {
    echo "[php -l] des fichiers PHP modifiés ont une erreur de syntaxe :"
    echo "$FAILED"
  } >&2
  exit 2
fi
exit 0
