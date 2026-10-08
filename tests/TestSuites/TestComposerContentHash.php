<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\Utils\ComposerContentHash;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see ComposerContentHash::fromConfigData()} produces
 * byte-identical hashes to the real Composer 2.9.5 binary's
 * `Locker::getContentHash()` / `composer.lock`'s `content-hash` key.
 *
 * Every golden hash below was captured by running the real
 * `composer update --no-install` binary (Composer 2.9.5) against the
 * corresponding `composer.json` payload and reading the resulting
 * lock file's `content-hash` key — these are not independently
 * re-derived from this class's own algorithm, so a golden vector
 * failing would mean an actual behavioral mismatch against Composer.
 *
 * This value object carries no filesystem or process dependencies,
 * so it is tested directly against {@see TestCase} rather than the
 * fixture-driven {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase}.
 */
final class TestComposerContentHash extends TestCase
{
    // region: _Tests

    /**
     * A `composer.json` payload touching every relevant key (including
     * `config.platform`) produces Composer's own real `content-hash`.
     */
    public function test_fullPayloadMatchesComposerGoldenHash() : void
    {
        $this->assertSame(
            '5ddee311cde8ad8ecf924d9ba00de702',
            ComposerContentHash::fromConfigData($this->goldenOneData())
        );
    }

    /**
     * The same payload, with every top-level key reordered and every
     * irrelevant key either changed or newly added (`description`,
     * `homepage`, `authors`), still produces the identical golden
     * hash — Composer's top-level `ksort()` neutralizes key order,
     * and irrelevant keys are excluded entirely.
     */
    public function test_reorderedKeysAndIrrelevantChangesProduceSameHash() : void
    {
        $this->assertSame(
            '5ddee311cde8ad8ecf924d9ba00de702',
            ComposerContentHash::fromConfigData($this->goldenOneReorderedWithIrrelevantChangesData())
        );
    }

    /**
     * Changing a relevant key's constraint (`conflict.foo/bar` from
     * `<1.0` to `<2.0`) changes the hash, and the result still matches
     * Composer's own golden hash for that modified payload.
     */
    public function test_changingARelevantConstraintChangesTheHash() : void
    {
        $original = ComposerContentHash::fromConfigData($this->goldenOneData());
        $modified = ComposerContentHash::fromConfigData($this->goldenOneWithChangedConflictData());

        $this->assertNotSame($original, $modified);
        $this->assertSame('49056c8d8b6852a0619f45c27e58197e', $modified);
    }

    /**
     * A minimal payload with only `name` and `require` — no
     * `config` key at all — matches Composer's own golden hash.
     */
    public function test_minimalPayloadMatchesComposerGoldenHash() : void
    {
        $this->assertSame(
            'f4b5dc5b8d19f531247b47f7060b93a9',
            ComposerContentHash::fromConfigData($this->minimalData())
        );
    }

    /**
     * A `config` key that exists but has no `platform` sub-key
     * (`config.sort-packages` only) is irrelevant and produces the
     * same hash as if `config` were absent entirely — `config.platform`
     * is the only part of `config` Composer considers relevant.
     */
    public function test_configWithoutPlatformIsIrrelevant() : void
    {
        $this->assertSame(
            'f4b5dc5b8d19f531247b47f7060b93a9',
            ComposerContentHash::fromConfigData($this->minimalDataWithIrrelevantConfigData())
        );
    }

    // endregion

    // region: _Support

    /**
     * @return array<string,mixed>
     */
    private function goldenOneData() : array
    {
        return array(
            'name' => 'acme/golden-one',
            'version' => '1.2.3',
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'require' => array(
                'php' => '>=8.1'
            ),
            'require-dev' => array(
                'phpunit/phpunit' => '^9.0'
            ),
            'conflict' => array(
                'foo/bar' => '<1.0'
            ),
            'replace' => array(
                'foo/baz' => 'self.version'
            ),
            'provide' => array(
                'psr/log-implementation' => '1.0.0'
            ),
            'repositories' => array(
                array('type' => 'path', 'url' => '../local-pkg')
            ),
            'extra' => array(
                'custom' => 'value'
            ),
            'config' => array(
                'platform' => array(
                    'php' => '8.1.0'
                ),
                'sort-packages' => true
            ),
            'description' => 'irrelevant key that must not affect the hash',
            'authors' => array(
                array('name' => 'Someone')
            )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function goldenOneReorderedWithIrrelevantChangesData() : array
    {
        return array(
            'require-dev' => array(
                'phpunit/phpunit' => '^9.0'
            ),
            'description' => 'A DIFFERENT irrelevant description, and extra junk key below',
            'homepage' => 'https://example.com/should-not-matter',
            'minimum-stability' => 'dev',
            'config' => array(
                'sort-packages' => true,
                'platform' => array(
                    'php' => '8.1.0'
                )
            ),
            'version' => '1.2.3',
            'extra' => array(
                'custom' => 'value'
            ),
            'name' => 'acme/golden-one',
            'require' => array(
                'php' => '>=8.1'
            ),
            'authors' => array(
                array('name' => 'Someone Else Entirely')
            ),
            'prefer-stable' => true,
            'replace' => array(
                'foo/baz' => 'self.version'
            ),
            'repositories' => array(
                array('type' => 'path', 'url' => '../local-pkg')
            ),
            'conflict' => array(
                'foo/bar' => '<1.0'
            ),
            'provide' => array(
                'psr/log-implementation' => '1.0.0'
            )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function goldenOneWithChangedConflictData() : array
    {
        $data = $this->goldenOneData();
        $data['conflict']['foo/bar'] = '<2.0';

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalData() : array
    {
        return array(
            'name' => 'acme/minimal',
            'require' => array(
                'php' => '>=8.1'
            )
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function minimalDataWithIrrelevantConfigData() : array
    {
        $data = $this->minimalData();
        $data['config'] = array(
            'sort-packages' => true
        );

        return $data;
    }

    // endregion
}
