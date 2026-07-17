<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

use Illuminate\Support\Facades\File;
use Sodeker\ModuleGenerator\Support\GeneratorConfig;
use Sodeker\ModuleGenerator\Support\ModuleContext;
use Sodeker\ModuleGenerator\Support\ModuleWriter;
use Sodeker\ModuleGenerator\Support\StubRenderer;

/**
 * Páginas Inertia/Vue del módulo: Index, Create, Edit y Show.
 */
final class FrontendGenerator extends Generator
{
    public function __construct(
        ModuleContext $ctx,
        StubRenderer $stubs,
        ModuleWriter $writer,
        private readonly GeneratorConfig $config,
    ) {
        parent::__construct($ctx, $stubs, $writer);
    }

    public function pages(): void
    {
        $dir = $this->config->pagesDir($this->ctx->plural);
        $plural = $this->ctx->plural;

        File::ensureDirectoryExists($dir);
        $this->writer->record("resources/js/{$this->ctx->pagesPath}/{$plural}");

        $pages = [
            'Index.vue' => $this->index(),
            'Create.vue' => $this->form(isEdit: false),
            'Edit.vue' => $this->form(isEdit: true),
            'Show.vue' => $this->show(),
        ];

        foreach ($pages as $file => $contents) {
            $this->writer->put(
                "{$dir}/{$file}",
                $contents,
                "resources/js/{$this->ctx->pagesPath}/{$plural}/{$file}",
            );
        }
    }

    /** Listado con DataTable, búsqueda y acciones según permisos. */
    private function index(): string
    {
        if ($this->ctx->hasCode) {
            $headers = "                    { label: 'Código', key: 'code', width: '20%' },\n                    { label: 'Descripción', key: 'description', width: '50%' },\n                    { label: 'Estado', key: 'status', width: '15%' },\n                    { label: 'Acciones', key: 'actions', width: '15%' },";
            $searchFilter = "                item.code?.toLowerCase().includes(query) ||\n                item.description?.toLowerCase().includes(query)";
            $orderBy = 'code';
            $searchPlaceholder = 'Buscar por código o descripción...';
        } else {
            $headers = "                    { label: 'Descripción', key: 'description', width: '70%' },\n                    { label: 'Estado', key: 'status', width: '15%' },\n                    { label: 'Acciones', key: 'actions', width: '15%' },";
            $searchFilter = '                item.description?.toLowerCase().includes(query)';
            $orderBy = 'description';
            $searchPlaceholder = 'Buscar por descripción...';
        }

        // Columnas extra: se insertan antes de "Estado" en el header, y cada una
        // con su plantilla de celda de texto simple.
        $extraHeaders = '';
        $extraCells = '';
        foreach ($this->ctx->fields as $field) {
            $extraHeaders .= "                    { label: '{$field->label()}', key: '{$field->name}' },\n";
            $extraCells .= "                                <template #cell-{$field->name}=\"{ item }\">\n"
                ."                                    <span>{{ item.{$field->name} ?? '' }}</span>\n"
                ."                                </template>\n";
        }

        if ($extraHeaders !== '') {
            $statusHeader = "                    { label: 'Estado', key: 'status', width: '15%' },";
            $headers = str_replace($statusHeader, $extraHeaders.$statusHeader, $headers);
        }

        return $this->render('vue/index.vue', [
            'headers' => $headers,
            'searchFilter' => $searchFilter,
            'orderBy' => $orderBy,
            'searchPlaceholder' => $searchPlaceholder,
            'extraCells' => $extraCells,
            'codeColumn' => $this->ctx->hasCode
                ? "                                <template #cell-code=\"{ item }\">\n                                    <span class=\"fw-medium\">{{ item.code }}</span>\n                                </template>\n"
                : '',
        ]);
    }

    /** Create y Edit comparten plantilla; cambian props, watch, endpoint y verbo. */
    private function form(bool $isEdit): string
    {
        $hasCode = $this->ctx->hasCode;
        $camel = $this->ctx->singularCamel;
        $moduleCode = $this->ctx->moduleCode;
        $formVar = 'form'.$this->ctx->plural;

        // Estado inicial: Create usa booleano; Edit, el string '1'/'0' del backend.
        $initStatus = $isEdit ? "'1'" : 'true';
        $codeInit = $hasCode ? "                    code: '',\n" : '';

        // Fragmentos de los campos extra del SQL (idénticos en Create y Edit,
        // salvo la hidratación del watch, que solo aplica en Edit).
        $extraFormData = $this->extraFormData();
        $extraWatch = $isEdit ? $this->extraWatch() : '';

        // Edit recibe uuid + el registro, y lo hidrata en el form vía watch.
        $props = $isEdit
            ? "        props: {\n            uuid: {\n                type: String,\n                required: true,\n            },\n            {$camel}: {\n                type: Object,\n                required: true,\n            },\n        },\n"
            : '';
        $codeWatch = $hasCode ? "                        code: val?.code ?? '',\n" : '';
        $watch = $isEdit
            ? "        watch: {\n            {$camel}: {\n                immediate: true,\n                handler(val) {\n                    this.{$formVar} = {\n{$codeWatch}                        description: val?.description ?? '',\n{$extraWatch}                        status: val?.status === '1' || val?.status === 1 || val?.status === true ? '1' : '0',\n                    };\n                },\n            },\n        },\n"
            : '';

        $codeMethods = $hasCode
            ? $this->render('vue/fragments/form-code-methods', [
                'formVar' => $formVar,
                'uuidArg' => $isEdit ? 'this.uuid' : 'null',
            ])."\n"
            : '';

        $statusInput = $isEdit
            ? "                                        <input\n                                            id=\"{$moduleCode}Status\"\n                                            :true-value=\"'1'\"\n                                            :false-value=\"'0'\"\n                                            v-model=\"{$formVar}.status\"\n                                            type=\"checkbox\"\n                                            class=\"form-check-input\"\n                                        />"
            : "                                        <input\n                                            v-model=\"{$formVar}.status\"\n                                            type=\"checkbox\"\n                                            class=\"form-check-input\"\n                                            id=\"{$moduleCode}Status\"\n                                        >";

        return $this->render('vue/form.vue', [
            'formVar' => $formVar,
            'nameSuffix' => $isEdit ? 'Edit' : 'Create',
            'action' => $isEdit ? 'Editar' : 'Crear',
            'actionVerb' => $isEdit ? 'actualizar' : 'crear',
            'actionPast' => $isEdit ? 'actualizado' : 'creado',
            'submitIdle' => $isEdit ? 'Actualizar' : 'Guardar',
            'submitBusy' => $isEdit ? 'Actualizando...' : 'Guardando...',
            'request' => $isEdit ? "route('{$moduleCode}.update', this.uuid)" : "route('{$moduleCode}.store')",
            'method' => $isEdit ? 'PUT' : 'POST',
            'props' => $props,
            'watch' => $watch,
            'statusInput' => $statusInput,
            'codeMethods' => $codeMethods,
            'extraImports' => $this->formImports(),
            'extraComponents' => $this->formComponents(),
            'extraConfig' => $this->flatpickrConfig(),
            'extraFields' => $this->extraFormFields($formVar),
            'formData' => "{$codeInit}                    description: '',\n{$extraFormData}                    status: {$initStatus},",
            'codeExistsData' => $hasCode ? "                codeExistsError: '',\n" : '',
            'codeRequired' => $hasCode ? "                if (!this.normalizeCode(f.code)) e.code = true;\n" : '',
            'codeClearError' => $hasCode ? "                if (field === 'code') {\n                    this.codeExistsError = '';\n                }\n" : '',
            'codeExistsCheck' => $hasCode ? "                    const codeExists = await this.validateCodeUniqueness();\n                    if (codeExists) {\n                        await this.\$nextTick();\n                        return;\n                    }\n\n" : '',
            'codeBodyNormalize' => $hasCode ? "                            code: this.normalizeCode(this.{$formVar}.code),\n" : '',
            'codeField' => $this->render(
                $hasCode
                    ? 'vue/fragments/form-fields-with-code'
                    : 'vue/fragments/form-fields-without-code',
                ['formVar' => $formVar],
            ),
        ]);
    }

    private function show(): string
    {
        return $this->render('vue/show.vue', [
            'codeBlock' => $this->render(
                $this->ctx->hasCode
                    ? 'vue/fragments/show-fields-with-code'
                    : 'vue/fragments/show-fields-without-code',
            ),
            'extraShowFields' => $this->extraShowFields(),
        ]);
    }

    /** Tipos de campo presentes, sin repetir. Ej: ['date', 'decimal']. */
    private function fieldTypes(): array
    {
        return array_unique(array_map(static fn ($field): string => $field->type, $this->ctx->fields));
    }

    /** Imports que exigen los tipos presentes (flatpickr para date, FormattedNumberInput para decimal). */
    private function formImports(): string
    {
        $types = $this->fieldTypes();
        $lines = [];

        if (in_array('date', $types, true)) {
            $lines[] = '    import flatPickr from "vue-flatpickr-component";';
            $lines[] = '    import "flatpickr/dist/flatpickr.css";';
            $lines[] = '    import { Spanish } from "flatpickr/dist/l10n/es.js";';
        }

        if (in_array('decimal', $types, true)) {
            $lines[] = '    import FormattedNumberInput from "@/Components/FormattedNumberInput.vue";';
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /** Registro en components: {} de los componentes usados. */
    private function formComponents(): string
    {
        $types = $this->fieldTypes();
        $lines = [];

        if (in_array('date', $types, true)) {
            $lines[] = '            flatPickr,';
        }

        if (in_array('decimal', $types, true)) {
            $lines[] = '            FormattedNumberInput,';
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /** Objeto config de flatpickr en data(), solo si hay algún campo fecha. */
    private function flatpickrConfig(): string
    {
        if (! in_array('date', $this->fieldTypes(), true)) {
            return '';
        }

        return "                config: {\n"
            ."                    dateFormat: 'Y-m-d',\n"
            ."                    altInput: true,\n"
            ."                    altFormat: 'd M, Y',\n"
            ."                    locale: Spanish,\n"
            ."                },\n";
    }

    /** Valores iniciales de los campos extra en el objeto del formulario. */
    private function extraFormData(): string
    {
        return $this->block(array_map(
            fn ($field): string => "                    {$field->name}: ".$this->initialValue($field).',',
            $this->ctx->fields,
        ));
    }

    /** Hidratación de los campos extra en el watch de Edit. */
    private function extraWatch(): string
    {
        return $this->block(array_map(
            fn ($field): string => "                        {$field->name}: val?.{$field->name} ?? ".$this->initialValue($field).',',
            $this->ctx->fields,
        ));
    }

    /** Valor inicial por tipo: null para fecha, cadena vacía para el resto. */
    private function initialValue($field): string
    {
        return $field->type === 'date' ? 'null' : "''";
    }

    /** Bloque de campos del formulario, cada uno con el componente que le toca. */
    private function extraFormFields(string $formVar): string
    {
        return $this->block(array_map(
            fn ($field): string => $this->formField($field, $formVar),
            $this->ctx->fields,
        ));
    }

    /** HTML de un campo del formulario según su tipo. */
    private function formField($field, string $formVar): string
    {
        $id = $field->name;
        $model = "{$formVar}.{$field->name}";
        $required = $field->nullable ? '' : '<span class="text-danger ms-1">*</span>';
        $label = "                                    <label for=\"{$id}\" class=\"form-label\">{$field->label()}{$required}</label>";

        $control = match ($field->type) {
            'text' => "                                    <textarea\n"
                ."                                        v-model=\"{$model}\"\n"
                ."                                        class=\"form-control\"\n"
                ."                                        id=\"{$id}\"\n"
                ."                                        rows=\"3\"\n"
                ."                                        placeholder=\"Ingrese {$field->label()}\"\n"
                ."                                    ></textarea>",
            'date' => "                                    <flat-pickr\n"
                ."                                        v-model=\"{$model}\"\n"
                ."                                        :config=\"config\"\n"
                ."                                        class=\"form-control flatpickr-input\"\n"
                ."                                        placeholder=\"Seleccione...\"\n"
                ."                                    ></flat-pickr>",
            'decimal' => "                                    <FormattedNumberInput\n"
                ."                                        v-model=\"{$model}\"\n"
                ."                                        placeholder=\"0,00\"\n"
                ."                                    />",
            default => "                                    <input\n"
                ."                                        v-model=\"{$model}\"\n"
                ."                                        type=\"text\"\n"
                ."                                        class=\"form-control\"\n"
                ."                                        id=\"{$id}\"\n"
                ."                                        placeholder=\"Ingrese {$field->label()}\"\n"
                ."                                    />",
        };

        $col = $field->type === 'text' ? 'col-12' : 'col-md-6';

        return "                                <div class=\"{$col}\">\n{$label}\n{$control}\n                                </div>";
    }

    /** Bloque de detalle (Show) de los campos extra. */
    private function extraShowFields(): string
    {
        $camel = $this->ctx->singularCamel;

        return $this->block(array_map(
            fn ($field): string => "                            <div class=\"col-md-6 mb-1\">\n"
                ."                                <label class=\"form-label\">{$field->label()}</label>\n"
                ."                                <p class=\"text-muted\">{{ {$camel}.{$field->name} ?? '—' }}</p>\n"
                ."                            </div>",
            $this->ctx->fields,
        ));
    }
}
