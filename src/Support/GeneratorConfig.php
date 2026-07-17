<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

/**
 * Lectura de config/module-generator.php y resolución de rutas en disco.
 * Aísla al resto del paquete de los helpers globales de Laravel.
 */
final class GeneratorConfig
{
    public function __construct(
        public readonly string $moduleNamespace,
        public readonly string $sharedContractsNamespace,
        public readonly ?string $connection,
        public readonly string $pagesPath,
        public readonly string $tablePrefix,
        public readonly string $suiteDefaultPath,
    ) {}

    public static function fromConfig(): self
    {
        $connection = config('module-generator.connection', 'tenant');

        return new self(
            moduleNamespace: trim((string) config('module-generator.module_namespace', 'App\\Modules'), '\\'),
            sharedContractsNamespace: trim((string) config('module-generator.shared_contracts_namespace', 'App\\Shared\\Contracts'), '\\'),
            connection: $connection === null ? null : (string) $connection,
            pagesPath: trim((string) config('module-generator.pages_path', 'Pages'), '/'),
            tablePrefix: trim((string) config('module-generator.table_prefix', '')),
            suiteDefaultPath: (string) config('module-generator.suite_default_path', '../Suite'),
        );
    }

    /** app/Modules/EmailTypes */
    public function modulePath(string $plural): string
    {
        return $this->namespaceToPath($this->moduleNamespace)."/{$plural}";
    }

    /** app/Shared/Contracts/EmailTypes */
    public function sharedPath(string $plural): string
    {
        return $this->namespaceToPath($this->sharedContractsNamespace)."/{$plural}";
    }

    /** resources/js/Pages/EmailTypes */
    public function pagesDir(string $plural): string
    {
        return resource_path("js/{$this->pagesPath}/{$plural}");
    }

    /**
     * Convierte un namespace PSR-4 bajo App\ en su ruta de carpeta.
     * Ej: "App\Modules" => app/Modules (asume root App\ => app/).
     */
    private function namespaceToPath(string $namespace): string
    {
        $relative = ltrim(preg_replace('/^App\\\\/', '', $namespace) ?? $namespace, '\\');

        return $relative === ''
            ? app_path()
            : app_path(str_replace('\\', '/', $relative));
    }
}
