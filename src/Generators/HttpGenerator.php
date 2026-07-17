<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

/**
 * Capa HTTP: form requests, controlador y rutas. El controlador y las rutas
 * tienen dos variantes: módulo de usuario (permisos Casbin) o interno (guard
 * de la cuenta Sodeker).
 */
final class HttpGenerator extends Generator
{
    public function requests(): void
    {
        $hasCode = $this->ctx->hasCode;
        $table = $this->ctx->table;

        // Prefijo de conexión para Rule::unique('tenant.tabla', ...).
        $conn = $this->ctx->connection !== null && $this->ctx->connection !== ''
            ? $this->ctx->connection.'.'
            : '';

        // La regla unique de description siempre necesita Rule, haya code o no.
        $useRule = "use Illuminate\\Validation\\Rule;\n";

        $prepareCode = $hasCode
            ? "        \$code = (string) \$this->input('code', '');\n        \$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', \$code));\n        \$this->merge(['code' => \$code, 'description' => \$description]);"
            : "        \$this->merge(['description' => \$description]);";

        $createCodeRule = $hasCode
            ? "            'code' => [\n                'required',\n                'string',\n                'max:10',\n                'regex:/^[A-Z0-9]+\$/',\n                Rule::unique('{$conn}{$table}', 'code')->whereNull('deleted_at'),\n            ],\n"
            : '';
        $updateCodeRule = $hasCode
            ? "            'code' => [\n                'required',\n                'string',\n                'max:10',\n                'regex:/^[A-Z0-9]+\$/',\n                Rule::unique('{$conn}{$table}', 'code')\n                    ->ignore(\$this->route('uuid'), 'uuid')\n                    ->whereNull('deleted_at'),\n            ],\n"
            : '';

        $createDescRule = "            'description' => [\n                'required',\n                'string',\n                'max:191',\n                Rule::unique('{$conn}{$table}', 'description')->whereNull('deleted_at'),\n            ],";
        $updateDescRule = "            'description' => [\n                'required',\n                'string',\n                'max:191',\n                Rule::unique('{$conn}{$table}', 'description')\n                    ->ignore(\$this->route('uuid'), 'uuid')\n                    ->whereNull('deleted_at'),\n            ],";

        $codeMessages = $hasCode
            ? "            'code.required' => 'El campo código es requerido',\n            'code.max' => 'El campo código debe tener máximo 10 caracteres',\n            'code.regex' => 'El código solo puede contener letras y números, sin espacios ni símbolos',\n            'code.unique' => 'El código que intenta guardar ya existe',\n"
            : '';

        $singular = $this->ctx->singular;
        $plural = $this->ctx->plural;

        // Las reglas de create y update de los campos extra son iguales: dependen
        // solo del tipo y la nulabilidad de la columna.
        $extraRules = $this->fieldBlock(
            fn ($field): string => "            '{$field->name}' => '".implode('|', $field->validationRules())."',",
        );

        $requests = [
            "Create{$singular}Request" => ['http/request-create', [
                'useRule' => $useRule,
                'prepareCode' => $prepareCode,
                'createCodeRule' => $createCodeRule,
                'createDescRule' => $createDescRule,
                'extraCreateRules' => $extraRules,
                'codeMessages' => $codeMessages,
            ]],
            "Update{$singular}Request" => ['http/request-update', [
                'useRule' => $useRule,
                'prepareCode' => $prepareCode,
                'updateCodeRule' => $updateCodeRule,
                'updateDescRule' => $updateDescRule,
                'extraUpdateRules' => $extraRules,
                'codeMessages' => $codeMessages,
            ]],
            "Filter{$plural}Request" => ['http/request-filter', []],
        ];

        foreach ($requests as $class => [$stub, $fragments]) {
            $this->writer->put(
                "{$this->ctx->basePath}/Infrastructure/Http/Requests/{$class}.php",
                $this->render($stub, $fragments),
                "Infrastructure/Http/Requests/{$class}.php",
            );
        }
    }

    public function controller(): void
    {
        $hasCode = $this->ctx->hasCode;
        $singular = $this->ctx->singular;
        $variant = $this->ctx->isInternal ? 'internal' : 'user';

        $codeInput = $hasCode ? "            code: \$r->input('code'),\n" : '';

        // El mapeo de inputs es idéntico en store y update.
        $extraInput = $this->fieldBlock(
            fn ($field): string => "            {$field->camel()}: \$r->input('{$field->name}'),",
        );

        $contents = $this->render("http/controller-{$variant}", [
            'validateHandlerUse' => $hasCode ? "\n    Validate{$singular}CodeHandler," : '',
            'validateHandlerCtor' => $hasCode
                ? "        private Validate{$singular}CodeHandler \$validate{$singular}CodeHandler,\n"
                : '',
            'codeStore' => $codeInput,
            'codeUpdate' => $codeInput,
            'extraStore' => $extraInput,
            'extraUpdate' => $extraInput,
            'validateMethod' => $hasCode
                ? $this->render("http/fragments/validate-code-method-{$variant}")
                : "\n",
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Infrastructure/Http/Controllers/{$singular}Controller.php",
            $contents,
            "Infrastructure/Http/Controllers/{$singular}Controller.php",
        );
    }

    public function routes(): void
    {
        $controller = "{$this->ctx->singular}Controller";
        $variant = $this->ctx->isInternal ? 'internal' : 'user';

        $contents = $this->render("http/routes-{$variant}", [
            'controller' => $controller,
            'validateRoute' => $this->ctx->hasCode
                ? "\n    Route::post('{$this->ctx->kebab}/validate-code', [{$controller}::class, 'validateCode'])->name('{$this->ctx->moduleCode}.validateCode');\n"
                : '',
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/Infrastructure/Http/Routes/web.php",
            $contents,
            'Infrastructure/Http/Routes/web.php',
        );
    }
}
