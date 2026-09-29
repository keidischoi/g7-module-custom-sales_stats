<?php

namespace Modules\Custom\SalesStats\Tests\Unit;

use App\Rules\NoExternalUrls;
use App\Rules\SafeLayoutExpressions;
use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 레이아웃 JSON 검증 — 코어 저장 규칙(ValidLayoutStructure / WhitelistedEndpoint / NoExternalUrls / SafeLayoutExpressions)
 * + 이 모듈 규칙(?. / ?? 금지, sirsoft-admin_basic CSS 클래스만, $t 키 존재, partial 규칙)
 */
class LayoutValidationTest extends TestCase
{
    private const MOD = 'custom-sales_stats';

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private function layoutDir(): string
    {
        return $this->base().'/resources/layouts/admin';
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function layouts(): array
    {
        return [
            'main' => ['admin_sales_stats'],
            'seller' => ['admin_sales_stats_seller'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(mixed $node, string $dir): mixed
    {
        if (is_array($node)) {
            if (array_keys($node) === ['partial']) {
                $path = realpath($dir.'/'.$node['partial']);
                $this->assertNotFalse($path, 'partial 없음: '.$node['partial']);
                $this->assertStringStartsWith(realpath($this->layoutDir()), $path);
                $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                $this->assertTrue($data['meta']['is_partial'] ?? false, 'meta.is_partial 없음: '.$node['partial']);
                $this->assertArrayNotHasKey('data_sources', $data);
                $this->assertArrayNotHasKey('computed', $data);
                unset($data['meta']);

                return $this->resolve($data, dirname($path));
            }

            return array_map(fn ($v) => $this->resolve($v, $dir), $node);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $name): array
    {
        $raw = json_decode((string) file_get_contents($this->layoutDir().'/'.$name.'.json'), true, 512, JSON_THROW_ON_ERROR);

        return $this->resolve($raw, $this->layoutDir());
    }

    /**
     * @return array<int, string>
     */
    private function strings(mixed $node): array
    {
        $out = [];
        array_walk_recursive($node, function ($v) use (&$out) {
            if (is_string($v)) {
                $out[] = $v;
            }
        });

        return $out;
    }

    #[DataProvider('layouts')]
    public function test_core_layout_rules_pass(string $name): void
    {
        $layout = $this->load($name);
        $v = Validator::make(['content' => $layout], ['content' => [
            'required', 'array', new ValidLayoutStructure, new WhitelistedEndpoint, new NoExternalUrls, new SafeLayoutExpressions,
        ]]);
        $this->assertFalse($v->fails(), implode("\n", $v->errors()->all()));
        $this->assertSame('_admin_base', $layout['extends']);
        $this->assertContains(self::MOD.'.stats.view', $layout['permissions']);
        foreach ($layout['data_sources'] as $ds) {
            $this->assertStringStartsWith('/api/modules/'.self::MOD.'/', $ds['endpoint']);
            $this->assertArrayHasKey('errorHandling', $ds);
        }
    }

    #[DataProvider('layouts')]
    public function test_no_optional_chaining_or_nullish_in_expressions(string $name): void
    {
        foreach ($this->strings($this->load($name)) as $s) {
            preg_match_all('/\{\{(.*?)\}\}/s', $s, $m);
            foreach ($m[1] as $expr) {
                $bare = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', "''", $expr);
                $this->assertStringNotContainsString('?.', $bare, $expr);
                $this->assertStringNotContainsString('??', $bare, $expr);
            }
        }
    }

    #[DataProvider('layouts')]
    public function test_class_names_exist_in_admin_template_css(string $name): void
    {
        $known = array_flip(array_filter(array_map('trim', file($this->base().'/tests/fixtures/admin_basic_classes.txt')), fn ($l) => $l !== '' && $l[0] !== '#'));
        $missing = [];
        $walk = function ($node) use (&$walk, &$missing, $known) {
            if (! is_array($node)) {
                return;
            }
            $cls = $node['props']['className'] ?? null;
            if (is_string($cls)) {
                $tokens = [];
                if (str_contains($cls, '{{')) {
                    preg_match_all('/\{\{(.*?)\}\}/s', $cls, $m);
                    foreach ($m[1] as $expr) {
                        preg_match_all('/\'([^\']*)\'/', $expr, $lits, PREG_OFFSET_CAPTURE);
                        foreach ($lits[1] as [$lit, $pos]) {
                            $before = rtrim(substr($expr, 0, $pos - 1));
                            $after = ltrim(substr($expr, $pos + strlen($lit) + 1), ' )');
                            if (preg_match('/[!=]==?$/', $before) || preg_match('/^[!=]=/', $after)) {
                                continue;
                            }
                            $tokens = array_merge($tokens, preg_split('/\s+/', $lit, -1, PREG_SPLIT_NO_EMPTY));
                        }
                    }
                } else {
                    $tokens = preg_split('/\s+/', $cls, -1, PREG_SPLIT_NO_EMPTY);
                    if (in_array('admin-page-content-responsive', $tokens, true)) {
                        foreach ($tokens as $tok) {
                            $this->assertDoesNotMatchRegularExpression('/^(max-w-|w-|min-w-|mx-auto$)/', $tok, '관리자 콘텐츠 래퍼에 폭 유틸리티 금지');
                        }
                    }
                }
                foreach ($tokens as $tok) {
                    if (! isset($known[$tok])) {
                        $missing[] = $tok;
                    }
                }
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($this->load($name));
        $this->assertSame([], array_values(array_unique($missing)), 'sirsoft-admin_basic CSS 에 없는 클래스');
    }

    #[DataProvider('layouts')]
    public function test_translation_keys_exist(string $name): void
    {
        $text = implode("\n", $this->strings($this->load($name)))."\n".file_get_contents($this->base().'/resources/routes/admin.json');
        preg_match_all('/\$t:(?:defer:)?'.preg_quote(self::MOD, '/').'\.([A-Za-z0-9_.]+)/', $text, $m);
        $keys = array_unique($m[1]);
        $this->assertNotEmpty($keys);
        foreach (['ko', 'en'] as $locale) {
            $lang = json_decode((string) file_get_contents($this->base().'/resources/lang/'.$locale.'.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey(self::MOD, $lang, '언어 파일 최상위에 모듈 접두사 금지');
            foreach ($keys as $key) {
                $this->assertNotNull(data_get($lang, $key), "{$locale}.json 에 {$key} 없음");
            }
            foreach (['js.no_member', 'js.note_unavailable'] as $jsKey) {
                $this->assertNotNull(data_get($lang, $jsKey), "{$locale}.json 에 {$jsKey} 없음");
            }
        }
    }

    public function test_routes_point_to_existing_layouts(): void
    {
        $routes = json_decode((string) file_get_contents($this->base().'/resources/routes/admin.json'), true, 512, JSON_THROW_ON_ERROR);
        $paths = array_column($routes['routes'], 'path');
        $this->assertSame(['*/admin/sales-stats', '*/admin/ecommerce/sales-stats', '*/admin/sales-stats/sellers/:userId'], $paths);
        foreach ($routes['routes'] as $r) {
            $this->assertFileExists($this->layoutDir().'/'.$r['layout'].'.json');
            $this->assertNotEmpty($r['meta']['title']);
            $this->assertNotEmpty($r['meta']['breadcrumb']);
        }
    }

    public function test_dist_bundle_matches_source(): void
    {
        $this->assertFileEquals($this->base().'/resources/assets/module.js', $this->base().'/dist/js/module.iife.js');
    }
}
