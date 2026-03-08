<?php
/**
 * force_run.php — Dispara os Adhoc Tasks e mostra o progresso da transcrição no Terminal.
 * 
 * Uso via CLI (Recomendado na VM):
 *   php local/videotranscriber/cli/force_run.php
 *
 * @package local_videotranscriber
 */

define('CLI_SCRIPT', PHP_SAPI === 'cli');

require_once(__DIR__ . '/../../../config.php');

if (!CLI_SCRIPT) {
    die("Este script só pode ser rodado pelo terminal (SSH) da Máquina Virtual.\nUse: php /var/www/html/moodle/local/videotranscriber/cli/force_run.php\n");
}

echo "========================================================\n";
echo "    Transcritor de Vídeos Moodle - Execução Forçada      \n";
echo "========================================================\n\n";

// Pega as transcrições "processing" que estão presas
$sql = "SELECT * FROM {local_videotranscriber} WHERE status = 'processing'";
$pendentes = $DB->get_records_sql($sql);

if (empty($pendentes)) {
    echo "✅ A fila está limpa. Não há vídeos aguardando transcrição.\n\n";
    // Pode ser que tenhamos URLs não registradas, roda o force_all.php pra garantir
    echo "Procurando novas URLs sem registro...\n";
    require_once(__DIR__ . '/force_all.php');
}

// Verifica de novo após forçar o registro acima
$pendentes = $DB->get_records_sql($sql);

if (!empty($pendentes)) {
    echo "\n⏳ Existem " . count($pendentes) . " vídeos na fila de transcrição.\n";
    echo "Iniciando a fila de tarefas do Moodle (Adhoc Tasks) em background...\n\n";
    
    // Força o cron a rodar só as adhoc tasks (em background) para não travar esta tela
    $php_binary = (PHP_BINARY && is_file(PHP_BINARY)) ? PHP_BINARY : 'php';
    $cron_cmd = escapeshellarg($php_binary) . ' ' . escapeshellarg($CFG->dirroot . '/admin/cli/adhoc_task.php') . ' --execute=\\\local_videotranscriber\\\task\\\transcribe_task';
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" ' . $cron_cmd . ' > NUL 2>&1', 'r'));
    } else {
        exec($cron_cmd . ' > /dev/null 2>&1 &');
    }
    
    echo "As tarefas foram acionadas no servidor. Vamos monitorar o banco de dados:\n\n";
    
    // Loop de monitoramento (barra de progresso no terminal)
    $active = true;
    while ($active) {
        $active = false;
        $pendentes = $DB->get_records_sql($sql);
        
        // Limpa a tela (gambiarra de terminal ANSI)
        echo "\033[2J\033[;H";
        
        echo "Monitorando o progresso das transcrições (Pressione Ctrl+C para sair):\n";
        echo "---------------------------------------------------------------------\n\n";
        
        if (empty($pendentes)) {
            echo "✅ Todas as transcrições terminaram!\n";
            break;
        }
        
        $active = true;
        foreach ($pendentes as $p) {
            $msg = $p->transcription ?: 'Iniciando...';
            
            // Extrai a porcentagem tipo [60%]
            $percent = 0;
            if (preg_match('/\[(\d+)%\]/', $msg, $matches)) {
                $percent = (int)$matches[1];
            }
            
            // Desenha a barra no terminal
            $bar_size = 40;
            $filled = round(($percent / 100) * $bar_size);
            $empty = $bar_size - $filled;
            $bar_str = str_repeat('█', $filled) . str_repeat('░', $empty);
            
            echo "   ID {$p->cmid} | $bar_str | $percent%\n";
            echo "   Status: " . mb_substr($msg, 0, 70) . "\n\n";
        }
        
        sleep(3);
    }
}
