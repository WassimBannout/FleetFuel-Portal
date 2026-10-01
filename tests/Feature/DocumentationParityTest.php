<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The documents must describe the application that exists (M10, "validate
 * docs/contracts against routes and commands"). Every `make` target, Artisan
 * command, endpoint, local URL and relative link named in a maintained
 * document has to be real. OpenApiContractTest compares openapi.json with
 * the routes; this test covers the prose.
 *
 * Commands and endpoints count only inside code (`...` or fenced blocks), so
 * words such as "make sure" are not mistaken for commands.
 */
class DocumentationParityTest extends TestCase
{
    public function test_every_documented_make_target_exists(): void
    {
        preg_match_all('/^([a-z][a-z-]*):/m', $this->read('Makefile'), $matches);
        $targets = $matches[1];
        $this->assertContains('verify', $targets, 'The Makefile could not be read.');

        $missing = [];
        foreach ($this->documents() as $document => $markdown) {
            foreach ($this->code($markdown) as $code) {
                preg_match_all('/(?:^|[\s;&|(])make\s+([a-z][a-z-]*)/m', $code, $found);
                foreach (array_diff($found[1], $targets) as $target) {
                    $missing[] = "{$document}: make {$target}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Documented make targets missing from the Makefile.');
    }

    public function test_every_documented_artisan_command_exists(): void
    {
        $commands = array_keys(Artisan::all());

        $missing = [];
        foreach ($this->documents() as $document => $markdown) {
            foreach ($this->code($markdown) as $code) {
                preg_match_all('/php artisan ([a-z][a-z0-9:-]*)/', $code, $found);
                foreach (array_diff($found[1], $commands) as $command) {
                    $missing[] = "{$document}: php artisan {$command}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Documented Artisan commands that are not registered.');
    }

    /**
     * "`PATCH /api/v1/cards/{id}`" in the README, or "`GET /stations`" in the
     * API contract, whose paths are relative to /api/v1.
     */
    public function test_every_documented_endpoint_is_routed(): void
    {
        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $routes[] = $method.' '.$this->normalizedPath($route->uri());
            }
        }

        $missing = [];
        foreach ($this->documents() as $document => $markdown) {
            preg_match_all('/`(GET|POST|PUT|PATCH|DELETE) (\/[^\s`?]*)/', $this->withoutFencedBlocks($markdown), $found, PREG_SET_ORDER);
            foreach ($found as [, $method, $path]) {
                $candidates = [$method.' '.$this->normalizedPath($path), $method.' '.$this->normalizedPath('/api/v1'.$path)];
                if (array_intersect($candidates, $routes) === []) {
                    $missing[] = "{$document}: {$method} {$path}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Documented endpoints without a route.');
    }

    /** A URL may name a page, an endpoint, or the API's base URL. */
    public function test_every_documented_local_url_is_routed(): void
    {
        $routes = Route::getRoutes();

        $missing = [];
        foreach ($this->documents() as $document => $markdown) {
            preg_match_all('#http://localhost:8080(/[^\s)>`"\']*)?#', $markdown, $found);
            foreach ($found[1] as $path) {
                $path = '/'.trim((string) strtok($path, '?#'), '/');
                if ($path === '/api/v1') {
                    continue;
                }

                $known = false;
                foreach (['GET', 'POST'] as $method) {
                    try {
                        $known = $known || ! $routes->match(request()->create($path, $method))->isFallback;
                    } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                        // Not this method; try the next.
                    }
                }

                if (! $known) {
                    $missing[] = "{$document}: {$path}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Documented local URLs without a route.');
    }

    public function test_every_relative_link_points_at_a_file(): void
    {
        $missing = [];
        foreach ($this->documents() as $document => $markdown) {
            preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $this->withoutFencedBlocks($markdown), $found);
            foreach ($found[1] as $link) {
                if (preg_match('/^(https?:|mailto:|#)/', $link) === 1) {
                    continue;
                }

                if (! file_exists(dirname(base_path($document)).'/'.strtok($link, '#'))) {
                    $missing[] = "{$document}: {$link}";
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)), 'Relative links to files that do not exist.');
    }

    /**
     * Maintained documents. PROGRESS.md and CHANGELOG.md are dated logs, and
     * docs/reference holds preserved source material.
     *
     * @return array<string, string> path relative to the project root => contents
     */
    private function documents(): array
    {
        $paths = [
            'README.md', 'START_HERE.md', 'CLAUDE.md',
            'tools/pos-simulator/README.md', 'postman/README.md', 'docs/screenshots/README.md',
        ];

        foreach (glob(base_path('docs/*.md')) ?: [] as $file) {
            if (basename($file) !== 'PROGRESS.md') {
                $paths[] = 'docs/'.basename($file);
            }
        }

        $documents = [];
        foreach ($paths as $path) {
            $documents[$path] = $this->read($path);
        }

        return $documents;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents(base_path($path));
        $this->assertIsString($contents, "{$path} could not be read.");

        return $contents;
    }

    /**
     * The contents of fenced blocks and inline code spans.
     *
     * @return list<string>
     */
    private function code(string $markdown): array
    {
        preg_match_all('/```[^\n]*\n(.*?)```/s', $markdown, $blocks);
        preg_match_all('/`([^`\n]+)`/', $this->withoutFencedBlocks($markdown), $spans);

        return [...$blocks[1], ...$spans[1]];
    }

    private function withoutFencedBlocks(string $markdown): string
    {
        return (string) preg_replace('/```.*?```/s', '', $markdown);
    }

    /** "/api/v1/cards/{card_no}/balance" and "api/v1/cards/{card}/balance" compare equal. */
    private function normalizedPath(string $path): string
    {
        return '/'.trim((string) preg_replace('/\{[^}]+\}/', '{}', $path), '/');
    }
}
