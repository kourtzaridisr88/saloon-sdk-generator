<?php

namespace Crescat\SaloonSdkGenerator\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Exceptions\ParserNotRegisteredException;
use Crescat\SaloonSdkGenerator\Factory;
use Crescat\SaloonSdkGenerator\Generators\ComposerGenerator;
use Crescat\SaloonSdkGenerator\Generators\PhpUnitTestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Nette\PhpGenerator\PhpFile;
use ZipArchive;

class GenerateSdk extends Command
{
    protected $signature = 'generate:sdk
                            {path : Path to the API specification file to generate the SDK from, must be a local file}
                            {--type=postman : The type of API Specification (postman, openapi)}
                            {--name=Unnamed : The name of the SDK}
                            {--namespace=App\\Sdk : The root namespace of the SDK}
                            {--output=./build : The output path where the code will be created, will be created if it does not exist.}
                            {--force : Force overwriting existing files}
                            {--dry : Dry run, will only show the files to be generated, does not create or modify any files.}
                            {--zip : Generate a zip archive containing all the files}';

    protected $description = 'Generate an SDK based on an API specification file.';

    public function handle(): void
    {
        $inputPath = $this->argument('path');

        // TODO: Support remote URLs or move this into each parser class so they can deal with it instead.
        if (! file_exists($inputPath)) {
            $this->error("File not found: $inputPath");

            return;
        }

        $type = trim(strtolower($this->option('type')));

        // Append \SDK to the namespace for generated classes
        $baseNamespace = rtrim($this->option('namespace'), '\\');
        $sdkNamespace = $baseNamespace . '\\SDK';

        $generator = new CodeGenerator(
            config: new Config(
                connectorName: $this->option('name'),
                namespace: $sdkNamespace,
                resourceNamespaceSuffix: 'Resource',
                requestNamespaceSuffix: 'Requests',
                dtoNamespaceSuffix: 'Dto',
                ignoredQueryParams: [
                    'after',
                    'order_by',
                    'per_page',
                ]
            ),
        );

        // Always generate PHPUnit tests
        $generator->registerPostProcessor(new PhpUnitTestGenerator);

        // Always generate composer.json
        $generator->registerPostProcessor(new ComposerGenerator);

        try {
            $specification = Factory::parse($type, $inputPath);
        } catch (ParserNotRegisteredException) {
            // TODO: Prettier errors using termwind
            $this->error("No parser registered for --type='$type'");

            if (in_array($type, ['yml', 'yaml', 'json', 'xml'])) {
                $this->warn('Note: the --type option is used to specify the API Specification type (ex: openapi, postman), not the file format.');
            }

            $this->line('Available types: '.implode(', ', Factory::getRegisteredParserTypes()));

            return;
        }

        $result = $generator->run($specification);

        if ($this->option('dry')) {
            $this->printGeneratedFiles($result);

            return;
        }

        $this->option('zip')
            ? $this->generateZipArchive($result)
            : $this->dumpGeneratedFiles($result);
    }

    protected function printGeneratedFiles(GeneratedCode $result): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($result->connectorClass) {
            $this->line(Utils::formatNamespaceAndClass($result->connectorClass));
        }

        $this->comment("\nResources:");
        foreach ($result->resourceClasses as $resourceClass) {
            $this->line(Utils::formatNamespaceAndClass($resourceClass));
        }

        $this->comment("\nRequests:");
        foreach ($result->requestClasses as $requestClass) {
            $this->line(Utils::formatNamespaceAndClass($requestClass));
        }

        $this->comment("\nDTOs:");
        foreach ($result->dtoClasses as $dtoClass) {
            $this->line(Utils::formatNamespaceAndClass($dtoClass));
        }

        $this->comment("\nTests:");
        foreach ($result->getWithTag('phpunit') as $test) {
            $this->line($test->path);
        }
    }

    protected function dumpGeneratedFiles(GeneratedCode $result): void
    {
        $this->title('Generated Files');

        $this->comment("\nConnector:");
        if ($result->connectorClass) {
            $this->dumpToFile($result->connectorClass);
        }

        $this->comment("\nResources:");
        foreach ($result->resourceClasses as $resourceClass) {
            $this->dumpToFile($resourceClass);
        }

        $this->comment("\nRequests:");
        foreach ($result->requestClasses as $requestClass) {
            $this->dumpToFile($requestClass);
        }

        $this->comment("\nDTOs:");
        foreach ($result->dtoClasses as $dtoClass) {
            $this->dumpToFile($dtoClass);
        }

        $this->comment("\nTests:");
        foreach ($result->getWithTag('phpunit') as $test) {
            $testFilePath = $this->option('output').'/'.$test->path;

            if (! file_exists(dirname($testFilePath))) {
                mkdir(dirname($testFilePath), recursive: true);
            }

            // Check for @sdk-never-override annotation first (takes precedence over --force)
            if ($this->hasNeverOverrideAnnotation($testFilePath)) {
                $this->warn("- Protected by @sdk-never-override: $testFilePath");

                continue;
            }

            if (file_exists($testFilePath) && ! $this->option('force')) {
                $this->warn("- File already exists: $testFilePath");

                continue;
            }

            $ok = file_put_contents($testFilePath, $test->file);

            if ($ok === false) {
                $this->error("- Failed to write: $testFilePath");
            } else {
                $this->line("- Created: $testFilePath");
            }
        }

        // Handle other additional files (composer.json, etc.) - excluding test files
        $otherFiles = collect($result->additionalFiles)
            ->filter(fn ($file) => $file instanceof \Crescat\SaloonSdkGenerator\Data\TaggedOutputFile)
            ->filter(fn ($file) => $file->tag !== 'phpunit')
            ->values();

        if ($otherFiles->isNotEmpty()) {
            $this->comment("\nProject Files:");
            foreach ($otherFiles as $file) {
                $filePath = $this->option('output').'/'.$file->path;

                if (! file_exists(dirname($filePath))) {
                    mkdir(dirname($filePath), recursive: true);
                }

                // Check for @sdk-never-override annotation first (takes precedence over --force)
                if ($this->hasNeverOverrideAnnotation($filePath)) {
                    $this->warn("- Protected by @sdk-never-override: $filePath");

                    continue;
                }

                if (file_exists($filePath) && ! $this->option('force')) {
                    $this->warn("- File already exists: $filePath");

                    continue;
                }

                $ok = file_put_contents($filePath, $file->file);

                if ($ok === false) {
                    $this->error("- Failed to write: $filePath");
                } else {
                    $this->line("- Created: $filePath");
                }
            }
        }

        // Format all generated files with Pint
        $this->formatGeneratedFiles();
    }

    protected function formatGeneratedFiles(): void
    {
        $outputPath = realpath($this->option('output'));
        if ($outputPath === false) {
            return;
        }

        $vendorPath = $outputPath.'/vendor';
        $pintPath = $vendorPath.'/bin/pint';

        // Check if composer.json exists
        if (!file_exists($outputPath.'/composer.json')) {
            return;
        }

        // Check if vendor directory exists
        if (!file_exists($vendorPath)) {
            $this->comment("\n⚠ Dependencies not installed yet. Run 'composer install' in the output directory to enable code formatting.");
            return;
        }

        // Check if Pint is available
        if (!file_exists($pintPath)) {
            return;
        }

        $this->comment("\nFormatting generated files...");

        // Run Pint with absolute paths
        $command = sprintf(
            'cd %s && %s 2>&1',
            escapeshellarg($outputPath),
            './vendor/bin/pint'
        );

        exec($command, $output, $exitCode);

        if ($exitCode === 0) {
            $this->line("✓ Code formatting completed successfully");

            // Display formatted files count if available in output
            foreach ($output as $line) {
                if (str_contains($line, 'fixed') || str_contains($line, 'FIXED')) {
                    $this->line("  ".$line);
                }
            }
        } else {
            $this->warn("⚠ Code formatting completed with warnings");
            foreach ($output as $line) {
                $this->line("  ".$line);
            }
        }
    }

    /**
     * Check if a file contains the @sdk-never-override annotation.
     *
     * This annotation can be added to the class docblock to prevent
     * the file from being overridden, even when using the --force flag.
     *
     * For PHP files: Add @sdk-never-override to any docblock (typically class docblock)
     * For JSON files (composer.json): Add "x-sdk-never-override": true field
     *
     * @param string $filePath Path to the file to check
     * @return bool True if the file has the annotation, false otherwise
     */
    protected function hasNeverOverrideAnnotation(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            return false;
        }

        // For JSON files (like composer.json), check for x-sdk-never-override field
        if (str_ends_with($filePath, '.json')) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['x-sdk-never-override'])) {
                return (bool) $decoded['x-sdk-never-override'];
            }
        }

        // For PHP files, check if the file contains the @sdk-never-override annotation
        // This can be in any docblock, but typically should be in the class docblock
        return str_contains($content, '@sdk-never-override');
    }

    protected function dumpToFile(PhpFile $file, $overrideFilePath = null): void
    {
        // Get namespace and class info
        $namespace = Arr::first($file->getNamespaces())->getName();
        $className = Arr::first($file->getClasses())?->getName();

        // Remove the root namespace to get the relative path
        // E.g., App\CrescatSdk\SDK\Resource -> Resource
        // Note: We use the SDK namespace since all generated classes have \SDK appended
        $baseNamespace = rtrim($this->option('namespace'), '\\');
        $rootNamespace = $baseNamespace . '\\SDK';
        $relativePath = str_replace($rootNamespace, '', $namespace);
        $relativePath = ltrim($relativePath, '\\');
        $relativePath = str_replace('\\', '/', $relativePath);

        $filePath = $overrideFilePath ?? sprintf(
            '%s/src/SDK/%s/%s.php',
            $this->option('output'),
            $relativePath,
            $className
        );

        if (! file_exists(dirname($filePath))) {
            mkdir(dirname($filePath), recursive: true);
        }

        // Check for @sdk-never-override annotation first (takes precedence over --force)
        if ($this->hasNeverOverrideAnnotation($filePath)) {
            $this->warn("- Protected by @sdk-never-override: $filePath");

            return;
        }

        if (file_exists($filePath) && ! $this->option('force')) {
            $this->warn("- File already exists: $filePath");

            return;
        }

        $ok = file_put_contents($filePath, (string) $file);

        if ($ok === false) {
            $this->error("- Failed to write: $filePath");
        } else {
            $this->line("- Created: $filePath");
        }
    }

    protected function generateZipArchive(GeneratedCode $result): void
    {
        $zipFileName = $this->option('name').'_sdk.zip';
        $zipPath = $this->option('output').DIRECTORY_SEPARATOR.$zipFileName;

        if (! file_exists(dirname($zipPath))) {
            mkdir(dirname($zipPath), recursive: true);
        }

        if (file_exists($zipPath) && ! $this->option('force')) {
            $this->warn("- Zip archive already exists: $zipPath");

            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error("- Failed to create the ZIP archive: $zipPath");

            return;
        }

        $filesToZip = array_merge(
            [$result->connectorClass],
            $result->resourceClasses,
            $result->requestClasses,
            $result->dtoClasses,
            $result->additionalFiles,
        );

        foreach ($filesToZip as $file) {
            $filePathInZip = str_replace('\\', '/', Arr::first($file->getNamespaces())->getName()).'/'.Arr::first($file->getClasses())->getName().'.php';
            $zip->addFromString($filePathInZip, (string) $file);
            $this->line("- Wrote file to ZIP: $filePathInZip");
        }

        $zip->close();

        $this->line("- Created zip archive: $zipPath");
    }
}
