#!/usr/bin/env bash
# Notification + Stop hook — desktop ping when Claude needs your input or
# finishes, avec deux boutons d'action :
#   • « Voir »            → ramène la fenêtre du terminal au premier plan
#   • « Marquer comme lu » → ferme simplement le popup
# Best-effort : no-op silencieux sans session graphique (headless / SSH).
#
# Contrainte : `notify-send -A` implique `--wait` (il bloque jusqu'au clic).
# On lance donc la partie attente+réaction en arrière-plan détaché (setsid)
# pour que le hook rende la main immédiatement et ne bloque pas Claude.
# sudo apt-get install -y xdotool
set -uo pipefail

command -v notify-send >/dev/null 2>&1 || exit 0
[ -n "${DISPLAY:-}${WAYLAND_DISPLAY:-}" ] || exit 0

PAYLOAD="$(cat)"
INFO="$(printf '%s' "$PAYLOAD" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{try{const j=JSON.parse(s);process.stdout.write((j.hook_event_name||"")+"\x1f"+(j.message||""))}catch{process.stdout.write("")}})')"
EVENT="${INFO%%$'\x1f'*}"
MSG="${INFO#*$'\x1f'}"

PROJECT="$(basename "${CLAUDE_PROJECT_DIR:-$PWD}")"
SOUNDS="/usr/share/sounds/freedesktop/stereo"

# Résout la fenêtre du terminal qui héberge Claude : on remonte les PPID du
# hook jusqu'à l'émulateur de terminal (premier ancêtre qui possède une fenêtre
# X). Si ce terminal est focus, on garde sa fenêtre exacte ; sinon on prend une
# de ses fenêtres visibles — jamais la fenêtre active d'une autre appli.
resolve_term_win() {
  command -v xdotool >/dev/null 2>&1 || return 0
  local pid=$$ termpid="" wins active
  for _ in $(seq 1 15); do
    { [ -z "$pid" ] || [ "$pid" = "1" ]; } && break
    if [ -n "$(xdotool search --pid "$pid" 2>/dev/null)" ]; then termpid="$pid"; break; fi
    pid=$(ps -o ppid= -p "$pid" 2>/dev/null | tr -d ' ')
  done
  [ -z "$termpid" ] && return 0
  wins=$(xdotool search --pid "$termpid" 2>/dev/null)
  active=$(xdotool getactivewindow 2>/dev/null || true)
  if [ -n "$active" ] && grep -qx "$active" <<< "$wins"; then echo "$active"; return 0; fi
  xdotool search --pid "$termpid" --onlyvisible 2>/dev/null | head -1
}
WIN="$(resolve_term_win)"

case "$EVENT" in
  Notification)
    TITLE="Claude Code — action requise · $PROJECT"
    BODY="${MSG:-En attente de ton autorisation ou réponse}"
    URGENCY="critical"
    SOUND="$SOUNDS/message.oga"
    ;;
  Stop | *)
    TITLE="Claude Code — terminé · $PROJECT"
    BODY="${MSG:-La tâche est finie ✓}"
    URGENCY="normal"
    SOUND="$SOUNDS/complete.oga"
    ;;
esac

# Détaché : notify-send attend le clic, on réagit, le hook (lui) est déjà sorti.
export NS_TITLE="$TITLE" NS_BODY="$BODY" NS_URGENCY="$URGENCY" NS_WIN="$WIN"
setsid bash -c '
  ACT="$(notify-send -a "Claude Code" -u "$NS_URGENCY" \
        -A "voir=Voir" -A "lu=Marquer comme lu" \
        "$NS_TITLE" "$NS_BODY" 2>/dev/null)"
  if [ "$ACT" = "voir" ] && [ -n "$NS_WIN" ] && command -v xdotool >/dev/null 2>&1; then
    xdotool windowactivate "$NS_WIN" 2>/dev/null || true
  fi
' </dev/null >/dev/null 2>&1 &

[ -f "$SOUND" ] && command -v paplay >/dev/null 2>&1 && paplay "$SOUND" 2>/dev/null &
exit 0
