<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

use Illuminate\Support\Str;

/**
 * Seeder del módulo en Suite, plano dentro de database/seeders/tenant/{proyecto}.
 */
final class SeederGenerator extends Generator
{
    public function suite(string $suitePath, string $project): void
    {
        $seederClass = "{$this->ctx->plural}Seeder";
        $hasCode = $this->ctx->hasCode;

        $contents = $this->render('suite/seeder', [
            'namespace' => 'Database\\Seeders\\Tenant\\'.Str::studly($project),
            'seederClass' => $seederClass,
            // Con code la fila se identifica por code; sin él, por description.
            'matchKey' => $hasCode ? 'code' : 'description',
            'sampleRow' => $hasCode
                ? "            // ['code' => 'EJ', 'description' => 'Ejemplo'],"
                : "            // ['description' => 'Ejemplo'],",
            'codeInsert' => $hasCode
                ? "                    'code' => \$row['code'],\n"
                : '',
        ]);

        $relative = "database/seeders/tenant/{$project}/{$seederClass}.php";
        $this->writer->put("{$suitePath}/{$relative}", $contents, "Suite/{$relative}");
    }
}
