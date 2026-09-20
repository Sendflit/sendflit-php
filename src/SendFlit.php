<?php
/**
 * SendFlit PHP SDK — zero dependencies (cURL + JSON only), PHP 7.4+.
 * Transactional email API for applications and AI agents.
 *
 *   $sf = new SendFlit('re_...');
 *   $sf->send(['from' => 'you@yourdomain.com', 'to' => 'user@example.com',
 *              'subject' => 'Confirm', 'html' => '<p>…</p>']);
 */

class SendFlit
{
    const DEFAULT_BASE = 'https://api.sendflit.com';
    const VERSION = '1.0.0';

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    public function __construct(string $apiKey, string $baseUrl = self::DEFAULT_BASE,
                                int $timeout = 15, int $maxRetries = 2)
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('SendFlit: apiKey is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    /** Send a transactional email. Attachments: [['filename'=>, 'content'=>string, 'content_type'=>]] */
    public function send(array $email): array
    {
        return $this->request('POST', '/v1/email', $this->normalize($email));
    }

    /** Send up to 100 emails in one call. */
    public function batch(array $emails): array
    {
        if (count($emails) === 0) {
            throw new InvalidArgumentException('batch() expects a non-empty array');
        }
        if (count($emails) > 100) {
            throw new RangeException('batch() accepts at most 100 emails');
        }
        return $this->request('POST', '/v1/emails/batch',
            ['emails' => array_map([$this, 'normalize'], $emails)]);
    }

    public function listEmails(int $limit = null, int $offset = null, string $status = null): array
    {
        $q = array_filter(['limit' => $limit, 'offset' => $offset, 'status' => $status],
            fn($v) => $v !== null);
        return $this->request('GET', '/v1/emails' . ($q ? '?' . http_build_query($q) : ''));
    }

    public function getEmail(string $id): array
    {
        return $this->request('GET', "/v1/emails/{$id}");
    }

    public function cancelEmail(string $id): array
    {
        return $this->request('DELETE', "/v1/emails/{$id}");
    }

    public function addDomain(string $name): array
    {
        return $this->request('POST', '/v1/domains', ['name' => $name]);
    }

    public function listDomains(): array
    {
        return $this->request('GET', '/v1/domains');
    }

    public function verifyDomain(string $id): array
    {
        return $this->request('POST', "/v1/domains/{$id}/verify");
    }

    public function createTemplate(string $name, string $subject, string $html = '',
                                   string $text = ''): array
    {
        return $this->request('POST', '/v1/templates',
            ['name' => $name, 'subject' => $subject, 'html' => $html, 'text' => $text]);
    }

    public function listTemplates(): array
    {
        return $this->request('GET', '/v1/templates');
    }

    public function addContact(string $email, string $name = '', string $audience = 'default',
                               array $data = null): array
    {
        $payload = ['email' => $email, 'name' => $name, 'audience' => $audience];
        if ($data !== null) {
            $payload['data'] = $data;
        }
        return $this->request('POST', '/v1/contacts', $payload);
    }

    public function listContacts(int $limit = null, int $offset = null, string $audience = null): array
    {
        $q = array_filter(['limit' => $limit, 'offset' => $offset, 'audience' => $audience],
            fn($v) => $v !== null);
        return $this->request('GET', '/v1/contacts' . ($q ? '?' . http_build_query($q) : ''));
    }

    public function suppress(string $email, string $reason = 'unsubscribe'): array
    {
        return $this->request('POST', '/v1/suppressions', ['email' => $email, 'reason' => $reason]);
    }

    public function usage(): array
    {
        return $this->request('GET', '/v1/usage');
    }

    /** Liveness probe (root-level, not /v1). */
    public function health(): array
    {
        [$body] = $this->http('GET', '/health', null, 0);
        return json_decode($body, true) ?: [];
    }

    /** Verify an X-Sendflit-Signature header against the raw body. */
    public static function verifyWebhookSignature(string $secret, string $rawBody,
                                                  string $signature): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    private function normalize(array $e): array
    {
        if (!empty($e['attachments'])) {
            foreach ($e['attachments'] as &$a) {
                $a = [
                    'filename' => $a['filename'],
                    'content_b64' => base64_encode($a['content']),
                    'content_type' => $a['content_type'] ?? 'application/octet-stream',
                ];
            }
            unset($a);
        }
        return array_filter($e, fn($v) => $v !== null);
    }

    private function request(string $method, string $path, $body): array
    {
        [$respBody, $status, $retryAfter] = $this->http($method, $path, $body, $this->maxRetries);
        return json_decode($respBody, true) ?: [];
    }

    private function http(string $method, string $path, $body, int $retries): array
    {
        $url = $this->baseUrl . ($path[0] === '/' ? $path : '/' . $path);
        $attempt = 0;
        while (true) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_HTTPHEADER => array_filter([
                    'Authorization: Bearer ' . $this->apiKey,
                    'User-Agent: sendflit-php/' . self::VERSION,
                    $body !== null ? 'Content-Type: application/json' : null,
                ]),
                $body !== null ? CURLOPT_POSTFIELDS : null => $body !== null ? json_encode($body) : null,
            ]);
            $respBody = curl_exec($ch);
            if ($respBody === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new SendFlitException("Network error: {$err}", 0, null);
            }
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $retryAfter = 0;
            curl_close($ch);

            $data = json_decode((string)$respBody, true) ?: ['raw' => $respBody];

            if ($status >= 200 && $status < 300) {
                return [$respBody, $status, 0];
            }
            $retryable = $status === 429 || $status >= 500;
            if ($retryable && $attempt < $retries) {
                $attempt++;
                usleep(($retryAfter > 0 ? $retryAfter * 1000000 : 300000 * (2 ** $attempt)) + random_int(0, 150000));
                continue;
            }
            $detail = $data['detail'] ?? null;
            $msg = is_string($detail) ? $detail
                : (is_array($detail) ? implode('; ', array_column($detail, 'msg'))
                : "SendFlit API error ({$status})");
            throw new SendFlitException($msg, $status, $data);
        }
    }
}

class SendFlitException extends Exception
{
    public array $body;

    public function __construct(string $message, int $status = 0, array $body = null)
    {
        parent::__construct($message);
        $this->code = $status;
        $this->body = $body ?? [];
    }
}
