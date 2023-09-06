<?php

declare(strict_types=1);

namespace LightIt\ScrambleExtensions\InfererExtensions;

use Dedoc\Scramble\Infer\Definition\ClassDefinition;
use Dedoc\Scramble\Infer\Extensions\ExpressionTypeInferExtension;
use Dedoc\Scramble\Infer\Scope\Scope;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use League\Fractal\TransformerAbstract;
use PhpParser\Node;
use PhpParser\Node\Expr;
use Dedoc\Scramble\Support\Generator\Types\UnknownType;
use Dedoc\Scramble\Infer\Services\FileNameResolver;
use Dedoc\Scramble\Support\ResponseExtractor\ModelInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ResponderTypeInfer implements ExpressionTypeInferExtension
{
    private ObjectType $modelType;
    private string $modelPropertyName;

    /** @var array<array{0: string, 1: ObjectType}> */
    public static $transformerModelTypesCache = [];

    public function getType(Expr $node, Scope $scope): ?Type
    {
        if (
            !$scope->classDefinition()?->isInstanceOf(TransformerAbstract::class)
            || $scope->classDefinition()?->name === TransformerAbstract::class
        ) {
            return null;
        }

        $transformerArguments = $scope
            ->classDefinition()
            ->getMethodDefinition('transform')
            ->type
            ->arguments;

        $this->modelPropertyName = array_key_first($transformerArguments);
        $this->modelType = $transformerArguments[$this->modelPropertyName]->is;

        /** $modelPropertyName->? */
        if (
            $node instanceof Node\Expr\PropertyFetch && $node->var?->name === $this->modelPropertyName
            && is_string($node->name?->name)
            && !array_key_exists($node->name->name, $scope->classDefinition()->properties)
            && ($type = $this->modelType($scope->classDefinition(), $scope))
        ) {
            return $scope->getPropertyFetchType($type, $node->name->name);
        }

        /**
         * new MissingValue
         */
        if ($scope->getType($node)->isInstanceOf(MissingValue::class)) {
            return new ObjectType(MissingValue::class);
        }

        return null;
    }

    private function modelType(ClassDefinition $jsonClass, Scope $scope): ?Type
    {
        if ([$cachedModelType, $cachedModelDefinition] = static::$transformerModelTypesCache[$jsonClass->name] ?? null) {
            if ($cachedModelDefinition) {
                $scope->index->registerClassDefinition($cachedModelDefinition);
            }

            return $cachedModelType;
        }

        $modelClass = $this->getModelName(
            $jsonClass->name,
            new \ReflectionClass($jsonClass->name),
            $scope->nameResolver,
        );

        $modelType = new UnknownType("Cannot resolve [$modelClass] model type.");
        $modelClassDefinition = null;
        if ($modelClass && is_a($modelClass, Model::class, true)) {
            try {
                $modelClassDefinition = (new ModelInfo($modelClass))->type();

                $scope->index->registerClassDefinition($modelClassDefinition);

                $modelType = new ObjectType($modelClassDefinition->name);
            } catch (\LogicException $e) {
                // Here doctrine/dbal is not installed.
                $modelType = null;
                $modelClassDefinition = null;
            }
        }

        static::$transformerModelTypesCache[$jsonClass->name] = [$modelType, $modelClassDefinition];

        return $modelType;
    }

    private function getModelName(string $jsonResourceClassName, \ReflectionClass $reflectionClass, FileNameResolver $getFqName)
    {

        if ($this->modelType) {
            return $this->modelType->toString();
        }

        $phpDoc = $reflectionClass->getDocComment() ?: '';

        $mixinOrPropertyLine = Str::of($phpDoc)
            ->explode("\n")
            ->first(fn (string $str) => Str::is(['*@property*$resource', '*@mixin*'], $str));

        if ($mixinOrPropertyLine) {
            $modelName = Str::replace(['@property', '$resource', '@mixin', ' ', '*'], '', $mixinOrPropertyLine);

            $modelClass = $getFqName($modelName);

            if (class_exists($modelClass)) {
                return "\\{$modelClass}";
            }
        }

        $modelName = (string) Str::of(Str::of($jsonResourceClassName)->explode('\\')->last())->replace('Resource', '')->singular();

        $modelClass = "App\\Models\\{$modelName}";
        if (!class_exists($modelClass)) {
            return null;
        }

        return $modelClass;
    }
}
