<?php

declare (strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
final class CollaborationOperation
{
    public function __construct(private readonly Database $db)
    {
    }
    public function run(int $user, string $kind, array $input, callable $write, callable $read): array
    {
        $operation = $input['operation_id'] ?? null;
        if ($operation !== null && (!is_string($operation) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $operation))) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator operacji.');
        }
        if ($operation !== null) {
            $operation = strtolower($operation);
            $input['operation_id'] = $operation;
        }
        $hash = hash('sha256', json_encode([$kind, self::canonical($input)], JSON_THROW_ON_ERROR));
        $ownsTransaction = !$this->db->pdo()->inTransaction();
        $perform = fn() => $this->db->transaction(function () use ($user, $kind, $input, $operation, $hash, $write, $read): array {
            $this->db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$user]);
            $receipt = $operation === null ? null : $this->db->fetchOne('SELECT * FROM collaboration_operations WHERE user_id = ? AND operation_id = ?', [$user, $operation]);
            if ($receipt !== null) {
                if ($receipt['kind'] !== $kind || $receipt['input_hash'] !== $hash) {
                    throw new CollaborationConflict('Identyfikator operacji został użyty dla innych danych.');
                }
                return $read((int) $receipt['resource_id']) + ['replayed' => true, 'operation_version' => (int) $receipt['version']];
            }
            $result = $write();
            if ($operation !== null) {
                $this->db->execute('INSERT INTO collaboration_operations (user_id, operation_id, kind, input_hash, resource_id, version) VALUES (?, ?, ?, ?, ?, ?)', [$user, $operation, $kind, $hash, $result['id'], $result['version']]);
            }
            return $result;
        });
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                return $perform();
            } catch (\PDOException $error) {
                if (!$ownsTransaction || !in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    throw $error;
                }
                if ($attempt === 3) {
                    throw new CollaborationConflict('Operacja napotkała równoległą zmianę. Ponów zapis; dane formularza są zachowane.', 0, $error);
                }
                usleep(random_int(10000, 50000));
            }
        }
        throw new \LogicException('Operation retry limit was not reached.');
    }
    private static function canonical(array $input): array
    {
        if (!array_is_list($input)) {
            ksort($input);
        }
        foreach ($input as &$value) {
            if (is_array($value)) {
                $value = self::canonical($value);
            }
        }
        return $input;
    }
}
