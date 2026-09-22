<?php

declare(strict_types=1);

namespace OpenWiki\Auth;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use OpenWiki\Security\SecretCipher;

final class MfaService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function enabled(int $userId): bool
    {
        return $this->database->fetchOne(
            'SELECT id FROM mfa_factors
             WHERE user_id = :user_id AND type = "totp" AND enabled_at IS NOT NULL
             LIMIT 1',
            ['user_id' => $userId]
        ) !== null;
    }

    public function required(int $userId): bool
    {
        $global = $this->setting('mfa.enforce_global');
        if (filter_var($global, FILTER_VALIDATE_BOOL)) {
            return true;
        }

        $roleIds = json_decode((string) ($this->setting('mfa.enforced_role_ids') ?? '[]'), true);
        if (!is_array($roleIds) || $roleIds === []) {
            return false;
        }

        $roleIds = array_values(array_unique(array_filter(array_map('intval', $roleIds), static fn (int $id): bool => $id > 0)));
        if ($roleIds === []) {
            return false;
        }

        $row = $this->database->fetchOne(
            'SELECT 1 AS found
             FROM user_roles
             WHERE user_id = ?
               AND role_id IN (' . implode(',', array_fill(0, count($roleIds), '?')) . ')
             LIMIT 1',
            array_merge([$userId], $roleIds)
        );

        return $row !== null;
    }

    public function policy(): array
    {
        $roleIds = json_decode((string) ($this->setting('mfa.enforced_role_ids') ?? '[]'), true);

        return [
            'enforce_global' => filter_var($this->setting('mfa.enforce_global'), FILTER_VALIDATE_BOOL),
            'role_ids' => is_array($roleIds) ? array_values(array_map('intval', $roleIds)) : [],
        ];
    }

    public function updatePolicy(bool $global, array $roleIds, int $actorId): void
    {
        $ids = [];
        foreach ($roleIds as $roleId) {
            $id = filter_var($roleId, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new \InvalidArgumentException('Invalid MFA role selection.');
            }
            $ids[(int) $id] = (int) $id;
        }
        $ids = array_values($ids);

        if ($ids !== []) {
            $row = $this->database->fetchOne(
                'SELECT COUNT(*) AS total FROM roles WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
                $ids
            );
            if ((int) ($row['total'] ?? 0) !== count($ids)) {
                throw new \InvalidArgumentException('One or more selected roles do not exist.');
            }
        }

        $this->saveSetting('mfa.enforce_global', $global ? 'true' : 'false', $actorId);
        $this->saveSetting(
            'mfa.enforced_role_ids',
            json_encode($ids, JSON_THROW_ON_ERROR),
            $actorId
        );
    }

    public function enrollment(int $userId, string $username, string $issuer): array
    {
        $factor = $this->database->fetchOne(
            'SELECT id, secret_ciphertext, enabled_at
             FROM mfa_factors
             WHERE user_id = :user_id AND type = "totp"
             LIMIT 1',
            ['user_id' => $userId]
        );

        if ($factor !== null && $factor['enabled_at'] !== null) {
            throw new \InvalidArgumentException('MFA is already enabled.');
        }

        if ($factor === null) {
            $secret = $this->base32Encode(random_bytes(20));
            $this->database->execute(
                'INSERT INTO mfa_factors
                 (user_id, type, secret_ciphertext, enabled_at, created_at)
                 VALUES (:user_id, "totp", :secret, NULL, UTC_TIMESTAMP())',
                [
                    'user_id' => $userId,
                    'secret' => $this->cipher()->encrypt($secret),
                ]
            );
        } else {
            $secret = $this->cipher()->decrypt((string) $factor['secret_ciphertext']);
        }

        $issuer = trim($issuer) === '' ? 'OpenWiki' : trim($issuer);
        $label = rawurlencode($issuer . ':' . $username);
        $uri = 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';

        return ['secret' => $secret, 'otpauth_uri' => $uri];
    }

    public function confirmEnrollment(int $userId, string $code): array
    {
        $factor = $this->database->fetchOne(
            'SELECT id, secret_ciphertext, enabled_at
             FROM mfa_factors
             WHERE user_id = :user_id AND type = "totp"
             LIMIT 1',
            ['user_id' => $userId]
        );
        if ($factor === null) {
            throw new \InvalidArgumentException('MFA enrollment has not been started.');
        }
        if ($factor['enabled_at'] !== null) {
            throw new \InvalidArgumentException('MFA is already enabled.');
        }

        $secret = $this->cipher()->decrypt((string) $factor['secret_ciphertext']);
        if (!$this->verifyTotp($secret, $code)) {
            throw new \InvalidArgumentException('Invalid authentication code.');
        }

        $codes = $this->generateRecoveryCodes();

        $this->database->transaction(function (Database $db) use ($userId, $factor, $codes): void {
            $db->execute(
                'UPDATE mfa_factors SET enabled_at = UTC_TIMESTAMP() WHERE id = :id AND enabled_at IS NULL',
                ['id' => (int) $factor['id']]
            );
            $db->execute(
                'DELETE FROM mfa_recovery_codes WHERE user_id = :user_id',
                ['user_id' => $userId]
            );
            foreach ($codes as $code) {
                $db->execute(
                    'INSERT INTO mfa_recovery_codes
                     (user_id, code_hash, used_at, created_at)
                     VALUES (:user_id, :code_hash, NULL, UTC_TIMESTAMP())',
                    [
                        'user_id' => $userId,
                        'code_hash' => $this->recoveryHash($code),
                    ]
                );
            }
        });

        return $codes;
    }

    public function verifyUserCode(int $userId, string $code): bool
    {
        $factor = $this->database->fetchOne(
            'SELECT secret_ciphertext
             FROM mfa_factors
             WHERE user_id = :user_id AND type = "totp" AND enabled_at IS NOT NULL
             LIMIT 1',
            ['user_id' => $userId]
        );
        if ($factor === null) {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', trim($code)) ?? '';
        if (preg_match('/^\d{6}$/', $normalized)) {
            $secret = $this->cipher()->decrypt((string) $factor['secret_ciphertext']);
            if ($this->verifyTotp($secret, $normalized)) {
                return true;
            }
        }

        $hash = $this->recoveryHash($normalized);
        return $this->database->execute(
            'UPDATE mfa_recovery_codes
             SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND code_hash = :code_hash AND used_at IS NULL',
            ['user_id' => $userId, 'code_hash' => $hash]
        ) === 1;
    }

    public function verifyTotp(string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, 30);

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->totpAtCounter($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        return $this->totpAtCounter($secret, intdiv($timestamp, 30));
    }

    private function totpAtCounter(string $secret, int $counter): string
    {
        if ($counter < 0) {
            return '000000';
        }

        $key = $this->base32Decode($secret);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = (
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(6)));
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        }

        return $codes;
    }

    private function recoveryHash(string $code): string
    {
        $normalized = strtoupper(str_replace(['-', ' '], '', trim($code)));
        return hash('sha256', $normalized);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = strtoupper(str_replace(['=', ' ', '-'], '', $encoded));
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $data = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) < 8) {
                break;
            }
            $data .= chr(bindec($chunk));
        }

        return $data;
    }

    private function cipher(): SecretCipher
    {
        return new SecretCipher((string) Env::get('APP_KEY', ''));
    }

    private function setting(string $key): ?string
    {
        $row = $this->database->fetchOne(
            'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $key]
        );

        return $row === null ? null : (string) $row['setting_value'];
    }

    private function saveSetting(string $key, string $value, int $actorId): void
    {
        $this->database->execute(
            'INSERT INTO settings
             (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES (:setting_key, :setting_value, 0, :updated_by, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = UTC_TIMESTAMP()',
            [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by' => $actorId,
            ]
        );
    }
}
