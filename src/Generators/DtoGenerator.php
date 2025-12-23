<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;

class DtoGenerator extends Generator
{
    protected array $generated = [];

    protected bool $paginationMetaDtoGenerated = false;

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        // Check if any endpoints have paginated responses
        $hasPaginatedResponses = collect($specification->endpoints)
            ->contains(fn($endpoint) => $endpoint->responseDtoIsPaginated);

        // Generate base pagination DTOs if needed
        if ($hasPaginatedResponses && !$this->paginationMetaDtoGenerated) {
            $this->generatePaginatedResponseMetaDto();
        }

        if ($specification->components) {
            foreach ($specification->components->schemas as $className => $schema) {
                $this->generateDtoClass(NameHelper::safeClassName($className), $schema);
            }
        }

        // Generate paginated response DTOs for endpoints with paginated responses
        foreach ($specification->endpoints as $endpoint) {
            if ($endpoint->responseDtoIsPaginated && $endpoint->responseDto) {
                $this->generatePaginatedResponseDto($endpoint->responseDto);
            }
        }

        return $this->generated;
    }

    protected function generateDtoClass($className, Schema $schema)
    {
        /** @var Schema[] $properties */
        $properties = $schema->properties ?? [];

        $classType = new ClassType($className);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        $classType->setExtends(Data::class)
            ->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        $classConstructor = $classType->addMethod('__construct');

        $generatedMappings = false;
        $referencedDtos = [];

        foreach ($properties as $propertyName => $propertySpec) {
            $type = $this->convertOpenApiTypeToPhp($propertySpec);

            // Check if this is a reference to another schema
            if ($propertySpec instanceof Reference) {
                // For references, we need to use the DTO class name
                // The schema name from the reference is already the base name (e.g., "User")
                // We need to apply the same transformation as we do for the DTO class names
                $schemaName = $type;
                $dtoClassName = NameHelper::dtoClassName($schemaName);
                // Use the FQN for the type
                $type = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";
                // Track referenced DTOs
                $referencedDtos[] = $dtoClassName;
            }

            // Check if this is an array with items that reference another schema
            $arrayItemDtoClass = null;
            if ($type === 'array' && isset($propertySpec->items) && $propertySpec->items instanceof Reference) {
                // Extract the schema name from the reference
                $schemaName = Str::afterLast($propertySpec->items->getReference(), '/');
                $dtoClassName = NameHelper::dtoClassName($schemaName);
                // Set the type to array
                $type = 'array';
                // Track referenced DTOs
                $referencedDtos[] = $dtoClassName;
                // Store the DTO class name for PHPDoc
                $arrayItemDtoClass = $dtoClassName;
            }

            $sub = NameHelper::dtoClassName($type);

            if ($type === 'object' || $type == 'array') {

                if (! isset($this->generated[$sub]) && ! empty($propertySpec->properties)) {
                    $this->generated[$sub] = $this->generateDtoClass($propertyName, $propertySpec);
                }
            }

            $name = NameHelper::safeVariableName($propertyName);

            $property = $classConstructor->addPromotedParameter($name)
                ->setPublic();

            // Add PHPDoc comment for array of DTOs
            if ($arrayItemDtoClass) {
                $property->addComment("@var {$arrayItemDtoClass}[] " . ($propertySpec->description ?? ''));
            }

            // Determine if property is optional/nullable
            $isOptional = (!isset($schema->required) || ! in_array($propertyName, $schema->required));
            $isNullable = (isset($propertySpec->nullable) && $propertySpec->nullable === true) || $isOptional;

            if ($isNullable) {
                $property->setNullable(true);
                $property->setDefaultValue(null);
            }

            $property->setType($type);

            if ($name != $propertyName) {
                $property->addAttribute(MapName::class, [$propertyName]);
                $generatedMappings = true;
            }
        }

        $namespace->addUse(Data::class, alias: 'SpatieData');

        if ($generatedMappings) {
            $namespace->addUse(MapName::class);
        }

        $namespace->add($classType);

        $this->generated[$className] = $classFile;

        return $classFile;
    }

    protected function convertOpenApiTypeToPhp(Schema|Reference $schema)
    {
        if ($schema instanceof Reference) {
            return Str::afterLast($schema->getReference(), '/');
        }

        if (is_array($schema->type)) {
            return collect($schema->type)->map(fn ($type) => $this->mapType($type))->implode('|');
        }

        if (is_string($schema->type)) {
            return $this->mapType($schema->type, $schema->format);
        }

        return 'mixed';
    }

    protected function mapType($type, $format = null): string
    {
        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'object' => 'array', // Recurse
            'number' => match ($format) {
                'float' => 'float',
                'int32', 'int64	' => 'int',
                default => 'int|float',
            },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed',
        };
    }

    protected function generatePaginatedResponseMetaDto(): void
    {
        $className = 'PaginatedResponseMetaDto';
        $classType = new ClassType($className);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        $classType->setExtends(Data::class)
            ->setComment('Pagination metadata for paginated responses');

        $classConstructor = $classType->addMethod('__construct');

        // Add common pagination properties
        $currentPageParam = $classConstructor->addPromotedParameter('currentPage')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);
        $currentPageParam->addAttribute(MapName::class, ['current_page']);

        $perPageParam = $classConstructor->addPromotedParameter('perPage')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);
        $perPageParam->addAttribute(MapName::class, ['per_page']);

        $lastPageParam = $classConstructor->addPromotedParameter('lastPage')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);
        $lastPageParam->addAttribute(MapName::class, ['last_page']);

        $totalParam = $classConstructor->addPromotedParameter('total')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);

        $fromParam = $classConstructor->addPromotedParameter('from')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);

        $toParam = $classConstructor->addPromotedParameter('to')
            ->setPublic()
            ->setType('int')
            ->setDefaultValue(null)
            ->setNullable(true);

        $namespace->addUse(Data::class, alias: 'SpatieData');
        $namespace->addUse(MapName::class);
        $namespace->add($classType);

        $this->generated[$className] = $classFile;
        $this->paginationMetaDtoGenerated = true;
    }

    protected function generatePaginatedResponseDto(string $itemDtoName): void
    {
        $itemDtoClassName = NameHelper::dtoClassName($itemDtoName);
        $className = "{$itemDtoClassName}PaginatedResponseDto";

        // Avoid generating the same paginated DTO multiple times
        if (isset($this->generated[$className])) {
            return;
        }

        $classType = new ClassType($className);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        $classType->setExtends(Data::class)
            ->setComment("Paginated response containing {$itemDtoClassName} items");

        $classConstructor = $classType->addMethod('__construct');

        // Add data property (array of items)
        $dataParam = $classConstructor->addPromotedParameter('data')
            ->setPublic()
            ->setType('array');
        $dataParam->addComment("@var {$itemDtoClassName}[]");

        // Add meta property (PaginatedResponseMetaDto)
        $metaParam = $classConstructor->addPromotedParameter('meta')
            ->setPublic()
            ->setType("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\PaginatedResponseMetaDto");

        $namespace->addUse(Data::class, alias: 'SpatieData');
        $namespace->addUse("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\PaginatedResponseMetaDto");
        $namespace->addUse("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$itemDtoClassName}");
        $namespace->add($classType);

        $this->generated[$className] = $classFile;
    }
}
