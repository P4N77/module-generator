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

        $singular = $this->ctx->singular;

        $names = [
            'modelName' => $singular,
            'listDto' => "{$plural}ListDTO",
            'listContract' => "List{$plural}Contract",
            'matchContract' => "Match{$plural}RowContract",
            'listService' => "List{$plural}Service",
            'matchService' => "Match{$plural}RowService",
            'listRepoInterface' => "{$plural}ListRepositoryInterface",
            'listRepoImpl' => "Eloquent{$plural}ListRepository",
            // Contratos y services de escritura (create/update/delete).
            'createContract' => "Create{$singular}Contract",
            'updateContract' => "Update{$singular}Contract",
            'deleteContract' => "Delete{$singular}Contract",
            'createService' => "Create{$singular}Service",
            'updateService' => "Update{$singular}Service",
            'deleteService' => "Delete{$singular}Service",
            // El README documenta los namespaces completos.
            'sharedNs' => $this->ctx->shared,
            'moduleNs' => $this->ctx->ns,
        ];

        $writeArgs = [
            'codeArg' => $this->ctx->hasCode ? "            code: (string) (\$data['code'] ?? ''),\n" : '',
            'extraArgs' => $this->extraDataArgs(),
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

        $writeContracts = [
            'createContract' => 'databridge/create-contract',
            'updateContract' => 'databridge/update-contract',
            'deleteContract' => 'databridge/delete-contract',
        ];
        foreach ($writeContracts as $key => $stub) {
            $this->writer->put(
                "{$sharedDir}/{$names[$key]}.php",
                $this->render($stub, $names),
                "Shared/Contracts/{$plural}/{$names[$key]}.php",
            );
        }

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

        // Services de escritura: create y update mapean el payload a su Command;
        // delete solo delega el uuid.
        $this->writer->put(
            "{$base}/Application/Services/{$names['createService']}.php",
            $this->render('databridge/create-service', array_merge($names, $writeArgs)),
            "Application/Services/{$names['createService']}.php",
        );

        $this->writer->put(
            "{$base}/Application/Services/{$names['updateService']}.php",
            $this->render('databridge/update-service', array_merge($names, $writeArgs)),
            "Application/Services/{$names['updateService']}.php",
        );

        $this->writer->put(
            "{$base}/Application/Services/{$names['deleteService']}.php",
            $this->render('databridge/delete-service', $names),
            "Application/Services/{$names['deleteService']}.php",
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
     * Líneas que mapean cada campo extra del payload plano al Command, para los
     * services de create y update. Los no nulos se castean a string; los nulables
     * pasan null si la clave no viene.
     */
    private function extraDataArgs(): string
    {
        return $this->fieldBlock(
            fn ($field): string => $field->nullable
                ? "            {$field->camel()}: \$data['{$field->name}'] ?? null,"
                : "            {$field->camel()}: (string) (\$data['{$field->name}'] ?? ''),",
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
