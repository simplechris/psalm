<?php
namespace Psalm\Checker\Statements\Block;

use PhpParser;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Issue\NullReference;
use Psalm\Issue\InvalidIterator;
use Psalm\Type;

class ForeachChecker
{
    /**
     * @return false|null
     */
    public static function check(StatementsChecker $statements_checker, PhpParser\Node\Stmt\Foreach_ $stmt, Context $context)
    {
        if (ExpressionChecker::check($statements_checker, $stmt->expr, $context) === false) {
            return false;
        }

        $foreach_context = clone $context;
        $foreach_context->in_loop = true;

        /** @var Type\Union|null */
        $key_type = null;

        /** @var Type\Union|null */
        $value_type = null;

        $var_id = ExpressionChecker::getVarId(
            $stmt->expr,
            $statements_checker->getAbsoluteClass(),
            $statements_checker->getNamespace(),
            $statements_checker->getAliasedClasses()
        );

        if (isset($stmt->expr->inferredType)) {
            /** @var Type\Union */
            $iterator_type = $stmt->expr->inferredType;
        }
        elseif (isset($foreach_context->vars_in_scope[$var_id])) {
            $iterator_type = $foreach_context->vars_in_scope[$var_id];
        }
        else {
            $iterator_type = null;
        }

        if ($iterator_type) {
            foreach ($iterator_type->types as $return_type) {
                // if it's an empty array, we cannot iterate over it
                if ((string) $return_type === 'array<empty,empty>') {
                    continue;
                }

                if ($return_type instanceof Type\Generic) {
                    $value_index = count($return_type->type_params) - 1;
                    $value_type_part = $return_type->type_params[$value_index];

                    if (!$value_type) {
                        $value_type = $value_type_part;
                    }
                    else {
                        $value_type = Type::combineUnionTypes($value_type, $value_type_part);
                    }

                    if ($value_index) {
                        $key_type_part = $return_type->type_params[0];

                        if (!$key_type) {
                            $key_type = $key_type_part;
                        }
                        else {
                            $key_type = Type::combineUnionTypes($key_type, $key_type_part);
                        }
                    }
                    continue;
                }

                switch ($return_type->value) {
                    case 'mixed':
                    case 'empty':
                    case 'Generator':
                        $value_type = Type::getMixed();
                        break;

                    case 'array':
                    case 'object':
                    case 'object-like':
                        $value_type = Type::getMixed();
                        break;

                    case 'null':
                        if (IssueBuffer::accepts(
                            new NullReference('Cannot iterate over ' . $return_type->value, $statements_checker->getCheckedFileName(), $stmt->getLine()),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        $value_type = Type::getMixed();
                        break;

                    case 'string':
                    case 'void':
                    case 'int':
                    case 'bool':
                    case 'false':
                        if (IssueBuffer::accepts(
                            new InvalidIterator('Cannot iterate over ' . $return_type->value, $statements_checker->getCheckedFileName(), $stmt->getLine()),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                        $value_type = Type::getMixed();
                        break;

                    default:
                        if (ClassChecker::classImplements($return_type->value, 'Iterator')) {
                            $iterator_method = $return_type->value . '::current';
                            $iterator_class_type = MethodChecker::getMethodReturnTypes($iterator_method);

                            if ($iterator_class_type) {
                                $value_type_part = ExpressionChecker::fleshOutTypes($iterator_class_type, [], $return_type->value, $iterator_method);

                                if (!$value_type) {
                                    $value_type = $value_type_part;
                                }
                                else {
                                    $value_type = Type::combineUnionTypes($value_type, $value_type_part);
                                }
                            }
                            else {
                                $value_type = Type::getMixed();
                            }
                        }

                        if ($return_type->value !== 'Traversable' && $return_type->value !== $statements_checker->getClassName()) {
                            if (ClassLikeChecker::checkAbsoluteClassOrInterface($return_type->value, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                                return false;
                            }
                        }
                }
            }
        }

        if ($stmt->keyVar) {
            $foreach_context->vars_in_scope['$' . $stmt->keyVar->name] = $key_type ?: Type::getMixed();
            $foreach_context->vars_possibly_in_scope['$' . $stmt->keyVar->name] = true;
            $statements_checker->registerVariable('$' . $stmt->keyVar->name, $stmt->getLine());
        }

        if ($value_type && $value_type instanceof Type\Atomic) {
            $value_type = new Type\Union([$value_type]);
        }

        if ($stmt->valueVar instanceof PhpParser\Node\Expr\List_) {
            foreach ($stmt->valueVar->vars as $var) {
                if ($var) {
                    $foreach_context->vars_in_scope['$' . $var->name] = Type::getMixed();
                    $foreach_context->vars_possibly_in_scope['$' . $var->name] = true;
                    $statements_checker->registerVariable('$' . $var->name, $var->getLine());
                }
            }
        }
        else {
            $foreach_context->vars_in_scope['$' . $stmt->valueVar->name] = $value_type ? $value_type : Type::getMixed();
            $foreach_context->vars_possibly_in_scope['$' . $stmt->valueVar->name] = true;
            $statements_checker->registerVariable('$' . $stmt->valueVar->name, $stmt->getLine());
        }

        CommentChecker::getTypeFromComment((string) $stmt->getDocComment(), $foreach_context, $statements_checker->getSource(), null);

        $statements_checker->check($stmt->stmts, $foreach_context, $context);

        foreach ($context->vars_in_scope as $var => $type) {
            if ($type->isMixed()) {
                continue;
            }

            if (!isset($foreach_context->vars_in_scope[$var])) {
                unset($context->vars_in_scope[$var]);
                continue;
            }

            if ($foreach_context->vars_in_scope[$var]->isMixed()) {
                $context->vars_in_scope[$var] = $foreach_context->vars_in_scope[$var];
            }

            if ((string) $foreach_context->vars_in_scope[$var] !== (string) $type) {
                $context->vars_in_scope[$var] = Type::combineUnionTypes($context->vars_in_scope[$var], $foreach_context->vars_in_scope[$var]);
            }
        }

        $context->vars_possibly_in_scope = array_merge($foreach_context->vars_possibly_in_scope, $context->vars_possibly_in_scope);
    }
}