<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Capa de aplicación: DTOs, commands y handlers.
 */
final class ApplicationGenerator extends Generator
{
    public function dtos(): void
    {
        $hasCode = $this->ctx->hasCode;
        $singular = $this->ctx->singular;
        $camel = $this->ctx->singularCamel;

        $fragments = [
            'codeProp' => $hasCode ? "        public string \$code,\n" : '',
            'codeAssign' => $hasCode ? "            code: \${$camel}->code(),\n" : '',
            'codeArray' => $hasCode ? "            'code' => \$this->code,\n" : '',
            'codeCollArray' => $hasCode ? "                    'code' => \$dto->code,\n" : '',
            'extraProps' => $this->fieldBlock(
                fn ($field): string => "        public {$field->phpType()} \${$field->camel()},",
            ),
            'extraAssigns' => $this->fieldBlock(
                fn ($field): string => "            {$field->camel()}: \${$camel}->{$field->camel()}(),",
            ),
            'extraArray' => $this->fieldBlock(
                fn ($field): string => "            '{$field->name}' => \$this->{$field->camel()},",
            ),
            'extraCollArray' => $this->fieldBlock(
                fn ($field): string => "                    '{$field->name}' => \$dto->{$field->camel()},",
            ),
        ];

        $dtos = [
            "{$singular}DTO" => 'application/dto',
            "Save{$singular}DTO" => 'application/save-dto',
            "{$singular}CollectionDTO" => 'application/collection-dto',
        ];

        foreach ($dtos as $class => $stub) {
            $this->writer->put(
                "{$this->ctx->basePath}/Application/DTOs/{$class}.php",
                $this->render($stub, $fragments),
                "Application/DTOs/{$class}.php",
            );
        }
    }

    public function commands(): void
    {
        $codeProp = $this->ctx->hasCode ? "        public string \$code,\n" : '';
        $singular = $this->ctx->singular;

        $extraProps = $this->fieldBlock(
            fn ($field): string => "        public {$field->phpType()} \${$field->camel()},",
        );

        $commands = [
            "Create{$singular}Command" => ['application/command-create', ['codeCreate' => $codeProp, 'extraCreate' => $extraProps]],
            "Update{$singular}Command" => ['application/command-update', ['codeUpdate' => $codeProp, 'extraUpdate' => $extraProps]],
            "Delete{$singular}Command" => ['application/command-delete', []],
        ];

        foreach ($commands as $class => [$stub, $fragments]) {
            $this->writer->put(
                "{$this->ctx->basePath}/Application/Commands/{$class}.php",
                $this->render($stub, $fragments),
                "Application/Commands/{$class}.php",
            );
        }
    }

    public function handlers(): void
    {
        $singular = $this->ctx->singular;
        $plural = $this->ctx->plural;
        $codeAssign = $this->ctx->hasCode ? "            code: \$c->code,\n" : '';

        $extraAssign = $this->fieldBlock(
            fn ($field): string => "            {$field->camel()}: \$c->{$field->camel()},",
        );

        $handlers = [
            "Create{$singular}Handler" => ['application/handler-create', ['codeCreateAssign' => $codeAssign, 'extraCreateAssign' => $extraAssign]],
            "List{$plural}Handler" => ['application/handler-list', []],
            "Update{$singular}Handler" => ['application/handler-update', ['codeUpdateAssign' => $codeAssign, 'extraUpdateAssign' => $extraAssign]],
            "Get{$singular}ByUuidHandler" => ['application/handler-get-by-uuid', []],
            "Delete{$singular}Handler" => ['application/handler-delete', []],
        ];

        // La validación de unicidad solo existe si el módulo lleva code.
        if ($this->ctx->hasCode) {
            $handlers["Validate{$singular}CodeHandler"] = ['application/handler-validate-code', []];
        }

        foreach ($handlers as $class => [$stub, $fragments]) {
            $this->writer->put(
                "{$this->ctx->basePath}/Application/Handlers/{$class}.php",
                $this->render($stub, $fragments),
                "Application/Handlers/{$class}.php",
            );
        }
    }
}
