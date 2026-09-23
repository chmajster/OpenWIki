<?php

declare(strict_types=1);

namespace OpenWiki\Webhooks;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use OpenWiki\Security\SecretCipher;

final class WebhookService
{
    private const EVENTS = [
        'page.created',
        'page.updated',
        'page.deleted',
        'page.published',
        'space.created',
        'comment.created',
        'user.created',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function events(): array
    {
        return self::EVENTS;
    }

    public function webhooks(): array
    {
        return $this->database->fetchAll(
            'SELECT id, name, target_url, events_json, status, created_by, created_at, updated_at
             FROM webhooks
             ORDER BY name ASC'
        );
    }

    public function deliveries(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));

        return $this->database->fetchAll(
            'SELECT d.id, d.webhook_id, w.name AS webhook_name, d.event_type, d.attempt_number,
                    d.response_status, d.response_body, d.last_error, d.next_attempt_at,
                    d.delivered_at, d.failed_at, d.created_at
             FROM webhook_deliveries d
             INNER JOIN webhooks w ON w.id = d.webhook_id
             ORDER BY d.id DESC
             LIMIT ' . $limit
        );
    }

    public function create(array $input, int $userId): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 191) {
            throw new \InvalidArgumentException('Webhook name is required and may contain at most 191 characters.');
        }

        $url = trim((string) ($input['target_url'] ?? ''));
        $this->resolveTarget($url);

        $events = $this->validateEvents($input['events'] ?? []);
        $secret = trim((string) ($input['secret'] ?? ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(24));
        }
        if (strlen($secret) < 16 || strlen($secret) > 512) {
            throw new \InvalidArgumentException('Webhook secret must contain 16-512 characters.');
        }

        $id = $this->database->insert(
            'INSERT INTO webhooks
             (name, target_url, secret_ciphertext, events_json, status, created_by, created_at, updated_at)
             VALUES
             (:name, :target_url, :secret_ciphertext, :events_json, "active", :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => $name,
                'target_url' => $url,
                'secret_ciphertext' => $this->cipher()->encrypt($secret),
                'events_json' => json_encode($events, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_by' => $userId,
            ]
        );

        return ['id' => $id, 'secret' => $secret];
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Invalid webhook status.');
        }

        return $this->database->execute(
            'UPDATE webhooks SET status = :status, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['status' => $status, 'id' => $id]
        ) === 1;
    }

    public function delete(int $id): bool
    {
        return $this->database->execute(
            'DELETE FROM webhooks WHERE id = :id',
            ['id' => $id]
        ) === 1;
    }

    public function queue(string $event, array $data): int
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unsupported webhook event: ' . $event);
        }

        $payload = json_encode([
            'event' => $event,
            'occurred_at' => gmdate(DATE_ATOM),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $queued = 0;
        foreach ($this->database->fetchAll(
            'SELECT id, events_json FROM webhooks WHERE status = "active" ORDER BY id ASC'
        ) as $webhook) {
            $events = json_decode((string) $webhook['events_json'], true);
            if (!is_array($events) || !in_array($event, $events, true)) {
                continue;
            }

            $this->database->execute(
                'INSERT INTO webhook_deliveries
                 (webhook_id, event_type, payload_json, attempt_number, response_status, response_body,
                  last_error, next_attempt_at, delivered_at, failed_at, created_at)
                 VALUES
                 (:webhook_id, :event_type, :payload_json, 1, NULL, NULL, NULL, UTC_TIMESTAMP(), NULL, NULL, UTC_TIMESTAMP())',
                [
                    'webhook_id' => (int) $webhook['id'],
                    'event_type' => $event,
                    'payload_json' => $payload,
                ]
            );
            $queued++;
        }

        return $queued;
    }

    public function processDue(int $limit = 25): array
    {
        $limit = max(1, min($limit, 100));
        $rows = $this->database->fetchAll(
            'SELECT d.*, w.target_url, w.secret_ciphertext, w.status AS webhook_status
             FROM webhook_deliveries d
             INNER JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.delivered_at IS NULL
               AND d.failed_at IS NULL
               AND w.status = "active"
               AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= UTC_TIMESTAMP())
             ORDER BY d.id ASC
             LIMIT ' . $limit
        );

        $result = ['processed' => 0, 'delivered' => 0, 'retrying' => 0, 'failed' => 0];

        foreach ($rows as $delivery) {
            $result['processed']++;
            try {
                $response = $this->deliver($delivery);
                $status = $response['status'];
                $success = $status >= 200 && $status < 300;

                if ($success) {
                    $this->database->execute(
                        'UPDATE webhook_deliveries
                         SET response_status = :status, response_body = :body, last_error = NULL,
                             next_attempt_at = NULL, delivered_at = UTC_TIMESTAMP(), failed_at = NULL
                         WHERE id = :id',
                        [
                            'status' => $status,
                            'body' => $response['body'],
                            'id' => (int) $delivery['id'],
                        ]
                    );
                    $result['delivered']++;
                    continue;
                }

                $this->recordFailure($delivery, $status, $response['body'], 'HTTP ' . $status, $result);
            } catch (\Throwable $exception) {
                $this->recordFailure($delivery, null, null, $exception->getMessage(), $result);
            }
        }

        return $result;
    }

    private function recordFailure(
        array $delivery,
        ?int $status,
        ?string $body,
        string $error,
        array &$result
    ): void {
        $attempt = (int) $delivery['attempt_number'];
        if ($attempt >= 5) {
            $this->database->execute(
                'UPDATE webhook_deliveries
                 SET response_status = :status, response_body = :body, last_error = :error,
                     next_attempt_at = NULL, failed_at = UTC_TIMESTAMP()
                 WHERE id = :id',
                [
                    'status' => $status,
                    'body' => $body,
                    'error' => mb_substr($error, 0, 1000),
                    'id' => (int) $delivery['id'],
                ]
            );
            $result['failed']++;
            return;
        }

        $delay = min(3600, 60 * (2 ** max(0, $attempt - 1)));
        $next = gmdate('Y-m-d H:i:s', time() + $delay);

        $this->database->execute(
            'UPDATE webhook_deliveries
             SET attempt_number = :attempt_number, response_status = :status, response_body = :body,
                 last_error = :error, next_attempt_at = :next_attempt_at
             WHERE id = :id',
            [
                'attempt_number' => $attempt + 1,
                'status' => $status,
                'body' => $body,
                'error' => mb_substr($error, 0, 1000),
                'next_attempt_at' => $next,
                'id' => (int) $delivery['id'],
            ]
        );
        $result['retrying']++;
    }

    private function deliver(array $delivery): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP cURL extension is not available.');
        }

        $target = $this->resolveTarget((string) $delivery['target_url']);
        $payload = (string) $delivery['payload_json'];
        $secret = $this->cipher()->decrypt((string) $delivery['secret_ciphertext']);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $curl = curl_init($target['url']);
        if ($curl === false) {
            throw new \RuntimeException('Unable to initialize webhook request.');
        }

        $resolve = $target['host'] . ':' . $target['port'] . ':' . $target['resolved_ip'];

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: OpenWiki-Webhook/1.0',
                'X-OpenWiki-Event: ' . (string) $delivery['event_type'],
                'X-OpenWiki-Delivery: ' . (string) $delivery['id'],
                'X-OpenWiki-Signature: ' . $signature,
            ],
        ]);

        $body = curl_exec($curl);
        if ($body === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('Webhook request failed: ' . $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => mb_substr((string) $body, 0, 16000),
        ];
    }

    private function validateEvents(mixed $value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $events = [];
        foreach ($value as $event) {
            $event = (string) $event;
            if (!in_array($event, self::EVENTS, true)) {
                throw new \InvalidArgumentException('Unsupported webhook event: ' . $event);
            }
            $events[$event] = $event;
        }

        if ($events === []) {
            throw new \InvalidArgumentException('Select at least one webhook event.');
        }

        return array_values($events);
    }

    private function resolveTarget(string $url): array
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Webhook URL is invalid.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Webhook URL is invalid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Webhook URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('Webhook URL cannot contain credentials.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true)) {
            throw new \InvalidArgumentException('Webhook target host is not allowed.');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Webhook target port is invalid.');
        }

        $addresses = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            if (!is_array($records)) {
                $records = [];
            }
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new \InvalidArgumentException('Webhook target cannot be resolved.');
        }

        foreach ($addresses as $address) {
            if (!$this->publicIp($address)) {
                throw new \InvalidArgumentException('Webhook target resolves to a private or reserved address.');
            }
        }

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'resolved_ip' => $addresses[0],
        ];
    }

    private function publicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function cipher(): SecretCipher
    {
        return new SecretCipher((string) Env::get('APP_KEY', ''));
    }
}
