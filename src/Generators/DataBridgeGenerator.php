<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

use Sodeker\ModuleGenerator\Support\GeneratorConfig;
use Sodeker\ModuleGenerator\Support\ModuleContext;
use Sodeker\ModuleGenerator\Support\ModuleWriter;
use Sodeker\ModuleGenerator\Support\StubRenderer;

/**
 * "DataBridge": el listado del módulo expuesto a otros módulos mediante
 * contratos Shared (List + Match), con su DTO, services y repositorio de
 * listado autocontenido.
 */
final class DataBridgeGenerator extends Generator
{
    public function __construct(
        ModuleContext $ctx,
        StubRenderer $stubs,
        ModuleWriter $writer,
        private readonly GeneratorConfig $config,
    ) {
        parent::__construct($ctx, $stubs, $writer);
    }

    public function generate(): void
    {
        $plural = $this->ctx->plural;
        $base = $this->ctx->basePath;
        $sharedDir = $this->config->sharedPath($plural);

        $names = [
            'modelName' => $this->ctx->singular,
            'listDto' => "{$plural}ListDTO",
            'listContract' => "List{$plural}Contract",
            'matchContract' => "Match{$plural}RowContract",
            'listService' => "List{$plural}Service",
            'matchService' => "Match{$plural}RowService",
            'listRepoInterface' => "{$plural}ListRepositoryInterface",
            'listRepoImpl' => "Eloquent{$plural}ListRepository",
            // El README documenta los namespaces completos.
            'sharedNs' => $this->ctx->shared,
            'moduleNs' => $this->ctx->ns,
        ];

        // ===== Contratos compartidos =====
        $this->writer->put(
            "{$sharedDir}/{$names['listContract']}.php",
            $this->render('databridge/list-contract', $names),
            "Shared/Contracts/{$plural}/{$names['listContract']}.php",
        );

        $this->writer->put(
            "{$sharedDir}/{$names['matchContract']}.php",
            $this->render('databridge/match-contract', $names),
            "Shared/Contracts/{$plural}/{$names['matchContract']}.php",
        );

        $this->writer->put(
            "{$sharedDir}/README.md",
            $this->render('databridge/readme.md', $names),
            "Shared/Contracts/{$plural}/README.md",
        );

        // ===== Módulo: DTO y services =====
        $this->writer->put(
            "{$base}/Application/DTOs/{$names['listDto']}.php",
            $this->render('databridge/list-dto', $names),
            "Application/DTOs/{$names['listDto']}.php",
        );

        $this->writer->put(
            "{$base}/Application/Services/{$names['listService']}.php",
            $this->render('databridge/list-service', $names),
            "Application/Services/{$names['listService']}.php",
        );

        $this->writer->put(
            "{$base}/Application/Services/{$names['matchService']}.php",
            $this->render('databridge/match-service', $names),
            "Application/Services/{$names['matchService']}.php",
        );

        // ===== Módulo: repositorio de listado =====
        $this->writer->put(
            "{$base}/Domain/Repositories/{$names['listRepoInterface']}.php",
            $this->render('databridge/list-repository-interface', $names),
            "Domain/Repositories/{$names['listRepoInterface']}.php",
        );

        $this->writer->put(
            "{$base}/Infrastructure/Database/Repositories/{$names['listRepoImpl']}.php",
            $this->render('databridge/eloquent-list-repository', array_merge($names, $this->listRepoFragments())),
            "Infrastructure/Database/Repositories/{$names['listRepoImpl']}.php",
        );
    }

    /**
     * Campo calculado y normalización, según el módulo lleve code.
     *
     * @return array<string, string>
     */
    private function listRepoFragments(): array
    {
        $hasCode = $this->ctx->hasCode;

        return [
            'extraFields' => $hasCode
                ? "        return [\n            'codeAndDescription' => \$row->getAttribute('code').' - '.\$row->getAttribute('description'),\n        ];"
                : "        return [\n            'name' => (string) \$row->getAttribute('description'),\n        ];",
            'codeNormalize' => $hasCode
                ? "        // char(N) llega con padding; el code de negocio no debe llevar espacios.\n        if (\$column === 'code' && is_string(\$value)) {\n            return rtrim(\$value);\n        }\n"
                : '',
        ];
    }
}
