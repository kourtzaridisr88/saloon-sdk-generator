<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Data\Generator\SecuritySchemeType;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class PhpUnitTestGenerator implements PostProcessor
{
    protected Config $config;

    protected ApiSpecification $specification;

    protected GeneratedCode $generatedCode;

    protected TestStubGenerator $stubGenerator;

    protected ?array $authScheme = null;

    protected array $generatedStubs = [];

    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $this->config = $config;
        $this->specification = $specification;
        $this->generatedCode = $generatedCode;
        $this->stubGenerator = new TestStubGenerator;
        $this->authScheme = $this->detectAuthScheme();

        return $this->generateAllTests();
    }

    /**
     * Generate all test files
     *
     * @return array|TaggedOutputFile[]
     */
    protected function generateAllTests(): array
    {
        return [
            $this->generateTestCaseFile(),
            $this->generatePhpUnitXml(),
            ...$this->generateFeatureTests(),
            ...$this->generateDtoUnitTests(),
            ...$this->generatedStubs, // Include all generated stub files
        ];
    }

    /**
     * Generate the base TestCase class
     */
    protected function generateTestCaseFile(): TaggedOutputFile
    {
        $stub = file_get_contents(__DIR__.'/../Stubs/phpunit-testcase.stub');
        $stub = str_replace('{{ namespace }}', $this->config->namespace, $stub);

        return new TaggedOutputFile(
            tag: 'phpunit',
            file: $stub,
            path: 'tests/TestCase.php',
        );
    }

    /**
     * Generate phpunit.xml configuration
     */
    protected function generatePhpUnitXml(): TaggedOutputFile
    {
        $stub = file_get_contents(__DIR__.'/../Stubs/phpunit-xml.stub');

        return new TaggedOutputFile(
            tag: 'phpunit',
            file: $stub,
            path: 'phpunit.xml',
        );
    }

    /**
     * Generate feature tests for all resources
     *
     * @return array|TaggedOutputFile[]
     */
    protected function generateFeatureTests(): array
    {
        $classes = [];

        $groupedByCollection = collect($this->specification->endpoints)->groupBy(function (Endpoint $endpoint) {
            return NameHelper::resourceClassName(
                $endpoint->collection ?: $this->config->fallbackResourceName
            );
        });

        foreach ($groupedByCollection as $collection => $items) {
            $test = $this->generateResourceFeatureTest($collection, $items->toArray());
            if ($test) {
                $classes[] = $test;
            }
        }

        return $classes;
    }

    /**
     * Generate a feature test for a specific resource
     *
     * @param  array|Endpoint[]  $endpoints
     */
    protected function generateResourceFeatureTest(string $resourceName, array $endpoints): ?TaggedOutputFile
    {
        try {
            $fileStub = file_get_contents(__DIR__.'/../Stubs/phpunit-feature-test.stub');

            $fileStub = str_replace('{{ connectorName }}', $this->config->connectorName, $fileStub);
            $fileStub = str_replace('{{ namespace }}', $this->config->namespace, $fileStub);
            $fileStub = str_replace('{{ resourceName }}', $resourceName, $fileStub);

            // Get connector constructor parameters for setUp method
            $namespace = Arr::first($this->generatedCode->connectorClass->getNamespaces());
            $classType = Arr::first($namespace->getClasses());
            $constructorParameters = $classType->getMethod('__construct')->getParameters();

            $constructorArgs = [];
            foreach ($constructorParameters as $parameter) {
                if ($parameter->isNullable()) {
                    continue;
                }

                $defaultValue = match ($parameter->getType()) {
                    'string' => "'test_value'",
                    'bool' => 'true',
                    'int' => 0,
                    default => 'null',
                };

                $constructorArgs[] = $parameter->getName().': '.$defaultValue;
            }

            $fileStub = str_replace(
                '{{ connectorArgs }}',
                Str::wrap(implode(",\n            ", $constructorArgs), "\n            ", "\n        "),
                $fileStub
            );

            // Generate request imports
            $imports = [];
            foreach ($endpoints as $endpoint) {
                $requestClassName = NameHelper::resourceClassName($endpoint->name);
                $imports[] = "use {$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}\\{$requestClassName};";
            }
            $fileStub = str_replace('{{ requestImports }}', implode("\n", $imports), $fileStub);

            // Generate DTO imports
            $dtoImports = $this->generateDtoImports($endpoints);
            $fileStub = str_replace('{{ dtoImports }}', implode("\n", $dtoImports), $fileStub);

            // Generate auth import if needed
            $authImport = $this->generateAuthImport();
            $fileStub = str_replace('{{ authImport }}', $authImport, $fileStub);

            // Generate test methods
            $testMethods = '';
            foreach ($endpoints as $endpoint) {
                $testMethods .= $this->generateTestMethodForEndpoint($endpoint, $resourceName);
            }
            $fileStub = str_replace('{{ testMethods }}', $testMethods, $fileStub);

            return new TaggedOutputFile(
                tag: 'phpunit',
                file: $fileStub,
                path: "tests/Feature/{$resourceName}Test.php",
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate a test method for a specific endpoint
     */
    protected function generateTestMethodForEndpoint(Endpoint $endpoint, string $resourceName): string
    {
        $methodStub = file_get_contents(__DIR__.'/../Stubs/phpunit-feature-test-method.stub');

        $requestClassName = NameHelper::resourceClassName($endpoint->name);
        $testMethodName = $this->generateTestMethodName($endpoint);
        $stubFileName = $this->generateStubFileName($endpoint);

        $methodStub = str_replace('{{ testMethodName }}', $testMethodName, $methodStub);
        $methodStub = str_replace('{{ resourceName }}', $resourceName, $methodStub);
        $methodStub = str_replace('{{ stubFileName }}', $stubFileName, $methodStub);
        $methodStub = str_replace('{{ requestClass }}', $requestClassName, $methodStub);
        $methodStub = str_replace('{{ resourceMethod }}', NameHelper::safeVariableName($resourceName), $methodStub);
        $methodStub = str_replace('{{ endpointMethod }}', NameHelper::safeVariableName($requestClassName), $methodStub);

        // Generate auth mock
        $authMock = $this->generateAuthMock();
        $methodStub = str_replace('{{ authMock }}', $authMock, $methodStub);

        // Generate method arguments
        $methodArguments = $this->generateMethodArguments($endpoint);
        $methodStub = str_replace('{{ methodArguments }}', $methodArguments, $methodStub);

        // Generate assertSentInOrder
        $assertSentInOrder = $this->generateAssertSentInOrder($requestClassName);
        $methodStub = str_replace('{{ assertSentInOrder }}', $assertSentInOrder, $methodStub);

        // Generate parameter assertions
        $parameterAssertions = $this->generateParameterAssertions($endpoint);
        $methodStub = str_replace('{{ parameterAssertions }}', $parameterAssertions, $methodStub);

        // Generate response DTO assertion
        $responseDtoAssertion = $this->generateResponseDtoAssertion($endpoint);
        $methodStub = str_replace('{{ responseDtoAssertion }}', $responseDtoAssertion, $methodStub);

        // Generate stub file
        $this->generateStubFile($endpoint, $resourceName, $stubFileName);

        return $methodStub;
    }

    /**
     * Generate test method name from endpoint
     */
    protected function generateTestMethodName(Endpoint $endpoint): string
    {
        $name = NameHelper::safeVariableName($endpoint->name);
        $method = strtolower($endpoint->method->value);

        return "{$method}_{$name}";
    }

    /**
     * Generate stub file name
     */
    protected function generateStubFileName(Endpoint $endpoint): string
    {
        return NameHelper::safeVariableName($endpoint->name);
    }

    /**
     * Generate method arguments for endpoint
     */
    protected function generateMethodArguments(Endpoint $endpoint): string
    {
        $withoutIgnoredQueryParams = collect($endpoint->queryParameters)
            ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
            ->values()
            ->toArray();

        $withoutIgnoredHeaderParams = collect($endpoint->headerParameters)
            ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredHeaderParams))
            ->values()
            ->toArray();

        $combined = [
            ...$endpoint->pathParameters,
            ...$endpoint->bodyParameters,
            ...$withoutIgnoredQueryParams,
            ...$withoutIgnoredHeaderParams,
        ];

        $methodArguments = [];
        foreach ($combined as $param) {
            $methodArguments[] = sprintf('%s: %s', NameHelper::safeVariableName($param->name), match ($param->type) {
                'string' => "'test string'",
                'int', 'integer' => '123',
                'float', 'float|int', 'int|float' => '123.45',
                'bool', 'boolean' => 'true',
                'array' => '[]',
                default => 'null',
            });
        }

        if (empty($methodArguments)) {
            return '';
        }

        return Str::wrap(implode(",\n            ", $methodArguments), "\n            ", "\n        ");
    }

    /**
     * Generate assertSentInOrder assertions
     */
    protected function generateAssertSentInOrder(string $requestClassName): string
    {
        $assertions = [];

        if ($this->authScheme && $this->needsAuthRequest()) {
            $assertions[] = '            // TokenRequest::class,';
        }

        $assertions[] = "            {$requestClassName}::class,";

        return implode("\n", $assertions);
    }

    /**
     * Generate parameter assertions for assertSent
     */
    protected function generateParameterAssertions(Endpoint $endpoint): string
    {
        // For now, keep it simple - just check instance type
        // Could be extended to check specific parameters
        return '';
    }

    /**
     * Generate response DTO assertion
     */
    protected function generateResponseDtoAssertion(Endpoint $endpoint): string
    {
        if (!$endpoint->responseDto) {
            return '        $this->assertEquals(Response::HTTP_OK, $response->status());';
        }

        $dtoClassName = class_basename($endpoint->responseDto);

        return "        \$this->assertInstanceOf({$dtoClassName}::class, \$response);";
    }

    /**
     * Generate stub file for endpoint
     */
    protected function generateStubFile(Endpoint $endpoint, string $resourceName, string $stubFileName): void
    {
        $stubContent = $this->stubGenerator->generateStubForEndpoint($endpoint);

        $this->generatedStubs[] = new TaggedOutputFile(
            tag: 'phpunit',
            file: $stubContent,
            path: "tests/Stubs/{$resourceName}/{$stubFileName}.json",
        );
    }

    /**
     * Generate DTO unit tests
     *
     * @return array|TaggedOutputFile[]
     */
    protected function generateDtoUnitTests(): array
    {
        $tests = [];

        foreach ($this->generatedCode->dtoClasses as $dtoFile) {
            $test = $this->generateDtoTest($dtoFile);
            if ($test) {
                $tests[] = $test;
            }
        }

        return $tests;
    }

    /**
     * Generate a unit test for a DTO
     */
    protected function generateDtoTest(PhpFile $dtoFile): ?TaggedOutputFile
    {
        try {
            // Extract DTO class info
            $namespace = Arr::first($dtoFile->getNamespaces());
            $classType = Arr::first($namespace->getClasses());
            $dtoName = $classType->getName();
            $dtoFullyQualifiedName = $namespace->getName().'\\'.$dtoName;

            $stub = file_get_contents(__DIR__.'/../Stubs/phpunit-dto-test.stub');

            $stub = str_replace('{{ namespace }}', $this->config->namespace, $stub);
            $stub = str_replace('{{ dtoName }}', $dtoName, $stub);
            $stub = str_replace('{{ dtoFullyQualifiedName }}', $dtoFullyQualifiedName, $stub);

            // Generate nested DTO imports
            $nestedDtoImports = $this->generateNestedDtoImports($classType);
            $stub = str_replace('{{ nestedDtoImports }}', implode("\n", $nestedDtoImports), $stub);

            // Generate sample JSON
            $sampleJson = $this->generateSampleJsonForDto($classType);
            $stub = str_replace('{{ sampleJson }}', addslashes($sampleJson), $stub);

            // Generate property assertions
            $propertyAssertions = $this->generatePropertyAssertions($classType);
            $stub = str_replace('{{ propertyAssertions }}', $propertyAssertions, $stub);

            // Generate additional test methods
            $additionalTestMethods = $this->generateAdditionalDtoTestMethods($classType);
            $stub = str_replace('{{ additionalTestMethods }}', $additionalTestMethods, $stub);

            return new TaggedOutputFile(
                tag: 'phpunit',
                file: $stub,
                path: "tests/Unit/Dto/{$dtoName}Test.php",
            );
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Generate sample JSON for DTO
     */
    protected function generateSampleJsonForDto(ClassType $classType): string
    {
        $data = [];

        foreach ($classType->getProperties() as $property) {
            $propertyName = $property->getName();
            $type = $property->getType();

            // Generate sample value based on type
            $data[$propertyName] = $this->generateSampleValueForType($type);
        }

        return json_encode($data);
    }

    /**
     * Generate sample value based on type
     */
    protected function generateSampleValueForType(?string $type): mixed
    {
        if (!$type) {
            return null;
        }

        // Remove nullable marker
        $type = str_replace('?', '', $type);

        // Handle union types (take first type)
        if (str_contains($type, '|')) {
            $types = explode('|', $type);
            $type = $types[0];
        }

        return match ($type) {
            'string' => 'test string',
            'int' => 123,
            'float' => 123.45,
            'bool' => true,
            'array' => [],
            default => $this->isDtoType($type) ? [] : null,
        };
    }

    /**
     * Generate property assertions
     */
    protected function generatePropertyAssertions(ClassType $classType): string
    {
        $assertions = [];

        foreach ($classType->getProperties() as $property) {
            $propertyName = $property->getName();
            $assertions[] = "        // \$this->assertEquals('expected', \$dto->{$propertyName});";
        }

        return implode("\n", $assertions);
    }

    /**
     * Generate additional test methods for DTO
     */
    protected function generateAdditionalDtoTestMethods(ClassType $classType): string
    {
        $methods = [];

        // Check if DTO has nullable properties
        if ($this->hasNullableProperties($classType)) {
            $methods[] = $this->generateNullablePropertiesTest($classType);
        }

        // Check if DTO has nested DTOs
        if ($this->hasNestedDtos($classType)) {
            $methods[] = $this->generateNestedDtosTest($classType);
        }

        // Check if DTO has array collections
        if ($this->hasArrayProperties($classType)) {
            $methods[] = $this->generateArrayCollectionsTest($classType);
        }

        return implode("\n\n", $methods);
    }

    /**
     * Check if DTO has nullable properties
     */
    protected function hasNullableProperties(ClassType $classType): bool
    {
        foreach ($classType->getProperties() as $property) {
            if ($property->isNullable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if DTO has nested DTOs
     */
    protected function hasNestedDtos(ClassType $classType): bool
    {
        foreach ($classType->getProperties() as $property) {
            $type = $property->getType();
            if ($type && $this->isDtoType($type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if DTO has array properties
     */
    protected function hasArrayProperties(ClassType $classType): bool
    {
        foreach ($classType->getProperties() as $property) {
            $type = $property->getType();
            if ($type && str_contains($type, 'array')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generate nullable properties test
     */
    protected function generateNullablePropertiesTest(ClassType $classType): string
    {
        $dtoName = $classType->getName();

        return "    #[Test]
    public function it_handles_nullable_properties(): void
    {
        // Test with minimal required data
        \$data = [];
        // TODO: Add only required properties

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
    }";
    }

    /**
     * Generate nested DTOs test
     */
    protected function generateNestedDtosTest(ClassType $classType): string
    {
        $dtoName = $classType->getName();

        return "    #[Test]
    public function it_handles_nested_dtos(): void
    {
        // Test with nested DTO data
        \$data = [];
        // TODO: Add nested DTO structure

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
        // TODO: Assert nested DTO instances
    }";
    }

    /**
     * Generate array collections test
     */
    protected function generateArrayCollectionsTest(ClassType $classType): string
    {
        $dtoName = $classType->getName();

        return "    #[Test]
    public function it_handles_array_collections(): void
    {
        // Test with array collections
        \$data = [];
        // TODO: Add array collection data

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
        // TODO: Assert array collections
    }";
    }

    /**
     * Detect authentication scheme from API specification
     */
    protected function detectAuthScheme(): ?array
    {
        if (!$this->specification->components || empty($this->specification->components->securitySchemes)) {
            return null;
        }

        $scheme = Arr::first($this->specification->components->securitySchemes);

        return match ($scheme->type) {
            SecuritySchemeType::apiKey => [
                'type' => 'apiKey',
                'name' => $scheme->name,
                'in' => $scheme->in->value ?? 'header',
            ],
            SecuritySchemeType::http => [
                'type' => $scheme->scheme ?? 'bearer',
            ],
            SecuritySchemeType::oauth2 => [
                'type' => 'oauth2',
            ],
            default => null,
        };
    }

    /**
     * Check if auth request is needed in mocks
     */
    protected function needsAuthRequest(): bool
    {
        if (!$this->authScheme) {
            return false;
        }

        $type = $this->authScheme['type'];

        // Bearer and OAuth2 usually need a token request
        return in_array($type, ['bearer', 'oauth2']);
    }

    /**
     * Generate auth import statement
     */
    protected function generateAuthImport(): string
    {
        if (!$this->authScheme || !$this->needsAuthRequest()) {
            return '';
        }

        // For now, assume a generic TokenRequest
        // This could be improved to detect the actual auth request class
        return "// use {$this->config->namespace}\\Requests\\TokenRequest;";
    }

    /**
     * Generate auth mock for MockClient
     */
    protected function generateAuthMock(): string
    {
        if (!$this->authScheme || !$this->needsAuthRequest()) {
            return '';
        }

        return "            // TokenRequest::class => MockResponse::make(['access_token' => 'test_token'], Response::HTTP_OK),\n";
    }

    /**
     * Generate DTO import statements for endpoints
     *
     * @param  array|Endpoint[]  $endpoints
     */
    protected function generateDtoImports(array $endpoints): array
    {
        $dtoTypes = [];

        foreach ($endpoints as $endpoint) {
            if ($endpoint->responseDto) {
                $dtoTypes[$endpoint->responseDto] = true;
            }

            foreach ($endpoint->allParameters() as $parameter) {
                if ($this->isDtoType($parameter->type)) {
                    $dtoTypes[$parameter->type] = true;
                }
            }
        }

        $imports = [];
        foreach (array_keys($dtoTypes) as $dtoType) {
            $imports[] = "use {$dtoType};";
        }

        sort($imports);

        return $imports;
    }

    /**
     * Generate nested DTO imports for a DTO class
     */
    protected function generateNestedDtoImports(ClassType $classType): array
    {
        $imports = [];

        foreach ($classType->getProperties() as $property) {
            $type = $property->getType();
            if ($type && $this->isDtoType($type)) {
                $imports[] = "use {$type};";
            }
        }

        return array_unique($imports);
    }

    /**
     * Check if a type is a DTO type
     */
    protected function isDtoType(string $type): bool
    {
        // Remove nullable and union type markers
        $type = str_replace(['?', '|null'], '', $type);

        // Must contain a backslash (namespace separator)
        if (!str_contains($type, '\\')) {
            return false;
        }

        // Must start with our configured namespace
        if (!str_starts_with($type, $this->config->namespace)) {
            return false;
        }

        // Must be in the DTO namespace
        $dtoNamespacePart = "\\{$this->config->dtoNamespaceSuffix}\\";

        return str_contains($type, $dtoNamespacePart);
    }
}
