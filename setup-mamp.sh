#!/bin/bash
# ================================================================
# BHIMAS — MAMP Symlink Setup
#
# Run this ONCE from Terminal to link MAMP's htdocs directly to
# this project folder. After running it, every edit you make here
# is instantly live in MAMP — no copying needed.
#
# Usage:
#   1. Open Terminal
#   2. Drag this file into the Terminal window (fills in the path)
#   3. Press Return
# ================================================================

PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
MAMP_HTDOCS="/Applications/MAMP/htdocs"
LINK_TARGET="${MAMP_HTDOCS}/bhimas"

echo ""
echo "================================================"
echo " Bhimas Kitchen — MAMP Symlink Setup"
echo "================================================"
echo ""
echo "Project folder : $PROJECT_DIR"
echo "MAMP htdocs    : $MAMP_HTDOCS"
echo "Symlink target : $LINK_TARGET"
echo ""

# ── 1. Check MAMP is installed ───────────────────────────────
if [ ! -d "$MAMP_HTDOCS" ]; then
    echo "ERROR: MAMP htdocs not found at $MAMP_HTDOCS"
    echo "       Is MAMP installed? If it's somewhere else, edit"
    echo "       MAMP_HTDOCS in this script and re-run."
    exit 1
fi

# ── 2. Remove existing folder / symlink if present ───────────
if [ -L "$LINK_TARGET" ]; then
    echo "Removing existing symlink at $LINK_TARGET ..."
    rm "$LINK_TARGET"
elif [ -d "$LINK_TARGET" ]; then
    echo "WARNING: Found a real folder at $LINK_TARGET"
    echo "         It will be renamed to bhimas_backup_$(date +%Y%m%d%H%M%S)"
    mv "$LINK_TARGET" "${LINK_TARGET}_backup_$(date +%Y%m%d%H%M%S)"
fi

# ── 3. Create the symlink ─────────────────────────────────────
ln -s "$PROJECT_DIR" "$LINK_TARGET"
if [ $? -eq 0 ]; then
    echo ""
    echo "✓ Symlink created successfully!"
    echo ""
    echo "  MAMP now serves this project directly from:"
    echo "  $PROJECT_DIR"
    echo ""
    echo "  Any change you save here is immediately live."
    echo ""
    echo "  Site URL: http://localhost:8888/bhimas/"
    echo "  Admin  : http://localhost:8888/bhimas/admin/login.php"
    echo "  Test   : http://localhost:8888/bhimas/php/test.php"
    echo ""
else
    echo ""
    echo "ERROR: Could not create symlink. You may need to run with sudo:"
    echo "  sudo bash \"$0\""
    exit 1
fi

# ── 4. Remind about the database ─────────────────────────────
echo "================================================"
echo " NEXT: Set up the database (if not done yet)"
echo "================================================"
echo ""
echo "  1. Open MAMP and start Apache + MySQL (both green)"
echo "  2. Go to: http://localhost:8888/phpMyAdmin/"
echo "  3. Click 'New' → Database name: bhimas_db"
echo "     Collation: utf8mb4_unicode_ci → Create"
echo "  4. Click bhimas_db → Import tab"
echo "     Choose file: $PROJECT_DIR/database.sql → Go"
echo ""
echo "  Then visit http://localhost:8888/bhimas/php/test.php"
echo "  to confirm everything is connected."
echo ""
