<?php

declare(strict_types=1);

namespace Spaceemotion\PhpCodingStandard\Tools;

use Spaceemotion\PhpCodingStandard\Context;
use Spaceemotion\PhpCodingStandard\DiffViolation;
use Spaceemotion\PhpCodingStandard\Formatter\File;
use Spaceemotion\PhpCodingStandard\Formatter\Result;
use Spaceemotion\PhpCodingStandard\Formatter\Violation;

use function array_map;
use function array_merge;
use function basename;
use function implode;
use function preg_replace;
use function str_replace;
use function strtolower;

class EasyCodingStandard extends Tool
{
    /** @var string */
    protected $name = 'ecs';

    public function run(Context $context): bool
    {
        $output = [];

        $this->execute(self::vendorBinary($this->name), array_merge(
            [
                'check',
                '--output-format=json',
                '--no-progress-bar',
            ],
            $context->isFixing ? ['--fix'] : [],
            $context->files
        ), $output);

        $outputText = implode('', $output);
        $json = self::parseJson(substr($outputText, (int) strpos($outputText, '{')));

        if ($json === []) {
            return false;
        }

        $result = $this->parseResult($json['files'] ?? [], $context);

        $context->addResult($result);

        return count($result->files) === 0;
    }

    /**
     * @param mixed[] $files
     */
    protected function parseResult(array $files, Context $context): Result
    {
        $result = new Result();

        foreach ($files as $path => $details) {
            $file = new File();

            foreach (($details['errors'] ?? []) as $error) {
                $violation = new Violation();
                $violation->line = $error['line'];
                $violation->message = $error['message'];
                $violation->source = $error['source_class'];
                $violation->tool = $this->name;

                $file->violations[] = $violation;
            }

            if (! $context->isFixing) {
                foreach (($details['diffs'] ?? []) as $diff) {
                    $violations = DiffViolation::make($this, $diff['diff'], static function (int $idx) use ($diff): string {
                        return $idx > 0
                            ? '(contd.)'
                            : "Styling issues:\n- " . implode(
                                "\n- ",
                                self::prettifyCheckers($diff['applied_checkers'])
                            );
                    });

                    $file->violations = array_merge($file->violations, $violations);
                }
            }

            if (count($file->violations) > 0) {
                $result->files[$path] = $file;
            }
        }

        return $result;
    }

    /**
     * @return string[]
     *
     * @psalm-return array<array-key, string>
     */
    private static function prettifyCheckers($applied_checkers): array
    {
        return array_map(static function (string $checker): string {
            $className = basename(str_replace(['\\', '.'], '/', $checker));
            $withoutSuffix = preg_replace('/Fixer$/', '', $className);

            $name = strtolower(preg_replace('/(?<!^)[A-Z]/', ' $0', $withoutSuffix));

            return $name . " <gray>(${checker})</gray>";
        }, $applied_checkers);
    }
}
