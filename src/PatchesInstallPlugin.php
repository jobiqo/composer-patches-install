<?php

namespace Jobiqo\ComposerPatchesInstall;

use Composer\Composer;
use Composer\Console\Application;
use Composer\DependencyResolver\Operation\UninstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Composer plugin that detects patches.lock.json changes and triggers re-patching of affected packages.
 *
 * Subscribes to Composer script events so that consumers do not need to add
 * any manual entries to their "scripts" section in composer.json.
 *
 * On first composer install, copies patches.lock.json to vendor/composer/patches.lock.json.
 * On subsequent installs, compares patches.lock.json with the cached version,
 * triggers re-installation of packages where patch hashes have changed, and
 * updates vendor/composer/patches.lock.json after processing.
 */
class PatchesInstallPlugin implements PluginInterface, EventSubscriberInterface {

  /**
   * Flag to indicate if this is a fresh install (vendor/composer doesn't exist yet).
   */
  protected static bool $isFreshInstall = TRUE;

  /**
   * {@inheritdoc}
   */
  public function activate(Composer $composer, IOInterface $io): void {}

  /**
   * {@inheritdoc}
   */
  public function deactivate(Composer $composer, IOInterface $io): void {}

  /**
   * {@inheritdoc}
   */
  public function uninstall(Composer $composer, IOInterface $io): void {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ScriptEvents::PRE_INSTALL_CMD => ['onPreInstall', 0],
      ScriptEvents::POST_INSTALL_CMD => ['onPostInstall', 0],
      ScriptEvents::POST_UPDATE_CMD => ['onPostUpdate', 0],
    ];
  }

  /**
   * Handles the pre-install-cmd event.
   */
  public function onPreInstall(Event $event): void {
    // This hook is only called when composer already installed something before,
    // so we always know this is not a fresh install.
    self::$isFreshInstall = FALSE;
  }

  /**
   * Handles the post-install-cmd event.
   */
  public function onPostInstall(Event $event): void {
    $this->checkPatchChanges($event);
  }

  /**
   * Handles the post-update-cmd event.
   */
  public function onPostUpdate(Event $event): void {
    $this->checkPatchChanges($event);
  }

  /**
   * Checks if we are currently in the process of reinstalling packages to prevent infinite loops.
    *
    * @param Event $event
    *   The Composer event.
    *
    * @return bool
    *   TRUE if we are currently reinstalling, FALSE otherwise.
   */
  public function isReinstalling(Event $event): bool {
    $composer = $event->getComposer();
    $baseDir = dirname($composer->getConfig()->getConfigSource()->getName());
    $lockFile = $baseDir . '/vendor/composer/patches.reinstall.lock';
    // If file is older than 1 hour, assume it's a stale lock and not an active
    // reinstall to prevent blocking installs indefinitely.
    if (file_exists($lockFile) && (time() - filemtime($lockFile) > 3600)) {
      @unlink($lockFile);
    }
    return file_exists($lockFile);
  }

  /**
   * Sets a lock file to indicate that we are currently in the process of reinstalling packages.
   */
  public function setReinstalling(Composer $composer, bool $value): void {
    $baseDir = dirname($composer->getConfig()->getConfigSource()->getName());
    $lockFile = $baseDir . '/vendor/composer/patches.reinstall.lock';
    if ($value) {
      @file_put_contents($lockFile, time());
    }
    else {
      @unlink($lockFile);
    }
  }

  /**
   * Handler for post-install-cmd and post-update-cmd events.
   *
   * @param Event $event
   *   The Composer event.
   */
  public function checkPatchChanges(Event $event): void {
    $composer = $event->getComposer();
    $io = $event->getIO();
    $devMode = $event->isDevMode();

    $baseDir = dirname($composer->getConfig()->getConfigSource()->getName());
    $sourceLockFile = $baseDir . '/patches.lock.json';
    $cachedLockFile = $baseDir . '/vendor/composer/patches.lock.json';

    // If there is no source lock file, there's nothing to check, so we can skip everything.
    if (!file_exists($sourceLockFile)) {
      return;
    }

    // Skip fresh installations, composer patches does everything correct on first install.
    if (self::$isFreshInstall) {
      // Create vendor/composer and copy lock file to skip reinstalls in post-install.
      self::copyLockFile($sourceLockFile, $cachedLockFile);
      $io->write('<info>PatchesLockPlugin: Fresh install detected, pre-initialized patches.lock.json cache.</info>');
      return;
    }

    // Prevent infinite recursion when we trigger reinstallations.
    if ($this->isReinstalling($event)) {
      return;
    }

    // Determine dev-only package names so we can skip them in --no-dev mode.
    $devPackageNames = [];
    if (!$devMode) {
      $devPackageNames = self::getDevPackageNames($baseDir, $io);
    }

    $sourceData = self::readLockFile($sourceLockFile);
    if ($sourceData === NULL) {
      $io->write('<error>Failed to read patches.lock.json</error>');
      return;
    }

    // First install: reinstall all patched packages to ensure consistent state.
    if (!file_exists($cachedLockFile)) {
      $patchedPackages = self::getPatchedPackages($sourceData);
      $patchedPackages = self::filterDevPackages($patchedPackages, $devPackageNames, $devMode, $io);
      if (!empty($patchedPackages)) {
        $io->write('<info>PatchesLockPlugin: First run - reinstalling all patched packages for consistent state:</info>');
        foreach ($patchedPackages as $packageName) {
          $io->write('  - ' . $packageName);
        }
        if (!$this->reinstallPackages($patchedPackages, $composer, $io, $devMode)) {
          $io->write('<error>PatchesLockPlugin: Failed to reinstall packages, not updating lock file cache.</error>');
          return;
        }
      }
      self::copyLockFile($sourceLockFile, $cachedLockFile);
      $io->write('<info>PatchesLockPlugin: Initialized vendor/composer/patches.lock.json</info>');
      return;
    }

    $cachedData = self::readLockFile($cachedLockFile);
    if ($cachedData === NULL) {
      // Cached file is corrupted, reinstall all patched packages.
      $patchedPackages = self::getPatchedPackages($sourceData);
      $patchedPackages = self::filterDevPackages($patchedPackages, $devPackageNames, $devMode, $io);
      if (!empty($patchedPackages)) {
        $io->write('<info>PatchesLockPlugin: Cached file corrupted - reinstalling all patched packages:</info>');
        foreach ($patchedPackages as $packageName) {
          $io->write('  - ' . $packageName);
        }
        if (!$this->reinstallPackages($patchedPackages, $composer, $io, $devMode)) {
          $io->write('<error>PatchesLockPlugin: Failed to reinstall packages, not updating lock file cache.</error>');
          return;
        }
      }
      self::copyLockFile($sourceLockFile, $cachedLockFile);
      $io->write('<info>PatchesLockPlugin: Re-initialized vendor/composer/patches.lock.json</info>');
      return;
    }

    // Compare and find packages needing reinstallation.
    $packagesToReinstall = self::findChangedPackages($sourceData, $cachedData);
    $packagesToReinstall = self::filterDevPackages($packagesToReinstall, $devPackageNames, $devMode, $io);

    if (empty($packagesToReinstall)) {
      $io->write('<info>PatchesLockPlugin: No patch changes detected.</info>');
      // Still update the cached file to ensure hashes are in sync.
      self::copyLockFile($sourceLockFile, $cachedLockFile);
      return;
    }

    $io->write('<info>PatchesLockPlugin: Detected patch changes for ' . count($packagesToReinstall) . ' package(s):</info>');
    foreach ($packagesToReinstall as $packageName) {
      $io->write('  - ' . $packageName);
    }

    // Reinstall the affected packages.
    if (!$this->reinstallPackages($packagesToReinstall, $composer, $io, $devMode)) {
      $io->write('<error>PatchesLockPlugin: Failed to reinstall packages, not updating lock file cache.</error>');
      return;
    }

    // Update the cached lock file after successful reinstallation.
    self::copyLockFile($sourceLockFile, $cachedLockFile);
    $io->write('<info>PatchesLockPlugin: Updated vendor/composer/patches.lock.json</info>');
  }

  /**
   * Reads and parses a patches.lock.json file.
   *
   * @param string $path
   *   Path to the lock file.
   *
   * @return array|null
   *   The parsed data, or NULL on error.
   */
  protected static function readLockFile(string $path): ?array {
    $content = @file_get_contents($path);
    if ($content === FALSE) {
      return NULL;
    }

    $data = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return NULL;
    }

    return $data;
  }

  /**
   * Copies the source lock file to the vendor directory.
   *
   * @param string $sourceLockFile
   *   Path to the source lock file.
   * @param string $cachedLockFile
   *   Path to the cached lock file.
   */
  protected static function copyLockFile(string $sourceLockFile, string $cachedLockFile): void {
    $dir = dirname($cachedLockFile);
    if (!is_dir($dir)) {
      @mkdir($dir, 0755, TRUE);
    }

    copy($sourceLockFile, $cachedLockFile);
  }

  /**
   * Gets all package names that have patches defined.
   *
   * @param array $lockData
   *   The patches.lock.json data.
   *
   * @return array
   *   List of package names with patches.
   */
  protected static function getPatchedPackages(array $lockData): array {
    return array_keys($lockData['patches'] ?? []);
  }

  /**
   * Gets the list of dev-only package names from composer.lock.
   *
   * @param string $baseDir
   *   The project base directory.
   * @param IOInterface $io
   *   The IO interface.
   *
   * @return array
   *   List of dev-only package names.
   */
  protected static function getDevPackageNames(string $baseDir, IOInterface $io): array {
    $lockFile = $baseDir . '/composer.lock';
    if (!file_exists($lockFile)) {
      $io->write('<warning>PatchesLockPlugin: composer.lock not found, cannot determine dev packages.</warning>');
      return [];
    }

    $content = @file_get_contents($lockFile);
    if ($content === FALSE) {
      return [];
    }

    $lockData = json_decode($content, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return [];
    }

    $devPackages = [];
    foreach ($lockData['packages-dev'] ?? [] as $package) {
      if (isset($package['name'])) {
        $devPackages[] = $package['name'];
      }
    }

    return $devPackages;
  }

  /**
   * Filters out dev-only packages when running in --no-dev mode.
   *
   * @param array $packageNames
   *   List of package names.
   * @param array $devPackageNames
   *   List of dev-only package names.
   * @param bool $devMode
   *   Whether dev mode is active.
   * @param IOInterface $io
   *   The IO interface.
   *
   * @return array
   *   Filtered list of package names.
   */
  protected static function filterDevPackages(array $packageNames, array $devPackageNames, bool $devMode, IOInterface $io): array {
    if ($devMode || empty($devPackageNames)) {
      return $packageNames;
    }

    $filtered = [];
    foreach ($packageNames as $packageName) {
      if (in_array($packageName, $devPackageNames, TRUE)) {
        $io->write('<info>PatchesLockPlugin: Skipping dev package ' . $packageName . ' (--no-dev mode)</info>');
        continue;
      }
      $filtered[] = $packageName;
    }

    return $filtered;
  }

  /**
   * Finds packages with changed patches by comparing lock files.
   *
   * @param array $sourceData
   *   The source patches.lock.json data.
   * @param array $cachedData
   *   The cached patches.lock.json data.
   *
   * @return array
   *   List of package names that need reinstallation.
   */
  protected static function findChangedPackages(array $sourceData, array $cachedData): array {
    $changedPackages = [];

    $sourcePatches = $sourceData['patches'] ?? [];
    $cachedPatches = $cachedData['patches'] ?? [];

    // Get all unique package names.
    $allPackages = array_unique(array_merge(
      array_keys($sourcePatches),
      array_keys($cachedPatches)
    ));

    foreach ($allPackages as $packageName) {
      $sourcePatchHashes = self::getPatchHashes($sourcePatches[$packageName] ?? []);
      $cachedPatchHashes = self::getPatchHashes($cachedPatches[$packageName] ?? []);

      // Check if patches have changed (added, removed, or modified).
      if ($sourcePatchHashes !== $cachedPatchHashes) {
        $changedPackages[] = $packageName;
      }
    }

    return $changedPackages;
  }

  /**
   * Extracts a normalized list of patch hashes for a package.
   *
   * @param array $patches
   *   Array of patch definitions.
   *
   * @return array
   *   Sorted array of sha256 hashes.
   */
  protected static function getPatchHashes(array $patches): array {
    $hashes = [];
    foreach ($patches as $patch) {
      if (isset($patch['sha256'])) {
        $hashes[] = $patch['sha256'];
      }
      // Fall back to URL if no hash (for comparison purposes).
      elseif (isset($patch['url'])) {
        $hashes[] = md5($patch['url']);
      }
    }
    sort($hashes);
    return $hashes;
  }

  /**
   * Reinstalls packages to reapply patches.
   *
   * Uses Composer's internal API (like cweagans/composer-patches) for
   * cross-platform compatibility.
   *
   * @param array $packageNames
   *   List of package names to reinstall.
   * @param Composer $composer
   *   The Composer instance.
   * @param IOInterface $io
   *   The IO interface.
   * @param bool $devMode
   *   Whether dev mode is active.
   *
   * @return bool
   *   TRUE if reinstallation was successful, FALSE on failure.
   */
  protected function reinstallPackages(array $packageNames, Composer $composer, IOInterface $io, bool $devMode = TRUE): bool {
    $this->setReinstalling($composer, TRUE);

    try {
      $localRepository = $composer->getRepositoryManager()->getLocalRepository();
      $installationManager = $composer->getInstallationManager();

      // Find the package objects for the given names.
      // Filter out alias packages (e.g., "dev-main as 2.9.3") to avoid reinstall issues.
      $packages = array_filter(
        $localRepository->getPackages(),
        function ($package) use ($packageNames) {
          // Skip alias packages - they wrap real packages and cause issues on reinstall.
          if ($package instanceof AliasPackage) {
            return FALSE;
          }
          return in_array($package->getName(), $packageNames);
        }
      );

      if (empty($packages)) {
        $io->write("<warning>No matching packages found in local repository.</warning>");
        return TRUE;
      }

      // Uninstall packages so they can be re-installed with patches.
      $promises = [];
      foreach ($packages as $package) {
        try {
          $io->write("<info>Removing {$package->getName()} for repatching...</info>");
          $uninstallOperation = new UninstallOperation($package);
          $promises[] = $installationManager->uninstall($localRepository, $uninstallOperation);
        }
        catch (\Exception $e) {
          $io->write("<warning>Could not uninstall {$package->getName()}: {$e->getMessage()}</warning>");
        }
      }

      // Wait for uninstalls to finish (async operations).
      $promises = array_filter($promises);
      if (!empty($promises)) {
        $composer->getLoop()->wait($promises);
      }

      $io->write("<info>Running composer install to reinstall packages...</info>");

      // Trigger a fresh install to reinstall the removed packages with patches.
      // Use ArrayInput to run the install command programmatically.
      $installArgs = [
        'command' => 'install',
        '--no-scripts' => TRUE,
      ];
      if (!$devMode) {
        $installArgs['--no-dev'] = TRUE;
      }
      $input = new ArrayInput($installArgs);

      $application = new Application();
      $application->setAutoExit(FALSE);
      $exitCode = $application->run($input);

      if ($exitCode !== 0) {
        $io->write("<error>Composer install failed with exit code $exitCode</error>");
        return FALSE;
      }

      $io->write("<info>Successfully reinstalled packages for repatching.</info>");
      return TRUE;
    }
    catch (\Exception $e) {
      $io->write("<error>Failed to reinstall packages: {$e->getMessage()}</error>");
      return FALSE;
    }
    finally {
      $this->setReinstalling($composer, FALSE);
    }
  }

}
