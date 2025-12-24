<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\PhpFile;

class ComposerGenerator implements PostProcessor
{
    public function __construct() {}

    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $composer = [
            'name' => $this->generatePackageName($config),
            'description' => "{$specification->name} SDK",
            'type' => 'library',
            'require' => [
                'php' => '^8.1',
                'saloonphp/saloon' => '^3.0',
                'spatie/laravel-data' => '^3.0|^4.0',
            ],
            'require-dev' => $this->getDevDependencies(),
            'autoload' => [
                'psr-4' => [
                    $this->getBaseNamespace($config->namespace) => 'src/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    "Tests\\{$this->getBaseNamespace($config->namespace)}" => 'tests/',
                ],
            ],
            'scripts' => [
                'test' => 'vendor/bin/phpunit',
                'test-coverage' => 'vendor/bin/phpunit --coverage-html coverage',
                'format' => 'vendor/bin/pint',
            ],
        ];

        $generatedCode->addAdditionalFile(
            new TaggedOutputFile(
                tag: 'composer',
                file: json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                path: 'composer.json',
            )
        );

        // Add pint.json configuration
        $pintConfig = file_get_contents(__DIR__.'/../Stubs/pint.stub');
        $generatedCode->addAdditionalFile(
            new TaggedOutputFile(
                tag: 'composer',
                file: $pintConfig,
                path: 'pint.json',
            )
        );

        return [];
    }

    protected function generatePackageName(Config $config): string
    {
        $namespaceParts = explode('\\', $config->namespace);

        // Normalize vendor and package names for Composer
        $vendor = $this->toComposerName(NameHelper::normalize($namespaceParts[0] ?? 'vendor'));
        $package = $this->toComposerName(NameHelper::normalize($config->connectorName ?? 'sdk'));

        return "{$vendor}/{$package}";
    }

    protected function toComposerName(string $value): string
    {
        // Convert to lowercase and replace spaces/underscores with hyphens
        return strtolower(str_replace(['_', ' '], '-', $value));
    }

    protected function getDevDependencies(): array
    {
        return [
            'phpunit/phpunit' => '^10.0|^11.0',
            'orchestra/testbench' => '^8.0|^9.0',
            'laravel/pint' => '^1.0',
        ];
    }

    /**
     * Get base namespace by removing \SDK suffix
     *
     * @param string $namespace The full namespace (e.g., HackTheBox\ContentClient\SDK)
     * @param bool $withTrailingSlash Whether to add trailing backslashes
     * @return string The base namespace (e.g., HackTheBox\ContentClient\)
     */
    protected function getBaseNamespace(string $namespace, bool $withTrailingSlash = true): string
    {
        // Remove \SDK suffix if present
        $baseNamespace = preg_replace('/\\\\SDK$/', '', $namespace);

        return $withTrailingSlash ? $baseNamespace . '\\' : $baseNamespace;
    }
}
