<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Brazilian Portuguese language strings for local_videotranscriber.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tutor IA (Transcrição de Vídeo)';
$string['notranscription'] = 'A transcrição para este vídeo ainda não está pronta ou não foi encontrada.';
$string['opentutor'] = 'Abrir Tutor IA';
$string['tutortitle'] = 'Tutor IA';
$string['tutorheading'] = 'Tutor IA: {$a}';
$string['tutordescription'] = 'Faça perguntas sobre o conteúdo do vídeo. O Tutor IA responderá com base na transcrição.';
$string['yourquestion'] = 'Sua pergunta';
$string['questionplaceholder'] = 'Digite sua pergunta sobre o vídeo...';
$string['submitquestion'] = 'Enviar pergunta';
$string['tutoranswer'] = 'Resposta do Tutor IA';
$string['backtoactivity'] = '← Voltar à atividade';
$string['backtocourse'] = '← Voltar ao curso';
$string['transcription_unavailable'] = 'Transcrição Indisponível';
$string['transcription_unavailable_desc'] = 'A transcrição para este vídeo ainda não foi concluída ou o registro não foi encontrado. O processamento geralmente leva alguns minutos. Por favor, aguarde e recarregue a página.';
$string['settings_apikey'] = 'Chave da API OpenAI';
$string['settings_apikey_desc'] = 'Insira sua chave da API OpenAI para ativar o recurso Tutor IA.';
$string['error'] = 'Erro:';
$string['error_apikey'] = 'Chave da API não configurada.';
$string['error_emptyquestion'] = 'Pergunta vazia.';
$string['error_emptyresponse'] = 'Resposta vazia da IA.';
$string['error_json'] = 'Erro ao gerar JSON.';
$string['error_connection'] = 'Erro de conexão.';
$string['error_invalidresponse'] = 'Resposta inválida da OpenAI.';
$string['error_unknown'] = 'Erro desconhecido.';
$string['error_api'] = 'Erro da API: {$a}';
