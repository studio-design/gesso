<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Tests\Helpers;

use const DIRECTORY_SEPARATOR;

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
use function array_map;
use function array_values;
use function class_exists;
use function enum_exists;
use function interface_exists;
use function is_array;
use function is_object;
use function is_scalar;
use function ksort;
use function method_exists;
use function realpath;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
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

            $properties[$property->getName()] = [
                'type' => $property->hasType() ? (string) $property->getType() : null,
                'static' => $property->isStatic(),
                'readonly' => $property->isReadOnly(),
                'default' => $property->hasDefaultValue() ? self::normaliseValue($property->getDefaultValue()) : ['unavailable' => true],
            ];
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

        return [
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
            'return_type' => $method->hasReturnType() ? (string) $method->getReturnType() : null,
            'attributes' => self::describeAttributes($method->getAttributes()),
            'parameters' => array_map(self::describeParameter(...), $method->getParameters()),
        ];
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
