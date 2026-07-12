<?php
/**
 * Lunara_Dispatch_AI_Client
 *
 * Provider-agnostic dispatcher. Same Lunara editorial system prompt is
 * sent to whichever provider you select, so the voice stays identical
 * across Claude / ChatGPT / Gemini / Grok — only the brain changes.
 *
 * Supported providers (option key 'lunara_dispatch_provider'):
 *   - 'claude'  → Anthropic Messages API
 *   - 'openai'  → OpenAI Chat Completions (ChatGPT / GPT-4 family)
 *   - 'gemini'  → Google Gemini generateContent
 *   - 'grok'    → xAI Grok (OpenAI-compatible Chat Completions)
 *
 * Each provider has its own stored API key + model so you can switch
 * without re-pasting credentials.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_AI_Client {

    const MAX_INPUT_CHARS = 30000;
    const MAX_RESPONSE_BYTES = 2097152;

    /**
     * Generate the Lunara Journal HTML from a block of news data,
     * using whichever provider is currently selected.
     *
     * @param  string $news_data
     * @return string|WP_Error  HTML on success, WP_Error on failure.
     */
    public function generate($news_data) {
        $news_data = $this->limit_text((string) $news_data, self::MAX_INPUT_CHARS);
        $provider = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::provider() : sanitize_key(get_option('lunara_dispatch_provider', 'openai'));
        $system   = Lunara_Dispatch_Prompts::system_prompt();
        $user_prompt = Lunara_Dispatch_Prompts::user_directive_prompt();
        $user     = Lunara_Dispatch_Prompts::user_directive($news_data);
        $tokens   = $this->resolve_max_tokens();

        switch ($provider) {
            case 'openai':
                return $this->call_openai($system, $user, $tokens);
            case 'gemini':
                return $this->call_gemini($system, $user, $tokens);
            case 'grok':
                return $this->call_grok($system, $user, $tokens);
            case 'claude':
            default:
                return $this->call_claude($system, $user_prompt, $news_data, $tokens);
        }
    }

    private function resolve_max_tokens() {
        $t = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::max_tokens() : (int) get_option('lunara_dispatch_max_tokens', 4096);
        if ($t < 1024)  { $t = 1024; }
        if ($t > 16000) { $t = 16000; }
        return $t;
    }

    /* ──────────────────────────── CLAUDE ──────────────────────────── */

    private function call_claude($system, $user_prompt, $news_data, $max_tokens) {
        $key = $this->resolve_secret('claude');
        if (empty($key)) {
            return new WP_Error('missing_api_key', 'Anthropic API key is not set.');
        }
        $model = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::model_for_provider('claude', 'claude-opus-4-5') : sanitize_text_field(get_option('lunara_dispatch_claude_model', 'claude-opus-4-5'));

        $response = wp_safe_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 120,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode(array(
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => $system,
                'messages'   => array(array(
                    'role'    => 'user',
                    'content' => array(
                        array(
                            'type'          => 'text',
                            'text'          => $user_prompt,
                            'cache_control' => array('type' => 'ephemeral'),
                        ),
                        array(
                            'type' => 'text',
                            'text' => "\n" . $news_data,
                        ),
                    ),
                )),
            )),
        ));

        if (is_wp_error($response)) { return $response; }

        $status = (int) wp_remote_retrieve_response_code($response);
        $parsed = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            $msg = $parsed['error']['message'] ?? ('HTTP ' . $status);
            return new WP_Error('claude_api_error', 'Claude error: ' . $msg);
        }

        $html = '';
        if (!empty($parsed['content']) && is_array($parsed['content'])) {
            foreach ($parsed['content'] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $html .= $block['text'] ?? '';
                }
            }
        }
        return trim($html) !== '' ? $html : new WP_Error('claude_empty', 'Claude returned no text.');
    }

    /* ──────────────────────────── OPENAI ──────────────────────────── */

    private function call_openai($system, $user, $max_tokens) {
        $key = $this->resolve_secret('openai');
        if (empty($key)) {
            return new WP_Error('missing_api_key', 'OpenAI API key is not set.');
        }
        $model = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::model_for_provider('openai', 'gpt-4o') : sanitize_text_field(get_option('lunara_dispatch_openai_model', 'gpt-4o'));

        $response = wp_safe_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 120,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'model'       => $model,
                'max_tokens'  => $max_tokens,
                'temperature' => 0.7,
                'messages'    => array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user',   'content' => $user),
                ),
            )),
        ));

        return $this->parse_openai_compatible($response, 'OpenAI');
    }

    /* ───────────────────────────── GROK ───────────────────────────── */

    private function call_grok($system, $user, $max_tokens) {
        $key = $this->resolve_secret('grok');
        if (empty($key)) {
            return new WP_Error('missing_api_key', 'xAI Grok API key is not set.');
        }
        $model = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::model_for_provider('grok', 'grok-4') : sanitize_text_field(get_option('lunara_dispatch_grok_model', 'grok-4'));

        $response = wp_safe_remote_post('https://api.x.ai/v1/chat/completions', array(
            'timeout' => 120,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ),
            'body' => wp_json_encode(array(
                'model'       => $model,
                'max_tokens'  => $max_tokens,
                'temperature' => 0.7,
                'messages'    => array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user',   'content' => $user),
                ),
            )),
        ));

        return $this->parse_openai_compatible($response, 'Grok');
    }

    private function parse_openai_compatible($response, $label) {
        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $parsed = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200) {
            $msg = $parsed['error']['message'] ?? ('HTTP ' . $status);
            return new WP_Error('ai_api_error', $label . ' error: ' . $msg);
        }
        $html = $parsed['choices'][0]['message']['content'] ?? '';
        return trim($html) !== '' ? $html : new WP_Error('ai_empty', $label . ' returned no text.');
    }

    /* ──────────────────────────── GEMINI ──────────────────────────── */

    private function call_gemini($system, $user, $max_tokens) {
        $key = $this->resolve_secret('gemini');
        if (empty($key)) {
            return new WP_Error('missing_api_key', 'Google Gemini API key is not set.');
        }
        $model = class_exists('Lunara_Dispatch_Control_Plane_Client') ? Lunara_Dispatch_Control_Plane_Client::model_for_provider('gemini', 'gemini-2.5-pro') : sanitize_text_field(get_option('lunara_dispatch_gemini_model', 'gemini-2.5-pro'));

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent';

        $response = wp_safe_remote_post($endpoint, array(
            'timeout' => 120,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $key,
            ),
            'body' => wp_json_encode(array(
                'systemInstruction' => array(
                    'parts' => array(array('text' => $system)),
                ),
                'contents' => array(array(
                    'role'  => 'user',
                    'parts' => array(array('text' => $user)),
                )),
                'generationConfig' => array(
                    'maxOutputTokens' => $max_tokens,
                    'temperature'     => 0.7,
                ),
            )),
        ));

        if (is_wp_error($response)) { return $response; }
        $status = (int) wp_remote_retrieve_response_code($response);
        $parsed = json_decode(wp_remote_retrieve_body($response), true);
        if ($status !== 200) {
            $msg = $parsed['error']['message'] ?? ('HTTP ' . $status);
            return new WP_Error('gemini_api_error', 'Gemini error: ' . $msg);
        }
        $html = '';
        if (!empty($parsed['candidates'][0]['content']['parts'])) {
            foreach ($parsed['candidates'][0]['content']['parts'] as $part) {
                if (isset($part['text'])) { $html .= $part['text']; }
            }
        }
        return trim($html) !== '' ? $html : new WP_Error('gemini_empty', 'Gemini returned no text.');
    }

    public static function secret_is_configured($provider) {
        $client = new self();
        return '' !== $client->resolve_secret($provider);
    }

    private function resolve_secret($provider) {
        $provider = sanitize_key((string) $provider);
        $constants = array(
            'claude' => 'LUNARA_DISPATCH_CLAUDE_API_KEY',
            'openai' => 'LUNARA_DISPATCH_OPENAI_API_KEY',
            'gemini' => 'LUNARA_DISPATCH_GEMINI_API_KEY',
            'grok'   => 'LUNARA_DISPATCH_GROK_API_KEY',
        );
        if (empty($constants[$provider])) {
            return '';
        }

        $constant = $constants[$provider];
        if (defined($constant) && is_scalar(constant($constant))) {
            $value = trim((string) constant($constant));
            if ('' !== $value) {
                return $value;
            }
        }

        $environment = getenv($constant);
        if (is_string($environment) && '' !== trim($environment)) {
            return trim($environment);
        }

        return trim((string) get_option('lunara_dispatch_' . $provider . '_key', ''));
    }

    private function limit_text($text, $max_chars) {
        $max_chars = max(1, (int) $max_chars);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $max_chars ? mb_substr($text, 0, $max_chars) : $text;
        }
        return strlen($text) > $max_chars ? substr($text, 0, $max_chars) : $text;
    }
}
