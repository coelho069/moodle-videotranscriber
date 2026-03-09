#!/bin/bash
# ============================================================
# deploy.sh — Script de deploy do plugin VideoTranscriber
# 
# Uso na VM:
#   bash /tmp/deploy.sh
# ============================================================

set -e  # Para se der erro

MOODLE_ROOT="/var/www/html/moodle"
REPO_URL="https://github.com/coelho069/moodle-videotranscriber.git"
TMP_DIR="/tmp/vt_deploy_$(date +%s)"

echo ""
echo "╔══════════════════════════════════════════════╗"
echo "║   VideoTranscriber — Deploy para Moodle      ║"
echo "╚══════════════════════════════════════════════╝"
echo ""

# Verifica se o Moodle existe
if [ ! -f "$MOODLE_ROOT/config.php" ] && [ ! -f "$MOODLE_ROOT/public/config.php" ]; then
    echo "❌ ERRO: Moodle não encontrado em $MOODLE_ROOT"
    exit 1
fi

# Detecta se é Moodle 5.x (com /public) ou 4.x
if [ -f "$MOODLE_ROOT/public/config.php" ]; then
    MOODLE_WWW="$MOODLE_ROOT/public"
    echo "✅ Moodle 5.x detectado (usa /public)"
else
    MOODLE_WWW="$MOODLE_ROOT"
    echo "✅ Moodle 4.x detectado"
fi

echo ""
echo "📥 Baixando código mais recente do GitHub..."
git clone --depth=1 "$REPO_URL" "$TMP_DIR" 2>&1 | grep -E "(Cloning|done)"

echo ""
echo "📂 Copiando plugin local/videotranscriber..."
mkdir -p "$MOODLE_WWW/local/videotranscriber"
cp -r "$TMP_DIR/local/videotranscriber/." "$MOODLE_WWW/local/videotranscriber/"

echo "📂 Copiando mod/url/view.php modificado..."
cp "$TMP_DIR/mod/url/view.php" "$MOODLE_WWW/mod/url/view.php"

echo ""
echo "🔐 Ajustando permissões..."
chown -R www-data:www-data "$MOODLE_WWW/local/videotranscriber"
chown www-data:www-data "$MOODLE_WWW/mod/url/view.php"

echo ""
echo "🗑️  Limpando arquivos temporários..."
rm -rf "$TMP_DIR"

echo ""
echo "════════════════════════════════════════"
echo "✅ Deploy concluído com sucesso!"
echo ""
echo "Próximos passos:"
echo "  1. Atualize o banco do Moodle:"
echo "     sudo -u www-data php $MOODLE_WWW/admin/cli/upgrade.php --non-interactive"
echo ""
echo "  2. Force a transcrição de todas as URLs:"
echo "     php $MOODLE_WWW/local/videotranscriber/cli/force_all.php"
echo ""
echo "  3. Processe a fila:"
echo "     php $MOODLE_WWW/local/videotranscriber/cli/force_run.php"
echo "════════════════════════════════════════"
