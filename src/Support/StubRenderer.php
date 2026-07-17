<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use RuntimeException;

/**
 * Renderiza los stubs de stubs/ sustituyendo placeholders %%nombre%%.
 *
 * Se usa %%nombre%% en lugar de la sintaxis {{ nombre }} de Blade porque las
 * plantillas Vue emplean {{ }} para sus propias interpolaciones y chocarían.
 */
final class StubRenderer
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2).'/stubs');
    }

    /**
     * @param  string  $stub  Ruta relativa sin extensión. Ej: "domain/entity".
     * @param  array<string, string>  $replacements
     *
     * @throws RuntimeException si el stub no existe o queda algún placeholder sin resolver.
     */
    public function render(string $stub, array $replacements = []): string
    {
        $path = "{$this->basePath}/{$stub}.stub";

        if (! is_file($path)) {
            throw new RuntimeException("No se encontró el stub '{$stub}' en {$path}");
        }

        $tokens = [];
        foreach ($replacements as $key => $value) {
            $tokens["%%{$key}%%"] = $value;
        }

        $content = strtr((string) file_get_contents($path), $tokens);

        // Un placeholder sin resolver significa que el generator olvidó pasarlo:
        // mejor fallar aquí que escribir un archivo con "%%algo%%" dentro.
        if (preg_match('/%%([A-Za-z_][A-Za-z0-9_]*)%%/', $content, $matches) === 1) {
            throw new RuntimeException("El stub '{$stub}' dejó sin resolver el placeholder '{$matches[1]}'.");
        }

        return $content;
    }
}
