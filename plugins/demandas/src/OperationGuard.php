<?php
declare(strict_types=1);

namespace GlpiPlugin\Demandas;

use RuntimeException;

/** Cross-session serialization on the primary DB connection, including HTTP calls. */
final class OperationGuard
{
    public static function run(string $resource, callable $operation): mixed
    {
        global $DB;
        $name = 'demandas:' . substr(hash('sha256', $DB->dbdefault . ':' . $resource), 0, 54);
        $statement = $DB->prepare('SELECT GET_LOCK(?, 0)');
        $statement->bind_param('s', $name);
        $statement->execute();
        $acquired = (int) ($statement->get_result()->fetch_row()[0] ?? 0);
        $statement->close();
        if ($acquired !== 1) throw new RuntimeException('Esta operação já está em andamento. Aguarde e atualize a página antes de tentar novamente.');
        try { return $operation(); }
        finally {
            $statement = $DB->prepare('SELECT RELEASE_LOCK(?)');
            $statement->bind_param('s', $name);
            $statement->execute();
            $statement->close();
        }
    }

    public static function inTransaction(): bool
    {
        global $DB;
        // MariaDB exposes the actual session state; GLPI's helper is private.
        $statement=$DB->prepare('SELECT @@session.in_transaction');
        $statement->execute();
        $active=(bool)$statement->get_result()->fetch_row()[0];
        $statement->close();
        return $active;
    }

    public static function transaction(callable $operation): mixed
    {
        global $DB;
        $DB->beginTransaction();
        try {
            $result = $operation();
            $DB->commit();
            return $result;
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    public static function write(bool $result): void
    {
        if (!$result) throw new RuntimeException('Não foi possível concluir a gravação. Nenhuma alteração parcial deve ser reenviada sem conferência.');
    }
}
