<?php

namespace local_videotranscriber\ai;

defined('MOODLE_INTERNAL') || die();

class tutor_service {
    private string $apikey;
    private string $endpoint = 'https://api.openai.com/v1/responses';
    private string $model = 'gpt-4o-mini';

    public function __construct() {
        $this->apikey = getenv('OPENAI_API_KEY') ?: '';
        if ($this->apikey === '') {
            throw new \moodle_exception('OpenAI API key não configurada.');
        }
    }

    public function answer_question(string $question, array $contextchunks, array $history = []): array {
        $question = trim($question);

        if ($question === '') {
            throw new \InvalidArgumentException('Pergunta vazia.');
        }

        $context = $this->build_context($contextchunks);
        $input = $this->build_input($question, $context, $history);

        $payload = [
            'model' => $this->model,
            'input' => $input,
            'max_output_tokens' => 500
        ];

        $response = $this->request($payload);

        $text = $this->extract_output_text($response);

        if ($text === '') {
            throw new \moodle_exception('A API não retornou conteúdo válido.');
        }

        return [
            'answer' => $text,
            'raw' => $response
        ];
    }

    private function build_context(array $contextchunks): string {
        $parts = [];

        foreach ($contextchunks as $index => $chunk) {
            $content = trim((string)$chunk);
            if ($content !== '') {
                $parts[] = '[' . ($index + 1) . '] ' . $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function build_input(string $question, string $context, array $history): array {
        $instructions = implode("\n", [
            'Você é um tutor educacional dentro do Moodle.',
            'Use somente o contexto fornecido.',
            'Não invente fatos.',
            'Se a resposta não estiver no contexto, diga claramente: "Não encontrei isso na transcrição da aula."',
            'Explique de forma simples.',
            'Use passos curtos.',
            'Use exemplos somente quando o contexto permitir.',
            'No final, faça uma pergunta curta para verificar se o aluno entendeu.'
        ]);

        $items = [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $instructions
                    ]
                ]
            ]
        ];

        foreach ($history as $message) {
            if (empty($message['role']) || empty($message['content'])) {
                continue;
            }

            if (!in_array($message['role'], ['user', 'assistant'], true)) {
                continue;
            }

            $items[] = [
                'role' => $message['role'],
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => trim((string)$message['content'])
                    ]
                ]
            ];
        }

        $userprompt = "Contexto da aula:\n" . $context . "\n\nPergunta do aluno:\n" . $question;

        $items[] = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => $userprompt
                ]
            ]
        ];

        return $items;
    }

    private function request(array $payload): array {
        $ch = curl_init($this->endpoint);

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apikey
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);

        $body = curl_exec($ch);
        $curlerror = curl_error($ch);
        $httpcode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new \moodle_exception('Erro CURL: ' . $curlerror);
        }

        $json = json_decode($body, true);

        if (!is_array($json)) {
            throw new \moodle_exception('Resposta JSON inválida da OpenAI.');
        }

        if ($httpcode < 200 || $httpcode >= 300) {
            $message = $json['error']['message'] ?? 'Erro desconhecido da OpenAI.';
            throw new \moodle_exception('Erro HTTP ' . $httpcode . ': ' . $message);
        }

        return $json;
    }

    private function extract_output_text(array $response): string {
        if (!empty($response['output_text']) && is_string($response['output_text'])) {
            return trim($response['output_text']);
        }

        if (!empty($response['output']) && is_array($response['output'])) {
            $texts = [];

            foreach ($response['output'] as $item) {
                if (($item['type'] ?? '') !== 'message') {
                    continue;
                }

                foreach (($item['content'] ?? []) as $content) {
                    if (($content['type'] ?? '') === 'output_text' && !empty($content['text'])) {
                        $texts[] = $content['text'];
                    }
                }
            }

            return trim(implode("\n", $texts));
        }

        return '';
    }
}
