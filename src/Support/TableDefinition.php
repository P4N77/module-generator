<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

/**
 * Un bloque CREATE TABLE ya parseado.
 */
final class TableDefinition
{
    /**
     * @param  list<SqlColumn>  $columns
     * @param  list<string>  $constraints  Claves e índices, en crudo.
     */
    public function __construct(
        /** Nombre sin comillas ni esquema. Ej: shd_email_types */
        public readonly string $name,
        public readonly array $columns,
        public readonly array $constraints,
        /** El CREATE TABLE completo, tal cual venía en el SQL. */
        public readonly string $raw,
    ) {}

    public function hasColumn(string $name): bool
    {
        return $this->column($name) !== null;
    }

    public function column(string $name): ?SqlColumn
    {
        foreach ($this->columns as $column) {
            if (strcasecmp($column->name, $name) === 0) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (SqlColumn $c): string => $c->name, $this->columns);
    }
}
