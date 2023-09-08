<?php

declare(strict_types=1);

namespace Dedoc\Scramble\Support\TypeToSchemaExtensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use League\Fractal\TransformerAbstract;
use ReflectionClass;

class ResponderTypeToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type->isInstanceOf(TransformerAbstract::class);
    }

    /**
     * @param  ObjectType  $type
     */
    public function toSchema(Type $type)
    {
        $transformerArray = ($def = $type->getMethodDefinition('transform'))
            ? $def->type->getReturnType()
            : new UnknownType();

        $reflectionClass = new ReflectionClass($type->name);

        $relationsArray = $this->getPropertyItems($reflectionClass, 'relations', true);
        $loadArray = $this->getPropertyItems($reflectionClass, 'load', false);

        if (!$transformerArray instanceof ArrayType) {
            return new UnknownType();
        }

        $array = new ArrayType(
            array_merge(
                $transformerArray->items,
                $relationsArray,
                $loadArray,
            )
        );

        return $this->openApiTransformer->transform($array);
    }

    /**
     * @param  Generic  $type
     */
    public function toResponse(Type $type): Response
    {
        $this->infer->analyzeClass($type->name);

        $openApiType = $this->openApiTransformer->transform($type);

        return Response::make(200)
            ->description('`' . $this->components->uniqueSchemaName($type->name) . '`')
            ->setContent(
                'application/json',
                Schema::fromType($openApiType),
            );
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('schemas', $type->name, $this->components);
    }

    private function getPropertyItems(ReflectionClass $class, string $property, bool $optional): array
    {
        $relationsArray = [];

        if ($class->hasProperty($property)) {
            $relations = $class->getProperty($property)->getValue(new $class->name);

            foreach ($relations as $key => $relation) {
                $this->infer->analyzeClass($relation);

                $referenceType = new Generic($relation);

                $relationsArray[] = new ArrayItemType_(
                    $key,
                    $referenceType,
                    $optional
                );
            }
        }

        return $relationsArray;
    }
}
