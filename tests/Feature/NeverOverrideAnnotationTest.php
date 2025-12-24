<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->testOutput = __DIR__.'/../Output/NeverOverrideTest';
    $this->sampleSpec = __DIR__.'/../Samples/kassalapp.json';

    // Clean up test output directory
    if (File::exists($this->testOutput)) {
        File::deleteDirectory($this->testOutput);
    }
});

afterEach(function () {
    // Clean up test output directory
    if (File::exists($this->testOutput)) {
        File::deleteDirectory($this->testOutput);
    }
});

test('@sdk-never-override annotation prevents file overwrite even with --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find a generated connector file
    $connectorPath = $this->testOutput.'/src/SDK/TestSDK.php';
    expect(File::exists($connectorPath))->toBeTrue('Connector file should exist');

    // Read the original file content
    $originalContent = File::get($connectorPath);

    // Add the @sdk-never-override annotation to the class docblock
    $modifiedContent = str_replace(
        '/**',
        "/**\n * @sdk-never-override",
        $originalContent
    );

    // Add a custom comment to verify the file is not overwritten
    $modifiedContent = str_replace(
        'class TestSDK',
        "// CUSTOM MODIFICATION - Should not be overwritten\nclass TestSDK",
        $modifiedContent
    );

    File::put($connectorPath, $modifiedContent);

    // Verify our modifications are present
    expect(File::get($connectorPath))
        ->toContain('@sdk-never-override')
        ->toContain('CUSTOM MODIFICATION');

    // Run generation again with --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp --force',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration with --force failed:\n".$result->errorOutput()
    );

    // Check that the output mentions the protected file
    expect($result->output())
        ->toContain('Protected by @sdk-never-override');

    // Verify the file was NOT overwritten (our custom comment should still be there)
    $afterForceContent = File::get($connectorPath);
    expect($afterForceContent)->toContain('@sdk-never-override');
    expect($afterForceContent)->toContain('CUSTOM MODIFICATION');
});

test('Files without @sdk-never-override annotation are overwritten with --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find a generated connector file
    $connectorPath = $this->testOutput.'/src/SDK/TestSDK.php';
    expect(File::exists($connectorPath))->toBeTrue('Connector file should exist');

    // Read the original file content
    $originalContent = File::get($connectorPath);

    // Add a custom comment WITHOUT the @sdk-never-override annotation
    $modifiedContent = str_replace(
        'class TestSDK',
        "// CUSTOM MODIFICATION - Should BE overwritten\nclass TestSDK",
        $originalContent
    );

    File::put($connectorPath, $modifiedContent);

    // Verify our modification is present
    expect(File::get($connectorPath))
        ->toContain('CUSTOM MODIFICATION');

    // Run generation again with --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp --force',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration with --force failed:\n".$result->errorOutput()
    );

    // Verify the file WAS overwritten (our custom comment should be gone)
    $afterForceContent = File::get($connectorPath);
    expect($afterForceContent)->not->toContain('CUSTOM MODIFICATION');
});

test('composer.json with x-sdk-never-override field is protected even with --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find the generated composer.json file
    $composerPath = $this->testOutput.'/composer.json';
    expect(File::exists($composerPath))->toBeTrue('composer.json file should exist');

    // Read and modify composer.json to add the protection field and a custom script
    $composer = json_decode(File::get($composerPath), true);
    $composer['x-sdk-never-override'] = true;
    $composer['scripts']['custom'] = 'echo "This is my custom script"';
    File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Verify our modifications are present
    $modifiedComposer = json_decode(File::get($composerPath), true);
    expect($modifiedComposer)->toHaveKey('x-sdk-never-override');
    expect($modifiedComposer['x-sdk-never-override'])->toBeTrue();
    expect($modifiedComposer['scripts'])->toHaveKey('custom');

    // Run generation again with --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp --force',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration with --force failed:\n".$result->errorOutput()
    );

    // Check that the output mentions the protected file
    expect($result->output())
        ->toContain('Protected by @sdk-never-override');

    // Verify composer.json was NOT overwritten (our custom script should still be there)
    $afterForceComposer = json_decode(File::get($composerPath), true);
    expect($afterForceComposer)->toHaveKey('x-sdk-never-override');
    expect($afterForceComposer['x-sdk-never-override'])->toBeTrue();
    expect($afterForceComposer['scripts'])->toHaveKey('custom');
});

test('composer.json without x-sdk-never-override field is overwritten with --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find the generated composer.json file
    $composerPath = $this->testOutput.'/composer.json';
    expect(File::exists($composerPath))->toBeTrue('composer.json file should exist');

    // Modify composer.json WITHOUT the protection field
    $composer = json_decode(File::get($composerPath), true);
    $composer['scripts']['custom'] = 'echo "This should be overwritten"';
    File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // Verify our modification is present
    $modifiedComposer = json_decode(File::get($composerPath), true);
    expect($modifiedComposer['scripts'])->toHaveKey('custom');

    // Run generation again with --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp --force',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration with --force failed:\n".$result->errorOutput()
    );

    // Verify composer.json WAS overwritten (our custom script should be gone)
    $afterForceComposer = json_decode(File::get($composerPath), true);
    expect($afterForceComposer['scripts'])->not->toHaveKey('custom');
});

test('Test files with @sdk-never-override annotation are protected even with --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find a generated test file
    $testFiles = File::glob($this->testOutput.'/tests/Feature/*Test.php');
    expect($testFiles)->not->toBeEmpty('At least one test file should exist');
    $testFilePath = $testFiles[0];

    // Read and modify the test file
    $originalContent = File::get($testFilePath);
    $modifiedContent = str_replace(
        '<?php',
        "<?php\n// CUSTOM TEST MODIFICATION - Should remain after --force",
        $originalContent
    );

    // Add the @sdk-never-override annotation
    $modifiedContent = str_replace(
        'class ',
        "/**\n * @sdk-never-override\n */\nclass ",
        $modifiedContent
    );

    File::put($testFilePath, $modifiedContent);

    // Verify our modifications are present
    expect(File::get($testFilePath))->toContain('@sdk-never-override');
    expect(File::get($testFilePath))->toContain('CUSTOM TEST MODIFICATION');

    // Run generation again with --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp --force',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration with --force failed:\n".$result->errorOutput()
    );

    // Check that the output mentions the protected file
    expect($result->output())
        ->toContain('Protected by @sdk-never-override');

    // Verify the test file was NOT overwritten
    $afterForceContent = File::get($testFilePath);
    expect($afterForceContent)->toContain('@sdk-never-override');
    expect($afterForceContent)->toContain('CUSTOM TEST MODIFICATION');
});

test('Files without @sdk-never-override annotation are NOT overwritten without --force flag', function () {
    // First, generate the SDK
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "Initial SDK generation failed:\n".$result->errorOutput()
    );

    // Find a generated connector file
    $connectorPath = $this->testOutput.'/src/SDK/TestSDK.php';
    expect(File::exists($connectorPath))->toBeTrue('Connector file should exist');

    // Read the original file content
    $originalContent = File::get($connectorPath);

    // Add a custom comment WITHOUT the @sdk-never-override annotation
    $modifiedContent = str_replace(
        'class TestSDK',
        "// CUSTOM MODIFICATION - Should be preserved\nclass TestSDK",
        $originalContent
    );

    File::put($connectorPath, $modifiedContent);

    // Verify our modification is present
    expect(File::get($connectorPath))
        ->toContain('CUSTOM MODIFICATION');

    // Run generation again WITHOUT --force flag
    $result = Process::run(sprintf(
        './codegen generate:sdk %s --type=openapi --name=TestSDK --output=%s --namespace=TestApp',
        escapeshellarg($this->sampleSpec),
        escapeshellarg($this->testOutput)
    ));

    expect($result->successful())->toBeTrue(
        "SDK regeneration without --force failed:\n".$result->errorOutput()
    );

    // Check that the output mentions the file already exists
    expect($result->output())
        ->toContain('File already exists');

    // Verify the file was NOT overwritten (our custom comment should still be there)
    $afterContent = File::get($connectorPath);
    expect($afterContent)->toContain('CUSTOM MODIFICATION');
});
