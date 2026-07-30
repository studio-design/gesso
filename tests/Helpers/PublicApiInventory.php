<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use const DIRECTORY_SEPARATOR;
use const T_COMMENT;
use const T_DOC_COMMENT;
use const T_FUNCTION;
use const T_STRING;
use const T_WHITESPACE;

use BackedEnum;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use UnitEnum;

use function array_diff;
use function array_filter;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function enum_exists;
use function file_get_contents;
use function interface_exists;
use function is_array;
use function is_object;
use function is_scalar;
use function ksort;
use function ltrim;
use function method_exists;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use function token_get_all;
use function trait_exists;
use function usort;

/**
 * Reflection-based snapshot of the package's non-@internal PHP API.
 *
 * @internal Build-time compatibility helper; not part of the library API.
 */
final class PublicApiInventory
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function capture(string $sourceDirectory, string $namespacePrefix): array
    {
        $root = realpath($sourceDirectory);
        if ($root === false) {
            throw new RuntimeException("Source directory does not exist: {$sourceDirectory}");
        }

        $symbols = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1, -4);
            $symbol = $namespacePrefix . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (!class_exists($symbol) && !interface_exists($symbol) && !trait_exists($symbol) && !enum_exists($symbol)) {
                continue;
            }

            $reflection = new ReflectionClass($symbol);
            if (self::isInternal($reflection->getDocComment())) {
                continue;
            }

            $symbols[$symbol] = self::describe($reflection);
        }

        ksort($symbols);

        return $symbols;
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, mixed>
     */
    private static function describe(ReflectionClass $reflection): array
    {
        $parent = $reflection->getParentClass();
        $interfaces = $reflection->getInterfaceNames();
        if ($parent !== false) {
            $interfaces = array_values(array_diff($interfaces, $parent->getInterfaceNames()));
        }
        sort($interfaces);

        $traits = $reflection->getTraitNames();
        if ($parent !== false) {
            $traits = array_values(array_diff($traits, $parent->getTraitNames()));
        }
        // Trait composition is an implementation detail unless the trait is
        // itself public API: renaming or splitting an @internal trait must not
        // read as a baseline change (#420).
        $traits = array_values(array_filter(
            $traits,
            static fn(string $trait): bool => !self::isInternal((new ReflectionClass($trait))->getDocComment()),
        ));
        sort($traits);

        $methods = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName() ||
                self::isInternal($method->getDocComment())) {
                continue;
            }

            $methods[$method->getName()] = self::describeMethod($method);
        }
        ksort($methods);

        $properties = [];
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName() ||
                self::isInternal($property->getDocComment())) {
                continue;
            }

            $properties[$property->getName()] = self::describeProperty($property);
        }
        ksort($properties);

        $constants = [];
        foreach ($reflection->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName() ||
                $constant->isEnumCase() ||
                self::isInternal($constant->getDocComment())) {
                continue;
            }

            $constants[$constant->getName()] = self::normaliseValue($constant->getValue());
        }
        ksort($constants);

        $cases = [];
        $backingType = null;
        if ($reflection->isEnum()) {
            $enum = new ReflectionEnum($reflection->getName());
            $backingType = $enum->isBacked() ? (string) $enum->getBackingType() : null;
            foreach ($enum->getCases() as $case) {
                $cases[$case->getName()] = method_exists($case, 'getBackingValue')
                    ? self::normaliseValue($case->getBackingValue())
                    : null;
            }
        }

        $description = [
            'kind' => $reflection->isTrait() ? 'trait' : ($reflection->isInterface() ? 'interface' : ($reflection->isEnum() ? 'enum' : 'class')),
            'final' => $reflection->isFinal(),
            'abstract' => $reflection->isAbstract(),
            'readonly' => $reflection->isReadOnly(),
            'instantiable' => $reflection->isInstantiable(),
            'constructor' => self::describeConstructor($reflection),
            'parent' => $parent === false ? null : $parent->getName(),
            'interfaces' => $interfaces,
            'traits' => $traits,
            'attributes' => self::describeAttributes($reflection->getAttributes()),
            'backing_type' => $backingType,
            'cases' => $cases,
            'constants' => $constants,
            'properties' => $properties,
            'methods' => $methods,
        ];

        if ($reflection->isTrait()) {
            $description['trait_composition'] = self::describeTraitComposition($reflection);
        }

        return $description;
    }

    /**
     * Trait members of every visibility are copied into the consuming class.
     * Their names and declarations can therefore conflict with consumer code
     * even when they are protected or private.
     *
     * @param ReflectionClass<object> $reflection
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function describeTraitComposition(ReflectionClass $reflection): array
    {
        $methods = [];
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $reflection->getName() ||
                self::isInternal($method->getDocComment())) {
                continue;
            }

            $methods[$method->getName()] = [
                'visibility' => self::visibility($method),
                ...self::describeMethod($method),
            ];
        }
        ksort($methods);

        $properties = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $reflection->getName() ||
                self::isInternal($property->getDocComment())) {
                continue;
            }

            $properties[$property->getName()] = [
                'visibility' => self::visibility($property),
                ...self::describeProperty($property),
                'attributes' => self::describeAttributes($property->getAttributes()),
            ];
        }
        ksort($properties);

        $constants = [];
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() !== $reflection->getName() ||
                $constant->isEnumCase() ||
                self::isInternal($constant->getDocComment())) {
                continue;
            }

            $constants[$constant->getName()] = [
                'visibility' => self::visibility($constant),
                'final' => $constant->isFinal(),
                'value' => self::normaliseValue($constant->getValue()),
                'attributes' => self::describeAttributes($constant->getAttributes()),
            ];
        }
        ksort($constants);

        return [
            'constants' => $constants,
            'properties' => $properties,
            'methods' => $methods,
        ];
    }

    /**
     * @param ReflectionClass<object> $reflection
     *
     * @return null|array<string, mixed>
     */
    private static function describeConstructor(ReflectionClass $reflection): ?array
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            if ($reflection->isInterface() || $reflection->isTrait() || $reflection->isEnum()) {
                return null;
            }

            return [
                'kind' => 'implicit',
                'visibility' => 'public',
            ];
        }

        return [
            'kind' => $constructor->getDeclaringClass()->getName() === $reflection->getName() ? 'declared' : 'inherited',
            'visibility' => $constructor->isPublic() ? 'public' : ($constructor->isProtected() ? 'protected' : 'private'),
        ];
    }

    /** @return array<string, mixed> */
    private static function describeMethod(ReflectionMethod $method): array
    {
        return [
            'static' => $method->isStatic(),
            'final' => $method->isFinal(),
            'abstract' => $method->isAbstract(),
            'returns_reference' => $method->returnsReference(),
            'return_type' => self::describeReturnType($method),
            'attributes' => self::describeAttributes($method->getAttributes()),
            'parameters' => array_map(self::describeParameter(...), $method->getParameters()),
        ];
    }

    /** @return array<string, mixed> */
    private static function describeProperty(ReflectionProperty $property): array
    {
        return [
            'type' => $property->hasType() ? (string) $property->getType() : null,
            'static' => $property->isStatic(),
            'readonly' => $property->isReadOnly(),
            'default' => $property->hasDefaultValue() ? self::normaliseValue($property->getDefaultValue()) : ['unavailable' => true],
        ];
    }

    private static function visibility(
        ReflectionClassConstant|ReflectionMethod|ReflectionProperty $member,
    ): string {
        return $member->isPublic() ? 'public' : ($member->isProtected() ? 'protected' : 'private');
    }

    private static function describeReturnType(ReflectionMethod $method): ?string
    {
        if (!$method->hasReturnType()) {
            return null;
        }

        $returnType = (string) $method->getReturnType();
        $declaringClass = $method->getDeclaringClass()->getName();
        if (ltrim($returnType, '?') !== $declaringClass) {
            return $returnType;
        }

        $sourceReturnType = self::sourceReturnType($method);

        return $sourceReturnType === 'self' || $sourceReturnType === '?self'
            ? $sourceReturnType
            : $returnType;
    }

    /**
     * PHP 8.5 resolves `self` to the declaring class name in Reflection's
     * string representation. Read the declared spelling only for that
     * ambiguous case so a real `self` -> FQCN source change remains visible.
     */
    private static function sourceReturnType(ReflectionMethod $method): ?string
    {
        $file = $method->getFileName();
        if ($file === false) {
            return null;
        }

        $source = file_get_contents($file);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $tokenCount = count($tokens);
        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = $index + 1;
            while ($nameIndex < $tokenCount) {
                $nameToken = $tokens[$nameIndex];
                if ($nameToken === '(') {
                    break;
                }
                if (is_array($nameToken) && $nameToken[0] === T_STRING) {
                    break;
                }
                $nameIndex++;
            }

            if ($nameIndex >= $tokenCount || !is_array($tokens[$nameIndex]) ||
                $tokens[$nameIndex][1] !== $method->getName()) {
                continue;
            }

            $parameterIndex = $nameIndex + 1;
            while ($parameterIndex < $tokenCount && $tokens[$parameterIndex] !== '(') {
                $parameterIndex++;
            }
            if ($parameterIndex >= $tokenCount) {
                return null;
            }

            $depth = 1;
            for (++$parameterIndex; $parameterIndex < $tokenCount && $depth > 0; $parameterIndex++) {
                if ($tokens[$parameterIndex] === '(') {
                    $depth++;
                } elseif ($tokens[$parameterIndex] === ')') {
                    $depth--;
                }
            }

            while ($parameterIndex < $tokenCount && self::isIgnorableToken($tokens[$parameterIndex])) {
                $parameterIndex++;
            }
            if ($parameterIndex >= $tokenCount || $tokens[$parameterIndex] !== ':') {
                return null;
            }

            $declaredType = '';
            for (++$parameterIndex; $parameterIndex < $tokenCount; $parameterIndex++) {
                $typeToken = $tokens[$parameterIndex];
                if ($typeToken === '{' || $typeToken === ';') {
                    break;
                }
                if (self::isIgnorableToken($typeToken)) {
                    continue;
                }

                $declaredType .= is_array($typeToken) ? $typeToken[1] : $typeToken;
            }

            return $declaredType === '' ? null : $declaredType;
        }

        return null;
    }

    /** @param array{int, string, int}|string $token */
    private static function isIgnorableToken(array|string $token): bool
    {
        return is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT);
    }

    /** @return array<string, mixed> */
    private static function describeParameter(ReflectionParameter $parameter): array
    {
        $default = ['unavailable' => true];
        if ($parameter->isDefaultValueAvailable()) {
            $constant = $parameter->getDefaultValueConstantName();
            $default = $constant === null
                ? self::normaliseValue($parameter->getDefaultValue())
                : ['constant' => $constant, 'value' => self::normaliseValue($parameter->getDefaultValue())];
        }

        return [
            'name' => $parameter->getName(),
            'type' => $parameter->hasType() ? (string) $parameter->getType() : null,
            'optional' => $parameter->isOptional(),
            'variadic' => $parameter->isVariadic(),
            'by_reference' => $parameter->isPassedByReference(),
            'default' => $default,
            'attributes' => self::describeAttributes($parameter->getAttributes()),
        ];
    }

    /**
     * @param list<ReflectionAttribute<object>> $attributes
     *
     * @return list<array{name: string, arguments: array<mixed>}>
     */
    private static function describeAttributes(array $attributes): array
    {
        $described = array_map(
            static fn(ReflectionAttribute $attribute): array => [
                'name' => $attribute->getName(),
                'arguments' => self::normaliseValue($attribute->getArguments()),
            ],
            $attributes,
        );

        usort($described, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $described;
    }

    private static function isInternal(false|string $docComment): bool
    {
        return $docComment !== false && str_contains($docComment, '@internal');
    }

    private static function normaliseValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $key => $item) {
                $normalised[$key] = self::normaliseValue($item);
            }

            return $normalised;
        }
        if ($value instanceof BackedEnum) {
            return ['enum' => $value::class . '::' . $value->name, 'value' => $value->value];
        }
        if ($value instanceof UnitEnum) {
            return ['enum' => $value::class . '::' . $value->name];
        }
        if (is_object($value)) {
            return ['object' => $value::class];
        }

        throw new RuntimeException('Unsupported reflected value.');
    }
}
