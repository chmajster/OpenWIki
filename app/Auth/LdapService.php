<?php

declare(strict_types=1);

namespace OpenWiki\Auth;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use OpenWiki\Security\SecretCipher;

final class LdapService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function enabled(): bool
    {
        return filter_var($this->setting('ldap.enabled'), FILTER_VALIDATE_BOOL);
    }

    public function displayConfig(): array
    {
        $mapping = json_decode((string) ($this->setting('ldap.group_mapping') ?? '{}'), true);
        if (!is_array($mapping)) {
            $mapping = [];
        }

        $lines = [];
        foreach ($mapping as $group => $role) {
            $lines[] = $group . ' => ' . $role;
        }

        return [
            'enabled' => $this->enabled(),
            'host' => $this->setting('ldap.host') ?? '',
            'port' => (int) ($this->setting('ldap.port') ?? 389),
            'security' => $this->setting('ldap.security') ?? 'ldap',
            'base_dn' => $this->setting('ldap.base_dn') ?? '',
            'bind_dn' => $this->setting('ldap.bind_dn') ?? '',
            'bind_password_set' => ($this->setting('ldap.bind_password') ?? '') !== '',
            'user_dn' => $this->setting('ldap.user_dn') ?? '',
            'group_dn' => $this->setting('ldap.group_dn') ?? '',
            'username_attribute' => $this->setting('ldap.username_attribute') ?? 'uid',
            'mail_attribute' => $this->setting('ldap.mail_attribute') ?? 'mail',
            'first_name_attribute' => $this->setting('ldap.first_name_attribute') ?? 'givenName',
            'last_name_attribute' => $this->setting('ldap.last_name_attribute') ?? 'sn',
            'group_attribute' => $this->setting('ldap.group_attribute') ?? 'memberOf',
            'group_mapping' => implode(PHP_EOL, $lines),
        ];
    }

    public function saveConfig(array $input, int $actorId): void
    {
        $config = $this->validatedConfig($input);
        $currentSecret = $this->storedBindPassword();
        $submittedSecret = (string) ($input['bind_password'] ?? '');

        if ($config['bind_dn'] !== '' && $submittedSecret === '' && $currentSecret === '') {
            throw new \InvalidArgumentException('Bind password is required when Bind DN is configured.');
        }

        $values = [
            'ldap.enabled' => $config['enabled'] ? 'true' : 'false',
            'ldap.host' => $config['host'],
            'ldap.port' => (string) $config['port'],
            'ldap.security' => $config['security'],
            'ldap.base_dn' => $config['base_dn'],
            'ldap.bind_dn' => $config['bind_dn'],
            'ldap.user_dn' => $config['user_dn'],
            'ldap.group_dn' => $config['group_dn'],
            'ldap.username_attribute' => $config['username_attribute'],
            'ldap.mail_attribute' => $config['mail_attribute'],
            'ldap.first_name_attribute' => $config['first_name_attribute'],
            'ldap.last_name_attribute' => $config['last_name_attribute'],
            'ldap.group_attribute' => $config['group_attribute'],
            'ldap.group_mapping' => json_encode($config['group_mapping'], JSON_THROW_ON_ERROR),
        ];

        $this->database->transaction(function (Database $db) use ($values, $actorId, $submittedSecret): void {
            foreach ($values as $key => $value) {
                $this->saveSetting($key, $value, false, $actorId);
            }

            if ($submittedSecret !== '') {
                if (strlen($submittedSecret) > 2048) {
                    throw new \InvalidArgumentException('Bind password is too long.');
                }
                $this->saveSetting(
                    'ldap.bind_password',
                    $this->cipher()->encrypt($submittedSecret),
                    true,
                    $actorId
                );
            }
        });
    }

    public function testConfig(array $input): array
    {
        $config = $this->validatedConfig($input);
        $password = (string) ($input['bind_password'] ?? '');
        if ($password === '') {
            $password = $this->storedBindPassword();
        }

        $connection = $this->connect($config);
        $this->serviceBind($connection, $config, $password);

        $result = @ldap_search(
            $connection,
            $config['base_dn'],
            '(objectClass=*)',
            ['dn'],
            0,
            1
        );
        if ($result === false) {
            throw new \RuntimeException('LDAP bind succeeded but Base DN cannot be searched.');
        }

        return [
            'ok' => true,
            'message' => 'LDAP connection, bind and Base DN search succeeded.',
        ];
    }

    public function authenticate(string $identifier, string $password): ?array
    {
        if (!$this->enabled() || $password === '') {
            return null;
        }

        $config = $this->runtimeConfig();
        $connection = $this->connect($config);
        $this->serviceBind($connection, $config, $this->storedBindPassword());

        $escaped = ldap_escape(trim($identifier), '', LDAP_ESCAPE_FILTER);
        $filter = '(|(' . $config['username_attribute'] . '=' . $escaped . ')('
            . $config['mail_attribute'] . '=' . $escaped . '))';

        $attributes = array_values(array_unique([
            $config['username_attribute'],
            $config['mail_attribute'],
            $config['first_name_attribute'],
            $config['last_name_attribute'],
            $config['group_attribute'],
        ]));

        $searchBase = $config['user_dn'] !== '' ? $config['user_dn'] : $config['base_dn'];
        $result = @ldap_search($connection, $searchBase, $filter, $attributes, 0, 2);
        if ($result === false || ldap_count_entries($connection, $result) !== 1) {
            return null;
        }

        $entries = ldap_get_entries($connection, $result);
        if (!is_array($entries) || !isset($entries[0]['dn'])) {
            return null;
        }

        $entry = $entries[0];
        $userDn = (string) $entry['dn'];

        if (!@ldap_bind($connection, $userDn, $password)) {
            return null;
        }

        $username = trim($this->firstAttribute($entry, $config['username_attribute']));
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            throw new \RuntimeException('LDAP username attribute cannot be represented as a local username.');
        }

        $email = mb_strtolower(trim($this->firstAttribute($entry, $config['mail_attribute'])));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            $email = mb_strtolower($username) . '@ldap.invalid';
        }

        $groups = $this->attributeValues($entry, $config['group_attribute']);
        if ($groups === [] && $config['group_dn'] !== '') {
            $groups = $this->searchGroups($connection, $config['group_dn'], $userDn);
        }

        return [
            'dn' => $userDn,
            'username' => $username,
            'email' => $email,
            'first_name' => $this->cleanName($this->firstAttribute($entry, $config['first_name_attribute'])),
            'last_name' => $this->cleanName($this->firstAttribute($entry, $config['last_name_attribute'])),
            'groups' => array_values(array_unique($groups)),
            'group_mapping' => $config['group_mapping'],
        ];
    }

    public function provision(array $profile): int
    {
        $username = (string) $profile['username'];
        $email = (string) $profile['email'];

        $existing = $this->database->fetchOne(
            'SELECT id, auth_source
             FROM users
             WHERE deleted_at IS NULL
               AND (LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email))
             LIMIT 1',
            ['username' => $username, 'email' => $email]
        );

        if ($existing !== null && $existing['auth_source'] !== 'ldap') {
            throw new \RuntimeException('LDAP identity conflicts with an existing local account.');
        }

        return $this->database->transaction(function (Database $db) use ($profile, $existing): int {
            if ($existing === null) {
                $userId = $db->insert(
                    'INSERT INTO users
                     (username, email, password_hash, first_name, last_name, status, auth_source,
                      force_password_change, created_at, updated_at)
                     VALUES
                     (:username, :email, NULL, :first_name, :last_name, "active", "ldap", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [
                        'username' => $profile['username'],
                        'email' => $profile['email'],
                        'first_name' => $profile['first_name'],
                        'last_name' => $profile['last_name'],
                    ]
                );
            } else {
                $userId = (int) $existing['id'];
                $db->execute(
                    'UPDATE users
                     SET username = :username, email = :email, first_name = :first_name, last_name = :last_name,
                         status = "active", updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND auth_source = "ldap" AND deleted_at IS NULL',
                    [
                        'id' => $userId,
                        'username' => $profile['username'],
                        'email' => $profile['email'],
                        'first_name' => $profile['first_name'],
                        'last_name' => $profile['last_name'],
                    ]
                );
            }

            $this->syncMappedAccess(
                $userId,
                (array) ($profile['groups'] ?? []),
                (array) ($profile['group_mapping'] ?? [])
            );

            return $userId;
        });
    }

    private function syncMappedAccess(int $userId, array $groups, array $mapping): void
    {
        $identifiers = [];
        foreach ($groups as $group) {
            $group = trim((string) $group);
            if ($group === '') {
                continue;
            }
            $identifiers[mb_strtolower($group)] = $group;
            $commonName = $this->commonName($group);
            if ($commonName !== null) {
                $identifiers[mb_strtolower($commonName)] = $group;
            }
        }

        $activeRoles = [];
        $activeGroups = [];
        foreach ($mapping as $externalGroup => $roleSlug) {
            $key = mb_strtolower(trim((string) $externalGroup));
            if (!isset($identifiers[$key])) {
                continue;
            }

            $role = $this->database->fetchOne(
                'SELECT id FROM roles WHERE slug = :slug LIMIT 1',
                ['slug' => (string) $roleSlug]
            );
            if ($role === null) {
                continue;
            }

            $roleId = (int) $role['id'];
            $activeRoles[$roleId] = $identifiers[$key];
            $activeGroups[$identifiers[$key]] = true;
        }

        $tracked = $this->database->fetchAll(
            'SELECT role_id FROM ldap_user_roles WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        foreach ($tracked as $row) {
            $roleId = (int) $row['role_id'];
            if (isset($activeRoles[$roleId])) {
                continue;
            }

            $this->database->execute(
                'DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
            $this->database->execute(
                'DELETE FROM ldap_user_roles WHERE user_id = :user_id AND role_id = :role_id',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
        }

        foreach ($activeRoles as $roleId => $externalGroup) {
            $trackedRole = $this->database->fetchOne(
                'SELECT 1 AS found FROM ldap_user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
            if ($trackedRole !== null) {
                continue;
            }

            $existingRole = $this->database->fetchOne(
                'SELECT 1 AS found FROM user_roles WHERE user_id = :user_id AND role_id = :role_id LIMIT 1',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
            if ($existingRole === null) {
                $this->database->execute(
                    'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
                    ['user_id' => $userId, 'role_id' => $roleId]
                );
                $this->database->execute(
                    'INSERT INTO ldap_user_roles (user_id, role_id, ldap_group, created_at)
                     VALUES (:user_id, :role_id, :ldap_group, UTC_TIMESTAMP())',
                    ['user_id' => $userId, 'role_id' => $roleId, 'ldap_group' => $externalGroup]
                );
            }
        }

        $this->database->execute(
            'DELETE gu
             FROM group_users gu
             INNER JOIN user_groups g ON g.id = gu.group_id
             WHERE gu.user_id = :user_id AND g.source = "ldap"',
            ['user_id' => $userId]
        );

        foreach (array_keys($activeGroups) as $externalGroup) {
            $group = $this->database->fetchOne(
                'SELECT id FROM user_groups WHERE source = "ldap" AND external_id = :external_id LIMIT 1',
                ['external_id' => $externalGroup]
            );
            if ($group === null) {
                $name = $this->commonName($externalGroup) ?? mb_substr($externalGroup, 0, 150);
                $groupId = $this->database->insert(
                    'INSERT INTO user_groups
                     (name, slug, description, source, external_id, created_at, updated_at)
                     VALUES (:name, :slug, NULL, "ldap", :external_id, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    [
                        'name' => mb_substr($name, 0, 150),
                        'slug' => 'ldap-' . substr(hash('sha256', mb_strtolower($externalGroup)), 0, 24),
                        'external_id' => $externalGroup,
                    ]
                );
            } else {
                $groupId = (int) $group['id'];
            }

            $this->database->execute(
                'INSERT IGNORE INTO group_users (group_id, user_id) VALUES (:group_id, :user_id)',
                ['group_id' => $groupId, 'user_id' => $userId]
            );
        }
    }

    private function runtimeConfig(): array
    {
        $display = $this->displayConfig();
        $display['group_mapping'] = $this->mappingFromSetting();
        return $display;
    }

    private function validatedConfig(array $input): array
    {
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $host = trim((string) ($input['host'] ?? ''));
        if ($host === '' || mb_strlen($host) > 255 || preg_match('/[\s\/\\\\]/', $host)) {
            throw new \InvalidArgumentException('LDAP host is invalid.');
        }

        $port = filter_var($input['port'] ?? 389, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($port === false) {
            throw new \InvalidArgumentException('LDAP port is invalid.');
        }

        $security = strtolower((string) ($input['security'] ?? 'ldap'));
        if (!in_array($security, ['ldap', 'ldaps'], true)) {
            throw new \InvalidArgumentException('LDAP security mode is invalid.');
        }

        $baseDn = trim((string) ($input['base_dn'] ?? ''));
        if ($baseDn === '' || mb_strlen($baseDn) > 1000) {
            throw new \InvalidArgumentException('Base DN is required.');
        }

        $attributes = [];
        foreach ([
            'username_attribute' => 'uid',
            'mail_attribute' => 'mail',
            'first_name_attribute' => 'givenName',
            'last_name_attribute' => 'sn',
            'group_attribute' => 'memberOf',
        ] as $key => $default) {
            $value = trim((string) ($input[$key] ?? $default));
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $value)) {
                throw new \InvalidArgumentException('Invalid LDAP attribute: ' . $key);
            }
            $attributes[$key] = $value;
        }

        return [
            'enabled' => $enabled,
            'host' => $host,
            'port' => (int) $port,
            'security' => $security,
            'base_dn' => $baseDn,
            'bind_dn' => mb_substr(trim((string) ($input['bind_dn'] ?? '')), 0, 1000),
            'user_dn' => mb_substr(trim((string) ($input['user_dn'] ?? '')), 0, 1000),
            'group_dn' => mb_substr(trim((string) ($input['group_dn'] ?? '')), 0, 1000),
            ...$attributes,
            'group_mapping' => $this->parseMapping((string) ($input['group_mapping'] ?? '')),
        ];
    }

    private function parseMapping(string $input): array
    {
        $mapping = [];
        foreach (preg_split('/\R/', trim($input)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = explode('=>', $line, 2);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException('Group mapping lines must use: LDAP group => role-slug.');
            }

            $group = trim($parts[0]);
            $roleSlug = trim($parts[1]);
            if ($group === '' || $roleSlug === '') {
                throw new \InvalidArgumentException('LDAP group and role slug are required in group mapping.');
            }

            $role = $this->database->fetchOne(
                'SELECT id FROM roles WHERE slug = :slug LIMIT 1',
                ['slug' => $roleSlug]
            );
            if ($role === null) {
                throw new \InvalidArgumentException('Unknown role in LDAP mapping: ' . $roleSlug);
            }

            $mapping[$group] = $roleSlug;
        }

        return $mapping;
    }

    private function connect(array $config)
    {
        if (!function_exists('ldap_connect')) {
            throw new \RuntimeException('PHP LDAP extension is not available.');
        }

        $host = (string) $config['host'];
        $hostForUri = str_contains($host, ':') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $host . ']'
            : $host;
        $uri = ($config['security'] === 'ldaps' ? 'ldaps' : 'ldap')
            . '://' . $hostForUri . ':' . (int) $config['port'];

        $connection = @ldap_connect($uri);
        if ($connection === false) {
            throw new \RuntimeException('Unable to initialize LDAP connection.');
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
        if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);
        }

        return $connection;
    }

    private function serviceBind($connection, array $config, string $password): void
    {
        $ok = $config['bind_dn'] === ''
            ? @ldap_bind($connection)
            : @ldap_bind($connection, $config['bind_dn'], $password);

        if (!$ok) {
            throw new \RuntimeException('LDAP bind failed.');
        }
    }

    private function searchGroups($connection, string $groupDn, string $userDn): array
    {
        $escapedDn = ldap_escape($userDn, '', LDAP_ESCAPE_FILTER);
        $filter = '(|(member=' . $escapedDn . ')(uniqueMember=' . $escapedDn . '))';
        $result = @ldap_search($connection, $groupDn, $filter, ['dn', 'cn']);
        if ($result === false) {
            return [];
        }

        $entries = ldap_get_entries($connection, $result);
        if (!is_array($entries)) {
            return [];
        }

        $groups = [];
        for ($i = 0; $i < (int) ($entries['count'] ?? 0); $i++) {
            if (isset($entries[$i]['dn'])) {
                $groups[] = (string) $entries[$i]['dn'];
            }
        }

        return $groups;
    }

    private function firstAttribute(array $entry, string $attribute): string
    {
        $key = mb_strtolower($attribute);
        return isset($entry[$key][0]) ? (string) $entry[$key][0] : '';
    }

    private function attributeValues(array $entry, string $attribute): array
    {
        $key = mb_strtolower($attribute);
        if (!isset($entry[$key]) || !is_array($entry[$key])) {
            return [];
        }

        $values = [];
        $count = (int) ($entry[$key]['count'] ?? 0);
        for ($i = 0; $i < $count; $i++) {
            $value = trim((string) ($entry[$key][$i] ?? ''));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function cleanName(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : mb_substr($value, 0, 100);
    }

    private function commonName(string $dn): ?string
    {
        if (preg_match('/(?:^|,)CN=([^,]+)/i', $dn, $match) !== 1) {
            return null;
        }

        return trim(str_replace(['\\,', '\\='], [',', '='], $match[1]));
    }

    private function storedBindPassword(): string
    {
        $stored = $this->setting('ldap.bind_password');
        if ($stored === null || $stored === '') {
            return '';
        }

        return $this->cipher()->decrypt($stored);
    }

    private function mappingFromSetting(): array
    {
        $mapping = json_decode((string) ($this->setting('ldap.group_mapping') ?? '{}'), true);
        return is_array($mapping) ? $mapping : [];
    }

    private function setting(string $key): ?string
    {
        $row = $this->database->fetchOne(
            'SELECT setting_value FROM settings WHERE setting_key = :setting_key LIMIT 1',
            ['setting_key' => $key]
        );

        return $row === null ? null : (string) $row['setting_value'];
    }

    private function saveSetting(string $key, string $value, bool $secret, int $actorId): void
    {
        $this->database->execute(
            'INSERT INTO settings
             (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES (:setting_key, :setting_value, :is_secret, :updated_by, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                is_secret = VALUES(is_secret),
                updated_by = VALUES(updated_by),
                updated_at = UTC_TIMESTAMP()',
            [
                'setting_key' => $key,
                'setting_value' => $value,
                'is_secret' => $secret ? 1 : 0,
                'updated_by' => $actorId,
            ]
        );
    }

    private function cipher(): SecretCipher
    {
        return new SecretCipher((string) Env::get('APP_KEY', ''));
    }
}
