#!/usr/bin/env bash
#
# Vigil — purge des caches.
#
#   ./bin/cache-clear.sh            Twig, APCu et OPcache
#   ./bin/cache-clear.sh --assets   idem, plus reconstruction de public/assets/dist
#   ./bin/cache-clear.sh --redis    idem, plus vidage de Redis (voir l'avertissement)
#   ./bin/cache-clear.sh --all      tout
#   ./bin/cache-clear.sh -h         cette aide
#
# ── Pourquoi un redemarrage plutot que des suppressions de fichiers ──────────
#
# Vigil tient trois caches dans le processus PHP-FPM lui-meme :
#
#   Twig     templates compiles, dans /tmp/vigil-twig A L'INTERIEUR du conteneur
#   APCu     resolution campagne x publisher du chemin chaud (TTL 60 s)
#   OPcache  bytecode PHP
#
# Aucun des trois ne se purge depuis l'hote : ils vivent dans la memoire ou le
# systeme de fichiers du conteneur. Et `apcu_clear_cache()` lance en CLI ne
# touche PAS la memoire partagee de PHP-FPM — c'est un autre processus, avec son
# propre segment. Un `docker exec ... php -r 'apcu_clear_cache();'` donnerait
# l'illusion d'avoir purge sans rien purger.
#
# Redemarrer le conteneur PHP vide les trois d'un coup, de facon certaine. Cela
# coute une a deux secondes d'indisponibilite : sur le chemin chaud, nginx
# renvoie une erreur le temps que PHP-FPM reponde a nouveau. A eviter en pleine
# pointe de trafic.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FAIRE_ASSETS=0
FAIRE_REDIS=0

if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_GREEN=""; C_YELLOW=""; C_RED=""
fi
ok()   { printf '  %s✓%s %s\n' "$C_GREEN" "$C_RESET" "$1"; }
skip() { printf '  %s·%s %s\n' "$C_DIM" "$C_RESET" "$1"; }
warn() { printf '  %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
die()  { printf '\n%s✗ %s%s\n\n' "$C_RED" "$1" "$C_RESET" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --assets)  FAIRE_ASSETS=1 ;;
        --redis)   FAIRE_REDIS=1 ;;
        --all)     FAIRE_ASSETS=1; FAIRE_REDIS=1 ;;
        -h|--help) sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) die "Option inconnue : $1  (./bin/cache-clear.sh -h)" ;;
    esac
    shift
done

# Compose v2 (plugin) ou v1 (binaire) : le paquet docker.io d'Ubuntu ne fournit
# pas le plugin, et beaucoup de serveurs n'ont que v1.
if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose"
else
    die "Ni 'docker compose' ni 'docker-compose' n'est installe."
fi

docker inspect vigil_php >/dev/null 2>&1 || die "Le conteneur vigil_php n'existe pas. Lance d'abord ./init.sh"

printf '%sPurge des caches Vigil%s\n\n' "$C_BOLD" "$C_RESET"

# ── Assets ──────────────────────────────────────────────────────────────────
# Avant le redemarrage : la reconstruction ne depend pas de PHP-FPM, et si elle
# echoue on prefere le savoir sans avoir coupe le service.
if [ "$FAIRE_ASSETS" -eq 1 ]; then
    if [ -d "$ROOT/node_modules/bootstrap" ]; then
        bash "$ROOT/scripts/copy-assets.sh" >/dev/null \
            && ok "assets reconstruits ($(find "$ROOT/public/assets/dist" -type f | wc -l) fichiers)" \
            || die "construction des assets echouee."
    else
        warn "node_modules absent — assets non reconstruits (lance ./init.sh)"
    fi
fi

# ── Redis ───────────────────────────────────────────────────────────────────
if [ "$FAIRE_REDIS" -eq 1 ]; then
    # Redis ne porte pas que du cache : il tient les compteurs d'unicite des
    # clics (cle `u:<campagne>:<empreinte>`, TTL 24 h). Les vider fait
    # recompter comme UNIQUES des visiteurs deja venus — les statistiques de
    # clics uniques s'en trouvent gonflees sur les 24 heures suivantes.
    warn "Redis porte les compteurs d'unicite des clics (24 h) :"
    warn "  les vider gonflera les « clics uniques » jusqu'a demain."
    printf '  Continuer ? [o/N] '
    read -r reponse
    if [ "${reponse,,}" = "o" ]; then
        docker exec vigil_redis redis-cli FLUSHDB >/dev/null \
            && ok "Redis vide" || warn "vidage de Redis impossible"
    else
        skip "Redis conserve"
    fi
fi

# ── Twig, APCu, OPcache ─────────────────────────────────────────────────────
AVANT="$(docker exec vigil_php sh -c 'find /tmp/vigil-twig -type f 2>/dev/null | wc -l' || echo 0)"

$DC restart vigil_php >/dev/null 2>&1 || die "redemarrage de vigil_php impossible."

# On attend que PHP-FPM reponde avant de rendre la main : annoncer « purge
# terminee » alors que le service est encore en train de remonter ferait
# conclure a une panne au premier essai.
PORT="$(grep -E '^DOCKER_NGINX_PORT=' "$ROOT/.env" 2>/dev/null | cut -d= -f2)"
PORT="${PORT:-388}"

for _ in $(seq 1 20); do
    CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "http://127.0.0.1:$PORT/health" || echo 000)"
    [ "$CODE" = "200" ] && break
    sleep 0.5
done

if [ "${CODE:-000}" = "200" ]; then
    ok "Twig, APCu et OPcache purges ($AVANT template(s) compile(s) supprime(s))"
    ok "PHP-FPM repond a nouveau"
else
    warn "PHP-FPM ne repond pas encore — verifie : docker logs vigil_php"
fi

printf '\n'
