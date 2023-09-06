<?php

declare(strict_types=1);

namespace LightIt\ScrambleExtensions;

use LightIt\ScrambleExtensions\InfererExtensions\ResponderTypeInfer;
use LightIt\ScrambleExtensions\OperationExtensions\ResponderExtension;
use LightIt\ScrambleExtensions\TypeToSchemaExtensions\ResponderTypeToSchema;

class LightitScrambleExtensions
{
    public static function getOperationExtensions(): array
    {
        return [
            ResponderExtension::class,
        ];
    }

    public static function getTypeToSchemaExtensions(): array
    {
        return [
            ResponderTypeToSchema::class,
        ];
    }

    public static function getInfererExtensions(): array
    {
        return [
            ResponderTypeInfer::class,
        ];
    }

    public static function getAllExtensions(): array
    {
        return array_merge(
            self::getOperationExtensions(),
            self::getTypeToSchemaExtensions(),
            self::getInfererExtensions(),
        );
    }
}
