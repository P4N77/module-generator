<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Test unitario del List Service en Suite (Pest + Mockery).
 */
final class TestGenerator extends Generator
{
    public function listService(string $suitePath): void
    {
        $plural = $this->ctx->plural;
        $listService = "List{$plural}Service";

        // El fixture del test cambia según el módulo lleve code: con code se
        // filtra por code y el campo calculado es codeAndDescription.
        $fixture = $this->ctx->hasCode
            ? [
                'filterKey' => 'code',
                'secondFilter' => "['code' => '001', 'description' => 'Ejemplo', 'match_any' => true]",
                'secondExecute' => "['code' => '001', 'description' => 'Ejemplo']",
                'row' => "['id' => 1, 'code' => '001', 'description' => 'Ejemplo', 'codeAndDescription' => '001 - Ejemplo']",
                'extraKey' => 'codeAndDescription',
                'extraValue' => '001 - Ejemplo',
            ]
            : [
                'filterKey' => 'description',
                'secondFilter' => "['description' => 'Ejemplo', 'status' => '1', 'match_any' => true]",
                'secondExecute' => "['description' => 'Ejemplo', 'status' => '1']",
                'row' => "['id' => 1, 'description' => 'Ejemplo', 'name' => 'Ejemplo']",
                'extraKey' => 'name',
                'extraValue' => 'Ejemplo',
            ];

        $contents = $this->render('suite/list-service-test', array_merge($fixture, [
            'listService' => $listService,
            'listRepoInterface' => "{$plural}ListRepositoryInterface",
        ]));

        $relative = "tests/Unit/{$plural}/{$listService}Test.php";
        $this->writer->put("{$suitePath}/{$relative}", $contents, "Suite/{$relative}");
    }
}
