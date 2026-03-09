<?php

namespace local_videotranscriber\task;

defined('MOODLE_INTERNAL') || die();

class transcribe_task extends \core\task\adhoc_task {
    public function execute() {
        global $DB;

        $customdata = $this->get_custom_data();
        $record_id = $customdata->record_id;
        $video_url = $customdata->video_url;

        $vt_record = $DB->get_record('local_videotranscriber', ['id' => $record_id]);
        if (!$vt_record) {
            return;
        }

        // --- CHAMAR API DA OPENAI WHISPER ---
        $apikey = get_config('local_videotranscriber', 'openaiapikey') ? get_config('local_videotranscriber', 'openaiapikey') : getenv('OPENAI_API_KEY');

        if (empty($apikey)) {
            $this->mark_failed($vt_record->id, "API Key não configurada ou não encontrada em 'openaiapikey'.");
            return;
        }

        // Cria o arquivo temporário
        $temp_audio = make_request_directory() . '/audio_dl_' . rand(1000, 9999) . '.mp4';
        
        // Verifica se é YouTube para usar o yt-dlp
        if (preg_match('/youtube\.com|youtu\.be/i', $video_url)) {
            $ytdlp_path = __DIR__ . '/../../cli/bin/yt-dlp.exe';
            
            if (!file_exists($ytdlp_path)) {
                $ytdlp_path = 'yt-dlp';
            }

            $temp_audio = make_request_directory() . '/audio_yt_' . rand(1000, 9999) . '.m4a';
            $cmd = escapeshellcmd($ytdlp_path) . " -x --audio-format m4a -o " . escapeshellarg($temp_audio) . " " . escapeshellarg($video_url);

            $this->update_status($vt_record->id, 'Baixando áudio do YouTube (isso pode demorar alguns minutos)...');
            shell_exec($cmd . " 2>&1");
            
            if (!file_exists($temp_audio) || filesize($temp_audio) == 0) {
                $this->mark_failed($vt_record->id, "Falha ao extrair áudio do YouTube. Verifique se o yt-dlp está instalado.");
                return;
            }
        } else {
            $this->update_status($vt_record->id, 'Baixando áudio do vídeo...');
            $downloaded = @copy($video_url, $temp_audio);
    
            if (!$downloaded || filesize($temp_audio) == 0) {
                $this->mark_failed($vt_record->id, "Falha ao baixar o arquivo: " . $video_url);
                return;
            }
        }

        if (filesize($temp_audio) > 25000000) {
            $this->mark_failed($vt_record->id, "Arquivo de áudio maior que 25MB. Limite da OpenAI (Whisper) atingido.");
            @unlink($temp_audio);
            return;
        }

        $this->update_status($vt_record->id, 'Transcrevendo áudio com Inteligência Artificial (OpenAI Whisper)...');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.openai.com/v1/audio/transcriptions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        
        $post_fields = [
            'model' => 'whisper-1',
            'file' => new \CURLFile($temp_audio),
            'response_format' => 'text'
        ];

        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $apikey,
            "Content-Type: multipart/form-data"
        ]);

        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        @unlink($temp_audio);

        if ($httpcode == 200 && !empty($response)) {
            $vt_update = new \stdClass();
            $vt_update->id = $vt_record->id;
            $vt_update->transcription = trim($response);
            $vt_update->status = 'completed';
            $vt_update->timemodified = time();
            $DB->update_record('local_videotranscriber', $vt_update);
        } else {
            $error_data = json_decode($response, true);
            $errmsg = isset($error_data['error']['message']) ? $error_data['error']['message'] : "Erro desconhecido Whisper.";
            $this->mark_failed($vt_record->id, "Erro API: " . $httpcode . " - " . $errmsg);
        }
    }

    private function update_status($record_id, $msg) {
        global $DB;
        $vt_update = new \stdClass();
        $vt_update->id = $record_id;
        $vt_update->transcription = $msg;
        $vt_update->timemodified = time();
        $DB->update_record('local_videotranscriber', $vt_update);
    }

    private function mark_failed($record_id, $error_msg) {
        global $DB;
        $vt_update = new \stdClass();
        $vt_update->id = $record_id;
        $vt_update->status = 'error';
        $vt_update->transcription = 'Error: ' . $error_msg;
        $vt_update->timemodified = time();
        $DB->update_record('local_videotranscriber', $vt_update);
    }
}
