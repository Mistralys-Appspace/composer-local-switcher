<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\ConfigSwitcher;
use Mistralys\ComposerSwitcher\State\LocalRepository;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see LocalRepository}: its strict ({@see LocalRepository::parseList()})
 * and lenient ({@see LocalRepository::parseListLenient()}) parsers,
 * which replace what used to be two duplicated, independently
 * drifting readers of the DEV configuration file's
 * `local-repositories` list, and its `toArray()`/`fromArray()`
 * status-file round-tripping.
 *
 * This value object carries no filesystem or process dependencies,
 * so it is tested directly against {@see TestCase} rather than the
 * fixture-driven {@see \Mistralys\ComposerSwitcher\Tests\TestClasses\ComposerSwitcherTestCase}.
 */
final class TestLocalRepository extends TestCase
{
    // region: _Tests - parseList (strict)

    public function test_parseList_entryWithVersion() : void
    {
        $repositories = LocalRepository::parseList(
            array(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                    array('package-name' => 'acme/with-version', 'path' => '../with-version', 'version' => '1.2.3')
                )
            ),
            '/project/composer/local-repositories.json'
        );

        $this->assertCount(1, $repositories);
        $this->assertSame('acme/with-version', $repositories[0]->getPackageName());
        $this->assertSame('../with-version', $repositories[0]->getPath());
        $this->assertSame('1.2.3', $repositories[0]->getVersionOverride());
        $this->assertSame('1.2.3', $repositories[0]->getVersion());
        $this->assertTrue($repositories[0]->hasVersionOverride());
    }

    public function test_parseList_entryWithoutVersion() : void
    {
        $repositories = LocalRepository::parseList(
            array(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                    array('package-name' => 'acme/no-version', 'path' => '../no-version')
                )
            ),
            '/project/composer/local-repositories.json'
        );

        $this->assertCount(1, $repositories);
        $this->assertNull($repositories[0]->getVersionOverride());
        $this->assertSame('*', $repositories[0]->getVersion());
        $this->assertFalse($repositories[0]->hasVersionOverride());
    }

    public function test_parseList_multipleEntries() : void
    {
        $repositories = LocalRepository::parseList(
            array(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                    array('package-name' => 'acme/one', 'path' => '../one'),
                    array('package-name' => 'acme/two', 'path' => '../two', 'version' => '2.0.0')
                )
            ),
            '/project/composer/local-repositories.json'
        );

        $this->assertCount(2, $repositories);
        $this->assertSame('acme/one', $repositories[0]->getPackageName());
        $this->assertSame('acme/two', $repositories[1]->getPackageName());
    }

    /**
     * A missing `local-repositories` key throws `ERROR_INVALID_JSON_STRUCTURE`
     * with the same context keys (`KEY_FILE_PATH`, `KEY_EXPECTED`) the
     * previous, duplicated logic in `switch_adjustConfigForDev()` used.
     */
    public function test_parseList_missingKeyThrowsWithExpectedContext() : void
    {
        try {
            LocalRepository::parseList(array(), '/project/composer/local-repositories.json');
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE, $e->getCode());
            $this->assertSame('/project/composer/local-repositories.json', $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));
            $this->assertSame(ConfigSwitcher::KEY_LOCAL_REPOSITORIES, $e->getContextValue(ComposerSwitcherException::KEY_EXPECTED));
        }
    }

    /**
     * A `local-repositories` key that is not an array is treated
     * identically to a missing key.
     */
    public function test_parseList_nonArrayKeyThrows() : void
    {
        $this->expectException(ComposerSwitcherException::class);
        $this->expectExceptionCode(ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE);

        LocalRepository::parseList(
            array(ConfigSwitcher::KEY_LOCAL_REPOSITORIES => 'not-an-array'),
            '/project/composer/local-repositories.json'
        );
    }

    /**
     * A malformed entry (missing `path`) throws `ERROR_INVALID_JSON_STRUCTURE`
     * with the offending package name carried in `KEY_PACKAGE_NAME` —
     * matching the previous, duplicated logic in `switch_adjustConfigForDev()`.
     */
    public function test_parseList_malformedEntryThrowsWithPackageNameContext() : void
    {
        try {
            LocalRepository::parseList(
                array(
                    ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                        array('package-name' => 'acme/malformed')
                    )
                ),
                '/project/composer/local-repositories.json'
            );
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertSame(ComposerSwitcherException::ERROR_INVALID_JSON_STRUCTURE, $e->getCode());
            $this->assertSame('/project/composer/local-repositories.json', $e->getContextValue(ComposerSwitcherException::KEY_FILE_PATH));
            $this->assertSame('acme/malformed', $e->getContextValue(ComposerSwitcherException::KEY_PACKAGE_NAME));
        }
    }

    /**
     * When the malformed entry's `package-name` is itself not a
     * string, the context value degrades to `null` rather than
     * carrying a non-string value.
     */
    public function test_parseList_malformedEntryWithNonStringPackageNameContextIsNull() : void
    {
        try {
            LocalRepository::parseList(
                array(
                    ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                        array('package-name' => 123, 'path' => '../broken')
                    )
                ),
                '/project/composer/local-repositories.json'
            );
            $this->fail('Expected a ComposerSwitcherException to be thrown.');
        } catch(ComposerSwitcherException $e) {
            $this->assertNull($e->getContextValue(ComposerSwitcherException::KEY_PACKAGE_NAME));
        }
    }

    // endregion

    // region: _Tests - parseListLenient

    public function test_parseListLenient_keepsValidEntriesAndSkipsMalformedOnes() : void
    {
        [$repositories, $warnings] = LocalRepository::parseListLenient(
            array(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                    array('package-name' => 'acme/good', 'path' => '../good'),
                    array('package-name' => 'acme/missing-path'),
                    array('package-name' => 'acme/also-good', 'path' => '../also-good', 'version' => '3.0.0')
                )
            ),
            '/project/composer/local-repositories.json'
        );

        $this->assertCount(2, $repositories);
        $this->assertSame('acme/good', $repositories[0]->getPackageName());
        $this->assertSame('acme/also-good', $repositories[1]->getPackageName());

        $this->assertSame(
            array('Skipped a malformed entry in the dev configuration [local-repositories] list.'),
            $warnings
        );
    }

    public function test_parseListLenient_missingKeyReturnsListLevelWarningAndEmptyList() : void
    {
        [$repositories, $warnings] = LocalRepository::parseListLenient(array(), '/project/composer/local-repositories.json');

        $this->assertSame(array(), $repositories);
        $this->assertSame(
            array('Dev configuration file does not contain a valid [local-repositories] list.'),
            $warnings
        );
    }

    public function test_parseListLenient_nonArrayKeyReturnsListLevelWarning() : void
    {
        [$repositories, $warnings] = LocalRepository::parseListLenient(
            array(ConfigSwitcher::KEY_LOCAL_REPOSITORIES => 'not-an-array'),
            '/project/composer/local-repositories.json'
        );

        $this->assertSame(array(), $repositories);
        $this->assertSame(
            array('Dev configuration file does not contain a valid [local-repositories] list.'),
            $warnings
        );
    }

    public function test_parseListLenient_neverThrowsOnMalformedEntries() : void
    {
        [$repositories, $warnings] = LocalRepository::parseListLenient(
            array(
                ConfigSwitcher::KEY_LOCAL_REPOSITORIES => array(
                    array('package-name' => 123, 'path' => '../broken'),
                    'not-even-an-array'
                )
            ),
            '/project/composer/local-repositories.json'
        );

        $this->assertSame(array(), $repositories);
        $this->assertCount(2, $warnings);
    }

    // endregion

    // region: _Tests - toArray/fromArray

    public function test_toArrayFromArrayRoundTripWithVersionOverride() : void
    {
        $original = new LocalRepository('acme/round-trip', '../round-trip', '1.0.0');

        $restored = LocalRepository::fromArray($original->toArray());

        $this->assertSame($original->getPackageName(), $restored->getPackageName());
        $this->assertSame($original->getPath(), $restored->getPath());
        $this->assertSame($original->getVersionOverride(), $restored->getVersionOverride());
    }

    public function test_toArrayFromArrayRoundTripWithoutVersionOverride() : void
    {
        $original = new LocalRepository('acme/round-trip', '../round-trip');

        $restored = LocalRepository::fromArray($original->toArray());

        $this->assertNull($restored->getVersionOverride());
        $this->assertSame('*', $restored->getVersion());
    }

    // endregion
}
