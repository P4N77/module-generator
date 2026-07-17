<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Support\Facades\File;

/**
 * Escribe los archivos generados y lleva el registro para el resumen final.
 * Se anota una etiqueta legible (no la ruta absoluta) porque es lo que se
 * muestra al usuario al terminar.
 */
final class ModuleWriter
{
    /** @var list<string> */
    private array $created = [];

    public function put(string $absolutePath, string $contents, string $label): void
    {
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $contents);
        $this->created[] = $label;
    }

    /** Anota algo generado que no pasó por put() (ej. una carpeta). */
    public function record(string $label): void
    {
        $this->created[] = $label;
    }

    /** @return list<string> */
    public function created(): array
    {
        return $this->created;
    }

    public function count(): int
    {
        return count($this->created);
    }
}
