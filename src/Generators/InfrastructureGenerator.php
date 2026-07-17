<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Capa de infraestructura: modelo Eloquent y repositorio.
 */
final class InfrastructureGenerator extends Generator
{
    public function model(): void
    {
        $contents = $this->render('infrastructure/model', [
            'connectionLine' => $this->ctx->connection !== null
                ? "\n    protected \$connection = '{$this->ctx->connection}';"
                : '',
            'codeFillable' => $this->ctx->hasCode ? "        'code',\n" : '',
            'extraFillable' => $this->fieldBlock(fn ($field): string => "        '{$field->name}',"),
            'castsBlock' => $this->castsBlock(),
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Infrastructure/Database/Models/{$this->ctx->singular}.php",
            $contents,
            "Infrastructure/Database/Models/{$this->ctx->singular}.php",
        );
    }

    /**
     * Bloque $casts para los campos que lo necesitan (date, decimal). Vacío si
     * ningún campo requiere cast, para no dejar una propiedad inútil.
     */
    private function castsBlock(): string
    {
        $lines = [];
        foreach ($this->ctx->fields as $field) {
            $cast = $field->cast();
            if ($cast !== null) {
                $lines[] = "        '{$field->name}' => '{$cast}',";
            }
        }

        return $lines === []
            ? ''
            : "\n    protected \$casts = [\n".implode("\n", $lines)."\n    ];\n";
    }

    public function repository(): void
    {
        $hasCode = $this->ctx->hasCode;
        $camel = $this->ctx->singularCamel;
        $repositoryName = "Eloquent{$this->ctx->singular}Repository";

        $contents = $this->render('infrastructure/eloquent-repository', [
            'repositoryName' => $repositoryName,
            'codeSave' => $hasCode ? "            \$model->code = \${$camel}->code();\n" : '',
            'codeUpdate' => $hasCode ? "            \$m->code = \${$camel}->code();\n" : '',
            'codeToDomain' => $hasCode ? "            code: \$m->code,\n" : '',
            'extraSave' => $this->fieldBlock(
                fn ($field): string => "            \$model->{$field->name} = \${$camel}->{$field->camel()}();",
            ),
            'extraUpdate' => $this->fieldBlock(
                fn ($field): string => "            \$m->{$field->name} = \${$camel}->{$field->camel()}();",
            ),
            'extraToDomain' => $this->fieldBlock(
                fn ($field): string => "            {$field->camel()}: \$m->{$field->name},",
            ),
            'codeExistsMethod' => $hasCode
                ? $this->render('infrastructure/fragments/code-exists-method')
                : "\n",
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Infrastructure/Database/Repositories/{$repositoryName}.php",
            $contents,
            "Infrastructure/Database/Repositories/{$repositoryName}.php",
        );
    }
}
