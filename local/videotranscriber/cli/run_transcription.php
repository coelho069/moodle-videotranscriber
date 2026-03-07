<?php
/**
 * run_transcription.php — Motor de transcrição de vídeo do VideoTranscriber
 *
 * Responsabilidades:
 *   1. Encontrar o Moodle root e carregar o ambiente
 *   2. Validar entradas e API key
 *   3. Baixar o áudio (YouTube via yt-dlp ou URL direta via cURL)
 *   4. Enviar para OpenAI Whisper e salvar a transcrição
 *
 * Uso: php run_transcription.php --record_id=<id> --video_url=<url>
 *
 * @package local_videotranscriber
 */

declare(strict_types=1);
define('CLI_SCRIPT', true);

// Previne que o script morra por timeout ou falta de memória em vídeos longos na VM
@set_time_limit(0);
@ini_set('memory_limit', '512M');

// ================================================================
// 1. BOOTSTRAP — localiza e carrega o Moodle
// ================================================================
$moodle_root = null;

// Caminho esperado: local/videotranscriber/cli/run_transcription.php => 3 níveis acima
$candidates = [
    realpath(__DIR__ . '/../../../'),
    realpath(__DIR__ . '/../../../../'),
    realpath(__DIR__ . '/../../../../../../moodle'),
];

foreach ($candidates as $path) {
    if ($path && is_file($path . '/config.php') && is_file($path . '/lib/setup.php')) {
        $moodle_root = $path;
        break;
    }
}

if (!$moodle_root) {
    fwrite(STDERR, "[FATAL] config.php do Moodle não encontrado. __DIR__=" . __DIR__ . "\n");
    exit(1);
}

require_once($moodle_root . '/config.php');

// Muda o diretório de trabalho para a raiz do Moodle
// Isso previne erros de include/require em plugins de terceiros e core tools
chdir($moodle_root);

global $DB, $CFG;

// ================================================================
// 2. ARGUMENTOS
// ================================================================
$opts      = getopt('', ['record_id:', 'video_url:']);
$record_id = isset($opts['record_id']) ? (int)$opts['record_id'] : 0;
$video_url = isset($opts['video_url']) ? trim($opts['video_url']) : '';

if (!$record_id || !$video_url) {
    fwrite(STDERR, "[ERRO] Uso: php run_transcription.php --record_id=<id> --video_url=<url>\n");
    exit(1);
}

// ================================================================
// 3. HELPERS — log, status e banco
// ================================================================
function vt_log(string $msg): void {
    global $CFG;
    $timestamp = '[' . date('Y-m-d H:i:s') . '] ';
    echo $timestamp . $msg . "\n";
    flush();

    // Log em arquivo para debug na VM
    if (isset($CFG->dataroot)) {
        $log_file = rtrim($CFG->dataroot, '/\\') . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'vt_debug.log';
        if (!is_dir(dirname($log_file))) {
            @mkdir(dirname($log_file), 0777, true);
        }
        @file_put_contents($log_file, $timestamp . $msg . "\n", FILE_APPEND);
    }
}

function vt_status(int $id, string $msg): void {
    global $DB;
    $upd               = new stdClass();
    $upd->id           = $id;
    $upd->transcription = $msg;
    $upd->timemodified = time();
    $DB->update_record('local_videotranscriber', $upd);
    vt_log("STATUS: " . $msg);
}

function vt_done(int $id, string $text): void {
    global $DB;
    $upd               = new stdClass();
    $upd->id           = $id;
    $upd->status       = 'completed';
    $upd->transcription = $text;
    $upd->timemodified = time();
    $DB->update_record('local_videotranscriber', $upd);
    vt_log('CONCLUÍDO! ' . mb_strlen($text) . ' caracteres transcritos.');
}

function vt_fail(int $id, string $msg): void {
    global $DB;
    $upd               = new stdClass();
    $upd->id           = $id;
    $upd->status       = 'error';
    $upd->transcription = $msg;
    $upd->timemodified = time();
    $DB->update_record('local_videotranscriber', $upd);
    fwrite(STDERR, '[ERRO] ' . $msg . "\n");
    vt_log("FALHA CRÍTICA: " . $msg);
}

function vt_cleanup(string $file): void {
    if ($file && is_file($file)) {
        @unlink($file);
    }
}

// ================================================================
// 4. VALIDA REGISTRO
// ================================================================
vt_log("=========================================");
vt_log("Iniciando transcrição | record_id=$record_id | url=$video_url");

$vt_record = $DB->get_record('local_videotranscriber', ['id' => $record_id]);
if (!$vt_record) {
    vt_log("[ERRO] Registro ID=$record_id não existe no banco.");
    exit(1);
}

// ================================================================
// 5. API KEY — tenta 3 fontes em ordem
// ================================================================
$apikey = '';

// Fonte 1: banco de dados direto (não exige plugin registrado)
try {
    $row = $DB->get_record_sql(
        "SELECT value FROM {config_plugins} WHERE plugin = :plugin AND name = :name",
        ['plugin' => 'local_videotranscriber', 'name' => 'openaiapikey']
    );
    if ($row && !empty($row->value)) {
        $apikey = trim($row->value);
    }
} catch (Throwable $e) { /* ignora */ }

// Fonte 2: variável de ambiente
if (empty($apikey)) {
    $apikey = (string)getenv('OPENAI_API_KEY');
}

// Fonte 3: get_config do Moodle
if (empty($apikey)) {
    $apikey = (string)get_config('local_videotranscriber', 'openaiapikey');
}

if (empty($apikey)) {
    vt_fail($record_id,
        "❌ API Key da OpenAI não configurada.\n\n" .
        "Acesse: Admin Moodle → Plugins → Plugins Locais → Video Transcriber → OpenAI API Key\n" .
        "Ou defina a variável de ambiente OPENAI_API_KEY."
    );
    exit(1);
}

vt_log('API Key OK: ' . substr($apikey, 0, 8) . '...');

// ================================================================
// 6. BINÁRIOS (Corrigido para resolver PATHs no Debian/Linux)
// ================================================================
$bin_dir = realpath($CFG->dirroot . '/local/videotranscriber/cli/bin') ?: '';

$ytdlp = 'yt-dlp'; // fallback padrão
$ffmpeg = 'ffmpeg';

// Procura yt-dlp explicitamente em diretórios comuns de Linux, pois em exec() o $PATH do Apache costuma ser limitado
$ytdlp_candidates = [
    $bin_dir . DIRECTORY_SEPARATOR . 'yt-dlp.exe',
    $bin_dir . DIRECTORY_SEPARATOR . 'yt-dlp',
    '/usr/local/bin/yt-dlp',
    '/usr/bin/yt-dlp',
    '/opt/homebrew/bin/yt-dlp'
];

foreach ($ytdlp_candidates as $candidate) {
    if ($candidate && is_file($candidate)) {
        if (!is_executable($candidate)) {
            @chmod($candidate, 0755); // Tenta dar permissão caso não tenha
        }
        $ytdlp = $candidate;
        break;
    }
}

// Localiza o FFMPEG absolute path também
foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $cand) {
    if (is_file($cand)) {
        $ffmpeg = $cand;
        break;
    }
}

$ffmpeg_dir_flag = '';
if ($bin_dir && is_file($bin_dir . DIRECTORY_SEPARATOR . 'ffmpeg.exe')) {
    $ffmpeg_dir_flag = ' --ffmpeg-location ' . escapeshellarg($bin_dir);
} elseif ($ffmpeg !== 'ffmpeg') {
    // Se achamos o path absoluto no linux, passa pro yt-dlp para ele não se perder
    $ffmpeg_dir_flag = ' --ffmpeg-location ' . escapeshellarg(dirname($ffmpeg));
}

vt_log('Binários: yt-dlp=' . $ytdlp . ' | ffmpeg=' . $ffmpeg);

// ================================================================
// 7. DOWNLOAD DO ÁUDIO
// ================================================================
$tmp       = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
$unique    = $record_id . '_' . time() . '_' . mt_rand(100, 999);
$audio_file = '';

$is_youtube = (bool)preg_match('#(youtube\.com/watch|youtu\.be/|youtube\.com/shorts/)#i', $video_url);

if ($is_youtube) {
    // --- YouTube via yt-dlp ---
    $audio_file = $tmp . DIRECTORY_SEPARATOR . 'vt_yt_' . $unique . '.%(ext)s';
    $final_file = $tmp . DIRECTORY_SEPARATOR . 'vt_yt_' . $unique . '.m4a';

    vt_status($record_id, '⏬ Baixando áudio do YouTube (aguarde, pode levar alguns minutos)...');

    $cmd = escapeshellarg($ytdlp)
         . $ffmpeg_dir_flag
         . ' --no-playlist'             // só o vídeo, não a playlist
         . ' -x'                        // extrai só o áudio
         . ' --audio-format m4a'        // formato compatível com Whisper
         . ' --audio-quality 0'         // melhor qualidade (menor ruído = melhor transcrição)
         . ' --socket-timeout 60'       // timeout de rede
         . ' --retries 5'               // retry em caso de falha
         . ' -o ' . escapeshellarg($audio_file)
         . ' ' . escapeshellarg($video_url)
         . ' 2>&1';

    vt_log("CMD: $cmd");

    $output   = [];
    $ret_code = 0;
    exec($cmd, $output, $ret_code);

    $output_str = implode("\n", $output);
    vt_log("yt-dlp exit=$ret_code\n" . $output_str);

    // yt-dlp adiciona a extensão automaticamente, precisa procurar o arquivo
    $found = glob($tmp . DIRECTORY_SEPARATOR . 'vt_yt_' . $unique . '.*');
    if ($found) {
        $audio_file = $found[0];
    } else {
        $audio_file = $final_file; // tenta o nome esperado
    }

    if ($ret_code !== 0 || !is_file($audio_file) || filesize($audio_file) < 1024) {
        vt_fail($record_id,
            "❌ Falha ao baixar o YouTube (código $ret_code).\n\n" .
            "Possíveis causas:\n" .
            "• O vídeo é privado ou indisponível\n" .
            "• O yt-dlp não está instalado (ou sem permissão) em /usr/local/bin/yt-dlp\n" .
            "• O ffmpeg não foi encontrado no PATH\n\n" .
            "Comando executado:\n$cmd\n\n" .
            "Saída do processo:\n" . mb_substr($output_str, 0, 1500)
        );
        exit(1);
    }

} else {
    // --- URL direta (mp3, mp4, wav, ogg...) ---
    $path_info  = parse_url($video_url, PHP_URL_PATH) ?? '';
    $ext        = strtolower(pathinfo($path_info, PATHINFO_EXTENSION)) ?: 'mp4';
    $audio_file = $tmp . DIRECTORY_SEPARATOR . 'vt_dl_' . $unique . '.' . $ext;

    vt_status($record_id, '⏬ Baixando arquivo de mídia...');

    $fp = @fopen($audio_file, 'wb');
    if (!$fp) {
        vt_fail($record_id, "❌ Não foi possível criar arquivo temporário em: $tmp");
        exit(1);
    }

    $ch = curl_init($video_url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VideoTranscriber/1.0)',
        CURLOPT_SSL_VERIFYPEER => false, // necessário em alguns ambientes locais
    ]);
    curl_exec($ch);
    $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($curl_err || $http_code < 200 || $http_code >= 300 || !is_file($audio_file) || filesize($audio_file) < 1024) {
        vt_cleanup($audio_file);
        vt_fail($record_id,
            "❌ Falha ao baixar mídia (HTTP $http_code).\n" .
            ($curl_err ? "cURL: $curl_err\n" : '') .
            "URL: $video_url"
        );
        exit(1);
    }
}

$size_bytes = filesize($audio_file);
$size_mb    = round($size_bytes / 1024 / 1024, 2);

vt_log("Áudio pronto: $audio_file ({$size_mb}MB)");

// ================================================================
// 8. VALIDA TAMANHO (limite Whisper = 25 MB)
// ================================================================
if ($size_bytes > 25 * 1024 * 1024) {
    vt_cleanup($audio_file);
    vt_fail($record_id,
        "❌ Arquivo de {$size_mb}MB excede o limite de 25MB da OpenAI Whisper.\n" .
        "Dica: Use vídeos com menos de ~3 horas ou converta para MP3 antes."
    );
    exit(1);
}

// ================================================================
// 9. OPENAI WHISPER — transcrição
// ================================================================
vt_status($record_id, "🤖 Enviando {$size_mb}MB para o Whisper transcrever... (pode levar minutos)");

// Detecta o mime type correto para o Whisper
$ext_map = [
    'm4a' => 'audio/mp4',
    'mp4' => 'video/mp4',
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'webm'=> 'audio/webm',
    'mpeg'=> 'audio/mpeg',
];
$file_ext  = strtolower(pathinfo($audio_file, PATHINFO_EXTENSION));
$mime_type = $ext_map[$file_ext] ?? 'audio/mpeg';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => 'https://api.openai.com/v1/audio/transcriptions',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'model'           => 'whisper-1',
        'file'            => new CURLFile($audio_file, $mime_type, 'audio.' . $file_ext),
        'response_format' => 'text',
        'language'        => 'pt',    // força português (melhora precisão)
    ],
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apikey,
    ],
    CURLOPT_TIMEOUT        => 1200,   // 20 minutos para vídeos longos e upload lento
    CURLOPT_SSL_VERIFYPEER => false,  // necessário em alguns ambientes XAMPP/Windows
    CURLOPT_CONNECTTIMEOUT => 60,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // Força IPv4 (evita timeouts em VMs com IPv6 mal configurado)
]);

$response  = curl_exec($ch);
$http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

vt_log("Whisper HTTP=$http_code | curl_err=" . ($curl_err ?: 'nenhum'));

// Remove arquivo temporário independente do resultado
vt_cleanup($audio_file);

// ================================================================
// 10. RESULTADO
// ================================================================
if ($curl_err) {
    vt_fail($record_id,
        "❌ Erro de conexão com a OpenAI.\n\n" .
        "cURL: $curl_err\n\n" .
        "Verifique se o servidor tem acesso à internet."
    );
    exit(1);
}

if ($http_code === 200 && !empty($response)) {
    vt_done($record_id, trim($response));
    exit(0);
}

// Trata erros da API
$error_data = @json_decode($response, true);
$api_error  = $error_data['error']['message'] ?? null;

if ($http_code === 401) {
    $error_msg = "❌ API Key inválida ou expirada (HTTP 401).\nVerifique sua chave em platform.openai.com";
} elseif ($http_code === 429) {
    $error_msg = "❌ Limite de requisições da OpenAI atingido (HTTP 429).\nAguarde alguns minutos e tente novamente.";
} elseif ($http_code === 413) {
    $error_msg = "❌ Arquivo muito grande para a API (HTTP 413).\nTente um vídeo mais curto.";
} elseif ($api_error) {
    $error_msg = "❌ Erro OpenAI Whisper: $api_error";
} else {
    $error_msg = "❌ Erro desconhecido (HTTP $http_code).\nResposta: " . mb_substr((string)$response, 0, 500);
}

vt_fail($record_id, $error_msg);
exit(1);
