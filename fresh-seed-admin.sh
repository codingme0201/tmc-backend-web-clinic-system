#!/usr/bin/env bash

# ==============================================================================
# TMC CareLink - Fresh Migration & Admin-Only Seeder
# ==============================================================================
# This script resets the TMC CareLink database to a clean beginning state:
# 1. Drops all existing tables and re-runs all database migrations fresh.
# 2. Seeds essential system beginning data:
#    - Roles & Permissions catalog (Administrator, Doctor, Nurse, Patient)
#    - Clinic System Settings (Default configuration)
#    - Primary Administrator account:
#        Email:    admin@tmc.edu.ph
#        Password: admin123
#        Role:     Administrator
# ==============================================================================

set -e

# Determine script directory and locate backend folder
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -d "$SCRIPT_DIR/backend" ]; then
    BACKEND_DIR="$SCRIPT_DIR/backend"
elif [ -f "$SCRIPT_DIR/artisan" ]; then
    BACKEND_DIR="$SCRIPT_DIR"
else
    echo "[!] Error: Could not locate Laravel backend directory (artisan not found)."
    exit 1
fi

echo "======================================================="
echo "  TMC CareLink - Starting Fresh Database Reset"
echo "======================================================="
echo "Working directory: $BACKEND_DIR"
echo ""

cd "$BACKEND_DIR"

echo "[1/3] Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear

echo ""
echo "[2/3] Running migrate:fresh with beginning seeders (Roles, Settings, Admin)..."
php artisan migrate:fresh --seed --force

echo ""
echo "[3/3] Clearing optimize caches..."
php artisan optimize:clear

echo ""
echo "======================================================="
echo "  Database Fresh Migration Succeeded!"
echo "======================================================="
echo "  The database now has fresh beginning data:"
echo "  [x] All tables recreated fresh"
echo "  [x] Roles & Permissions catalog seeded"
echo "  [x] System Settings initialized"
echo "  [x] Administrator account created:"
echo "        Email:    admin@tmc.edu.ph"
echo "        Password: admin123"
echo "        Role:     Administrator"
echo "======================================================="
