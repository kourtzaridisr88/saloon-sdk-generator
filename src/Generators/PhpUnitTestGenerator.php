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
            $this->generateTestbenchYaml(),
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
        $stub = str_replace('{{ namespace }}', $this->getTestNamespace(), $stub);

        // Convert namespace to PSR-4 path
        $testPath = $this->getTestPath('TestCase.php');

        return new TaggedOutputFile(
            tag: 'phpunit',
            file: $stub,
            path: $testPath,
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
     * Generate testbench.yaml configuration for Orchestra Testbench
     */
    protected function generateTestbenchYaml(): TaggedOutputFile
    {
        $stub = file_get_contents(__DIR__.'/../Stubs/testbench.stub');

        return new TaggedOutputFile(
            tag: 'phpunit',
            file: $stub,
            path: 'testbench.yaml',
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
            $fileStub = str_replace('{{ sdkNamespace }}', $this->config->namespace, $fileStub);
            $fileStub = str_replace('{{ namespace }}', $this->getTestNamespace(), $fileStub);
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

            // Convert namespace to PSR-4 path
            $testPath = $this->getTestPath("Feature/{$resourceName}Test.php");

            return new TaggedOutputFile(
                tag: 'phpunit',
                file: $fileStub,
                path: $testPath,
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

        // Normalize the DTO class name
        $dtoClassName = $this->normalizeDtoClassName($endpoint->responseDto);

        return "        \$this->assertInstanceOf({$dtoClassName}::class, \$response);";
    }

    /**
     * Generate stub file for endpoint
     */
    protected function generateStubFile(Endpoint $endpoint, string $resourceName, string $stubFileName): void
    {
        $stubContent = $this->stubGenerator->generateStubForEndpoint($endpoint);

        // Stubs path relative to tests root
        $stubPath = $this->getTestPath("Stubs/{$resourceName}/{$stubFileName}.json");

        $this->generatedStubs[] = new TaggedOutputFile(
            tag: 'phpunit',
            file: $stubContent,
            path: $stubPath,
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

            $stub = str_replace('{{ namespace }}', $this->getTestNamespace(), $stub);
            $stub = str_replace('{{ dtoName }}', $dtoName, $stub);
            $stub = str_replace('{{ dtoFullyQualifiedName }}', $dtoFullyQualifiedName, $stub);

            // Generate nested DTO imports
            $nestedDtoImports = $this->generateNestedDtoImports($classType);
            $stub = str_replace('{{ nestedDtoImports }}', implode("\n", $nestedDtoImports), $stub);

            // Generate sample data array with all properties (including nullable ones)
            $sampleDataArray = $this->generatePhpArrayString($this->generateFullPropertiesData($classType));
            $stub = str_replace('{{ sampleDataArray }}', $sampleDataArray, $stub);

            // Generate property assertions
            $propertyAssertions = $this->generatePropertyAssertions($classType);
            $stub = str_replace('{{ propertyAssertions }}', $propertyAssertions, $stub);

            // Generate additional test methods
            $additionalTestMethods = $this->generateAdditionalDtoTestMethods($classType);
            $stub = str_replace('{{ additionalTestMethods }}', $additionalTestMethods, $stub);

            // Convert namespace to PSR-4 path
            $testPath = $this->getTestPath("Unit/Dto/{$dtoName}Test.php");

            return new TaggedOutputFile(
                tag: 'phpunit',
                file: $stub,
                path: $testPath,
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

        // Get properties from constructor promoted parameters
        $constructor = $classType->getMethod('__construct');
        if (!$constructor) {
            return json_encode($data);
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $propertyName = $param->getName();
            $type = $param->getType();

            // Skip nullable properties with defaults - they're optional
            if ($param->isNullable() && $param->hasDefaultValue()) {
                continue;
            }

            // Generate sample value based on type
            $data[$propertyName] = $this->generateSampleValueForType($type, $propertyName);
        }

        return json_encode($data);
    }

    /**
     * Generate sample value based on type
     */
    protected function generateSampleValueForType(?string $type, string $propertyName = '', bool $includeNullable = false, int $depth = 0): mixed
    {
        if (!$type) {
            return null;
        }

        // Prevent infinite recursion
        if ($depth > 3) {
            return null;
        }

        // Remove nullable marker
        $type = str_replace('?', '', $type);

        // Handle union types (take first type)
        if (str_contains($type, '|')) {
            $types = explode('|', $type);
            $type = $types[0];
        }

        // Generate context-aware string values
        if ($type === 'string') {
            return match (true) {
                str_contains(strtolower($propertyName), 'email') => 'test@example.com',
                str_contains(strtolower($propertyName), 'url') || str_contains(strtolower($propertyName), 'link') => 'https://example.com',
                str_contains(strtolower($propertyName), 'phone') => '+1234567890',
                str_contains(strtolower($propertyName), 'id') => 'test-id-123',
                str_contains(strtolower($propertyName), 'name') => 'Test Name',
                str_contains(strtolower($propertyName), 'description') => 'Test description',
                default => 'test string',
            };
        }

        return match ($type) {
            'int' => 123,
            'float' => 123.45,
            'bool' => true,
            'array' => [],
            default => $this->isDtoType($type) ? $this->generateNestedDtoSampleData($type, $includeNullable, $depth + 1) : null,
        };
    }

    /**
     * Generate sample data for nested DTO
     */
    protected function generateNestedDtoSampleData(string $dtoType, bool $includeNullable = false, int $depth = 0): array
    {
        // Prevent infinite recursion
        if ($depth > 3) {
            return [];
        }

        // Try to find the DTO in generated DTOs
        foreach ($this->generatedCode->dtoClasses as $dtoFile) {
            $namespace = Arr::first($dtoFile->getNamespaces());
            $classType = Arr::first($namespace->getClasses());
            $fullClassName = $namespace->getName() . '\\' . $classType->getName();

            if ($fullClassName === $dtoType || class_basename($dtoType) === $classType->getName()) {
                $data = [];
                $constructor = $classType->getMethod('__construct');

                if (!$constructor) {
                    return $data;
                }

                foreach ($constructor->getParameters() as $param) {
                    if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                        continue;
                    }

                    // Skip nullable properties with defaults unless includeNullable is true
                    if (!$includeNullable && $param->isNullable() && $param->hasDefaultValue()) {
                        continue;
                    }

                    $data[$param->getName()] = $this->generateSampleValueForType($param->getType(), $param->getName(), $includeNullable, $depth + 1);
                }

                return $data;
            }
        }

        return [];
    }

    /**
     * Generate property assertions
     */
    protected function generatePropertyAssertions(ClassType $classType): string
    {
        $assertions = [];

        $constructor = $classType->getMethod('__construct');
        if (!$constructor) {
            return '';
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $propertyName = $param->getName();
            $type = $param->getType();

            // Remove nullable marker for type checking
            $cleanType = str_replace('?', '', $type ?? '');

            // Handle union types
            $isUnionType = str_contains($cleanType, '|');
            $isScalarType = false;

            // Generate type assertion
            if ($isUnionType) {
                // Special handling for common union types
                if ($cleanType === 'int|float') {
                    $typeAssertion = "\$this->assertIsNumeric(\$dto->{$propertyName});";
                    $isScalarType = true;
                } else {
                    // For other union types, just check it's not null
                    $typeAssertion = "\$this->assertNotNull(\$dto->{$propertyName});";
                    // Check if all types in union are scalar
                    $types = explode('|', $cleanType);
                    $isScalarType = !empty(array_intersect($types, ['string', 'int', 'float', 'bool']));
                }
            } else {
                $typeAssertion = match ($cleanType) {
                    'string' => "\$this->assertIsString(\$dto->{$propertyName});",
                    'int' => "\$this->assertIsInt(\$dto->{$propertyName});",
                    'float' => "\$this->assertIsFloat(\$dto->{$propertyName});",
                    'bool' => "\$this->assertIsBool(\$dto->{$propertyName});",
                    'array' => "\$this->assertIsArray(\$dto->{$propertyName});",
                    default => $this->isDtoType($cleanType)
                        ? "\$this->assertInstanceOf(" . class_basename($cleanType) . "::class, \$dto->{$propertyName});"
                        : "\$this->assertNotNull(\$dto->{$propertyName});",
                };
                $isScalarType = in_array($cleanType, ['string', 'int', 'float', 'bool']);
            }

            $assertions[] = "        {$typeAssertion}";

            // Generate value assertion for scalar types
            if ($isScalarType) {
                $assertions[] = "        \$this->assertEquals(\$data['{$propertyName}'], \$dto->{$propertyName});";
            }
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
        $constructor = $classType->getMethod('__construct');
        if (!$constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            if ($param instanceof \Nette\PhpGenerator\PromotedParameter && $param->isNullable() && $param->hasDefaultValue()) {
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
        $constructor = $classType->getMethod('__construct');
        if (!$constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = $param->getType();
            if ($type && $this->isDtoType(str_replace('?', '', $type))) {
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
        $constructor = $classType->getMethod('__construct');
        if (!$constructor) {
            return false;
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = $param->getType();
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

        // Generate data with only required properties (no nullable ones)
        $requiredData = $this->generateRequiredPropertiesData($classType);
        $dataArray = $this->generatePhpArrayString($requiredData);

        // Get nullable property names for assertions
        $nullableProperties = $this->getNullablePropertyNames($classType);
        $assertions = [];
        foreach ($nullableProperties as $propName) {
            $assertions[] = "        \$this->assertNull(\$dto->{$propName});";
        }
        $assertionsStr = implode("\n", $assertions);

        return "    #[Test]
    public function it_handles_nullable_properties(): void
    {
        // Test with only required properties, nullable ones should be null
        \$data = {$dataArray};

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
{$assertionsStr}
    }";
    }

    /**
     * Generate nested DTOs test
     */
    protected function generateNestedDtosTest(ClassType $classType): string
    {
        $dtoName = $classType->getName();

        // Generate full data including nested DTOs
        $fullData = $this->generateFullPropertiesData($classType);
        $dataArray = $this->generatePhpArrayString($fullData);

        // Get nested DTO properties for assertions
        $nestedDtoAssertions = $this->getNestedDtoAssertions($classType);

        return "    #[Test]
    public function it_handles_nested_dtos(): void
    {
        // Test with nested DTO data
        \$data = {$dataArray};

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
{$nestedDtoAssertions}
    }";
    }

    /**
     * Generate array collections test
     */
    protected function generateArrayCollectionsTest(ClassType $classType): string
    {
        $dtoName = $classType->getName();

        // Generate data with array collections
        $dataWithArrays = $this->generateDataWithArrays($classType);
        $dataArray = $this->generatePhpArrayString($dataWithArrays);

        // Get array property assertions
        $arrayAssertions = $this->getArrayPropertyAssertions($classType);

        return "    #[Test]
    public function it_handles_array_collections(): void
    {
        // Test with array collections
        \$data = {$dataArray};

        \$dto = {$dtoName}::from(\$data);

        \$this->assertInstanceOf({$dtoName}::class, \$dto);
{$arrayAssertions}
    }";
    }

    /**
     * Generate data with only required properties
     */
    protected function generateRequiredPropertiesData(ClassType $classType): array
    {
        $data = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return $data;
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            // Skip nullable properties with defaults
            if ($param->isNullable() && $param->hasDefaultValue()) {
                continue;
            }

            $data[$param->getName()] = $this->generateSampleValueForType($param->getType(), $param->getName());
        }

        return $data;
    }

    /**
     * Generate data with all properties including optional ones
     */
    protected function generateFullPropertiesData(ClassType $classType): array
    {
        $data = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return $data;
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            // Include all properties including nullable ones
            $data[$param->getName()] = $this->generateSampleValueForType($param->getType(), $param->getName(), true);
        }

        return $data;
    }

    /**
     * Generate data with array properties
     */
    protected function generateDataWithArrays(ClassType $classType): array
    {
        $data = $this->generateFullPropertiesData($classType);
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return $data;
        }

        // Make sure array properties have multiple items
        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = $param->getType();
            if ($type && str_contains($type, 'array')) {
                // Generate 2 sample items for arrays
                $data[$param->getName()] = [
                    $this->generateSampleValueForType('string', 'item'),
                    $this->generateSampleValueForType('string', 'item'),
                ];
            }
        }

        return $data;
    }

    /**
     * Get nullable property names
     */
    protected function getNullablePropertyNames(ClassType $classType): array
    {
        $names = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return $names;
        }

        foreach ($constructor->getParameters() as $param) {
            if ($param instanceof \Nette\PhpGenerator\PromotedParameter && $param->isNullable() && $param->hasDefaultValue()) {
                $names[] = $param->getName();
            }
        }

        return $names;
    }

    /**
     * Get nested DTO assertions
     */
    protected function getNestedDtoAssertions(ClassType $classType): string
    {
        $assertions = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return '';
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = str_replace('?', '', $param->getType() ?? '');
            if ($this->isDtoType($type)) {
                $propName = $param->getName();
                $dtoClassName = class_basename($type);
                $assertions[] = "        \$this->assertInstanceOf({$dtoClassName}::class, \$dto->{$propName});";
            }
        }

        return implode("\n", $assertions);
    }

    /**
     * Get array property assertions
     */
    protected function getArrayPropertyAssertions(ClassType $classType): string
    {
        $assertions = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return '';
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = $param->getType();
            if ($type && str_contains($type, 'array')) {
                $propName = $param->getName();
                $assertions[] = "        \$this->assertIsArray(\$dto->{$propName});";
                $assertions[] = "        \$this->assertCount(2, \$dto->{$propName});";
            }
        }

        return implode("\n", $assertions);
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
            // Normalize the DTO class name
            $className = $this->normalizeDtoClassName($dtoType);

            // Construct proper namespace using config
            $properNamespace = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$className}";

            $imports[] = "use {$properNamespace};";
        }

        sort($imports);

        return $imports;
    }

    /**
     * Convert PHP array to formatted array string for test code
     */
    protected function generatePhpArrayString(array $data, int $indent = 3): string
    {
        if (empty($data)) {
            return '[]';
        }

        $lines = [];
        $indentStr = str_repeat('    ', $indent);

        foreach ($data as $key => $value) {
            $formattedValue = $this->formatArrayValue($value, $indent + 1);
            $lines[] = "{$indentStr}'{$key}' => {$formattedValue},";
        }

        $openIndent = str_repeat('    ', $indent - 1);
        return "[\n" . implode("\n", $lines) . "\n{$openIndent}]";
    }

    /**
     * Format a value for PHP array string
     */
    protected function formatArrayValue(mixed $value, int $indent): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_array($value)) {
            if (empty($value)) {
                return '[]';
            }

            // Check if it's an associative array
            $isAssoc = array_keys($value) !== range(0, count($value) - 1);

            if ($isAssoc) {
                // Nested associative array
                $lines = [];
                $indentStr = str_repeat('    ', $indent);

                foreach ($value as $k => $v) {
                    $formattedValue = $this->formatArrayValue($v, $indent + 1);
                    $lines[] = "{$indentStr}'{$k}' => {$formattedValue},";
                }

                $openIndent = str_repeat('    ', $indent - 1);
                return "[\n" . implode("\n", $lines) . "\n{$openIndent}]";
            } else {
                // Indexed array
                $items = array_map(fn($v) => $this->formatArrayValue($v, $indent), $value);
                return '[' . implode(', ', $items) . ']';
            }
        }

        return 'null';
    }

    /**
     * Generate nested DTO imports for a DTO class
     */
    protected function generateNestedDtoImports(ClassType $classType): array
    {
        $imports = [];
        $constructor = $classType->getMethod('__construct');

        if (!$constructor) {
            return $imports;
        }

        foreach ($constructor->getParameters() as $param) {
            if (!$param instanceof \Nette\PhpGenerator\PromotedParameter) {
                continue;
            }

            $type = str_replace('?', '', $param->getType() ?? '');
            if ($type && $this->isDtoType($type)) {
                // Normalize the DTO class name
                $className = $this->normalizeDtoClassName($type);

                $properNamespace = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$className}";
                $imports[] = "use {$properNamespace};";
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

        // Normalize namespace separators (replace dots with backslashes)
        $type = str_replace('.', '\\', $type);

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

    /**
     * Get test file path
     * For namespace App\CrescatSdk:
     *   - PHP namespace: Tests\App\CrescatSdk\Feature
     *   - File path: tests/Feature/
     *   - Composer autoload-dev: "Tests\App\CrescatSdk\" => "tests/"
     */
    protected function getTestPath(string $relativePath): string
    {
        // Tests go directly in tests/{relativePath}
        // Namespace is Tests\{Config->namespace}\{relativePath} but files are in tests/{relativePath}
        return "tests/{$relativePath}";
    }

    /**
     * Get test namespace by removing \SDK suffix
     *
     * For namespace App\CrescatSdk\SDK:
     *   - Returns: App\CrescatSdk (for use in Tests\App\CrescatSdk)
     */
    protected function getTestNamespace(): string
    {
        // Remove \SDK suffix if present
        return preg_replace('/\\\\SDK$/', '', $this->config->namespace);
    }

    /**
     * Normalize DTO class name from various formats
     * Handles:
     *   - "v1.ProfessionalLabResource" -> "V1ProfessionalLabResource"
     *   - "address" -> "Address"
     *   - "App\Sdk\Dto\UserDto" -> "UserDto"
     */
    protected function normalizeDtoClassName(string $dtoType): string
    {
        // If it contains backslashes, it's already a proper namespace - extract basename
        if (str_contains($dtoType, '\\')) {
            return class_basename($dtoType);
        }

        // If it contains dots, it's dot-separated like "v1.ProfessionalLab"
        // Remove dots and capitalize first letter: "v1ProfessionalLab" -> "V1ProfessionalLab"
        if (str_contains($dtoType, '.')) {
            $normalized = str_replace('.', '', $dtoType);
            return ucfirst($normalized);
        }

        // Simple class name without namespace separators
        // Capitalize first letter: "address" -> "Address"
        return ucfirst($dtoType);
    }
}
