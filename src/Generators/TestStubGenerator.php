<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Illuminate\Support\Str;

class TestStubGenerator
{
    protected int $itemCounter = 1;

    /**
     * Generate stub JSON data for an endpoint
     */
    public function generateStubForEndpoint(Endpoint $endpoint): string
    {
        if (!$endpoint->response) {
            return json_encode(['message' => 'Success'], JSON_PRETTY_PRINT);
        }

        if ($endpoint->responseDtoIsPaginated) {
            return $this->generatePaginatedStub($endpoint);
        }

        if ($endpoint->responseDtoIsCollection) {
            return $this->generateCollectionStub($endpoint);
        }

        return $this->generateSingleObjectStub($endpoint->response);
    }

    /**
     * Generate JSON from a schema/response array
     */
    protected function generateSingleObjectStub(mixed $schema, int $depth = 0): string
    {
        $data = $this->generateFromSchema($schema, $depth);
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Generate data from schema (recursive)
     */
    protected function generateFromSchema(mixed $schema, int $depth = 0): mixed
    {
        // Prevent infinite recursion
        if ($depth > 5) {
            return null;
        }

        // Handle array schema (from response property)
        if (is_array($schema)) {
            $result = [];
            foreach ($schema as $key => $value) {
                if ($value instanceof Schema) {
                    $result[$key] = $this->generateFromSchemaObject($value, $depth);
                } elseif (is_array($value)) {
                    $result[$key] = $this->generateFromSchema($value, $depth + 1);
                } else {
                    $result[$key] = $value;
                }
            }
            return $result;
        }

        // Handle Schema object
        if ($schema instanceof Schema) {
            return $this->generateFromSchemaObject($schema, $depth);
        }

        return null;
    }

    /**
     * Generate data from a Schema object
     */
    protected function generateFromSchemaObject(Schema $schema, int $depth = 0): mixed
    {
        // Use example if available
        if ($schema->example !== null) {
            return $schema->example;
        }

        // Use default if available
        if ($schema->default !== null) {
            return $schema->default;
        }

        // Use enum if available
        if (!empty($schema->enum)) {
            return $schema->enum[0];
        }

        // Generate based on type
        return match ($schema->type) {
            'string' => $this->generateStringValue($schema),
            'integer' => $this->generateIntegerValue($schema),
            'number' => $this->generateNumberValue($schema),
            'boolean' => (bool) ($this->itemCounter % 2),
            'array' => $this->generateArrayValue($schema, $depth),
            'object' => $this->generateObjectValue($schema, $depth),
            default => null,
        };
    }

    /**
     * Generate string value based on format
     */
    protected function generateStringValue(Schema $schema): string
    {
        // Check format
        $value = match ($schema->format) {
            'date' => date('Y-m-d'),
            'date-time' => date('c'),
            'email' => "test{$this->itemCounter}@example.com",
            'uuid' => Str::uuid()->toString(),
            'uri', 'url' => "https://example.com/resource/{$this->itemCounter}",
            default => $this->generateGenericString($schema),
        };

        return $value;
    }

    /**
     * Generate generic string value
     */
    protected function generateGenericString(Schema $schema): string
    {
        $minLength = $schema->minLength ?? 5;
        $maxLength = $schema->maxLength ?? 50;
        $length = min(max($minLength, 10), $maxLength);

        $base = "Sample text {$this->itemCounter}";
        if (strlen($base) > $length) {
            return substr($base, 0, $length);
        }

        return $base;
    }

    /**
     * Generate integer value
     */
    protected function generateIntegerValue(Schema $schema): int
    {
        $min = $schema->minimum ?? 1;
        $max = $schema->maximum ?? 1000;

        return rand((int) $min, (int) $max);
    }

    /**
     * Generate number (float) value
     */
    protected function generateNumberValue(Schema $schema): float
    {
        $min = $schema->minimum ?? 1.0;
        $max = $schema->maximum ?? 1000.0;

        return round($min + (mt_rand() / mt_getrandmax()) * ($max - $min), 2);
    }

    /**
     * Generate array value
     */
    protected function generateArrayValue(Schema $schema, int $depth): array
    {
        $items = [];
        $count = $schema->minItems ?? 2;

        // Cap at 3 items for sample data
        $count = min($count, 3);

        for ($i = 0; $i < $count; $i++) {
            $this->itemCounter++;
            if ($schema->items) {
                $items[] = $this->generateFromSchemaObject($schema->items, $depth + 1);
            } else {
                $items[] = "item_{$i}";
            }
        }

        return $items;
    }

    /**
     * Generate object value
     */
    protected function generateObjectValue(Schema $schema, int $depth): array
    {
        $object = [];

        if ($schema->properties) {
            foreach ($schema->properties as $propertyName => $propertySchema) {
                $this->itemCounter++;
                $object[$propertyName] = $this->generateFromSchemaObject($propertySchema, $depth + 1);
            }
        }

        return $object;
    }

    /**
     * Generate paginated stub
     */
    protected function generatePaginatedStub(Endpoint $endpoint): string
    {
        $data = [];

        // Generate 3 sample items
        for ($i = 1; $i <= 3; $i++) {
            $this->itemCounter = $i;
            $data[] = $this->generateFromSchema($endpoint->response, 0);
        }

        $paginated = [
            'data' => $data,
            'meta' => [
                'current_page' => 1,
                'per_page' => 20,
                'total' => 100,
                'last_page' => 5,
            ],
            'links' => [
                'first' => 'https://api.example.com/resource?page=1',
                'last' => 'https://api.example.com/resource?page=5',
                'prev' => null,
                'next' => 'https://api.example.com/resource?page=2',
            ],
        ];

        return json_encode($paginated, JSON_PRETTY_PRINT);
    }

    /**
     * Generate collection stub (array of items)
     */
    protected function generateCollectionStub(Endpoint $endpoint): string
    {
        $items = [];

        // Generate 3 sample items
        for ($i = 1; $i <= 3; $i++) {
            $this->itemCounter = $i;
            $items[] = $this->generateFromSchema($endpoint->response, 0);
        }

        return json_encode($items, JSON_PRETTY_PRINT);
    }
}
