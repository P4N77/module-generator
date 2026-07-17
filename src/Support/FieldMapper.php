<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

/**
 * Convierte las columnas de un CREATE TABLE en los campos de negocio "extra" que
 * alimentan todas las capas del módulo. Descarta:
 *
 *   - Las columnas del esqueleto estándar (id, uuid, code, description, status,
 *     auditoría y timestamps): ya las genera el propio módulo.
 *   - Las foráneas declaradas con FOREIGN KEY: quedan para una pasada posterior.
 *   - Los tipos aún no soportados (enteros, datetime, boolean, enum, json...): se
 *     excluyen por completo para que las capas queden consistentes entre sí.
 */
final class FieldMapper
{
    /** Columnas del esqueleto estándar, que nunca son campos "extra". */
    private const RESERVED = [
        'id', 'uuid', 'code', 'description', 'status',
        'created_by', 'updated_by', 'created_at', 'updated_at', 'deleted_at',
    ];

    /**
     * @return list<ModuleField> En el orden en que aparecen en la tabla.
     */
    public static function map(TableDefinition $table): array
    {
        $foreignKeys = self::foreignKeyColumns($table->constraints);

        $fields = [];
        foreach ($table->columns as $column) {
            $name = mb_strtolower($column->name);

            if (in_array($name, self::RESERVED, true) || in_array($name, $foreignKeys, true)) {
                continue;
            }

            $field = self::toField($name, $column->definition);
            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Nombres de columna referenciados por una cláusula FOREIGN KEY.
     *
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private static function foreignKeyColumns(array $constraints): array
    {
        $columns = [];

        foreach ($constraints as $constraint) {
            if (preg_match('/\bFOREIGN\s+KEY\s*\(([^)]*)\)/i', $constraint, $matches) !== 1) {
                continue;
            }

            foreach (explode(',', $matches[1]) as $raw) {
                $clean = mb_strtolower(trim($raw, " \t\n\r`\"[]'"));
                if ($clean !== '') {
                    $columns[] = $clean;
                }
            }
        }

        return $columns;
    }

    /**
     * Interpreta la definición cruda de una columna. Devuelve null si el tipo no
     * está soportado en esta pasada.
     */
    private static function toField(string $name, string $definition): ?ModuleField
    {
        if (preg_match('/^\s*([A-Za-z0-9_]+)/', $definition, $m) !== 1) {
            return null;
        }

        $sqlType = mb_strtolower($m[1]);
        $params = self::typeParams($definition);
        $nullable = preg_match('/\bNOT\s+NULL\b/i', $definition) !== 1;

        return match (true) {
            in_array($sqlType, ['char', 'varchar', 'nvarchar', 'varchar2', 'nchar', 'character'], true)
                => new ModuleField($name, 'string', $nullable, length: $params[0] ?? null),

            in_array($sqlType, ['text', 'tinytext', 'mediumtext', 'longtext', 'ntext', 'clob'], true)
                => new ModuleField($name, 'text', $nullable),

            $sqlType === 'date'
                => new ModuleField($name, 'date', $nullable),

            in_array($sqlType, ['decimal', 'numeric', 'dec', 'float', 'double', 'real', 'money'], true)
                => new ModuleField($name, 'decimal', $nullable, decimalScale: $params[1] ?? 2),

            default => null,
        };
    }

    /**
     * Números entre los primeros paréntesis del tipo. Ej: decimal(10,2) -> [10, 2].
     *
     * @return list<int>
     */
    private static function typeParams(string $definition): array
    {
        if (preg_match('/^\s*[A-Za-z0-9_]+\s*\(([^)]*)\)/', $definition, $m) !== 1) {
            return [];
        }

        $params = [];
        foreach (explode(',', $m[1]) as $part) {
            $part = trim($part);
            if (is_numeric($part)) {
                $params[] = (int) $part;
            }
        }

        return $params;
    }
}
