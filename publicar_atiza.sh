#!/bin/bash
set -e

DIR="/var/www/html/atiza-dev-panel"
cd "$DIR"

echo "=========================================="
echo " Actualizando texto a ATIZA BARCELONA EVENTS"
echo "=========================================="

# 1. Cambiar texto en index.php si existe la versión antigua
if grep -q "ATIZA CULTO AMB" index.php 2>/dev/null; then
    sed -i 's/ATIZA CULTO AMB/ATIZA BARCELONA EVENTS/g' index.php
    echo "[OK] Texto modificado correctamente en index.php"
else
    echo "[!] No se encontró 'ATIZA CULTO AMB' en index.php (puede que ya estuviera cambiado)."
fi

# 2. Configuración previa de credenciales Git (guarda sesión)
git config --global credential.helper store

# 3. Subida a GitHub
echo "=========================================="
echo " Subiendo cambios a GitHub..."
echo "=========================================="

git add .
git commit -m "Actualizacion automatica: ATIZA BARCELONA EVENTS" || echo "[!] No habia cambios pendientes para commit."
git push origin main

echo "=========================================="
echo " ¡HECHO! Render desplegará la web en breve."
echo "=========================================="
