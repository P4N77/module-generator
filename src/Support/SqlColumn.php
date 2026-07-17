<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

/**
 * Una columna tal como viene en el CREATE TABLE, sin interpretar todavía.
 */
final class SqlColumn
{
    public function __construct(
        /** Nombre sin comillas. Ej: created_at */
        public readonly string $name,
        /** Resto de la definición. Ej: "timestamp NULL DEFAULT NULL" */
        public readonly string $definition,
    ) {}
}
