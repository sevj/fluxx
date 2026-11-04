<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Ui\AssetVersion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/fluxx-asset-version-' . uniqid('', true);
        mkdir($this->directory . '/src/Resources/public/scripts', recursive: true);
        mkdir($this->directory . '/src/Resources/public/styles', recursive: true);
        file_put_contents($this->directory . '/src/Resources/public/scripts/app.js', 'js');
        file_put_contents($this->directory . '/src/Resources/public/styles/app.css', 'css');
        file_put_contents($this->directory . '/logo.png', 'logo');
    }

    protected function tearDown(): void
    {
        unlink($this->directory . '/src/Resources/public/scripts/app.js');
        unlink($this->directory . '/src/Resources/public/styles/app.css');
        unlink($this->directory . '/logo.png');
        rmdir($this->directory . '/src/Resources/public/scripts');
        rmdir($this->directory . '/src/Resources/public/styles');
        rmdir($this->directory . '/src/Resources/public');
        rmdir($this->directory . '/src/Resources');
        rmdir($this->directory . '/src');
        rmdir($this->directory);
    }

    #[Test]
    public function it_stays_the_same_while_the_assets_do_not_change(): void
    {
        self::assertSame(
            (new AssetVersion($this->directory))->current(),
            (new AssetVersion($this->directory))->current(),
        );
    }

    #[Test]
    public function it_changes_with_the_content_of_any_asset(): void
    {
        $initial = (new AssetVersion($this->directory))->current();

        foreach (['src/Resources/public/scripts/app.js', 'src/Resources/public/styles/app.css', 'logo.png'] as $asset) {
            file_put_contents($this->directory . '/' . $asset, 'changed ' . $asset);

            self::assertNotSame($initial, (new AssetVersion($this->directory))->current(), $asset);
        }
    }

    #[Test]
    public function it_covers_the_assets_shipped_by_the_package(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (new AssetVersion())->current());
        self::assertNotSame((new AssetVersion($this->directory))->current(), (new AssetVersion())->current());
    }
}
