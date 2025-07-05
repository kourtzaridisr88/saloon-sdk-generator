<?php

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\BaseUrl;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Generators\ComposerGenerator;

it('generates composer.json without pest tests', function () {
    $config = new Config(
        connectorName: 'TestAPI',
        namespace: 'Acme\\TestSDK',
        resourceNamespaceSuffix: 'Resource',
        requestNamespaceSuffix: 'Requests',
        dtoNamespaceSuffix: 'Dto'
    );

    $apiSpec = new ApiSpecification(
        name: 'Test API',
        description: 'A test API for unit testing',
        baseUrl: new BaseUrl('https://api.example.com'),
        endpoints: []
    );

    $generatedCode = new GeneratedCode;

    $generator = new ComposerGenerator;
    $result = $generator->process($config, $apiSpec, $generatedCode);

    // Check that the generator returns an empty array
    expect($result)->toBe([]);

    // Check that a file was added to additionalFiles
    expect($generatedCode->additionalFiles)->toHaveCount(1);

    $composerFile = $generatedCode->additionalFiles[0];
    expect($composerFile)->toBeInstanceOf(TaggedOutputFile::class);
    expect($composerFile->tag)->toBe('composer');
    expect($composerFile->path)->toBe('composer.json');

    // Parse the JSON to check contents
    $composerData = json_decode($composerFile->file, true);

    expect($composerData['name'])->toBe('acme/test-api');
    expect($composerData['description'])->toBe('Test API SDK');
    expect($composerData['type'])->toBe('library');
    expect($composerData['require']['php'])->toBe('^8.1');
    expect($composerData['require']['saloonphp/saloon'])->toBe('^3.0');
    expect($composerData['require']['spatie/laravel-data'])->toBe('^3.0|^4.0');
    expect($composerData['autoload']['psr-4'])->toBe(['Acme\\TestSDK\\' => 'src/']);

    // Should have PHPUnit as default when no Pest
    expect($composerData['require-dev']['phpunit/phpunit'])->toBe('^10.0|^11.0');
    expect($composerData['scripts']['test'])->toBe('vendor/bin/phpunit');
});

it('generates composer.json with pest tests', function () {
    $config = new Config(
        connectorName: 'TestAPI',
        namespace: 'Acme\\TestSDK',
        resourceNamespaceSuffix: 'Resource',
        requestNamespaceSuffix: 'Requests',
        dtoNamespaceSuffix: 'Dto'
    );

    $apiSpec = new ApiSpecification(
        name: 'Test API',
        description: 'A test API for unit testing',
        baseUrl: new BaseUrl('https://api.example.com'),
        endpoints: []
    );

    $generatedCode = new GeneratedCode;

    // Add some test files to simulate Pest test generation
    $generatedCode->addAdditionalFile(new TaggedOutputFile(
        tag: 'pest',
        file: '<?php',
        path: 'tests/Pest.php'
    ));

    $generatedCode->addAdditionalFile(new TaggedOutputFile(
        tag: 'test',
        file: '<?php',
        path: 'tests/ExampleTest.php'
    ));

    $generator = new ComposerGenerator(pestEnabled: true);
    $result = $generator->process($config, $apiSpec, $generatedCode);

    // The composer file should be the third additional file
    expect($generatedCode->additionalFiles)->toHaveCount(3);

    // Find the composer file
    $composerFile = null;
    foreach ($generatedCode->additionalFiles as $file) {
        if ($file->tag === 'composer') {
            $composerFile = $file;
            break;
        }
    }

    expect($composerFile)->not->toBeNull();

    // Parse the JSON to check Pest-specific contents
    $composerData = json_decode($composerFile->file, true);

    // Should have Pest dependencies
    expect($composerData['require-dev']['pestphp/pest'])->toBe('^2.0');
    expect($composerData['require-dev']['orchestra/testbench'])->toBe('^8.0|^9.0');
    expect($composerData['require-dev']['saloonphp/laravel-plugin'])->toBe('^3.0');
    expect($composerData['require-dev']['vlucas/phpdotenv'])->toBe('^5.6');
    expect($composerData['require-dev']['spatie/laravel-data'])->toBe('^3.0|^4.0');

    // Should have test autoloading
    expect($composerData['autoload-dev']['psr-4'])->toBe(['Acme\\TestSDK\\Tests\\' => 'tests/']);

    // Should have Pest script
    expect($composerData['scripts']['test'])->toBe('vendor/bin/pest');

    // Should allow Pest plugin
    expect($composerData['config']['allow-plugins']['pestphp/pest-plugin'])->toBe(true);
});

it('handles special characters in namespace correctly', function () {
    $config = new Config(
        connectorName: 'My API-Test',
        namespace: 'VendorName\\MyAPI_Test',
        resourceNamespaceSuffix: 'Resource',
        requestNamespaceSuffix: 'Requests',
        dtoNamespaceSuffix: 'Dto'
    );

    $apiSpec = new ApiSpecification(
        name: 'Test API',
        description: 'A test API',
        baseUrl: new BaseUrl('https://api.example.com'),
        endpoints: []
    );

    $generatedCode = new GeneratedCode;

    $generator = new ComposerGenerator;
    $generator->process($config, $apiSpec, $generatedCode);

    $composerFile = $generatedCode->additionalFiles[0];
    $composerData = json_decode($composerFile->file, true);

    // Should convert to kebab-case properly
    expect($composerData['name'])->toBe('vendor-name/my-api-test');
});
