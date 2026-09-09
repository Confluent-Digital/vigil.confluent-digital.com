#!/usr/bin/env bash
# Copie les assets de node_modules vers public/assets/dist.
# Zero CDN : un back-office interne ne doit pas dependre d'un tiers pour
# s'afficher, et doit fonctionner hors ligne.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$ROOT/public/assets/dist"
mkdir -p "$DIST/fonts"

cp "$ROOT/node_modules/bootstrap/dist/css/bootstrap.min.css"       "$DIST/"
cp "$ROOT/node_modules/bootstrap/dist/js/bootstrap.bundle.min.js"  "$DIST/"
cp "$ROOT/node_modules/bootstrap-icons/font/bootstrap-icons.css"   "$DIST/"
cp -r "$ROOT/node_modules/bootstrap-icons/font/fonts/."            "$DIST/fonts/"

echo "Assets copies dans public/assets/dist"
