<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Support;

use Illuminate\Support\Str;

/**
 * Un campo de negocio "extra" del SQL, ya interpretado: los que no son columnas
 * del esqueleto estándar (id, uuid, code, description, status, auditoría) ni
 * foráneas. Es la única fuente de conocimiento de tipos: cada generator le pide
 * a este objeto cómo se renderiza en su capa.
 *
 * Tipos soportados por ahora: string, text, date, decimal. El mapeo desde el SQL
 * lo hace {@see FieldMapper}; aquí solo vive el "qué produce cada tipo".
 */
final class ModuleField
{
    public function __construct(
        /** Nombre de columna en snake_case. Ej: unit_price */
        public readonly string $name,
        /** string | text | date | decimal */
        public readonly string $type,
        public readonly bool $nullable,
        /** Longitud para string; null en el resto. */
        public readonly ?int $length = null,
        /** Escala decimal (dígitos tras la coma) para decimal; null en el resto. */
        public readonly ?int $decimalScale = null,
    ) {}

    /** camelCase para variables/getters PHP y claves del form Vue. Ej: unitPrice */
    public function camel(): string
    {
        return Str::camel($this->name);
    }

    /** Etiqueta legible. El dev la ajusta al español si hace falta. Ej: "Unit Price" */
    public function label(): string
    {
        return Str::headline($this->name);
    }

    /** Tipo PHP para props/parámetros, con "?" cuando la columna admite null. */
    public function phpType(): string
    {
        $base = $this->type === 'decimal' ? 'float' : 'string';

        return $this->nullable ? "?{$base}" : $base;
    }

    /** Valor de $casts, o null si el tipo no necesita cast. */
    public function cast(): ?string
    {
        return match ($this->type) {
            'date' => 'date',
            'decimal' => 'decimal:'.($this->decimalScale ?? 2),
            default => null,
        };
    }

    /** Línea de columna para la migración (sin indentación ni salto). */
    public function migrationColumn(): string
    {
        $method = match ($this->type) {
            'text' => "text('{$this->name}')",
            'date' => "date('{$this->name}')",
            'decimal' => "decimal('{$this->name}', 12, ".($this->decimalScale ?? 2).')',
            default => $this->length !== null
                ? "string('{$this->name}', {$this->length})"
                : "string('{$this->name}')",
        };

        return '$table->'.$method.($this->nullable ? '->nullable()' : '').';';
    }

    /**
     * Reglas de validación de Laravel para este campo.
     *
     * @return list<string>
     */
    public function validationRules(): array
    {
        $rules = [$this->nullable ? 'nullable' : 'required'];

        $rules = array_merge($rules, match ($this->type) {
            'date' => ['date'],
            'decimal' => ['numeric'],
            'text' => ['string'],
            default => $this->length !== null
                ? ['string', "max:{$this->length}"]
                : ['string'],
        });

        return $rules;
    }
}
