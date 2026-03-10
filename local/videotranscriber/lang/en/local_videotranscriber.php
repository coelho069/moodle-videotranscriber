<?php
/**
 * Language strings for local_videotranscriber (English)
 * @package local_videotranscriber
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']   = 'Video Transcriber';

// API Key
$string['openaiapikey']      = 'API Key';
$string['openaiapikey_desc'] = 'Your API key for the AI service (OpenAI, Groq, DeepSeek, Ollama, etc).';

// API URL
$string['apiurl']      = 'API Base URL';
$string['apiurl_desc'] = 'Base URL of the API (e.g. https://api.openai.com/v1 or https://api.groq.com/openai/v1 or http://localhost:11434/v1 for Ollama).';

// Chat Model
$string['chatmodel']      = 'Chat Model (Tutor IA)';
$string['chatmodel_desc'] = 'Model used for the AI Tutor chat (e.g. gpt-4o-mini, llama-3.3-70b-versatile, deepseek-chat).';

// Transcribe Model
$string['transcribemodel']      = 'Transcription Model';
$string['transcribemodel_desc'] = 'Model used for audio transcription (e.g. whisper-1, whisper-large-v3).';
