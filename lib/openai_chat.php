<?php

defined('MOODLE_INTERNAL') || die();

function videotranscriber_openai_chat($transcription, $question) {

    $apikey = getenv('OPENAI_API_KEY');

    $prompt = "Use a transcrição da aula para ensinar o aluno.

Regras:
1. Use apenas a transcrição.
2. Não invente informações.
3. Explique de forma simples.
4. Use exemplos quando possível.

Transcrição da aula:
".$transcription."

Pergunta do aluno:
".$question;

    $data = [
        "model" => "gpt-4o-mini",
        "messages" => [
            [
                "role" => "system",
                "content" => "Você é um tutor educacional. Responda apenas com base na transcrição."
            ],
            [
                "role" => "user",
                "content" => $prompt
            ]
        ]
    ];

    $url = "https://api.openai.com/v1/chat/completions";

    $ch = curl_init($url);

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer ".$apikey
    ];

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);

    if ($response === false) {

        $error = curl_error($ch);

        curl_close($ch);

        return "Erro CURL: ".$error;
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpcode != 200) {

        return "Erro HTTP ".$httpcode;
    }

    $json = json_decode($response, true);

    if (!isset($json["choices"][0]["message"]["content"])) {

        return "Erro ao gerar resposta.";
    }

    return $json["choices"][0]["message"]["content"];
}
