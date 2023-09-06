<?php

use LightIt\ScrambleExtensions\InfererExtensions\ResponderTypeInfer;
use LightIt\ScrambleExtensions\OperationExtensions\ResponderExtension;
use LightIt\ScrambleExtensions\TypeToSchemaExtensions\ResponderTypeToSchema;

class Exporter
{
    public function getOperationExtensions(): array
    {
        return [
            ResponderExtension::class,
        ];
    }

    public function getTypeToSchemaExtensions(): array
    {
        return [
            ResponderTypeToSchema::class,
        ];
    }

    public function getInfererExtensions(): array
    {
        return [
            ResponderTypeInfer::class,
        ];
    }

    public function getAllExtensions(): array
    {
        return array_merge(
            $this->getOperationExtensions(),
            $this->getTypeToSchemaExtensions(),
            $this->getInfererExtensions(),
        );
    }
}
