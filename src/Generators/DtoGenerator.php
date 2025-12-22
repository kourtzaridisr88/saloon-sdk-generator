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
use Spatie\LaravelData\Optional;

class DtoGenerator extends Generator
{
    protected array $generated = [];

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        if ($specification->components) {
            foreach ($specification->components->schemas as $className => $schema) {
                $this->generateDtoClass(NameHelper::safeClassName($className), $schema);
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

            $sub = NameHelper::dtoClassName($type);

            if ($type === 'object' || $type == 'array') {

                if (! isset($this->generated[$sub]) && ! empty($propertySpec->properties)) {
                    $this->generated[$sub] = $this->generateDtoClass($propertyName, $propertySpec);
                }
            }

            $name = NameHelper::safeVariableName($propertyName);

            $property = $classConstructor->addPromotedParameter($name)
                ->setPublic();

            if (isset($propertySpec->nullable) && $propertySpec->nullable === true) {
                $property->setDefaultValue(null);
            }

            // If the property is not set in the required marked as Optional.
            if (isset($schema->required) && ! in_array($propertyName, $schema->required)) {
                $type .= '|' . Optional::class;
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
            'object' => 'object', // Recurse
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
}
