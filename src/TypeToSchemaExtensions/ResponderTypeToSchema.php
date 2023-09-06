<?php

declare(strict_types=1);

namespace LightIt\ScrambleExtensions\TypeToSchemaExtensions;

use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Support\Type\ArrayItemType_;
use Dedoc\Scramble\Support\Type\ArrayType;
use Dedoc\Scramble\Support\Type\Generic;
use Dedoc\Scramble\Support\Type\Literal\LiteralBooleanType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Dedoc\Scramble\Support\Type\TypeWalker;
use Dedoc\Scramble\Support\Type\Union;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MergeValue;
use Illuminate\Http\Resources\MissingValue;
use League\Fractal\TransformerAbstract;

class ResponderTypeToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type)
    {
        return $type->isInstanceOf(TransformerAbstract::class);
    }

    /**
     * @param  ObjectType  $type
     */
    public function toSchema(Type $type)
    {

        $array = ($def = $type->getMethodDefinition('transform'))
            ? $def->type->getReturnType()
            : new UnknownType();

        if (!$array instanceof ArrayType) {
            return new UnknownType();
        }

        $array->items = $this->flattenMergeValues($array->items);

        return $this->openApiTransformer->transform($array);
    }

    /**
     * @param array<int, ArrayItemType_> $items
     *
     * @return array<ArrayItemType_>
     */
    private function flattenMergeValues(array $items): array
    {
        return collect($items)
            ->flatMap(function (ArrayItemType_ $item) {
                if ($item->value instanceof ArrayType) {
                    $item->value->items = $this->flattenMergeValues($item->value->items);

                    return [$item];
                }

                if ($item->value->isInstanceOf(JsonResource::class)) {
                    $resource = $this->getResourceType($item->value);

                    if ($resource->isInstanceOf(MissingValue::class)) {
                        return [];
                    }

                    if (
                        $resource instanceof Union
                        && (new TypeWalker)->first($resource, fn (Type $t) => $t->isInstanceOf(MissingValue::class))
                    ) {
                        $item->isOptional = true;

                        return [$item];
                    }
                }

                if (
                    $item->value instanceof Union
                    && (new TypeWalker)->first($item->value, fn (Type $t) => $t->isInstanceOf(MissingValue::class))
                ) {
                    $newType = array_filter($item->value->types, fn (Type $t) => !$t->isInstanceOf(MissingValue::class));

                    if (!count($newType)) {
                        return [];
                    }

                    $item->isOptional = true;

                    if (count($newType) === 1) {
                        $item->value = $newType[0];

                        return $this->flattenMergeValues([$item]);
                    }

                    $item->value = new Union($newType);

                    return $this->flattenMergeValues([$item]);
                }

                if (
                    $item->value instanceof Generic
                    && $item->value->isInstanceOf(MergeValue::class)
                ) {
                    $arrayToMerge = $item->value->templateTypes[1];

                    // Second generic argument of the `MergeValue` class must be an array.
                    // Otherwise, we ignore it from the resulting array.
                    if (!$arrayToMerge instanceof ArrayType) {
                        return [];
                    }

                    $arrayToMergeItems = $this->flattenMergeValues($arrayToMerge->items);

                    $mergingArrayValuesShouldBeRequired = $item->value->templateTypes[0] instanceof LiteralBooleanType
                        && $item->value->templateTypes[0]->value === true;

                    if (!$mergingArrayValuesShouldBeRequired || $item->isOptional) {
                        foreach ($arrayToMergeItems as $mergingItem) {
                            $mergingItem->isOptional = true;
                        }
                    }

                    return $arrayToMergeItems;
                }

                return [$item];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Generic  $type
     */
    public function toResponse(Type $type): Response
    {
        $definition = $this->infer->analyzeClass($type->name);

        $additional = $type->templateTypes[1] ?? new UnknownType();

        $openApiType = $this->openApiTransformer->transform($type);

        if (($withArray = $definition->getMethodCallType('with')) instanceof ArrayType) {
            $withArray->items = $this->flattenMergeValues($withArray->items);
        }
        if ($additional instanceof ArrayType) {
            $additional->items = $this->flattenMergeValues($additional->items);
        }

        $shouldWrap = !is_null($wrapKey = $type->name::$wrap ?? null)
            || $withArray instanceof ArrayType
            || $additional instanceof ArrayType;

        $wrapKey = $wrapKey ?: 'data';

        if ($shouldWrap) {
            $openApiType = (new \Dedoc\Scramble\Support\Generator\Types\ObjectType)
                ->addProperty($wrapKey, $openApiType)
                ->setRequired([$wrapKey]);

            if ($withArray instanceof ArrayType) {
                $this->mergeOpenApiObjects($openApiType, $this->openApiTransformer->transform($withArray));
            }

            if ($additional instanceof ArrayType) {
                $this->mergeOpenApiObjects($openApiType, $this->openApiTransformer->transform($additional));
            }
        }

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

    private function mergeOpenApiObjects(OpenApiTypes\ObjectType $into, OpenApiTypes\Type $what): void
    {
        if (!$what instanceof OpenApiTypes\ObjectType) {
            return;
        }

        foreach ($what->properties as $name => $property) {
            $into->addProperty($name, $property);
        }

        $into->addRequired(array_keys($what->properties));
    }

    private function getResourceType(Type $type): Type
    {
        if (!$type instanceof Generic) {
            return new UnknownType();
        }

        if ($type->isInstanceOf(AnonymousResourceCollection::class)) {
            return $type->templateTypes[0]->templateTypes[0]
                ?? new UnknownType();
        }

        if ($type->isInstanceOf(JsonResource::class)) {
            return $type->templateTypes[0];
        }

        return new UnknownType();
    }
}
