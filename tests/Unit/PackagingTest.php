<?php

namespace Modules\Custom\SalesStats\Tests\Unit;

use Tests\TestCase;

/**
 * 설치 폴더명 검증 — G7 은 모듈 디렉토리명을 식별자로 쓰므로(AbstractModule::getIdentifier)
 * 설치 위치는 반드시 modules/custom-sales_stats 이어야 하고, 배포 zip 의 최상위 폴더도 같아야 합니다.
 */
class PackagingTest extends TestCase
{
    private const MOD = 'custom-sales_stats';

    private function base(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return json_decode((string) file_get_contents($this->base().'/module.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_module_identifier_is_install_folder_name(): void
    {
        $m = $this->manifest();
        $this->assertSame(self::MOD, $m['identifier']);
        $this->assertSame('custom', $m['vendor']);
        $this->assertStringStartsWith('Modules\\Custom\\SalesStats\\', array_key_first(
            json_decode((string) file_get_contents($this->base().'/composer.json'), true)['autoload']['psr-4']
        ));
        // 하네스의 설치 경로(심볼릭 링크 포함)도 식별자와 같아야 합니다
        $this->assertDirectoryExists(base_path('modules/'.self::MOD));
        $this->assertFileExists(base_path('modules/'.self::MOD.'/module.json'));
    }

    public function test_release_workflow_uses_identifier_prefix(): void
    {
        $yml = (string) file_get_contents($this->base().'/.github/workflow-templates/release.yml');
        $this->assertStringContainsString('id=$(jq -r .identifier "$f")', $yml);
        $this->assertStringContainsString('--prefix="$ID/"', $yml);
    }

    public function test_readme_install_path(): void
    {
        $readme = (string) file_get_contents($this->base().'/README.md');
        $this->assertStringContainsString('modules/custom-sales_stats', $readme);
        $this->assertStringContainsString('custom-sales_stats-'.$this->manifest()['version'].'.zip', $readme);
        $this->assertDoesNotMatchRegularExpression('/^\s*php artisan /m', $readme, 'README 는 php82 artisan 을 씁니다');
    }

    public function test_package_script_builds_zip_with_top_level_folder(): void
    {
        if (! class_exists(\ZipArchive::class) || ! is_dir($this->base().'/.git') || trim((string) shell_exec('command -v git')) === '') {
            $this->markTestSkipped('git/ZipArchive 없음');
        }
        $out = sys_get_temp_dir().'/css-pkg-'.uniqid();
        $cmd = 'cd '.escapeshellarg($this->base()).' && OUT_DIR='.escapeshellarg($out).' PHP='.escapeshellarg(PHP_BINARY).' bash scripts/package.sh 2>&1';
        exec($cmd, $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
        $zipPath = $out.'/'.self::MOD.'-'.$this->manifest()['version'].'.zip';
        $this->assertFileExists($zipPath);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        array_map('unlink', glob($out.'/*') ?: []);
        @rmdir($out);

        foreach ($names as $n) {
            $this->assertStringStartsWith(self::MOD.'/', $n);
        }
        $this->assertContains(self::MOD.'/module.json', $names);
        $this->assertContains(self::MOD.'/module.php', $names);
        $this->assertContains(self::MOD.'/dist/js/module.iife.js', $names);
        $this->assertNotContains(self::MOD.'/HANDOFF.md', $names);
    }
}
