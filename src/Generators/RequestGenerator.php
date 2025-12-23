<?php

namespace Crescat\SaloonSdkGenerator\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use DateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method as SaloonHttpMethod;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class RequestGenerator extends Generator
{
    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $classes = [];

        foreach ($specification->endpoints as $endpoint) {
            $classes[] = $this->generateRequestClass($endpoint);
        }

        return $classes;
    }

    protected function generateRequestClass(Endpoint $endpoint): PhpFile
    {
        $pathBasedName = NameHelper::pathBasedName($endpoint);
        $resourceName = NameHelper::resourceClassName($endpoint->collection ?: $this->config->fallbackResourceName);
        $className = NameHelper::requestClassName($endpoint->name ?: $pathBasedName);

        $classType = new ClassType($className);

        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->requestNamespaceSuffix}\\{$resourceName}");

        $classType->setExtends(Request::class)
            ->setComment($endpoint->name)
            ->addComment('')
            ->addComment(Utils::wrapLongLines($endpoint->description ?? ''));

        // TODO: We assume JSON body if post/patch, make these assumptions configurable in the future.
        if ($endpoint->method->isPost() || $endpoint->method->isPatch()) {
            $classType
                ->addImplement(HasBody::class)
                ->addTrait(HasJsonBody::class);

            $namespace
                ->addUse(HasBody::class)
                ->addUse(HasJsonBody::class);
        }

        $classType->addProperty('method')
            ->setProtected()
            ->setType(SaloonHttpMethod::class)
            ->setValue(
                new Literal(
                    sprintf('Method::%s', $endpoint->method->value)
                )
            );

        $classType->addMethod('resolveEndpoint')
            ->setPublic()
            ->setReturnType('string')
            ->addBody(
                collect($endpoint->pathSegments)
                    ->map(function ($segment) {
                        return Str::startsWith($segment, ':')
                            ? new Literal(sprintf('{$this->%s}', NameHelper::safeVariableName($segment)))
                            : $segment;
                    })
                    ->pipe(function (Collection $segments) {
                        return new Literal(sprintf('return "/%s";', $segments->implode('/')));
                    })

            );

        $classConstructor = $classType->addMethod('__construct');

        // Priority 1. - Path Parameters
        foreach ($endpoint->pathParameters as $pathParam) {
            MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $pathParam);
        }

        // Priority 2. - Body Parameters
        if (! empty($endpoint->bodyParameters)) {
            $bodyParams = collect($endpoint->bodyParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredBodyParams))
                ->values()
                ->toArray();

            foreach ($bodyParams as $bodyParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $bodyParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultBody', $bodyParams, withArrayFilterWrapper: true);
        }

        // Priority 3. - Query Parameters
        if (! empty($endpoint->queryParameters)) {
            $queryParams = collect($endpoint->queryParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredQueryParams))
                ->values()
                ->toArray();

            foreach ($queryParams as $queryParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $queryParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultQuery', $queryParams, withArrayFilterWrapper: true);
        }

        // Priority 4. - Header Parameters
        if (! empty($endpoint->headerParameters)) {
            $headerParams = collect($endpoint->headerParameters)
                ->reject(fn (Parameter $parameter) => in_array($parameter->name, $this->config->ignoredHeaderParams))
                ->values()
                ->toArray();

            foreach ($headerParams as $headerParam) {
                MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $headerParam);
            }

            MethodGeneratorHelper::generateArrayReturnMethod($classType, 'defaultHeaders', $headerParams, withArrayFilterWrapper: true);
        }

        // Generate createDtoFromResponse method if a response DTO is defined
        if ($endpoint->responseDto) {
            $this->generateCreateDtoFromResponseMethod(
                $classType,
                $endpoint->responseDto,
                $endpoint->responseDtoPath,
                $endpoint->responseDtoIsCollection,
                $endpoint->responseDtoIsPaginated,
                $namespace
            );
        }

        $namespace
            ->addUse(SaloonHttpMethod::class)
            ->addUse(DateTime::class)
            ->addUse(Request::class)
            ->add($classType);

        return $classFile;
    }

    protected function generateCreateDtoFromResponseMethod(ClassType $classType, string $responseDtoName, ?string $responseDtoPath, bool $isCollection, bool $isPaginated, $namespace): void
    {
        $dtoClassName = NameHelper::dtoClassName($responseDtoName);
        $dtoFqn = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";

        // Import Response class
        $namespace->addUse(\Saloon\Http\Response::class);

        $method = $classType->addMethod('createDtoFromResponse')
            ->setPublic();

        $method->addParameter('response')
            ->setType(\Saloon\Http\Response::class);

        // Handle paginated responses
        if ($isPaginated) {
            $paginatedDtoClassName = "{$dtoClassName}PaginatedResponseDto";
            $paginatedDtoFqn = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$paginatedDtoClassName}";

            // Import the paginated DTO class
            $namespace->addUse($paginatedDtoFqn);
            $namespace->addUse($dtoFqn);

            $method->setReturnType($paginatedDtoFqn);

            $method->addBody('$array = $response->json();');
            $method->addBody('');
            $method->addBody(sprintf('return %s::from($array);', $paginatedDtoClassName));
        } elseif ($isCollection) {
            // Import the DTO class
            $namespace->addUse($dtoFqn);

            $method->setReturnType('array');
            $method->addComment('@return ' . $dtoClassName . '[]');

            $method->addBody('$array = $response->json();');
            $method->addBody('');

            // For collections, map over the array
            if ($responseDtoPath) {
                $method->addBody(sprintf('return array_map(fn($item) => %s::from($item), $array[\'%s\']);', $dtoClassName, $responseDtoPath));
            } else {
                $method->addBody(sprintf('return array_map(fn($item) => %s::from($item), $array);', $dtoClassName));
            }
        } else {
            // Import the DTO class
            $namespace->addUse($dtoFqn);

            $method->setReturnType($dtoFqn);

            $method->addBody('$array = $response->json();');
            $method->addBody('');

            // For single items
            if ($responseDtoPath) {
                $method->addBody(sprintf('return %s::from($array[\'%s\']);', $dtoClassName, $responseDtoPath));
            } else {
                $method->addBody(sprintf('return %s::from($array);', $dtoClassName));
            }
        }
    }
}
