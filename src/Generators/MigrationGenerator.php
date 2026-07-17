<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Migración de la tabla del módulo. Va al repositorio Suite
 * (database/migrations/tenant/{proyecto}/{Modulo}) o, en modo local, dentro del
 * propio módulo.
 */
final class MigrationGenerator extends Generator
{
    /** Dentro del módulo: Infrastructure/Database/Migrations. */
    public function local(): void
    {
        $fileName = $this->fileName();
        $dir = "{$this->ctx->basePath}/Infrastructure/Database/Migrations";

        $this->writer->put("{$dir}/{$fileName}", $this->contents(), $fileName);
    }

    /** En Suite: database/migrations/tenant/{proyecto}/{Modulo}. */
    public function suite(string $suitePath, string $project): void
    {
        $fileName = $this->fileName();
        $relative = "database/migrations/tenant/{$project}/{$this->ctx->plural}";

        $this->writer->put(
            "{$suitePath}/{$relative}/{$fileName}",
            $this->contents(),
            "Suite/{$relative}/{$fileName}",
        );
    }

    private function fileName(): string
    {
        return date('Y_m_d_His')."_create_table_{$this->ctx->table}.php";
    }

    private function contents(): string
    {
        return $this->render('migration/create-table', [
            'codeLine' => $this->ctx->hasCode
                ? "            \$table->char('code', 10)->unique();\n"
                : '',
            'extraColumns' => $this->fieldBlock(
                fn ($field): string => '            '.$field->migrationColumn(),
            ),
        ]);
    }
}
