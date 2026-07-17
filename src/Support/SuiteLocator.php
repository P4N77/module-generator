<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Localiza el repositorio Suite, donde viven migraciones y seeders del tenant.
 */
final class SuiteLocator
{
    /** Sentinela: generar la migración dentro del módulo en vez de en Suite. */
    public const LOCAL_TARGET = 'local';

    public function __construct(
        private readonly Command $command,
        private readonly GeneratorConfig $config,
    ) {}

    /**
     * Reintenta ante rutas inválidas (útil en Docker, donde Suite puede estar
     * montado en otra ruta) y permite escribir 'local' para generar la
     * migración dentro del módulo.
     *
     * @return string Ruta canónica de Suite, self::LOCAL_TARGET (modo local) o
     *                null para abortar.
     */
    public function resolve(): ?string
    {
        $default = $this->config->suiteDefaultPath;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $input = trim((string) $this->command->ask(
                "Ruta de Suite (absoluta o relativa al proyecto). Escribe 'local' para generar la migración dentro del módulo",
                $default
            ));

            if (strtolower($input) === self::LOCAL_TARGET) {
                $this->command->line('Modo local: la migración se generará dentro del módulo.');

                return self::LOCAL_TARGET;
            }

            $path = $this->normalize($input);
            if ($path !== null && File::isDirectory("{$path}/database/migrations/tenant")) {
                $this->command->line("Suite localizado en: {$path}");

                return $path;
            }

            $this->command->error("No se encontró 'database/migrations/tenant' en: {$input}");
            $default = $input; // conservar lo escrito para corregirlo
        }

        $this->command->warn('No se pudo localizar Suite. Puedes definir MODULE_GENERATOR_SUITE_PATH en el .env.');

        return $this->command->confirm('¿Generar la migración localmente dentro del módulo?', true)
            ? self::LOCAL_TARGET
            : null;
    }

    /**
     * Lista las carpetas de proyecto existentes bajo $tenantDir y deja elegir
     * una, o "otro" para crear un proyecto nuevo (cuyo nombre se solicita).
     */
    public function chooseProject(string $tenantDir, string $tipo): string
    {
        $projects = collect(File::directories($tenantDir))
            ->map(fn (string $dir): string => basename($dir))
            ->sort()
            ->values()
            ->all();

        $options = array_merge($projects, ['otro']);
        $default = in_array('shared', $projects, true) ? 'shared' : ($options[0] ?? 'otro');

        $choice = $this->command->choice("Seleccione el proyecto de {$tipo}", $options, $default);

        if ($choice === 'otro') {
            $choice = Str::lower(trim((string) $this->command->ask('Nombre del nuevo proyecto')));
        }

        return $choice;
    }

    /**
     * Rutas absolutas se usan tal cual; las relativas se resuelven contra la
     * raíz del proyecto (base_path()).
     */
    private function normalize(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $path = str_starts_with($input, '/') ? $input : base_path($input);
        $real = realpath($path);

        return $real !== false ? $real : null;
    }
}
