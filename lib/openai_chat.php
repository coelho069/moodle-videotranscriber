<?php

defined('MOODLE_INTERNAL') || die();

function videotranscriber_openai_chat($transcription, $question)
{

    $apikey = getenv('OPENAI_API_KEY');

    $prompt = "Transição da aula (Contexto):\n" . $transcription . "\n\n" . "Pergunta do aluno:\n" . $question;

    $data = [
        "model" => "gpt-4o-mini",
        "temperature" => 0.0,
        "messages" => [
            [
                "role" => "system",
                "content" => "ATENÇÃO MÁXIMA: Você é um assistente educacional estritamente limitado ao texto fornecido. REGRA 1: baseie-se EXCLUSIVAMENTE nas palavras da transcrição do vídeo. REGRA 2: É expressamente PROIBIDO inventar informação ou deduzir coisas óbvias. REGRA 3: Se a transcrição não contiver a resposta exata, VOCÊ DEVE RESPONDER EXATAMENTE: 'Sinto muito, mas essa informação não foi mencionada no vídeo.'"
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
        "Authorization: Bearer " . $apikey
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

        return "Erro CURL: " . $error;
    }

    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpcode != 200) {

        return "Erro HTTP " . $httpcode;
    }

    $json = json_decode($response, true);

    if (!isset($json["choices"][0]["message"]["content"])) {

        return "Erro ao gerar resposta.";
    }

    return $json["choices"][0]["message"]["content"];
}
