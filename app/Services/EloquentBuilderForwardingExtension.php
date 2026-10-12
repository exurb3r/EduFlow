<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Keeps the model type across Eloquent clauses that the framework forwards.
 *
 * `Illuminate\Database\Eloquent\Builder` declares `@mixin Query\Builder`, so
 * every clause it does not implement itself — `whereRaw()`, `orderBy()`,
 * `lockForUpdate()`, `groupBy()` and the rest — resolves to the *query*
 * builder. Everything chained after one of them then reads as
 * `Illuminate\Database\Query\Builder`, and a trailing `get()` returns
 * `Collection<int, stdClass>` instead of models, which hides genuine mistakes
 * such as calling a service method on a row.
 *
 * At runtime Laravel forwards these through `Builder::__call()`, applies the
 * call to the underlying query builder and returns the same Eloquent builder
 * instance, so the model type does survive. This extension states that in the
 * only place it can be stated without taking on a new analysis dependency, and
 * it returns the caller's own type so `Builder<TModel>` — and therefore the
 * model — is preserved.
 */
final class EloquentBuilderForwardingExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Forwarded calls that deliberately hand back the query result instead of
     * the builder. Mirrors `Illuminate\Database\Eloquent\Builder::$passthru`.
     *
     * @var list<string>
     */
    private const array PASSTHROUGH_CLAUSES = [
        'aggregate', 'average', 'avg', 'count', 'dd', 'ddrawsql', 'doesntexist', 'doesntexistor',
        'dump', 'dumprawsql', 'exists', 'existsor', 'explain', 'getbindings', 'getconnection', 'getcountforpagination', 'getgrammar',
        'getrawbindings', 'implode', 'insert', 'insertgetid', 'insertorignore', 'insertorignorereturning',
        'insertusing', 'insertorignoreusing', 'max', 'min', 'numericaggregate', 'raw', 'rawvalue',
        'sum', 'tosql', 'torawsql',
    ];

    public function getClass(): string
    {
        return Builder::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // Only clauses that live on the query builder and reach the Eloquent
        // builder through its @mixin. Clauses the Eloquent builder declares
        // itself already carry their real return types.
        return $methodReflection->getDeclaringClass()->getName() === QueryBuilder::class;
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        if (! $methodCall->name instanceof Identifier) {
            return null;
        }

        if (in_array($methodCall->name->toLowerString(), self::PASSTHROUGH_CLAUSES, true)) {
            return null;
        }

        $caller = TypeCombinator::removeNull($scope->getType($methodCall->var));

        if (! (new ObjectType(Builder::class))->isSuperTypeOf($caller)->yes()) {
            return null;
        }

        // Returning the caller's own type keeps Builder<TModel> intact, so
        // `->orderBy('id')->get()` still yields models rather than stdClass.
        return $caller;
    }
}
