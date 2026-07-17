<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Support\Str;

/**
 * Todo lo que define la forma del módulo: naming derivado, configuración del
 * paquete y respuestas del usuario. Se arma una vez en el comando y se pasa a
 * cada generator, que solo lee.
 */
final class ModuleContext
{
    public function __construct(
        /** Namespace raíz de los módulos. Ej: App\Modules */
        public readonly string $ns,
        /** Namespace de los contratos compartidos. Ej: App\Shared\Contracts */
        public readonly string $shared,
        /** Carpeta del módulo en disco. Ej: app/Modules/EmailTypes */
        public readonly string $basePath,
        public readonly string $plural,
        public readonly string $singular,
        public readonly string $moduleCode,
        public readonly string $singularCamel,
        public readonly string $kebab,
        public readonly string $table,
        /** Conexión Eloquent, o null para la de por defecto. */
        public readonly ?string $connection,
        /** Carpeta de páginas Inertia bajo resource_path('js'). */
        public readonly string $pagesPath,
        /** El módulo lleva columna "code" con unicidad. */
        public readonly bool $hasCode,
        /** Módulo interno: guard Sodeker, sin Casbin ni menú. */
        public readonly bool $isInternal,
        public readonly string $appSlug,
        /** Nombre visible en español. Ej: Tipos Email */
        public readonly string $visibleName,
        /** URL del índice en español. Ej: /tipos-email */
        public readonly string $indexUrl,
        /**
         * Campos de negocio extra derivados del SQL. Vacío en el módulo básico.
         *
         * @var list<ModuleField>
         */
        public readonly array $fields = [],
    ) {}

    /**
     * @param  list<ModuleField>  $fields  Campos extra del SQL, o [] para el módulo básico.
     * @param  string|null  $table  Nombre real de la tabla del SQL; por defecto el del naming.
     */
    public static function make(
        ModuleNaming $naming,
        GeneratorConfig $config,
        bool $hasCode,
        bool $isInternal,
        string $appSlug,
        string $visibleName,
        string $indexUrl,
        array $fields = [],
        ?string $table = null,
    ): self {
        return new self(
            ns: $config->moduleNamespace,
            shared: $config->sharedContractsNamespace,
            basePath: $config->modulePath($naming->plural),
            plural: $naming->plural,
            singular: $naming->singular,
            moduleCode: $naming->moduleCode,
            singularCamel: $naming->singularCamel,
            kebab: $naming->kebab,
            table: $table ?? $naming->table,
            connection: $config->connection,
            pagesPath: $config->pagesPath,
            hasCode: $hasCode,
            isInternal: $isInternal,
            appSlug: $appSlug,
            visibleName: $visibleName,
            indexUrl: $indexUrl,
            fields: $fields,
        );
    }

    /** "Tipos Email" -> "Tipo Email" */
    public function visibleSingular(): string
    {
        return Str::singular($this->visibleName);
    }

    /** "Tipos Email" -> "tipos email" */
    public function visibleLower(): string
    {
        return mb_strtolower($this->visibleName);
    }
}
