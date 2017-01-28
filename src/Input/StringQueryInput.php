<?php

declare(strict_types=1);

/*
 * This file is part of the RollerworksSearch package.
 *
 * (c) Sebastiaan Stok <s.stok@rollerscapes.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Rollerworks\Component\Search\Input;

use Rollerworks\Component\Search\ErrorList;
use Rollerworks\Component\Search\Exception\InputProcessorException;
use Rollerworks\Component\Search\Exception\InvalidSearchConditionException;
use Rollerworks\Component\Search\Exception\UnexpectedTypeException;
use Rollerworks\Component\Search\Exception\UnknownFieldException;
use Rollerworks\Component\Search\Field\FieldConfig;
use Rollerworks\Component\Search\FieldSet;
use Rollerworks\Component\Search\Input\StringQuery\Lexer;
use Rollerworks\Component\Search\Input\StringQuery\QueryException;
use Rollerworks\Component\Search\SearchCondition;
use Rollerworks\Component\Search\Value\ValuesBag;
use Rollerworks\Component\Search\Value\ValuesGroup;

/**
 * StringQuery - processes input in the StringQuery format.
 *
 * The formats works as follow (spaced are ignored).
 *
 * Each query-pair is a 'field-name: value1, value2;'.
 *
 *  Query-pairs can be nested inside a group "(field-name: value1, value2;)"
 *    Subgroups are threaded as AND-case to there parent,
 *    multiple groups inside the same group are OR-case to each other.
 *
 *    By default all the query-pairs and other direct-subgroups are treated as AND-case.
 *    To make a group OR-case (any of the fields), prefix the group with '*'
 *    Example: *(field1=values; field2=values);
 *
 *    Groups are separated with a single semicolon ";".
 *    If the subgroup is last in the group the semicolon can be omitted.
 *
 *  Query-Pairs are separated with a single semicolon ";"
 *  If the query-pair is last in the group the semicolon can be omitted.
 *
 *  Each value inside a query-pair is separated with a single comma.
 *  When the value contains special characters or spaces it must be quoted.
 *   Numbers only need to be quoted when there marked negative "-123".
 *
 *  To escape a quote use it double.
 *  Example: field: "va""lue";
 *
 *  Escaped quotes will be normalized to a single one.
 *
 * Ranges
 * ======
 *
 * A range consists of two sides, lower and upper bound (inclusive by default).
 * Each side is considered a value-part and must follow the value convention (as described above).
 *
 * Example: field: 1-100; field2: "-1" - 100
 *
 * Each side is inclusive by default, meaning 'the value' and anything lower/higher then it.
 * To mark a value exclusive (everything between, but not the actual value) prefix it with ']'.
 *
 * You can also the use '[' to mark it inclusive (explicitly).
 *
 *    `]1-100` is equal to (> 1 and <= 100)
 *    `[1-100` is equal to (>= 1 and <= 100)
 *    `[1-100[` is equal to (>= 1 and < 100)
 *    `]1-100[` is equal to (> 1 and < 100)
 *
 *   Example:
 *     field: ]1 - 100;
 *     field: [1 - 100;
 *
 * Excluded values
 * ===============
 *
 * To mark a value as excluded (also done for ranges) prefix it with an '!'.
 *
 * Example: field: !value, !1 - 10;
 *
 * Comparison
 * ==========
 *
 * Comparisons are very simple.
 * Supported operators are: <, <=, <>, >, >=
 *
 * Followed by a value-part.
 *
 * Example: field: >1=, < "-10";
 *
 * PatternMatch
 * ============
 *
 * PatternMatch works similar to Comparison,
 * everything that starts with tilde (~) is considered a pattern match.
 *
 * Supported operators are:
 *
 *    ~* (contains)
 *    ~> (starts with)
 *    ~< (ends with)
 *    ~? (regex matching)
 *
 * And not the NOT equivalent.
 *
 *     ~!* (does not contain)
 *     ~!> (does not start with)
 *     ~!< (does not end with)
 *     ~!? (does not match regex)
 *
 * Example: field: ~>foo, ~*"bar", ~?"^foo|bar$";
 *
 * To mark the pattern case insensitive add an 'i' directly after the '~'.
 *
 * Example: field: ~i>foo, ~i!*"bar", ~i?"^foo|bar$";
 *
 * Note: The regex is limited to simple POSIX expressions.
 * Actual usage is handled by the storage layer, and may not fully support complex expressions.
 *
 * Caution: Regex delimiters are not used.
 */
class StringQueryInput extends AbstractInput
{
    /**
     * @var FieldValuesByViewFactory
     */
    private $valuesFactory;

    /**
     * @var Lexer
     */
    private $lexer;

    /**
     * @var callable
     */
    private $labelResolver;

    /**
     * @var string
     */
    private $input;

    /**
     * @var array
     */
    private $fields = [];

    /**
     * Constructor.
     *
     * @param Validator|null $validator
     * @param callable|null  $labelResolver A callable to resolve the actual label
     *                                      of the field, receives a
     *                                      FieldConfigInterface instance.
     *                                      If null the `label` option value is
     *                                      used instead
     */
    public function __construct(Validator $validator = null, callable $labelResolver = null)
    {
        $this->lexer = new Lexer();
        $this->labelResolver = $labelResolver ?? function (FieldConfig $field) {
            return $field->getOption('label', $field->getName());
        };

        parent::__construct($validator);
    }

    /**
     * Process the input and returns the result.
     *
     * @param ProcessorConfig $config
     * @param string          $input
     *
     * @throws InvalidSearchConditionException
     *
     * @return SearchCondition
     */
    public function process(ProcessorConfig $config, $input): SearchCondition
    {
        if (!is_string($input)) {
            throw new UnexpectedTypeException($input, 'string');
        }

        $input = trim($input);

        if ('' === $input) {
            return new SearchCondition($config->getFieldSet(), new ValuesGroup());
        }

        $condition = null;
        $this->errors = new ErrorList();
        $this->config = $config;
        $this->fields = $this->resolveLabels($config->getFieldSet());
        $this->level = 0;

        $this->valuesFactory = new FieldValuesByViewFactory($this->errors, $this->validator, $this->config->getMaxValues());

        try {
            $condition = new SearchCondition($config->getFieldSet(), $this->parse($config, $input));
            $this->assertLevel0();
        } catch (InputProcessorException $e) {
            $this->errors[] = $e->toErrorMessageObj();
        } finally {
            $this->valuesFactory = null;
        }

        if (count($this->errors)) {
            $errors = $this->errors->getArrayCopy();

            throw new InvalidSearchConditionException($errors);
        }

        return $condition;
    }

    private function resolveLabels(FieldSet $fieldSet): array
    {
        $labels = [];
        $callable = $this->labelResolver;

        foreach ($fieldSet->all() as $name => $field) {
            $label = $callable($field);
            $labels[$label] = $name;
        }

        return $labels;
    }

    private function getFieldName(string $name): string
    {
        if (isset($this->fields[$name])) {
            return $this->fields[$name];
        }

        throw new UnknownFieldException($name);
    }

    /**
     * @param ProcessorConfig $config
     * @param string          $input
     *
     * @return ValuesGroup
     */
    private function parse(ProcessorConfig $config, $input)
    {
        $this->config = $config;
        $this->input = $input;

        $this->lexer->setInput($input);
        $this->lexer->moveNext();

        if ($this->lexer->isNextToken(Lexer::T_MULTIPLY)) {
            $this->match(Lexer::T_MULTIPLY);

            $valuesGroup = new ValuesGroup(ValuesGroup::GROUP_LOGICAL_OR);
        } else {
            $valuesGroup = new ValuesGroup();
        }

        $this->fieldValuesPairs($valuesGroup);

        return $valuesGroup;
    }

    /**
     * Attempts to match the given token with the current lookahead token.
     *
     * If they match, updates the lookahead token; otherwise raises a syntax
     * error.
     *
     * @param int $token The token type
     *
     * @throws QueryException If the tokens don't match
     */
    private function match($token)
    {
        $lookaheadType = $this->lexer->lookahead['type'];

        // short-circuit on first condition, usually types match
        if ($lookaheadType !== $token && $token !== Lexer::T_IDENTIFIER && $lookaheadType <= Lexer::T_IDENTIFIER) {
            $this->syntaxError($this->lexer->getCharOfToken($token));
        }

        $this->lexer->moveNext();
    }

    /**
     * Generates a new syntax error.
     *
     * @param string|string[] $expected Expected string
     * @param array|null      $token    Got token
     *
     * @throws QueryException
     */
    private function syntaxError($expected, array $token = null)
    {
        if ($token === null) {
            $token = $this->lexer->lookahead;
        }

        $tokenPos = $token['position'] ?? -1;
        $expected = (array) $expected;

        throw QueryException::syntaxError(
            $tokenPos,
            0,
            $expected,
            $this->lexer->lookahead === null ? 'end of string' : $token['value']
        );
    }

    /**
     * Group ::= {"(" {Group}* FieldValuesPairs {";" Group}* ")" |
     *     "(" FieldValuesPairs ";" FieldValuesPairs {";" Group}* ")" [ ";" ] | {Group}+ [ ";" ]}+.
     *
     * @param string $path
     *
     * @return ValuesGroup
     */
    private function fieldGroup(string $path = '')
    {
        $this->validateGroupNesting($path);

        if ($this->lexer->isNextToken(Lexer::T_MULTIPLY)) {
            $this->match(Lexer::T_MULTIPLY);

            $valuesGroup = new ValuesGroup(ValuesGroup::GROUP_LOGICAL_OR);
        } else {
            $valuesGroup = new ValuesGroup();
        }

        $this->match(Lexer::T_OPEN_PARENTHESIS);

        // If there is a subgroup the FieldValuesPairs() method will handle it.
        $this->fieldValuesPairs($valuesGroup, $path, true);

        $this->match(Lexer::T_CLOSE_PARENTHESIS);

        if (null !== $this->lexer->lookahead && $this->lexer->isNextToken(Lexer::T_SEMICOLON)) {
            $this->match(Lexer::T_SEMICOLON);
        }

        return $valuesGroup;
    }

    /**
     * {FieldIdentification ":" FieldValues}*.
     *
     * @param ValuesGroup $valuesGroup
     * @param string      $path
     * @param bool        $inGroup
     */
    private function fieldValuesPairs(ValuesGroup $valuesGroup, string $path = '', bool $inGroup = false)
    {
        $groupCount = 0;

        while (null !== $this->lexer->lookahead) {
            switch ($this->lexer->lookahead['type']) {
                case Lexer::T_OPEN_PARENTHESIS:
                case Lexer::T_MULTIPLY:

                    $this->validateGroupsCount($groupCount + 1, $path);

                    ++$groupCount;
                    ++$this->level;

                    $valuesGroup->addGroup($this->fieldGroup($path.'['.$groupCount.']'));

                    --$this->level;
                    break;

                case Lexer::T_IDENTIFIER:
                    $fieldName = $this->getFieldName($this->fieldIdentification());
                    $fieldConfig = $this->config->getFieldSet()->get($fieldName);

                    $valuesGroup->addField(
                        $fieldName,
                        $this->fieldValues($fieldConfig, new ValuesBag(), $path)
                    );
                    break;

                case $inGroup && Lexer::T_CLOSE_PARENTHESIS:
                    // Group closing is handled using the Group() method
                    break 2;

                default:
                    $this->syntaxError(['(', 'FieldIdentification']);
                    break;
            }
        }
    }

    /**
     * FieldIdentification ::= String.
     *
     * @return string
     */
    private function fieldIdentification()
    {
        $this->match(Lexer::T_IDENTIFIER);

        return $this->lexer->token['value'];
    }

    /**
     * FieldValues ::= [ "!" ] String {"," [ "!" ] String |
     *     [ "!" ] Range | Comparison | PatternMatch}* [ ";" ].
     *
     * @param FieldConfig $field
     * @param ValuesBag   $valuesBag
     * @param string      $path
     *
     * @return ValuesBag
     */
    private function fieldValues(FieldConfig $field, ValuesBag $valuesBag, string $path)
    {
        $hasValues = false;
        $this->valuesFactory->initContext($field, $valuesBag, $path);

        $pathVal = '['.$field->getName().'][%d]';

        while (null !== $this->lexer->lookahead) {
            switch ($this->lexer->lookahead['type']) {
                case Lexer::T_STRING:
                    $this->singleValueOrRange($pathVal);
                    break;

                case Lexer::T_OPEN_BRACE:
                case Lexer::T_CLOSE_BRACE:
                    $this->processRangeValue($pathVal);
                    break;

                case Lexer::T_NEGATE:
                    $this->match(Lexer::T_NEGATE);
                    $this->singleValueOrRange($pathVal, true);
                    break;

                case Lexer::T_LOWER_THAN:
                case Lexer::T_GREATER_THAN:
                    $this->valuesFactory->addComparisonValue($this->comparisonOperator(), $this->stringValue(), [$pathVal, '', '']);
                    break;

                case Lexer::T_TILDE:
                    $this->processMatcher($pathVal.'.value');
                    break;

                default:
                    $this->syntaxError(
                        [
                            'String',
                            'QuotedString',
                            'Range',
                            'ExcludedValue',
                            'ExcludedRange',
                            'Comparison',
                            'PatternMatch',
                        ],
                        $this->lexer->lookahead
                    );
                    break;
            }

            // We got here, so no errors.
            $hasValues = true;

            if (null !== $this->lexer->lookahead && $this->commaOrGroupEnd()) {
                break;
            }
        }

        if (!$hasValues) {
            $this->syntaxError(
                ['String', 'QuotedString', 'Range', 'ExcludedValue', 'ExcludedRange', 'Comparison', 'PatternMatch'],
                $this->lexer->lookahead
            );
        }

        return $valuesBag;
    }

    /**
     * RangeValue ::= [ "[" | "]" ] StringValue "-" StringValue [ "[" | "]" ].
     *
     * @param string $path
     * @param bool   $negative
     */
    private function processRangeValue(string $path, $negative = false)
    {
        $lowerInclusive = Lexer::T_CLOSE_BRACE !== $this->lexer->matchAndMoveNext([Lexer::T_OPEN_BRACE, Lexer::T_CLOSE_BRACE]);

        $lowerBound = $this->stringValue();
        $this->match(Lexer::T_MINUS);
        $upperBound = $this->stringValue();

        $upperInclusive = Lexer::T_OPEN_BRACE !== $this->lexer->matchAndMoveNext([Lexer::T_OPEN_BRACE, Lexer::T_CLOSE_BRACE]);

        if ($negative) {
            $this->valuesFactory->addExcludedRange($lowerBound, $upperBound, $lowerInclusive, $upperInclusive, [$path, '[lower]', '[upper]']);
        } else {
            $this->valuesFactory->addRange($lowerBound, $upperBound, $lowerInclusive, $upperInclusive, [$path, '[lower]', '[upper]']);
        }
    }

    private function processMatcher(string $path)
    {
        $this->match(Lexer::T_TILDE);

        $caseInsensitive = false;

        // Check for case insensitive.
        if ($this->lexer->isNextToken(Lexer::T_STRING) && 'i' === strtolower($this->lexer->lookahead['value'])) {
            $caseInsensitive = true;
            $this->match(Lexer::T_STRING);
        }

        $type = $this->getPatternMatchOperator();
        $value = $this->stringValue();

        $this->valuesFactory->addPatterMatch($type, $value, $caseInsensitive, [$path, '', '']);
    }

    private function singleValueOrRange(string $path, $negative = false)
    {
        if ($this->lexer->isNextTokenAny([Lexer::T_OPEN_BRACE, Lexer::T_CLOSE_BRACE])
            || $this->lexer->isGlimpse(Lexer::T_MINUS)
        ) {
            $this->processRangeValue($path, $negative);
        } else {
            if ($negative) {
                $this->valuesFactory->addExcludedSimpleValue($this->stringValue(), $path);
            } else {
                $this->valuesFactory->addSimpleValue($this->stringValue(), $path);
            }
        }
    }

    private function commaOrGroupEnd()
    {
        if ($this->lexer->isNextToken(Lexer::T_COMMA)) {
            $this->match(Lexer::T_COMMA);

            return false;
        }

        if ($this->lexer->isNextToken(Lexer::T_SEMICOLON)) {
            $this->match(Lexer::T_SEMICOLON);

            // values list has ended.
            return true;
        }

        if ($this->lexer->isNextToken(Lexer::T_CLOSE_PARENTHESIS)) {
            // Semicolon is optional when last
            // values list has ended.
            return true;
        }

        $this->syntaxError([';', '|', ',', '|', ')']);
    }

    /**
     * StringValue ::= String | QuotedString.
     *
     * @return string
     */
    private function stringValue()
    {
        if (!$this->lexer->isNextTokenAny([Lexer::T_STRING])) {
            $this->syntaxError(['String', 'QuotedString'], $this->lexer->token);
        }

        $this->lexer->moveNext();

        return $this->lexer->token['value'];
    }

    /**
     * ComparisonOperator ::= "<" | "<=" | "<>" | ">" | ">=".
     *
     * @return string
     */
    private function comparisonOperator()
    {
        switch ($this->lexer->lookahead['value']) {
            case '<':
                $this->match(Lexer::T_LOWER_THAN);
                $operator = '<';

                if ($this->lexer->isNextToken(Lexer::T_EQUALS)) {
                    $this->match(Lexer::T_EQUALS);
                    $operator .= '=';
                } elseif ($this->lexer->isNextToken(Lexer::T_GREATER_THAN)) {
                    $this->match(Lexer::T_GREATER_THAN);
                    $operator .= '>';
                }

                return $operator;

            case '>':
                $this->match(Lexer::T_GREATER_THAN);
                $operator = '>';

                if ($this->lexer->isNextToken(Lexer::T_EQUALS)) {
                    $this->match(Lexer::T_EQUALS);
                    $operator .= '=';
                }

                return $operator;

            default:
                $this->syntaxError(['<', '<=', '<>', '>', '>=']);
        }
    }

    /**
     * Gets the PatternMatch single operator.
     *
     * @return string
     */
    private function getPatternMatchOperator(bool $subParse = false)
    {
        switch ($this->lexer->lookahead['value']) {
            case '*':
                $this->match(Lexer::T_MULTIPLY);

                return 'CONTAINS';

            case '>':
                $this->match(Lexer::T_GREATER_THAN);

                return 'STARTS_WITH';

            case '<':
                $this->match(Lexer::T_LOWER_THAN);

                return 'ENDS_WITH';

            case '?':
                $this->match(Lexer::T_QUESTION_MARK);

                return 'REGEX';

            case '=':
                $this->match(Lexer::T_EQUALS);

                return 'EQUALS';

            case !$subParse && '!':
                $this->match(Lexer::T_NEGATE);

                return 'NOT_'.$this->getPatternMatchOperator(true);

            default:
                $this->syntaxError(['*', '>', '<', '?', '!*', '!>', '!<', '!?', '=', '!=']);
        }
    }
}
