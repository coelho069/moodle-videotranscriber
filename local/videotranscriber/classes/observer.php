<?php

namespace local_videotranscriber;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Listener for course_module_created
     */
    public static function course_module_created(\core\event\course_module_created $event) {
        self::process_video_module($event);
    }

    /**
     * Listener for course_module_updated
     */
    public static function course_module_updated(\core\event\course_module_updated $event) {
        self::process_video_module($event);
    }

    /**
     * Shared logic to process the module
     */
    private static function process_video_module(\core\event\base $event) {
        global $DB;

        if (!isset($event->other['modulename']) || $event->other['modulename'] !== 'url') {
            return;
        }

        $cmid     = $event->objectid;
        $courseid = $event->courseid;

        $urlrecord = $DB->get_record('url', ['id' => $event->other['instanceid']]);
        if (!$urlrecord || empty($urlrecord->externalurl)) {
            return;
        }

        self::trigger_transcription($cmid, $courseid, $urlrecord->externalurl);
    }

    /**
     * Creates the DB record and fires the PHP CLI script to run in background.
     * Does NOT depend on Moodle cron.
     *
     * @param int    $cmid
     * @param int    $courseid
     * @param string $video_url
     * @return bool  true if started, false if skipped
     */
    public static function trigger_transcription($cmid, $courseid, $video_url) {
        global $DB, $CFG;

        // URL vazia ou placeholder
        if (empty($video_url) || $video_url === 'http://') {
            return false;
        }

        // Verifica se já existe um registro em processamento ativo
        $existing = $DB->get_record('local_videotranscriber', ['cmid' => $cmid]);

        if ($existing && $existing->status === 'processing') {
            // Já está em execução – não dispara de novo
            return true;
        }

        if (!$existing) {
            $vt_record             = new \stdClass();
            $vt_record->cmid       = $cmid;
            $vt_record->status     = 'processing';
            $vt_record->transcription = 'Aguardando início do processo...';
            $vt_record->timecreated  = time();
            $vt_record->timemodified = time();
            $vt_record->id = $DB->insert_record('local_videotranscriber', $vt_record);
        } else {
            // Já existe mas estava com erro ou completo – reinicia
            $existing->status        = 'processing';
            $existing->transcription = 'Aguardando início do processo...';
            $existing->timemodified  = time();
            $DB->update_record('local_videotranscriber', $existing);
            $vt_record = $existing;
        }

        // Caminho para o PHP usado pelo servidor
        $php_bin = self::find_php_binary();

        // Script CLI que faz o trabalho pesado
        $cli_script = realpath($CFG->dirroot . '/local/videotranscriber/cli/run_transcription.php');

        if (!$cli_script || !file_exists($cli_script)) {
            // Script não encontrado – marca como erro
            $vt_record->status        = 'error';
            $vt_record->transcription = 'Erro: Script CLI run_transcription.php não encontrado em ' . $CFG->dirroot . '/local/videotranscriber/cli/';
            $vt_record->timemodified  = time();
            $DB->update_record('local_videotranscriber', $vt_record);
            return false;
        }

        // Monta o comando e roda em background (não bloqueia o PHP/Apache)
        $cmd = $php_bin . ' ' . escapeshellarg($cli_script)
             . ' --record_id=' . (int)$vt_record->id
             . ' --video_url=' . escapeshellarg($video_url);

        self::run_in_background($cmd);

        return true;
    }

    private static function run_in_background(string $cmd): void {
        global $CFG;

        // Arquivo de log para depuração (funciona em ambos os SOs)
        $log_file = $CFG->dataroot . '/temp/vt_transcription.log';

        if (self::is_windows()) {
            $full_cmd = 'start /B "" cmd /C "' . $cmd . ' >> ' . str_replace('/', '\\', $log_file) . ' 2>&1"';
            pclose(popen($full_cmd, 'r'));
        } else {
            // Linux/Mac: proc_open é mais confiável que exec() pois não herda
            // file descriptors do Apache, evitando travamentos
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $log_file, 'a'],
                2 => ['file', $log_file, 'a'],
            ];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (is_resource($proc)) {
                proc_close($proc); // fecha imediatamente — processo continua em background
            } else {
                // Fallback se proc_open falhar
                exec($cmd . ' >> ' . escapeshellarg($log_file) . ' 2>&1 &');
            }
        }
    }

    /**
     * Localiza o executável PHP do servidor.
     */
    private static function find_php_binary(): string {
        global $CFG;

        $candidates = [];

        // 1. PHP_BINARY: o binário que está rodando agora (mais confiável)
        //    Em Apache+mod_php pode ser 'php' vazio, mas em FPM funciona
        if (defined('PHP_BINARY') && !empty(PHP_BINARY)) {
            $dir = dirname(PHP_BINARY);
            // Prefere php.exe / php ao invés de php-cgi
            foreach (['php.exe', 'php'] as $name) {
                if (is_file($dir . DIRECTORY_SEPARATOR . $name)) {
                    $candidates[] = $dir . DIRECTORY_SEPARATOR . $name;
                }
            }
            $candidates[] = PHP_BINARY;
        }

        // 2. Caminhos Linux comuns (VM Ubuntu/Debian/CentOS)
        $candidates = array_merge($candidates, [
            '/usr/bin/php',
            '/usr/bin/php8.3',
            '/usr/bin/php8.2',
            '/usr/bin/php8.1',
            '/usr/bin/php8.0',
            '/usr/bin/php7.4',
            '/usr/local/bin/php',
            '/usr/sbin/php-fpm',
        ]);

        // 3. Caminhos Windows (para MoodleWindowsInstaller e XAMPP)
        if (!empty($CFG->dirroot)) {
            $win_php = realpath($CFG->dirroot . '/../php/php.exe');
            if ($win_php) $candidates[] = $win_php;
        }
        $candidates = array_merge($candidates, [
            'C:\\xampp\\php\\php.exe',
            'C:\\php\\php.exe',
            'php', // PATH do sistema — último recurso
        ]);

        foreach ($candidates as $bin) {
            if (!empty($bin) && @is_file($bin)) {
                return $bin;
            }
        }

        return 'php'; // fallback: PATH do sistema
    }


    /**
     * Detecta se está rodando no Windows.
     */
    private static function is_windows() {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
}
