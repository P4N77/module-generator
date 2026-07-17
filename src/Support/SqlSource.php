<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Console\Command;
use RuntimeException;

use function Laravel\Prompts\textarea;

/**
 * Obtiene el SQL con la definición de la tabla. Por orden de prioridad:
 *
 *   1. --sql=ruta/al/archivo.sql  (también .txt, o cualquier archivo legible)
 *   2. --sql="CREATE TABLE ..."   (el SQL en línea)
 *   3. Pegado en un textarea, si la consola es interactiva.
 */
final class SqlSource
{
    /** Un valor de --sql más largo que esto no se prueba como ruta. */
    private const MAX_PATH_LENGTH = 4096;

    public function __construct(
        private readonly Command $command,
    ) {}

    /**
     * @param  string|null  $option  Valor de --sql: ruta o SQL en línea.
     * @param  bool  $canPrompt  Si la consola es interactiva. En scripts y CI no
     *                           se pregunta, para no consumir stdin.
     * @return string|null SQL en crudo, o null si no se aportó ninguno.
     *
     * @throws RuntimeException si --sql apunta a algo que no se pudo leer.
     */
    public function capture(?string $option, bool $canPrompt): ?string
    {
        $option = $option !== null ? trim($option) : '';

        if ($option !== '') {
            return $this->fromOption($option);
        }

        return $canPrompt ? $this->fromPaste() : null;
    }

    /**
     * @throws RuntimeException
     */
    private function fromOption(string $value): ?string
    {
        // Primero se prueba como ruta: un archivo llamado "create_table.sql"
        // contiene las palabras CREATE y TABLE y se confundiría con SQL en línea.
        $path = $this->resolvePath($value);

        if ($path !== null) {
            $contents = (string) file_get_contents($path);

            if (trim($contents) === '') {
                throw new RuntimeException("El archivo SQL está vacío: {$path}");
            }

            $this->command->line("  <fg=gray>SQL leído de</> {$path}");

            return $contents;
        }

        if ($this->looksLikeSql($value)) {
            return $value;
        }

        throw new RuntimeException(
            "No se encontró el archivo '{$value}', y como SQL en línea no contiene ningún CREATE TABLE. ".
            'Indica la ruta de un .sql/.txt o pasa el bloque CREATE TABLE.'
        );
    }

    private function fromPaste(): ?string
    {
        $sql = trim(textarea(
            label: 'Pega el SQL del modelador (el CREATE TABLE o el dump completo)',
            placeholder: 'CREATE TABLE shd_email_types ( ... );',
            hint: 'Ctrl+D para terminar · déjalo vacío para generar el módulo básico (solo descripción)',
            rows: 12,
        ));

        return $sql === '' ? null : $sql;
    }

    /** Ruta absoluta, relativa al directorio actual o a la raíz del proyecto. */
    private function resolvePath(string $value): ?string
    {
        // Un SQL pegado no es una ruta: descartarlo antes de tocar el disco.
        if (str_contains($value, "\n") || strlen($value) > self::MAX_PATH_LENGTH) {
            return null;
        }

        $candidates = str_starts_with($value, '/')
            ? [$value]
            : [getcwd().'/'.$value, base_path($value)];

        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real) && is_readable($real)) {
                return $real;
            }
        }

        return null;
    }

    private function looksLikeSql(string $value): bool
    {
        return preg_match('/\bCREATE\b[\s\S]{0,60}?\bTABLE\b/i', $value) === 1;
    }
}
