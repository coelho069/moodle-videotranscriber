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
 * OpenAI tutor service for generating answers from transcription context.
 *
 * @package    local_videotranscriber
 * @copyright  2026 Mateus Coelho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_videotranscriber\ai;

defined('MOODLE_INTERNAL') || die();

/**
 * Service class for OpenAI API interactions.
 */
class tutor_service {
    /** @var string OpenAI API key. */
    private string $apikey;

    /** @var string OpenAI API endpoint. */
    private string $endpoint = 'https://api.openai.com/v1/chat/completions';

    /** @var string OpenAI model to use. */
    private string $model = 'gpt-4o-mini';

    /**
     * Constructor.
     *
     * @throws \moodle_exception If the API key is not configured.
     */
    public function __construct() {
        $this->apikey = get_config('local_videotranscriber', 'openai_api_key');
        if (empty($this->apikey)) {
            throw new \moodle_exception('error_apikey', 'local_videotranscriber');
        }
    }

    /**
     * Answer a student question using transcription context.
     *
     * @param string $question The student's question.
     * @param array $contextchunks Array of transcription text chunks.
     * @param array $history Optional conversation history.
     * @return array Response with 'answer' and 'raw' keys.
     * @throws \moodle_exception On API errors.
     */
    public function answer_question(string $question, array $contextchunks, array $history = []): array {
        $question = trim($question);

        if ($question === '') {
            throw new \invalid_parameter_exception(
                get_string('error_emptyquestion', 'local_videotranscriber')
            );
        }

        $context = $this->build_context($contextchunks);
        $messages = $this->build_messages($question, $context, $history);

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.2,
        ];

        $response = $this->request($payload);

        $text = $response['choices'][0]['message']['content'] ?? '';
        $text = trim($text);

        if ($text === '') {
            throw new \moodle_exception('error_emptyresponse', 'local_videotranscriber');
        }

        return [
            'answer' => $text,
            'raw' => $response,
        ];
    }

    /**
     * Build context string from chunks.
     *
     * @param array $contextchunks Array of text chunks.
     * @return string Formatted context string.
     */
    private function build_context(array $contextchunks): string {
        $parts = [];

        foreach ($contextchunks as $index => $chunk) {
            $content = trim((string) $chunk);
            if ($content !== '') {
                $parts[] = '[' . ($index + 1) . '] ' . $content;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build messages array for OpenAI Chat Completions API.
     *
     * @param string $question The student's question.
     * @param string $context The transcription context.
     * @param array $history Optional conversation history.
     * @return array Messages array for the API.
     */
    private function build_messages(string $question, string $context, array $history): array {
        $instructions = implode("\n", [
            'You are an educational tutor within Moodle.',
            'Use only the context provided.',
            'Do not make up facts.',
            'If the answer is not in the context, say clearly: "I could not find this in the lesson transcription."',
            'Explain simply.',
            'Use short steps.',
            'Use examples only when the context allows.',
            'At the end, ask a short question to check if the student understood.',
        ]);

        $messages = [
            [
                'role' => 'system',
                'content' => $instructions,
            ],
        ];

        foreach ($history as $message) {
            if (empty($message['role']) || empty($message['content'])) {
                continue;
            }

            if (!in_array($message['role'], ['user', 'assistant'], true)) {
                continue;
            }

            $messages[] = [
                'role' => $message['role'],
                'content' => trim((string) $message['content']),
            ];
        }

        $userprompt = "Lesson context:\n" . $context . "\n\nStudent question:\n" . $question;

        $messages[] = [
            'role' => 'user',
            'content' => $userprompt,
        ];

        return $messages;
    }

    /**
     * Send a request to the OpenAI API.
     *
     * @param array $payload The request payload.
     * @return array The decoded JSON response.
     * @throws \moodle_exception On connection or API errors.
     */
    private function request(array $payload): array {
        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $curlerror = curl_error($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \moodle_exception('error_connection', 'local_videotranscriber', '', $curlerror);
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new \moodle_exception('error_invalidresponse', 'local_videotranscriber');
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            $message = $json['error']['message'] ?? 'HTTP ' . $httpcode;
            throw new \moodle_exception('error_api', 'local_videotranscriber', '', $message);
        }

        return $json;
    }
}
