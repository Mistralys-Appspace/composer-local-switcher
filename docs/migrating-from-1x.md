# Migrating from 1.x

Version 2.0.0 raises the PHP requirement, replaces `void` returns with typed result objects, and fixes the `tryCopyTo()` behavior. The Composer script keys `switch-dev`, `switch-prod` and `switch-update` keep their names, and a project-owned wrapper class that constructs `ConfigSwitcher` with three `ConfigFile` instances continues to work (the constructor signature is unchanged).

1. **PHP 8.4 or newer is required.** Version 1.x supported PHP 7.3 and up; 2.0.0 declares `php: >=8.4` in `composer.json`. Upgrade the PHP runtime that executes Composer before updating the package.

2. **`switchTo()`, `switchToDevelopment()`, `switchToProduction()`, and `switchUpdate()` no longer return `void`.** They return a `SwitchOutcome` object. If you have a subclass or wrapper that declares a `: void` return type on an override, or code that asserted these calls returned nothing, update the type or assertion. See [Switch outcomes](api.md#switch-outcomes) and [Programmatic and agent usage](api.md#programmatic-and-agent-usage) for the new shape. Console output is unchanged. `ConfigSwitcher::getMessages()` returned plain strings in 1.x; it now returns `SwitchMessage` objects with numeric codes. Code that treated its entries as strings should call `getMessageTexts()` instead.

   ```php
   // Before (1.x)
   public static function switchToDEV() : void
   {
       self::createSwitcher()->switchToDevelopment();
   }

   // After (2.0.0) - still valid, the returned SwitchOutcome is simply ignored.
   // Wrapper methods only need changing if you want to inspect the outcome.
   ```

3. **`BaseFile::tryCopyTo()` no longer requires the target to already exist.** In 1.x, `tryCopyTo()` copied only when *both* the source and the target existed, so a target that did not exist yet was silently skipped. In 2.0.0, it copies whenever the *source* exists. If any custom code relied on `tryCopyTo()` refusing to create a new file, add an explicit `$target->exists()` guard before calling it.

4. **Hand-written script wrappers are now optional.** In 1.x, the `switch-dev`, `switch-prod` and `switch-update` scripts pointed to a `ComposerScripts` class you wrote yourself. 2.0.0 ships static entry points (`ConfigSwitcher::composerSwitchDev`, `composerSwitchProd`, `composerSwitchUpdate`) that use the standard file layout (`composer/composer-prod.json` and `composer/local-repositories.json`). Your existing wrapper, with its own file paths, keeps working; switch to the entry points only if you want to drop the wrapper and adopt that layout. See the [Setup Guide](setup.md#3-script-wiring).

5. **New scripts and capabilities are additive.** `switch-verify-config` and `switch-install-hooks` (with `verify()` and `installGitHooks()`), `describe()`, `previewSwitch()`, `reconcile()`, structured message codes (`SwitchMessage`), and exception context (`ComposerSwitcherException::getContext()`) did not exist in 1.x. Nothing requires you to adopt them. See the [API Guide](api.md), [Git Hooks](git-hooks.md) and [Setup Guide](setup.md).
