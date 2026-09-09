#!/usr/bin/env bash
#
# Construit public/assets/dist/ — repertoire entierement GENERE, jamais edite
# a la main et jamais versionne.
#
# Deux sources :
#   node_modules/       les paquets tiers (Bootstrap, Bootstrap Icons)
#   public/assets/src/  nos propres feuilles et scripts, eux VERSIONNES
#
# La separation compte : `dist/` etant gitignore, un fichier ecrit directement
# dedans n'existe que sur la machine qui l'a produit. C'est arrive — le systeme
# de design entier a failli n'exister nulle part ailleurs.
#
# Zero CDN : un back-office interne ne doit pas dependre d'un tiers pour
# s'afficher, ni cesser de fonctionner hors ligne.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/public/assets/dist"
SRC="$ROOT/public/assets/src"

[ -d "$ROOT/node_modules/bootstrap" ] || {
    echo "node_modules absent — lance d'abord : npm install" >&2
    exit 1
}

rm -rf "$DIST"
mkdir -p "$DIST/fonts"

cp "$ROOT/node_modules/bootstrap/dist/css/bootstrap.min.css"       "$DIST/"
cp "$ROOT/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"  "$DIST/"
cp "$ROOT/node_modules/bootstrap-icons/font/bootstrap-icons.css"   "$DIST/"
cp -r "$ROOT/node_modules/bootstrap-icons/font/fonts/."            "$DIST/fonts/"

cp "$SRC"/*.css "$SRC"/*.js "$DIST/"

echo "Assets construits dans public/assets/dist/ ($(find "$DIST" -type f | wc -l) fichiers)"
