<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

/**
 * Human-readable progress. Card numbers are shortened to their last four
 * characters and the token is never printed.
 */
final class Reporter
{
    private int $checks = 0;

    /**
     * @param  resource  $out
     */
    public function __construct(private $out) {}

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text.PHP_EOL);
    }

    public function scenario(string $name, string $title): void
    {
        $this->line("[{$name}] {$title}");
    }

    public function pass(string $text): void
    {
        $this->checks++;
        $this->line("  PASS  {$text}");
    }

    public function fail(string $text): void
    {
        $this->line("  FAIL  {$text}");
    }

    public function note(string $text): void
    {
        $this->line("        {$text}");
    }

    public function checks(): int
    {
        return $this->checks;
    }

    public static function card(string $cardNo): string
    {
        return 'card …'.substr($cardNo, -4);
    }
}
