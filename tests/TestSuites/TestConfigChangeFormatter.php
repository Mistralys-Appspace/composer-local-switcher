<?php

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\TestSuites;

use Mistralys\ComposerSwitcher\State\ConfigChange;
use Mistralys\ComposerSwitcher\State\ConfigChangeOrigin;
use Mistralys\ComposerSwitcher\Utils\ConfigChangeFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies {@see ConfigChangeFormatter} per change shape: a path
 * repository entry describes itself in words (package, relative path,
 * symlink, alias version and the JSON key it came from), a VCS/other
 * repository entry names its type and URL, a `require`/`require-dev`
 * constraint reads as `section › package: before → after`, a plain
 * scalar change reads as `path: before → after`, and anything else
 * falls back to a length-bounded JSON blob — never a raw, unbounded
 * JSON object. {@see ConfigChangeFormatter::displayPath()} is verified
 * independently for project-relative and sibling-relative paths.
 */
final class TestConfigChangeFormatter extends TestCase
{
    private const PROJECT_ROOT = '/project';

    // region: _Tests

    public function test_pathRepositoryEntryInWords() : void
    {
        $entry = array(
            'type' => 'path',
            'url' => '/project/packages/acme-one',
            'options' => array(
                'symlink' => true,
                'versions' => array('acme/one' => '3.0.0'),
            ),
        );

        $change = new ConfigChange(array('repositories', '0'), null, $entry, ConfigChange::KIND_ADDED, ConfigChangeOrigin::LocalSwitch);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringContainsString('./packages/acme-one', $text, 'Expected a project-relative path.');
        $this->assertStringContainsString('acme/one', $text, 'Expected the package name.');
        $this->assertStringContainsString('symlink', $text, 'Expected a symlink indicator.');
        $this->assertStringContainsString('3.0.0', $text, 'Expected the alias version.');
        $this->assertStringContainsString('options.versions', $text, 'Expected the alias\'s source JSON key.');
        $this->assertStringNotContainsString('{', $text, 'No raw JSON object should be rendered.');
        $this->assertStringNotContainsString('\\/', $text, 'No slash-escaped JSON should be rendered.');
    }

    public function test_pathRepositoryEntryWithoutAliasOmitsVersionClause() : void
    {
        $entry = array(
            'type' => 'path',
            'url' => '/project/packages/acme-one',
            'options' => array('symlink' => true),
        );

        $change = new ConfigChange(array('repositories', '0'), null, $entry, ConfigChange::KIND_ADDED, ConfigChangeOrigin::LocalSwitch);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringContainsString('symlink', $text);
        $this->assertStringNotContainsString('as version', $text);
    }

    /**
     * `options.versions` present but an empty array — distinct from
     * {@see self::test_pathRepositoryEntryWithoutAliasOmitsVersionClause()}'s
     * entirely absent key — still renders without error, with no
     * package name or alias clause (both derived from
     * `array_key_first($versions)`, which is `null` for an empty array).
     */
    public function test_pathRepositoryWithMissingOptionsVersions() : void
    {
        $entry = array(
            'type' => 'path',
            'url' => '/project/packages/acme-one',
            'options' => array(
                'symlink' => true,
                'versions' => array(),
            ),
        );

        $change = new ConfigChange(array('repositories', '0'), null, $entry, ConfigChange::KIND_ADDED, ConfigChangeOrigin::LocalSwitch);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringContainsString('./packages/acme-one', $text);
        $this->assertStringContainsString('symlink', $text);
        $this->assertStringNotContainsString('as version', $text);
        $this->assertStringNotContainsString(' for `', $text, 'No package name clause should be rendered without a versions entry.');
    }

    public function test_vcsRepositoryEntryNamesTypeAndUrl() : void
    {
        $entry = array('type' => 'vcs', 'url' => 'https://example.test/acme/one.git');

        $change = new ConfigChange(array('repositories', '0'), $entry, null, ConfigChange::KIND_REMOVED, ConfigChangeOrigin::CarriedBack);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringContainsString('vcs', $text);
        $this->assertStringContainsString('https://example.test/acme/one.git', $text);
        $this->assertStringNotContainsString('{', $text);
    }

    public function test_requireConstraintChangeReadsAsSectionPackageBeforeAfter() : void
    {
        $change = new ConfigChange(array('require', 'acme/one'), '^1.0', '^1.1', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::LocalSwitch);

        $text = $this->createFormatter()->describe($change);

        $this->assertSame('require › acme/one: ^1.0 → ^1.1', $text);
    }

    public function test_dynamicValueContainingLessThanIsNotEscapedByTheFormatterItself() : void
    {
        // Escaping for Composer's IO is EventContext::escape()'s job
        // (applied by OutcomeRenderer), not the formatter's — the
        // formatter only renders plain text.
        $change = new ConfigChange(array('require', 'acme/one'), '^1.0', '<2.0', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::LocalSwitch);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringContainsString('<2.0', $text);
    }

    public function test_scalarChangeReadsAsPathBeforeAfter() : void
    {
        $change = new ConfigChange(array('name'), 'acme/old', 'acme/new', ConfigChange::KIND_CHANGED, ConfigChangeOrigin::CarriedBack);

        $text = $this->createFormatter()->describe($change);

        $this->assertSame('name: acme/old → acme/new', $text);
    }

    public function test_genericFallbackIsTruncatedAndUnescaped() : void
    {
        $longValue = array_fill(0, 40, 'segment/with/a/slash');

        $change = new ConfigChange(array('scripts', 'post-install-cmd'), null, $longValue, ConfigChange::KIND_ADDED, ConfigChangeOrigin::CarriedBack);

        $text = $this->createFormatter()->describe($change);

        $this->assertStringNotContainsString('\\/', $text, 'JSON_UNESCAPED_SLASHES should be in effect.');

        $prefix = 'scripts › post-install-cmd: null → ';
        $renderedValue = substr($text, strlen($prefix));

        $this->assertLessThanOrEqual(
            ConfigChangeFormatter::MAX_VALUE_LENGTH + 1, // + the truncation ellipsis (one character, multiple bytes)
            mb_strlen($renderedValue),
            'The JSON fallback should be truncated past MAX_VALUE_LENGTH.'
        );
    }

    public function test_displayPathRelativeForProjectAndSibling() : void
    {
        // A nested root (parent `/work`, not the filesystem top `/`),
        // matching a realistic project path — the filesystem top is a
        // degenerate case where "every absolute path shares the same
        // parent" would make "adjacent" meaningless.
        $formatter = new ConfigChangeFormatter('/work/project');

        $this->assertSame('./composer.json', $formatter->displayPath('/work/project/composer.json'));
        $this->assertSame('.', $formatter->displayPath('/work/project'));
        $this->assertSame('../sibling/file.json', $formatter->displayPath('/work/sibling/file.json'));
        $this->assertSame('/elsewhere/far/away.json', $formatter->displayPath('/elsewhere/far/away.json'));
    }

    // endregion

    private function createFormatter() : ConfigChangeFormatter
    {
        return new ConfigChangeFormatter(self::PROJECT_ROOT);
    }
}
