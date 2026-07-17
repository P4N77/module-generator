<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

use Sodeker\ModuleGenerator\Support\ModuleContext;
use Sodeker\ModuleGenerator\Support\ModuleWriter;
use Sodeker\ModuleGenerator\Support\StubRenderer;

/**
 * Base de los generators: contexto de solo lectura, renderer de stubs y writer.
 */
abstract class Generator
{
    public function __construct(
        protected readonly ModuleContext $ctx,
        protected readonly StubRenderer $stubs,
        protected readonly ModuleWriter $writer,
    ) {}

    /**
     * Placeholders presentes en la mayoría de stubs. Cada generator los mezcla
     * con los suyos; sobran claves sin usar y eso es inofensivo.
     *
     * @return array<string, string>
     */
    protected function common(): array
    {
        return [
            'ns' => $this->ctx->ns,
            'shared' => $this->ctx->shared,
            'plural' => $this->ctx->plural,
            'singular' => $this->ctx->singular,
            'singularCamel' => $this->ctx->singularCamel,
            'moduleCode' => $this->ctx->moduleCode,
            'kebab' => $this->ctx->kebab,
            'table' => $this->ctx->table,
            'tableName' => $this->ctx->table,
            'indexUrl' => $this->ctx->indexUrl,
            'visibleName' => $this->ctx->visibleName,
            'visibleLower' => $this->ctx->visibleLower(),
            'visibleSingular' => $this->ctx->visibleSingular(),
            'statusVo' => "{$this->ctx->singular}Status",
            'repoInterface' => "{$this->ctx->singular}RepositoryInterface",
            'interfaceName' => "{$this->ctx->singular}RepositoryInterface",
            'entityAlias' => "{$this->ctx->singular}Entity",
        ];
    }

    /**
     * Renderiza un stub con los placeholders comunes más los propios.
     *
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements = []): string
    {
        return $this->stubs->render($stub, array_merge($this->common(), $replacements));
    }

    /**
     * Une líneas de un fragmento de campos en un bloque, cada una en su renglón,
     * con salto final. Devuelve '' si no hay líneas, para que el placeholder
     * desaparezca sin dejar renglones vacíos.
     *
     * @param  list<string>  $lines  Cada línea ya con su indentación, sin salto.
     */
    protected function block(array $lines): string
    {
        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * Mapea los campos extra a líneas mediante un callback y las une en bloque.
     *
     * @param  callable(\Sodeker\ModuleGenerator\Support\ModuleField): string  $line
     */
    protected function fieldBlock(callable $line): string
    {
        return $this->block(array_map($line, $this->ctx->fields));
    }
}
