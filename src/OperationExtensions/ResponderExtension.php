<?php

namespace Dedoc\Scramble\Support\OperationExtensions;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\RouteInfo;
use Dedoc\Scramble\Support\Type\Generic;
use Illuminate\Support\Collection;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;

class ResponderExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $definition = $routeInfo->methodNode();

        if (!$definition instanceof ClassMethod) {
            return;
        }

        $statements = $definition->getStmts();

        $returnStatements = array_filter($statements, function (mixed $statement) {
            return $statement instanceof Return_
                && $statement->expr instanceof \PhpParser\Node\Expr\MethodCall
                && $this->usesResponderClass($statement->expr);
        });

        $responses = collect($returnStatements)
            ->map(function (mixed $returnStatement) {
                $statusCode = $this->getStatusCode($returnStatement->expr);
                $parameter = $this->getParameter($returnStatement->expr);
                $transformer = $this->getTransformer($returnStatement->expr);

                if (!$transformer) {
                    return null;
                }

                $response = $this->openApiTransformer->toResponse(new Generic(
                    $transformer,
                    [
                        $parameter,
                    ]
                ));
                $response->code = $statusCode ?? 200;

                return $response;
            })
            ->filter();

        [$responses, $references] = $responses->partition(fn (mixed $r) => $r instanceof Response);

        $responses = $responses
            ->groupBy('code')
            ->map(function (Collection $responses, int $code) {
                if ($responses->count() === 1) {
                    return $responses->first();
                }

                return Response::make($code)
                    ->setContent(
                        'application/json',
                        Schema::fromType((new AnyOf)->setItems(
                            $responses->pluck('content.application/json.type')
                                /*
                                 * Empty response body can happen, and in case it is going to be grouped
                                 * by status, it should become an empty string.
                                 */
                                ->map(fn (mixed $type) => $type ?: new OpenApiTypes\StringType)
                                ->all()
                        ))
                    );
            })
            ->values()
            ->merge($references)
            ->each(fn (Response $response) => $operation->addResponse($response));
    }

    private function usesResponderClass($expression): ?bool
    {
        if (!$expression) {
            return false;
        }

        if ($expression->name->toString() === 'responder') {
            return true;
        }

        if (!isset($expression->var)) {
            return null;
        }

        return $this->usesResponderClass($expression->var);
    }

    private function getTransformer($expression): ?string
    {
        if (!$expression) {
            return null;
        }

        if ($expression->name->toString() === 'success' || $expression->name->toString() === 'error') {
            if (!isset($expression->args[1])) {
                return null;
            }

            if (!$expression->args[1]->value instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                return null;
            }

            return $expression->args[1]->value->class->toString();
        }

        if (!isset($expression->var)) {
            return null;
        }

        return $this->getTransformer($expression->var);
    }

    private function getParameter($expression)
    {
        if (!$expression) {
            return null;
        }

        if ($expression->name->toString() === 'success' || $expression->name->toString() === 'error') {

            if (!isset($expression->args[0])) {
                return null;
            }

            return $expression->args[0]->value;
        }

        if (!isset($expression->var)) {
            return null;
        }

        return $this->getParameter($expression->var);
    }

    private function getStatusCode($expression): ?int
    {
        if (!$expression) {
            return null;
        }

        if ($expression->name->toString() === 'respond') {
            if (!isset($expression->args[0])) {
                return null;
            }

            if ($expression->args[0]->value instanceof \PhpParser\Node\Expr\ClassConstFetch) {
                $constantName = $expression->args[0]->value->name->toString();
                $constantClass = $expression->args[0]->value->class->toString();
                $constant = constant("$constantClass::$constantName");

                return $constant;
            }

            if (!$expression->args[0]->value instanceof \PhpParser\Node\Scalar\LNumber) {
                return null;
            }

            return $expression->args[0]->value->value;
        }

        if (!isset($expression->var)) {
            return null;
        }

        return $this->getStatusCode($expression->var);
    }
}
