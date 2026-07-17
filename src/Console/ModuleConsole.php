<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Console;

use Illuminate\Console\Command;
use Illuminate\Console\View\Components\Factory;
use Sodeker\ModuleGenerator\Support\ModuleContext;
use Sodeker\ModuleGenerator\Support\TableDefinition;

/**
 * Toda la presentación del comando: banner, cajas, barra de progreso, resumen
 * y pasos manuales. Separado de la generación para que los generators no sepan
 * nada de consola.
 */
final class ModuleConsole
{
    /** IDs canónicos de apps del ecosistema (AppsSeeder de Suite). */
    private const APP_IDS = [
        'suite' => 1,
        'econnect' => 2,
        'sat' => 3,
        'iris' => 4,
        'tut' => 5,
        'f16' => 6,
        'fintegra' => 7,
    ];

    /** Command::$components es protegida, así que se arma una factory propia. */
    private readonly Factory $components;

    public function __construct(
        private readonly Command $command,
    ) {
        $this->components = new Factory($command->getOutput());
    }

    /**
     * Banner de apertura: logo Sódeker (varita + portal ø con destellos) en
     * púrpura de marca, con el título "MODULE GENERATOR". El púrpura se emite
     * como hex; Symfony lo degrada al color ANSI más cercano en terminales sin
     * soporte truecolor.
     */
    public function banner(): void
    {
        $art = [
            '                             ░█░',
            '                            ▒███░',
            '                    ░░   ░██  ▒',
            '                    ▒▒ ░▒█▒░',
            '                      ░██▒ ░██░',
            '                     ▒██░   ░░',
            '                    ▒██░',
            '                  ░███░',
            '                 ░██▒',
            '      ░▒▒▒▒▒▒▒▒▒▒███░',
            '    ░█████████████████',
            '    ████▒░░░░▒███▒████░',
            '    ████    ▒███░ ░███▒',
            '    ███▒   ▒██▒░   ███▒',
            '    ████░░███▒    ▒███▒',
            '    ▒████████▒▒▒▒█████░',
            '     ▒███████████████░',
            '      ░▒▒█▒▒▒▒▒▒▒░░░',
            '     ▒███▒',
            '   ░████░',
            '   ████░',
            ' ▒████░',
            '▒████░',
        ];

        $purple = '#7C3AED';

        $this->command->newLine();
        foreach ($art as $line) {
            $this->command->line("  <fg={$purple}>{$line}</>");
        }

        $this->command->newLine();
        $this->command->line('     <options=bold>M O D U L E   G E N E R A T O R</>');
        $this->command->line('     <fg=gray>Sódeker · Arquitectura DDD · maestras de Suite</>');
        $this->command->newLine();
    }

    /**
     * Muestra la tabla localizada en el SQL con sus columnas, para que el
     * usuario confirme que es la que espera.
     */
    public function tableSummary(TableDefinition $table, bool $exactMatch): void
    {
        $this->command->newLine();
        $this->drawBox("Tabla localizada · {$table->name}", [
            '<fg=gray>Coincidencia</>  '.($exactMatch
                ? '<fg=green>por nombre, con la convención del módulo</>'
                : '<fg=yellow>elegida a mano</>'),
            '<fg=gray>Columnas</>      '.'<fg=green;options=bold>'.count($table->columns).'</>',
            '<fg=gray>Claves</>        '.count($table->constraints),
        ], 'cyan');
        $this->command->newLine();

        foreach ($table->columns as $column) {
            $this->components->twoColumnDetail(
                "  <fg=cyan>{$column->name}</>",
                "<fg=gray>{$column->definition}</>",
            );
        }
        $this->command->newLine();
    }

    /**
     * No hubo coincidencia por nombre: se avisa antes de listar las tablas.
     *
     * @param  list<TableDefinition>  $tables
     */
    public function tableNotFound(string $expected, array $tables): void
    {
        $this->command->newLine();
        $this->components->warn("El SQL no trae ninguna tabla llamada <options=bold>{$expected}</>.");
        $this->command->line('  <fg=gray>Se detectaron '.count($tables).' tablas; elige cuál corresponde al módulo.</>');
        $this->command->newLine();
    }

    /**
     * Cabecera con el resumen de lo que se va a generar.
     */
    public function header(ModuleContext $ctx): void
    {
        $this->command->newLine();
        $this->drawBox("Generando módulo · {$ctx->plural}", [
            '<fg=gray>Tabla</>       '."<fg=green;options=bold>{$ctx->table}</>",
            '<fg=gray>Namespace</>   '."{$ctx->ns}\\{$ctx->plural}",
            '<fg=gray>Código</>      '.($ctx->hasCode ? '<fg=green>sí (columna code única)</>' : '<fg=gray>no</>'),
            '<fg=gray>Tipo</>        '.($ctx->isInternal ? '<fg=yellow>interno (guard Sodeker)</>' : '<fg=cyan>usuario (Casbin + menú)</>'),
        ], 'green');
        $this->command->newLine();
    }

    /**
     * Avisa que faltan carpetas base del proyecto destino.
     *
     * @param  array<string, string>  $missing  etiqueta => ruta ausente
     */
    public function missingStructure(array $missing): void
    {
        $this->command->newLine();
        $this->components->warn('El proyecto destino no tiene toda la estructura base esperada:');
        foreach ($missing as $label => $path) {
            $this->components->twoColumnDetail("<fg=yellow>{$label}</>", "<fg=gray>{$path}</>");
        }
        $this->command->line('  <fg=gray>Verifica que estás en un proyecto del ecosistema (Suite/Iris/…).</>');
        $this->command->newLine();
    }

    /**
     * Informa que el módulo ya existe y no se generó nada.
     *
     * @param  list<string>  $conflicts
     */
    public function moduleExists(string $plural, array $conflicts): void
    {
        $this->command->newLine();
        $this->components->error("El módulo '{$plural}' ya existe. No se creó nada.");
        foreach ($conflicts as $conflict) {
            $this->components->twoColumnDetail('<fg=gray>Ya existe</>', $conflict);
        }
        $this->command->newLine();
    }

    /**
     * Ejecuta las fases de generación mostrando una barra de progreso con el
     * nombre de la fase actual.
     *
     * @param  array<string, callable>  $phases
     */
    public function runPhases(array $phases): void
    {
        $bar = $this->command->getOutput()->createProgressBar(count($phases));
        $bar->setFormat('  %bar%  %percent:3s%%  <fg=gray>%message%</>');
        $bar->setBarCharacter('<fg=green>█</>');
        $bar->setProgressCharacter('<fg=green>█</>');
        $bar->setEmptyBarCharacter('░');
        $bar->setMessage('Iniciando…');
        $bar->start();

        foreach ($phases as $label => $task) {
            $bar->setMessage((string) $label);
            $bar->display();
            $task();
            $bar->advance();
            usleep(30000);
        }

        $bar->setMessage('Completado');
        $bar->finish();
        $this->command->newLine(2);
    }

    /**
     * Resumen final con el conteo de archivos y dónde quedó cada capa.
     */
    public function summary(ModuleContext $ctx, int $fileCount, string $migrationTarget): void
    {
        $this->command->newLine();
        $this->components->info("Módulo <options=bold>{$ctx->plural}</> creado correctamente");
        $this->components->twoColumnDetail('<fg=gray>Archivos generados</>', '<fg=green;options=bold>'.$fileCount.'</>');
        $this->components->twoColumnDetail('<fg=gray>Migración</>', $migrationTarget);
        $this->components->twoColumnDetail('<fg=gray>Backend</>', "app/Modules/{$ctx->plural}/");
        $this->components->twoColumnDetail('<fg=gray>Frontend</>', "resources/js/{$ctx->pagesPath}/{$ctx->plural}/");
        $this->command->newLine();
        $this->components->bulletList([
            "Ejecuta <fg=cyan>php artisan migrate</> para crear la tabla <fg=green>{$ctx->table}</>",
            "Carga la vista en <fg=cyan>{$ctx->indexUrl}</>",
        ]);
        $this->command->newLine();
    }

    /**
     * Pasos manuales para dejar el módulo operativo en la capa de permisos/menú
     * de Suite. Solo aplica a módulos de usuario (los internos no usan Casbin ni
     * menú lateral).
     */
    public function manualSteps(ModuleContext $ctx): void
    {
        if ($ctx->isInternal) {
            $this->components->warn('Módulo interno: sin menú lateral ni permisos Casbin.');
            $this->command->line('  El acceso queda restringido en el controller a la cuenta Sodeker con rol Developer.');
            $this->command->line('  Se ingresa únicamente por URL: <fg=cyan>'.$ctx->indexUrl.'</>');
            $this->command->newLine();

            return;
        }

        $appId = self::APP_IDS[$ctx->appSlug] ?? 'N /* app_id de '.$ctx->appSlug.', ajústalo */';
        $isSuiteApp = $ctx->appSlug === 'suite';

        $this->drawBox('PASOS MANUALES · permisos + menú', [
            '<fg=gray>Edita estos 3 archivos en Suite para dejar</>',
            '<fg=gray>el módulo</> <options=bold>'.$ctx->moduleCode.'</> <fg=gray>operativo.</>',
        ], 'yellow');
        $this->command->newLine();

        // 1) Registro del módulo (ModulesTenantAppsSeeder, dentro de CasbinSeeders).
        $this->command->line('  <fg=yellow;options=bold>1)</> <options=underscore>database/seeders/Landlord/CasbinSeeders/ModulesTenantAppsSeeder.php</>');
        $this->command->line('     <fg=gray>Agrega la entrada del módulo en el arreglo $modules:</>');
        $this->command->line("     <fg=green>['uuid' => Str::ulid(), 'app_id' => {$appId}, 'code' => '{$ctx->moduleCode}', 'name' => '{$ctx->visibleName}', 'status' => 1, 'apps_required' => null],</>");
        $this->command->newLine();

        // 2) Permisos Casbin.
        $this->command->line('  <fg=yellow;options=bold>2)</> <options=underscore>database/seeders/Landlord/CasbinSeeders/PermissionsTableSeeder.php</>');
        $this->command->line("     <fg=gray>Dentro del arreglo, bajo el slug de la app '{$ctx->appSlug}':</>");
        $this->command->line("     <fg=green>'{$ctx->moduleCode}' => ['view', 'edit', 'create', 'delete'],</>");
        $this->command->newLine();

        // 3) Ruta navegable: SuiteConfigModeResolver para app suite; CHILD_APP_MODULE_ROUTES para apps hijas.
        if ($isSuiteApp) {
            $this->command->line('  <fg=yellow;options=bold>3)</> <options=underscore>app/Http/Support/SuiteConfigModeResolver.php</>  <fg=gray>(const ROUTES)</>');
            $this->command->line("     <fg=green>'{$ctx->moduleCode}' => '{$ctx->indexUrl}',</>");
        } else {
            $this->command->line('  <fg=yellow;options=bold>3)</> <options=underscore>app/Http/Middleware/HandleInertiaRequests.php</>  <fg=gray>(const CHILD_APP_MODULE_ROUTES)</>');
            $this->command->line("     <fg=gray>Bajo la clave '{$ctx->appSlug}':</>");
            $this->command->line("     <fg=green>'{$ctx->moduleCode}' => '{$ctx->indexUrl}',</>");
        }
        $this->command->newLine();
    }

    /**
     * Dibuja una caja decorativa con título y líneas de contenido. Las líneas
     * pueden contener tags de color (<fg=...>, <options=...>); su ancho visible
     * se calcula ignorando dichos tags para alinear el borde derecho.
     *
     * @param  list<string>  $lines
     */
    public function drawBox(string $title, array $lines, string $border = 'cyan'): void
    {
        $inner = 60;
        $b = fn (string $s): string => "<fg={$border}>{$s}</>";
        $row = function (string $text) use ($inner, $b): void {
            $cell = ' '.$text;
            $cell .= str_repeat(' ', max(0, $inner - $this->visibleLen($cell)));
            $this->command->line('  '.$b('│').$cell.$b('│'));
        };

        $this->command->line('  '.$b('╭'.str_repeat('─', $inner).'╮'));
        if ($title !== '') {
            $row('<options=bold>'.$title.'</>');
            $this->command->line('  '.$b('├'.str_repeat('─', $inner).'┤'));
        }
        foreach ($lines as $line) {
            $row($line);
        }
        $this->command->line('  '.$b('╰'.str_repeat('─', $inner).'╯'));
    }

    /** Longitud visible de un texto con tags de consola (ignora <...>). */
    private function visibleLen(string $text): int
    {
        return mb_strlen(preg_replace('/<[^>]+>/', '', $text) ?? $text);
    }
}
