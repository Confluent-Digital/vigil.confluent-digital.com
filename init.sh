#!/usr/bin/env bash
#
# Vigil — initialisation complete du projet.
#
#   ./init.sh                 installation / remise en route complete
#   ./init.sh --no-nginx      saute l'installation du vhost hote (pas de sudo)
#   ./init.sh --force-nginx   ecrase le vhost hote existant (sauvegarde d'abord)
#   ./init.sh --no-build      ne reconstruit pas l'image PHP
#   ./init.sh --down          arrete la stack (les donnees sont conservees)
#   ./init.sh -h              cette aide
#
# Idempotent : relancable sans rien casser. Un .env existant n'est jamais
# ecrase, un vhost existant n'est remplace qu'avec --force-nginx.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

DOMAIN="vigil.confluent-digital.com"
NGINX_DIR="/data/nginx"
NGINX_VHOST="$NGINX_DIR/$DOMAIN.conf"
VHOST_TEMPLATE="$ROOT/.docker/nginx/host/vhost.conf.template"

DO_NGINX=1
FORCE_NGINX=0
DO_BUILD=1
DO_DOWN=0

# ── Sortie ───────────────────────────────────────────────────────────────────
if [ -t 1 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'; C_RED=$'\033[31m'; C_BLUE=$'\033[34m'
else
    C_RESET=""; C_BOLD=""; C_DIM=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_BLUE=""
fi

STEP=0
step() { STEP=$((STEP + 1)); printf '\n%s[%d/%d]%s %s%s%s\n' "$C_BLUE" "$STEP" "$TOTAL_STEPS" "$C_RESET" "$C_BOLD" "$1" "$C_RESET"; }
ok()   { printf '      %s✓%s %s\n' "$C_GREEN" "$C_RESET" "$1"; }
skip() { printf '      %s·%s %s\n' "$C_DIM" "$C_RESET" "$1"; }
warn() { printf '      %s!%s %s\n' "$C_YELLOW" "$C_RESET" "$1"; }
die()  { printf '\n%s✗ %s%s\n\n' "$C_RED" "$1" "$C_RESET" >&2; exit 1; }

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0; }

while [ $# -gt 0 ]; do
    case "$1" in
        --no-nginx)    DO_NGINX=0 ;;
        --force-nginx) FORCE_NGINX=1 ;;
        --no-build)    DO_BUILD=0 ;;
        --down)        DO_DOWN=1 ;;
        -h|--help)     usage ;;
        *) die "Option inconnue : $1  (./init.sh -h)" ;;
    esac
    shift
done

TOTAL_STEPS=8
[ "$DO_NGINX" -eq 1 ] || TOTAL_STEPS=7

# ── --down ───────────────────────────────────────────────────────────────────
if [ "$DO_DOWN" -eq 1 ]; then
    printf '%sArret de la stack Vigil%s\n' "$C_BOLD" "$C_RESET"
    # Volontairement sans -v : les donnees MariaDB vivent dans .docker-data/,
    # mais un `down -v` supprimerait tout volume anonyme au passage.
    $DC down
    printf '\n%s✓%s Conteneurs arretes. Les donnees de .docker-data/ sont conservees.\n\n' "$C_GREEN" "$C_RESET"
    exit 0
fi

printf '%s╭─────────────────────────────────────────╮%s\n' "$C_BOLD" "$C_RESET"
printf '%s│  Vigil — initialisation du projet       │%s\n' "$C_BOLD" "$C_RESET"
printf '%s╰─────────────────────────────────────────╯%s\n' "$C_BOLD" "$C_RESET"

# ─────────────────────────────────────────────────────────────────────────────
step "Prerequis"

command -v docker >/dev/null 2>&1 || die "docker introuvable."
docker info >/dev/null 2>&1 || die "Le daemon Docker ne repond pas (droits sur /var/run/docker.sock ?)."

# Compose v2 (plugin `docker compose`) ou v1 (binaire `docker-compose`) : le
# paquet `docker.io` d'Ubuntu ne fournit PAS le plugin, et beaucoup de serveurs
# n'ont donc que le binaire v1. Les deux comprennent ce docker-compose.yml.
if docker compose version >/dev/null 2>&1; then
    DC="docker compose"
    DC_VERSION="$(docker compose version --short 2>/dev/null || echo '?')"
    DC_KIND="plugin v2"
elif command -v docker-compose >/dev/null 2>&1; then
    DC="docker-compose"
    DC_VERSION="$(docker-compose version --short 2>/dev/null || echo '?')"
    DC_KIND="binaire v1"
else
    die "Ni 'docker compose' (plugin v2) ni 'docker-compose' (binaire v1) n'est installe.
  Sur Ubuntu, le paquet docker.io ne fournit pas le plugin. Installez-le avec :
      sudo apt-get install -y docker-compose-v2
  ou, a defaut :
      sudo apt-get install -y docker-compose"
fi

ok "docker $(docker version --format '{{.Server.Version}}' 2>/dev/null || echo '?') et compose $DC_VERSION ($DC_KIND)"

# Compose v1 n'est plus maintenu depuis juillet 2023 : il fonctionne ici, mais
# on le signale plutot que de laisser decouvrir un ecart de comportement.
if [ "$DC" = "docker-compose" ]; then
    warn "compose v1 n'est plus maintenu — 'sudo apt-get install docker-compose-v2' quand ce sera possible"

command -v openssl >/dev/null 2>&1 || die "openssl introuvable (necessaire pour generer les secrets)."
fi

ok "openssl"

[ -f "$ROOT/docker-compose.yml" ] || die "docker-compose.yml absent — mauvais repertoire ?"
[ -f "$ROOT/.env.example" ]       || die ".env.example absent."

# ─────────────────────────────────────────────────────────────────────────────
step "Fichier .env"

if [ -f "$ROOT/.env" ]; then
    skip ".env existe deja — laisse intact (les secrets ne sont pas regeneres)"
    # Un .env plus vieux que .env.example a peut-etre perdu des cles.
    MISSING="$(grep -oE '^[A-Z_][A-Z0-9_]*=' "$ROOT/.env.example" | tr -d '=' | while read -r k; do
        grep -qE "^${k}=" "$ROOT/.env" || echo "$k"
    done)"
    if [ -n "$MISSING" ]; then
        warn "cles presentes dans .env.example mais absentes de .env :"
        printf '        %s\n' $MISSING
        warn "ajoute-les a la main avant de continuer si elles sont utilisees"
    fi
else
    sed -e "s/^DB_PASSWORD=.*/DB_PASSWORD=$(openssl rand -hex 12)/" \
        -e "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=$(openssl rand -hex 12)/" \
        -e "s/^POSTBACK_SECRET=.*/POSTBACK_SECRET=$(openssl rand -hex 32)/" \
        -e "s/^APP_SECRET=.*/APP_SECRET=$(openssl rand -hex 32)/" \
        -e "s/^UID=.*/UID=$(id -u)/" \
        -e "s/^GID=.*/GID=$(id -g)/" \
        -e "s|^APP_URL=.*|APP_URL=http://dev.$DOMAIN|" \
        "$ROOT/.env.example" > "$ROOT/.env"
    chmod 600 "$ROOT/.env"
    ok ".env cree, secrets generes, APP_URL sur http://dev.$DOMAIN, permissions 600"
fi

# Charge le .env. UID, GID et consorts sont ecartes : bash les tient en
# lecture seule, et un `.` sur un fichier qui les assigne echoue — ce qui, avec
# `set -e`, tuerait le script sans message comprehensible. Docker Compose lit le
# .env de son cote, il y trouvera bien UID et GID.
ENV_SAFE="$(mktemp)"
grep -vE '^[[:space:]]*(UID|GID|EUID|PPID|SHELLOPTS|BASHOPTS|BASH_.*)=' "$ROOT/.env" > "$ENV_SAFE"
set -a
# shellcheck disable=SC1090
. "$ENV_SAFE"
set +a
rm -f "$ENV_SAFE"

DOCKER_PHP_PORT="${DOCKER_PHP_PORT:-988}"
DOCKER_REDIS_PORT="${DOCKER_REDIS_PORT:-6391}"

: "${DOCKER_NGINX_PORT:?DOCKER_NGINX_PORT absent du .env}"
: "${DOCKER_MYSQL_PORT:?DOCKER_MYSQL_PORT absent du .env}"
: "${DB_NAME:?DB_NAME absent du .env}"
: "${DB_USERNAME:?DB_USERNAME absent du .env}"
: "${DB_PASSWORD:?DB_PASSWORD absent du .env}"

# ─────────────────────────────────────────────────────────────────────────────
step "Repertoires et ports"

mkdir -p "$ROOT"/{cache/twig,logs/tasks,.docker-data/mariadb}
touch "$ROOT/cache/.gitkeep" "$ROOT/logs/.gitkeep"
ok "cache/, logs/, .docker-data/"

# Un port deja pris par un AUTRE projet fait echouer `up` avec un message
# obscur ; on le dit tout de suite. Un port tenu par nos propres conteneurs
# n'est pas un conflit.
# `docker compose ps --format` n'existe pas en v1 : on lit la sortie brute, qui
# contient de toute facon « 0.0.0.0:388->80/tcp ».
compose_ports() {
    if [ "$DC" = "docker compose" ]; then
        docker compose ps --format '{{.Ports}}'
    else
        docker-compose ps
    fi
}

port_conflict() {
    local port="$1" name="$2"
    command -v ss >/dev/null 2>&1 || return 0
    ss -Hltn "sport = :$port" 2>/dev/null | grep -q . || return 0
    compose_ports 2>/dev/null | grep -q ":$port->" && return 0
    warn "port $port ($name) deja occupe par un autre processus"
    return 1
}
CONFLICT=0
port_conflict "$DOCKER_NGINX_PORT" "nginx"    || CONFLICT=1
port_conflict "$DOCKER_MYSQL_PORT" "mariadb"  || CONFLICT=1
port_conflict "$DOCKER_PHP_PORT"   "php-fpm" || CONFLICT=1
port_conflict "$DOCKER_REDIS_PORT" "redis"  || CONFLICT=1
[ "$CONFLICT" -eq 0 ] && ok "ports $DOCKER_NGINX_PORT / $DOCKER_PHP_PORT / $DOCKER_MYSQL_PORT / $DOCKER_REDIS_PORT libres" \
                      || die "Libere les ports, ou change-les dans .env, puis relance."

# ─────────────────────────────────────────────────────────────────────────────
step "Image PHP"

BASE_IMAGE="docker-registry.confluent-digital.com/php:slim-8.3-fpm"

if [ "$DO_BUILD" -eq 1 ]; then
    # `push-images.sh` du depot registry supprime l'image locale apres le push.
    # Si personne ne s'est authentifie sur le registry avec CET utilisateur, le
    # build echoue sur un « no basic auth credentials » qui ne dit pas quoi
    # faire. On le detecte avant, et on donne la commande.
    if ! docker image inspect "$BASE_IMAGE" >/dev/null 2>&1; then
        if ! docker pull "$BASE_IMAGE" >/dev/null 2>&1; then
            printf '\n%s✗ Image de base introuvable : %s%s\n' "$C_RED" "$BASE_IMAGE" "$C_RESET" >&2
            cat >&2 <<MSG

  Elle n'est ni en local ni recuperable avec cet utilisateur. Le registry est
  authentifie, et \`push-images.sh\` supprime l'image locale apres chaque push.

  Connecte-toi une fois, puis relance :

      docker login docker-registry.confluent-digital.com
      ./init.sh

  (root est deja authentifie sur cette machine ; c'est ton compte qui ne l'est
  pas. Un \`sudo docker pull\` depannerait, mais l'image atterrirait dans le
  meme daemon — autant faire le login proprement.)

MSG
            exit 1
        fi
        ok "image de base recuperee depuis le registry"
    fi

    # Surcouche transitoire : apcu et redis tant que l'image du parc ne les
    # porte pas. Une fois `slim-8.3-fpm` republiee, le build ne fait plus rien.
    $DC build vigil_php >/dev/null 2>&1 \
        || { $DC build vigil_php; die "Echec du build de l'image PHP."; }
    ok "vigil/php:8.3-fpm construite"
else
    skip "build saute (--no-build)"
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Conteneurs"

$DC up -d >/dev/null 2>&1 || { $DC up -d; die "Echec du demarrage."; }
ok "vigil_database, vigil_redis, vigil_php, vigil_nginx demarres"

printf '      %s·%s attente de MariaDB' "$C_DIM" "$C_RESET"
READY=0
for _ in $(seq 1 60); do
    if docker exec vigil_database mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" \
         -e "SELECT 1" "$DB_NAME" >/dev/null 2>&1; then
        READY=1; break
    fi
    printf '.'; sleep 1
done
printf '\n'
[ "$READY" -eq 1 ] || die "MariaDB n'a pas repondu en 60 s. Voir : docker logs vigil_database"
ok "MariaDB $(docker exec vigil_database mariadb -N -B -u"$DB_USERNAME" -p"$DB_PASSWORD" -e 'SELECT VERSION()' 2>/dev/null) prete"

# ─────────────────────────────────────────────────────────────────────────────
step "Dependances et schema"

if [ -f "$ROOT/composer.json" ]; then
    docker exec vigil_php composer install --no-interaction --prefer-dist >/dev/null 2>&1 \
        || { docker exec vigil_php composer install --no-interaction; die "composer install a echoue."; }
    ok "composer install"
else
    skip "pas de composer.json — dependances non installees"
fi

if [ -f "$ROOT/phinx.php" ] && [ -n "$(ls -A "$ROOT/database/migrations" 2>/dev/null)" ]; then
    docker exec vigil_php vendor/bin/phinx migrate >/dev/null 2>&1 \
        || { docker exec vigil_php vendor/bin/phinx migrate; die "Les migrations ont echoue."; }
    ok "migrations Phinx appliquees"

    # Une partition manquante sur t_click arrete l'ingestion en silence : on la
    # verifie ici plutot que de la decouvrir au premier clic du mois.
    if docker exec vigil_database mariadb -N -B -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_NAME" \
         -e "SHOW TABLES LIKE 't_click'" 2>/dev/null | grep -q t_click; then
        NEXT_MONTH="p$(date -u -d '+1 month' +%Y%m)"
        if docker exec vigil_database mariadb -N -B -u"$DB_USERNAME" -p"$DB_PASSWORD" \
             -e "SELECT partition_name FROM information_schema.partitions
                 WHERE table_schema='$DB_NAME' AND table_name='t_click'" 2>/dev/null \
             | grep -q "$NEXT_MONTH"; then
            ok "partition $NEXT_MONTH de t_click presente"
        else
            warn "partition $NEXT_MONTH absente de t_click — lance PartitionMaintenanceTask"
        fi
    fi
else
    skip "pas de migrations a jouer"
fi

# ─────────────────────────────────────────────────────────────────────────────
if [ "$DO_NGINX" -eq 1 ]; then
step "Vhost nginx de l'hote"

    [ -f "$VHOST_TEMPLATE" ] || die "Template absent : $VHOST_TEMPLATE"
    [ -d "$NGINX_DIR" ]      || die "$NGINX_DIR n'existe pas — l'hote n'a pas la structure attendue."

    # Sur cette machine $NGINX_DIR appartient a l'utilisateur : l'ecriture ne
    # demande rien. Seuls `nginx -t` et le reload ont besoin de root, et on ne
    # reclame sudo que pour eux.
    SUDO_FS=""
    if [ ! -w "$NGINX_DIR" ]; then
        command -v sudo >/dev/null 2>&1 || die "$NGINX_DIR non accessible en ecriture et sudo introuvable. Utilise --no-nginx."
        SUDO_FS="sudo"
        warn "sudo requis pour ecrire dans $NGINX_DIR"
    fi

    SUDO=""
    if [ "$(id -u)" -ne 0 ]; then
        command -v sudo >/dev/null 2>&1 || die "sudo introuvable pour recharger nginx. Utilise --no-nginx."
        SUDO="sudo"
        if ! sudo -n true 2>/dev/null; then
            warn "sudo va demander ton mot de passe (test de conf + reload nginx)"
        fi
    fi

    RENDERED="$(mktemp)"
    trap 'rm -f "$RENDERED"' EXIT
    sed -e "s/__DOMAIN__/$DOMAIN/g" -e "s/__PORT__/$DOCKER_NGINX_PORT/g" \
        "$VHOST_TEMPLATE" > "$RENDERED"

    INSTALL=1
    if [ -f "$NGINX_VHOST" ]; then
        if cmp -s "$RENDERED" "$NGINX_VHOST"; then
            skip "vhost deja a jour"
            INSTALL=0
        elif [ "$FORCE_NGINX" -eq 1 ]; then
            BACKUP="$NGINX_VHOST.bak.$(date +%Y%m%d%H%M%S)"
            $SUDO_FS cp -a "$NGINX_VHOST" "$BACKUP"
            warn "vhost existant sauvegarde dans $BACKUP"
        else
            warn "$NGINX_VHOST existe et differe du template."
            warn "Relance avec --force-nginx pour l'ecraser (une sauvegarde sera faite)."
            INSTALL=0
        fi
    fi

    if [ "$INSTALL" -eq 1 ]; then
        $SUDO_FS install -m 644 "$RENDERED" "$NGINX_VHOST"
        ok "vhost installe : $NGINX_VHOST -> 127.0.0.1:$DOCKER_NGINX_PORT"

        # nginx -t avant reload : une conf invalide couperait TOUS les sites de
        # la machine, pas seulement Vigil.
        if $SUDO nginx -t >/dev/null 2>&1; then
            ok "nginx -t : configuration valide"
            if command -v systemctl >/dev/null 2>&1; then
                $SUDO systemctl reload nginx && ok "nginx recharge"
            else
                $SUDO nginx -s reload && ok "nginx recharge"
            fi
        else
            $SUDO_FS rm -f "$NGINX_VHOST"
            $SUDO nginx -t || true
            die "nginx -t a echoue : le vhost a ete retire, nginx n'a PAS ete recharge."
        fi
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
step "Verifications"

check() {
    local label="$1"; shift
    if "$@" >/dev/null 2>&1; then ok "$label"; else warn "$label — KO"; return 1; fi
}

FAILED=0
check "PHP $(docker exec vigil_php php -r 'echo PHP_VERSION;' 2>/dev/null)" \
      docker exec vigil_php php -v || FAILED=1

for ext in pdo_mysql apcu redis curl; do
    docker exec vigil_php php -m 2>/dev/null | grep -qix "$ext" \
        && ok "extension $ext" || { warn "extension $ext absente"; FAILED=1; }
done

TZ_PHP="$(docker exec vigil_php php -r 'echo date_default_timezone_get();' 2>/dev/null || echo '?')"
[ "$TZ_PHP" = "UTC" ] && ok "timezone PHP = UTC" \
    || { warn "timezone PHP = $TZ_PHP (attendu UTC — tout Vigil est en UTC en base)"; FAILED=1; }

check "Redis" docker exec vigil_redis redis-cli ping || FAILED=1

check "MariaDB accessible depuis l'hote (:$DOCKER_MYSQL_PORT, ce que fera SQLyog)" \
      docker exec vigil_database mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_NAME" || FAILED=1

AUTH="$(docker exec vigil_database mariadb -N -B -u root -p"${DB_ROOT_PASSWORD:-}" \
        -e "SELECT plugin FROM mysql.user WHERE user='$DB_USERNAME' AND host='%'" 2>/dev/null || true)"
[ "$AUTH" = "mysql_native_password" ] \
    && ok "auth MariaDB = mysql_native_password (compatible SQLyog)" \
    || warn "auth MariaDB = ${AUTH:-inconnue} — SQLyog ne saura pas se connecter"

HTTP="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "http://127.0.0.1:$DOCKER_NGINX_PORT/" || echo 000)"
case "$HTTP" in
    000) warn "nginx conteneur : pas de reponse"; FAILED=1 ;;
    404) ok  "nginx conteneur repond (404 — normal tant que public/index.php n'existe pas)" ;;
    5*)  warn "nginx conteneur : HTTP $HTTP — voir docker logs vigil_php"; FAILED=1 ;;
    *)   ok  "nginx conteneur repond (HTTP $HTTP)" ;;
esac

# Le lien de tracking affiche dans le back-office est prefixe par APP_URL, pas
# par l'adresse consultee : une valeur pointant vers un domaine hors ligne
# produit des liens sur lesquels personne ne peut cliquer, sans erreur visible.
APP_URL_VALUE="$(grep -E '^APP_URL=' "$ROOT/.env" | cut -d= -f2-)"
if [ -n "$APP_URL_VALUE" ]; then
    APP_URL_CODE="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$APP_URL_VALUE/health" || echo 000)"
    if [ "$APP_URL_CODE" = "200" ]; then
        ok "APP_URL joignable ($APP_URL_VALUE) — les liens de tracking seront cliquables"
    else
        warn "APP_URL ne repond pas : $APP_URL_VALUE"
        warn "  les liens de tracking affiches dans le back-office pointeront dans le vide."
        warn "  en developpement : APP_URL=http://dev.$DOMAIN"
        FAILED=1
    fi
fi

if [ "$DO_NGINX" -eq 1 ] && [ -f "$NGINX_VHOST" ]; then
    HTTP_HOST="$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
                 -H "Host: $DOMAIN" "http://127.0.0.1/" || echo 000)"
    [ "$HTTP_HOST" != "000" ] \
        && ok "nginx hote route $DOMAIN (HTTP $HTTP_HOST)" \
        || warn "nginx hote ne repond pas pour $DOMAIN"
fi

# ─────────────────────────────────────────────────────────────────────────────
printf '\n%s╭─────────────────────────────────────────╮%s\n' "$C_BOLD" "$C_RESET"
if [ "$FAILED" -eq 0 ]; then
    printf '%s│  Vigil est pret                         │%s\n' "$C_GREEN$C_BOLD" "$C_RESET"
else
    printf '%s│  Vigil demarre, avec des avertissements │%s\n' "$C_YELLOW$C_BOLD" "$C_RESET"
fi
printf '%s╰─────────────────────────────────────────╯%s\n\n' "$C_BOLD" "$C_RESET"

cat <<INFO
  Back-office      ${APP_URL_VALUE:-http://dev.$DOMAIN}/
                   http://127.0.0.1:$DOCKER_NGINX_PORT/            (direct conteneur)
  Bac a sable      ${APP_URL_VALUE:-http://dev.$DOMAIN}/sandbox    (hors production)
  Clic             ${APP_URL_VALUE:-http://dev.$DOMAIN}/c/<token>
  Postback         ${APP_URL_VALUE:-http://dev.$DOMAIN}/pb?clickid=<ulid>&txid=<id>

  SQLyog           tunnel SSH vers cette machine, puis
                   host 127.0.0.1  port $DOCKER_MYSQL_PORT  user $DB_USERNAME  base $DB_NAME
                   (le port n'ecoute que sur 127.0.0.1 — jamais l'ouvrir sur 0.0.0.0)

  Logs             docker logs -f vigil_php
                   /var/log/nginx/${DOMAIN}_click.log
  Base             docker exec -it vigil_database mariadb -u$DB_USERNAME -p $DB_NAME
  Arret            ./init.sh --down

INFO
