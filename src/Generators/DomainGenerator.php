<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Capa de dominio: value object de estado, entidad, contrato de repositorio y
 * excepción de "no encontrado".
 */
final class DomainGenerator extends Generator
{
    /** Enum backed string 1/0 con el estado del registro. */
    public function statusValueObject(): void
    {
        $class = "{$this->ctx->singular}Status";

        $this->writer->put(
            "{$this->ctx->basePath}/Domain/ValueObjects/{$class}.php",
            $this->render('domain/status-value-object', ['class' => $class]),
            "Domain/ValueObjects/{$class}.php",
        );
    }

    public function entity(): void
    {
        $hasCode = $this->ctx->hasCode;

        $contents = $this->render('domain/entity', [
            'codeCtorProp' => $hasCode ? "        private string \$code,\n" : '',
            'codeCreateParam' => $hasCode ? "        string \$code,\n" : '',
            'codeCreateAssign' => $hasCode ? "            code: \$code,\n" : '',
            'codeUpdateParam' => $hasCode ? 'string $code, ' : '',
            'codeUpdateAssign' => $hasCode ? "        \$this->code = \$code;\n" : '',
            'codeGetter' => $hasCode ? "    public function code(): string { return \$this->code; }\n" : '',
            'extraCtorProps' => $this->fieldBlock(
                fn ($field): string => "        private {$field->phpType()} \${$field->camel()},",
            ),
            'extraCreateParams' => $this->fieldBlock(
                fn ($field): string => "        {$field->phpType()} \${$field->camel()},",
            ),
            'extraCreateAssigns' => $this->fieldBlock(
                fn ($field): string => "            {$field->camel()}: \${$field->camel()},",
            ),
            // La firma de update() es de una sola línea: parámetros en línea.
            'extraUpdateParams' => $this->inlineParams(),
            'extraUpdateAssigns' => $this->fieldBlock(
                fn ($field): string => "        \$this->{$field->camel()} = \${$field->camel()};",
            ),
            'extraGetters' => $this->fieldBlock(
                fn ($field): string => "    public function {$field->camel()}(): {$field->phpType()} { return \$this->{$field->camel()}; }",
            ),
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Domain/Entities/{$this->ctx->singular}.php",
            $contents,
            "Domain/Entities/{$this->ctx->singular}.php",
        );
    }

    /**
     * Parámetros de los campos extra para la firma de update(), en línea y con
     * espacio final, para insertarse antes del $status. Vacío si no hay campos.
     */
    private function inlineParams(): string
    {
        $parts = array_map(
            fn ($field): string => "{$field->phpType()} \${$field->camel()}",
            $this->ctx->fields,
        );

        return $parts === [] ? '' : implode(', ', $parts).', ';
    }

    public function repositoryInterface(): void
    {
        $interfaceName = "{$this->ctx->singular}RepositoryInterface";

        $contents = $this->render('domain/repository-interface', [
            'codeExists' => $this->ctx->hasCode
                ? "\n    public function codeExists(string \$code, ?string \$excludeUuid = null): bool;\n"
                : '',
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Domain/Repositories/{$interfaceName}.php",
            $contents,
            "Domain/Repositories/{$interfaceName}.php",
        );
    }

    public function notFoundException(): void
    {
        $exceptionName = "{$this->ctx->singular}NotFoundException";

        $this->writer->put(
            "{$this->ctx->basePath}/Domain/Exceptions/{$exceptionName}.php",
            $this->render('domain/not-found-exception', ['exceptionName' => $exceptionName]),
            "Domain/Exceptions/{$exceptionName}.php",
        );
    }
}
