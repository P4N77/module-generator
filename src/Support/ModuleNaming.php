<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Support\Str;

/**
 * Naming derivado del argumento del comando, siguiendo la convención de las
 * maestras de Suite. De "shd/email_types" salen:
 *
 *   plural        EmailTypes    (carpetas, clases, páginas Vue)
 *   singular      EmailType     (entidad, modelo, DTO)
 *   moduleCode    emailTypes    (nombres de ruta + módulo Casbin)
 *   singularCamel emailType     (prop de detalle/edición)
 *   kebab         email-types   (segmentos de URL)
 *   table         shd_email_types
 */
final class ModuleNaming
{
    private function __construct(
        public readonly string $plural,
        public readonly string $singular,
        public readonly string $moduleCode,
        public readonly string $singularCamel,
        public readonly string $kebab,
        public readonly string $table,
        /** Prefijo de Suite resuelto (del "prefijo/nombre" o de config), o null. */
        public readonly ?string $prefix,
    ) {}

    /**
     * @param  string  $rawName  Argumento del comando: "email_types" o "shd/email_types".
     * @param  string|null  $defaultPrefix  Prefijo de config, usado si el nombre no trae "prefijo/".
     */
    public static function fromArgument(string $rawName, ?string $defaultPrefix = null): self
    {
        $prefix = $defaultPrefix !== null && trim($defaultPrefix) !== ''
            ? Str::lower(trim($defaultPrefix))
            : null;

        // El prefijo explícito "prefijo/nombre" gana sobre el de config.
        if (str_contains($rawName, '/')) {
            [$prefix, $rawName] = explode('/', $rawName, 2);
            $prefix = Str::lower(trim($prefix));
        }

        $plural = Str::studly(Str::plural($rawName));
        $singular = Str::studly(Str::singular($rawName));
        $pluralSnake = Str::snake($plural);

        return new self(
            plural: $plural,
            singular: $singular,
            moduleCode: Str::camel($plural),
            singularCamel: Str::camel($singular),
            kebab: Str::kebab($plural),
            table: $prefix !== null ? "{$prefix}_{$pluralSnake}" : $pluralSnake,
            prefix: $prefix,
        );
    }
}
