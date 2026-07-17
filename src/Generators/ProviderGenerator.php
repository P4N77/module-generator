<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Generators;

use Illuminate\Support\Facades\File;

/**
 * ServiceProvider del módulo y su registro en config/app.php.
 */
final class ProviderGenerator extends Generator
{
    public function serviceProvider(): void
    {
        $plural = $this->ctx->plural;
        $singular = $this->ctx->singular;
        $serviceProviderName = "{$plural}ServiceProvider";

        $contents = $this->render('provider/service-provider', [
            'serviceProviderName' => $serviceProviderName,
            'repositoryInterface' => "{$singular}RepositoryInterface",
            'repositoryImplementation' => "Eloquent{$singular}Repository",
            'listRepositoryInterface' => "{$plural}ListRepositoryInterface",
            'listRepositoryImplementation' => "Eloquent{$plural}ListRepository",
            'listContract' => "List{$plural}Contract",
            'listService' => "List{$plural}Service",
            'matchContract' => "Match{$plural}RowContract",
            'matchService' => "Match{$plural}RowService",
            'createContract' => "Create{$singular}Contract",
            'updateContract' => "Update{$singular}Contract",
            'deleteContract' => "Delete{$singular}Contract",
            'createService' => "Create{$singular}Service",
            'updateService' => "Update{$singular}Service",
            'deleteService' => "Delete{$singular}Service",
        ]);

        $this->writer->put(
            "{$this->ctx->basePath}/{$serviceProviderName}.php",
            $contents,
            "{$serviceProviderName}.php",
        );
    }

    /**
     * Añade el provider al array 'providers' de config/app.php.
     *
     * @return string|null Mensaje de advertencia si hubo que dejarlo al usuario.
     */
    public function registerInConfig(): ?string
    {
        $configPath = config_path('app.php');
        $content = File::get($configPath);

        $plural = $this->ctx->plural;
        $providerClass = "{$this->ctx->ns}\\{$plural}\\{$plural}ServiceProvider::class,";
        $marker = '        // Módulos (SAT)';

        if (str_contains($content, $marker)) {
            $content = str_replace(
                $marker,
                "        {$providerClass}\n        ".trim($marker),
                $content
            );
        } else {
            // Solo el primer `])->toArray(),` tras `providers`; no usar str_replace global
            // porque `aliases` cierra con la misma cadena y duplicaría el provider.
            $providersKey = "'providers' => ServiceProvider::defaultProviders()->merge([";
            $providersPos = strpos($content, $providersKey);
            if ($providersPos === false) {
                return 'No se encontró providers en config/app.php. Regístralo manualmente.';
            }

            $closing = '    ])->toArray(),';
            $insertPos = strpos($content, $closing, $providersPos);
            if ($insertPos === false) {
                return 'No se encontró el cierre del array providers en config/app.php. Regístralo manualmente.';
            }

            $content = substr($content, 0, $insertPos)
                ."        {$providerClass}\n".$closing
                .substr($content, $insertPos + strlen($closing));
        }

        File::put($configPath, $content);
        $this->writer->record("config/app.php (provider {$plural}ServiceProvider)");

        return null;
    }
}
