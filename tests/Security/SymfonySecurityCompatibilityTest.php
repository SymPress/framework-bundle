<?php

declare(strict_types=1);

namespace SymPress\Framework\Tests\Security;

use Composer\Semver\Semver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;

final class SymfonySecurityCompatibilityTest extends TestCase
{
    #[DataProvider('dependencyBoundaries')]
    public function testDeclaredRequirementsExcludeUnsafeVersions(string $package, string $rejected, string $accepted): void
    {
        /** @var array{require: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse(Semver::satisfies($rejected, $manifest['require'][$package]));
        self::assertTrue(Semver::satisfies($accepted, $manifest['require'][$package]));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function dependencyBoundaries(): iterable
    {
        yield 'expression initial incomplete fix' => ['symfony/expression-language', '8.1.6', '8.1.9'];
        yield 'expression last affected patch' => ['symfony/expression-language', '8.1.8', '8.1.9'];
        yield 'yaml inline lexer' => ['symfony/yaml', '8.1.5', '8.1.6'];
        yield 'kernel header parser floor' => ['sympress/kernel', '1.1.9', '1.1.10'];
    }

    #[DataProvider('deepExpressions')]
    public function testDependencyInjectionRejectsDeepExpressions(string $expression): void
    {
        try {
            (new ExpressionLanguage())->lint($expression, []);
            self::fail('An excessively nested expression was accepted.');
        } catch (SyntaxError $exception) {
            self::assertStringContainsString('maximum nesting level is 256', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function deepExpressions(): iterable
    {
        yield 'unary nesting' => [str_repeat('!', 300) . '1'];

        $expression = '1';
        for ($level = 0; $level < 60; ++$level) {
            $expression = '(' . $expression . str_repeat(' + 1', 60) . ')';
        }
        yield 'chained binary nesting missed by 8.1.6' => [$expression];
    }

    public function testOrdinaryDependencyInjectionExpressionStillWorks(): void
    {
        self::assertSame(3, (new ExpressionLanguage())->evaluate('1 + 2'));
    }

    public function testInlineYamlLexerHonorsConfiguredNestingLimit(): void
    {
        $parser = new Parser(8);
        self::assertSame(['root' => [[1]]], $parser->parse('root: [[1]]'));

        try {
            $parser->parse('root: ' . str_repeat('[', 9));
            self::fail('The inline YAML lexer exceeded its configured nesting limit.');
        } catch (ParseException $exception) {
            self::assertStringContainsString('Maximum nesting depth of 8 exceeded', $exception->getMessage());
        }
    }
}
