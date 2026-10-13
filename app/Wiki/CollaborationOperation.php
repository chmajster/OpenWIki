<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;
use OpenWiki\Core\Database;

final class CollaborationOperation
{
    public function __construct(private readonly Database $db) {}

    public function run(int $user, string $kind, array $input, callable $write, callable $read): array
    {
        $operation = $input['operation_id'] ?? null;
        if ($operation !== null && (!is_string($operation) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $operation))) throw new \InvalidArgumentException('Nieprawidłowy identyfikator operacji.');
        $hash = hash('sha256', json_encode([$kind, $input], JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use ($user, $kind, $input, $operation, $hash, $write, $read): array {
            $this->db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
            $receipt = $operation === null ? null : $this->db->fetchOne('SELECT * FROM collaboration_operations WHERE user_id = ? AND operation_id = ?', [$user, $operation]);
            if ($receipt !== null) {
                if ($receipt['kind'] !== $kind || $receipt['input_hash'] !== $hash) throw new CollaborationConflict('Identyfikator operacji został użyty dla innych danych.');
                return $read((int) $receipt['resource_id']) + ['replayed' => true, 'operation_version' => (int) $receipt['version']];
            }
            $result = $write();
            if ($operation !== null) $this->db->execute('INSERT INTO collaboration_operations (user_id, operation_id, kind, input_hash, resource_id, version) VALUES (?, ?, ?, ?, ?, ?)', [$user, $operation, $kind, $hash, $result['id'], $result['version']]);
            return $result;
        });
    }
}
