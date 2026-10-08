<?php
/**
 * @package Composer Switcher
 * @subpackage Utils
 */

declare(strict_types=1);

namespace Mistralys\ComposerSwitcher\Utils;

use ErrorException;
use Mistralys\ComposerSwitcher\ComposerSwitcherException;
use Mistralys\ComposerSwitcher\State\ComposerCommand;

/**
 * Executes a planned {@see ComposerCommand} as a real, inherited-IO
 * child process — the only place in the library that actually spawns
 * Composer. `ConfigSwitcher` itself never executes anything ("plan in
 * the core, execute at the edge"): it only ever hands back a
 * {@see ComposerCommand} for a caller (here, {@see SwitchCommandRunner})
 * to run with this class, or with its own runner.
 *
 * @package Composer Switcher
 * @subpackage Utils
 */
final class ComposerProcess
{
    /**
     * @param string|null $binaryPath Explicit Composer binary path, for
     *        tests. When omitted, resolved from the `COMPOSER_BINARY`
     *        environment variable (set by Composer itself for every
     *        script it runs), falling back to an executable `composer`
     *        found on `PATH`.
     */
    public function __construct(
        private readonly ?string $binaryPath = null
    )
    {
    }

    /**
     * Runs `$command` via `proc_open()` with the array argument form
     * (no shell string interpolation) and inherited STDIN/STDOUT/STDERR,
     * so the child's output streams directly to the console. The
     * environment is the current process's `getenv()` plus
     * `COMPOSER_SWITCHER_NESTED=1`, so a `post-update-cmd` hook firing
     * inside this child process can detect it is nested and no-op.
     *
     * @return int The child process's exit code.
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_COMPOSER_BINARY_NOT_FOUND}
     */
    public function run(ComposerCommand $command, string $workingDir) : int
    {
        $binary = $this->resolveBinary();

        $argv = array_merge(array(PHP_BINARY, $binary), $command->getArguments());

        $env = $this->buildEnvironment();

        $descriptorSpec = array(
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        );

        $process = $this->runNative(
            static fn() => proc_open($argv, $descriptorSpec, $pipes, $workingDir, $env),
            $nativeError
        );

        if(!is_resource($process))
        {
            throw (new ComposerSwitcherException(
                'ERROR: Failed to start the Composer process.',
                ComposerSwitcherException::ERROR_COMPOSER_COMMAND_FAILED,
                $nativeError
            ))
                ->setContext(array(
                    ComposerSwitcherException::KEY_COMMAND => $command->toShellString(),
                    ComposerSwitcherException::KEY_NATIVE_ERROR => $nativeError?->getMessage() ?? ''
                ));
        }

        return proc_close($process);
    }

    /**
     * @return array<string,string>
     */
    private function buildEnvironment() : array
    {
        $env = getenv();
        $env['COMPOSER_SWITCHER_NESTED'] = '1';

        return $env;
    }

    /**
     * Resolves the Composer binary to invoke: the constructor-injected
     * path, else the `COMPOSER_BINARY` environment variable Composer
     * itself sets for every script it runs, else the first executable
     * `composer` found on `PATH` (checked via `is_executable()`, not a
     * bare existence check, so a non-executable file of the same name
     * is correctly skipped).
     *
     * @throws ComposerSwitcherException {@see ComposerSwitcherException::ERROR_COMPOSER_BINARY_NOT_FOUND}
     */
    private function resolveBinary() : string
    {
        if($this->binaryPath !== null)
        {
            return $this->binaryPath;
        }

        $envBinary = getenv('COMPOSER_BINARY');

        if(is_string($envBinary) && $envBinary !== '' && is_executable($envBinary))
        {
            return $envBinary;
        }

        $pathEnv = getenv('PATH');
        $pathEnv = is_string($pathEnv) ? $pathEnv : '';
        $separator = DIRECTORY_SEPARATOR === '\\' ? ';' : ':';

        foreach(explode($separator, $pathEnv) as $directory)
        {
            if($directory === '')
            {
                continue;
            }

            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'composer';

            if(is_executable($candidate))
            {
                return $candidate;
            }
        }

        throw new ComposerSwitcherException(
            'ERROR: Could not locate the Composer binary (neither COMPOSER_BINARY nor an executable `composer` on PATH).',
            ComposerSwitcherException::ERROR_COMPOSER_BINARY_NOT_FOUND
        );
    }

    /**
     * Runs a native PHP call with any warning/notice it raises captured
     * into `$capturedError` instead of reaching the ambient error
     * handler, mirroring {@see FileSystem::runNative()}.
     *
     * @template T
     * @param callable():T $call
     * @param ErrorException|null $capturedError
     * @return T
     */
    private function runNative(callable $call, ?ErrorException &$capturedError = null) : mixed
    {
        $capturedError = null;

        set_error_handler(static function(int $severity, string $message, string $file = '', int $line = 0) use (&$capturedError) : bool {
            $capturedError = new ErrorException($message, 0, $severity, $file, $line);

            return true;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
