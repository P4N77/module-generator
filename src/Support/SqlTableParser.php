<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

/**
 * Extrae los bloques CREATE TABLE de un dump SQL.
 *
 * No usa una expresión regular para delimitar el bloque porque el cuerpo lleva
 * paréntesis anidados —decimal(10,2), enum('a','b')— que romperían cualquier
 * intento de buscar el ");" de cierre. En su lugar recorre el texto contando
 * paréntesis y saltando cadenas y comentarios, que es lo único robusto ante los
 * dumps reales del modelador.
 *
 * Acepta la sintaxis de MySQL/MariaDB, PostgreSQL y SQL Server (identificadores
 * con backticks, comillas dobles o corchetes).
 */
final class SqlTableParser
{
    private const CREATE_TABLE = '/\bCREATE\s+(?:OR\s+REPLACE\s+)?(?:(?:GLOBAL|LOCAL)\s+)?(?:TEMPORARY\s+|TEMP\s+|UNLOGGED\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?/i';

    /** Cláusulas que no son columnas sino claves/índices de la tabla. */
    private const CONSTRAINT_START = '/^(?:CONSTRAINT|PRIMARY\s+KEY|UNIQUE(?:\s+(?:KEY|INDEX))?|KEY|INDEX|FOREIGN\s+KEY|CHECK|FULLTEXT|SPATIAL|EXCLUDE|PERIOD|LIKE|INHERITS)\b/i';

    /**
     * @return list<TableDefinition> En el orden en que aparecen en el SQL.
     */
    public static function parse(string $sql): array
    {
        $tables = [];
        $offset = 0;
        $length = strlen($sql);

        while (
            $offset < $length
            && preg_match(self::CREATE_TABLE, $sql, $matches, PREG_OFFSET_CAPTURE, $offset) === 1
        ) {
            $statementStart = (int) $matches[0][1];
            $afterKeyword = $statementStart + strlen((string) $matches[0][0]);

            // Avanzar siempre, pase lo que pase después, para no ciclar.
            $offset = $afterKeyword;

            [$name, $position] = self::readIdentifier($sql, $afterKeyword);
            if ($name === null) {
                continue;
            }

            $position = self::skipTrivia($sql, $position);

            // Sin "(" no hay lista de columnas: es un CREATE TABLE ... AS SELECT
            // o un ... LIKE otra_tabla. No aporta definición de campos.
            if ($position >= $length || $sql[$position] !== '(') {
                continue;
            }

            $closing = self::matchBalanced($sql, $position);
            if ($closing === null) {
                continue;
            }

            $tables[] = self::toDefinition(
                name: $name,
                body: substr($sql, $position + 1, $closing - $position - 1),
                raw: rtrim(substr($sql, $statementStart, $closing - $statementStart + 1)),
            );

            $offset = $closing;
        }

        return $tables;
    }

    /**
     * Busca una tabla por nombre exacto, ignorando mayúsculas.
     *
     * @param  list<TableDefinition>  $tables
     */
    public static function find(array $tables, string $name): ?TableDefinition
    {
        foreach ($tables as $table) {
            if (strcasecmp($table->name, $name) === 0) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Separa el cuerpo en columnas y constraints.
     */
    private static function toDefinition(string $name, string $body, string $raw): TableDefinition
    {
        $columns = [];
        $constraints = [];

        foreach (self::splitTopLevel($body) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (preg_match(self::CONSTRAINT_START, $part) === 1) {
                $constraints[] = $part;
                continue;
            }

            [$columnName, $position] = self::readIdentifier($part, 0);
            if ($columnName === null) {
                $constraints[] = $part;
                continue;
            }

            $columns[] = new SqlColumn($columnName, trim(substr($part, $position)));
        }

        return new TableDefinition($name, $columns, $constraints, $raw);
    }

    /**
     * Lee un identificador, posiblemente cualificado (`db`.`tabla`) y entre
     * backticks, comillas dobles o corchetes. Devuelve el último segmento, que
     * es el nombre de la tabla o columna.
     *
     * @return array{0: string|null, 1: int} [nombre, posición siguiente]
     */
    private static function readIdentifier(string $sql, int $position): array
    {
        $length = strlen($sql);
        $segments = [];
        $position = self::skipTrivia($sql, $position);

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === '`' || $char === '"' || $char === '[') {
                $end = self::findClosingDelimiter($sql, $position, $char === '[' ? ']' : $char);
                if ($end === null) {
                    return [null, $position];
                }
                $segments[] = substr($sql, $position + 1, $end - $position - 1);
                $position = $end + 1;
            } else {
                $start = $position;
                while ($position < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$position]) === 1) {
                    $position++;
                }
                if ($position === $start) {
                    break;
                }
                $segments[] = substr($sql, $start, $position - $start);
            }

            $position = self::skipTrivia($sql, $position);

            // Identificador cualificado: seguir con el siguiente segmento.
            if ($position < $length && $sql[$position] === '.') {
                $position = self::skipTrivia($sql, $position + 1);
                continue;
            }

            break;
        }

        if ($segments === []) {
            return [null, $position];
        }

        return [(string) end($segments), $position];
    }

    /**
     * Separa por comas de primer nivel, ignorando las que van dentro de
     * paréntesis, cadenas o comentarios.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            $skipTo = self::skipCommentOrString($body, $i);
            if ($skipTo !== null) {
                $current .= substr($body, $i, $skipTo - $i + 1);
                $i = $skipTo;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Índice del ")" que cierra el "(" en $open.
     */
    private static function matchBalanced(string $sql, int $open): ?int
    {
        $depth = 0;
        $length = strlen($sql);

        for ($i = $open; $i < $length; $i++) {
            $skipTo = self::skipCommentOrString($sql, $i);
            if ($skipTo !== null) {
                $i = $skipTo;
                continue;
            }

            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Si en $position empieza un comentario o una cadena, devuelve el índice de
     * su último carácter. Si no, null.
     */
    private static function skipCommentOrString(string $sql, int $position): ?int
    {
        $length = strlen($sql);
        $char = $sql[$position];
        $next = $position + 1 < $length ? $sql[$position + 1] : '';

        // "--" solo abre comentario si le sigue un espacio o fin de línea; así
        // no se confunde con operadores.
        if ($char === '-' && $next === '-' && ($position + 2 >= $length || preg_match('/\s/', $sql[$position + 2]) === 1)) {
            return self::skipToLineEnd($sql, $position);
        }

        if ($char === '#') {
            return self::skipToLineEnd($sql, $position);
        }

        if ($char === '/' && $next === '*') {
            $end = strpos($sql, '*/', $position + 2);

            return $end === false ? $length - 1 : $end + 1;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $end = self::findStringEnd($sql, $position);

            return $end ?? $length - 1;
        }

        return null;
    }

    /** Índice del último carácter de la línea (el salto queda fuera). */
    private static function skipToLineEnd(string $sql, int $position): int
    {
        $end = strpos($sql, "\n", $position);

        return $end === false ? strlen($sql) - 1 : $end - 1;
    }

    /**
     * Cierre de una cadena. Contempla el escape con backslash y el comillado
     * doble ('' dentro de '...').
     */
    private static function findStringEnd(string $sql, int $start): ?int
    {
        $quote = $sql[$start];
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === '\\' && $quote !== '`') {
                $i++;
                continue;
            }

            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i++;
                    continue;
                }

                return $i;
            }
        }

        return null;
    }

    /** Cierre de un identificador delimitado (`x`, "x", [x]). */
    private static function findClosingDelimiter(string $sql, int $start, string $closing): ?int
    {
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($sql[$i] === $closing) {
                if ($i + 1 < $length && $sql[$i + 1] === $closing) {
                    $i++;
                    continue;
                }

                return $i;
            }
        }

        return null;
    }

    /** Avanza sobre espacios y comentarios. */
    private static function skipTrivia(string $sql, int $position): int
    {
        $length = strlen($sql);

        while ($position < $length) {
            $char = $sql[$position];

            if (preg_match('/\s/', $char) === 1) {
                $position++;
                continue;
            }

            $next = $position + 1 < $length ? $sql[$position + 1] : '';

            if (($char === '-' && $next === '-') || $char === '#') {
                $position = self::skipToLineEnd($sql, $position) + 1;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $position + 2);
                $position = $end === false ? $length : $end + 2;
                continue;
            }

            break;
        }

        return $position;
    }
}
