<?php

namespace Valet;

use ConsoleComponents\Writer;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\DevTools as DevToolsFacade;

class PhpFpm
{
    protected Configuration $config;
    protected PackageManager $pm;
    protected ServiceManager $sm;
    protected CommandLine $cli;
    protected Filesystem $files;
    protected Site $site;
    protected Nginx $nginx;

    public const SUPPORTED_PHP_VERSIONS = [
        '8.2', '8.3', '8.4',
    ];

    public const ISOLATION_SUPPORTED_PHP_VERSIONS = [
        '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', ...self::SUPPORTED_PHP_VERSIONS
    ];

    public const COMMON_EXTENSIONS = [
        'cli', 'mysql', 'gd', 'zip', 'xml', 'curl', 'mbstring', 'pgsql', 'intl', 'posix',
    ];

    public const FPM_CONFIG_FILE_NAME = 'valet.conf';

    /**
     * Create a new PHP FPM class instance.
     */
    public function __construct(
        Configuration $config,
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Site $site,
        Nginx $nginx
    ) {
        $this->config = $config;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->cli = $cli;
        $this->files = $files;
        $this->site = $site;
        $this->nginx = $nginx;
    }

    /**
     * Install and configure PHP FPM.
     */
    public function install(?string $version = null, bool $installExt = true): void
    {
        $version = $version ?: $this->getCurrentVersion();
        $version = $this->normalizePhpVersion($version);
        if ($version === '') {
            return;
        }

        $packageName = $this->pm->getPhpFpmName($version);
        if (!$this->pm->installed($packageName)) {
            $this->pm->ensureInstalled($packageName);
            if ($installExt) {
                $this->installExtensions($version);
            }
            $this->sm->enable($this->serviceName($version));
        }

        $this->files->ensureDirExists('/var/log', user());

        $this->installConfiguration($version);

        $this->restart($version);
    }

    /**
     * Uninstall PHP FPM valet config.
     */
    public function uninstall(?string $version = null): void
    {
        $version = $version ?: $this->getCurrentVersion();
        $version = $this->normalizePhpVersion($version);
        if ($version === '') {
            return;
        }

        $fpmConfPath = $this->fpmConfigPath($version) . '/' . self::FPM_CONFIG_FILE_NAME;
        if ($this->files->exists($fpmConfPath)) {
            $this->files->unlink($fpmConfPath);
            $this->stop($version);
        }
    }

    /**
     * Change the php-fpm version.
     */
    public function switchVersion(
        string $version,
        bool $updateCli = false,
        bool $ignoreExt = false
    ): void {
        $currentVersion = $this->getCurrentVersion();
        // Validate if in use
        $version = $this->normalizePhpVersion($version);

        Writer::info('Changing php version...');

        $this->install($version, !$ignoreExt);

        if ($this->sm->disabled($this->serviceName($version))) {
            $this->sm->enable($this->serviceName($version));
        }

        $this->config->set('php_version', $version);

        $this->stopIfUnused($currentVersion);

        $this->updateNginxConfigFiles($version);
        $this->nginx->restart();
        $this->status($version);
        if ($updateCli) {
            $this->cli->run("update-alternatives --set php /usr/bin/php$version");
        }
    }

    /**
     * Restart the PHP FPM process.
     * @param null|mixed $version
     */
    public function restart($version = null): void
    {
        $this->sm->restart($this->serviceName($version));
    }

    /**
     * Stop the PHP FPM process.
     * @param null|mixed $version
     */
    public function stop($version = null): void
    {
        $this->sm->stop($this->serviceName($version));
    }

    /**
     * PHP-FPM service status.
     * @param null|mixed $version
     */
    public function status($version = null): void
    {
        $this->sm->printStatus($this->serviceName($version));
    }

    /**
     * Get FPM socket file name for a given PHP version.
     */
    public function socketFileName(string $version = null): string
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }
        $version = preg_replace('~[^\d]~', '', $version);

        return "valet{$version}.sock";
    }

    /**
     * Normalize inputs (php-x.x, php@x.x, phpx.x, phpxx) to version (x.x).
     */
    public function normalizePhpVersion(string $version): string
    {
        preg_match(
            '/^(?:php[@-]?)?(?<MAJOR_VERSION>[\d]{1}).?(?<MINOR_VERSION>[\d]{1})$/i',
            $version,
            $matches
        );

        if (!isset($matches['MAJOR_VERSION'], $matches['MINOR_VERSION'])) {
            return '';
        }

        return \sprintf('%s.%s', $matches['MAJOR_VERSION'], $matches['MINOR_VERSION']);
    }

    /**
     * Get installed PHP version.
     */
    public function getCurrentVersion(): string
    {
        return $this->config->get('php_version', $this->getDefaultVersion());
    }

    /**
     * Get executable php path.
     * @return false|string
     */
    public function getPhpExecutablePath(string $version = null)
    {
        if (!$version) {
            return DevToolsFacade::getBin('php', ['/usr/local/bin/php']);
        }

        $version = $this->normalizePhpVersion($version);

        return DevToolsFacade::getBin('php' . $version, ['/usr/local/bin/php']);
    }

    public function fpmSocketFile(string $version): string
    {
        return VALET_HOME_PATH . '/' . $this->socketFileName($version);
    }

    public function updateHomePath(string $oldHomePath, string $newHomePath): void
    {
        foreach (self::ISOLATION_SUPPORTED_PHP_VERSIONS as $version) {
            try {
                $confPath = $this->fpmConfigPath($version) . '/' . self::FPM_CONFIG_FILE_NAME;
                if ($this->files->exists($confPath)) {
                    $valetConf = $this->files->get($confPath);
                    $valetConf = str_replace($oldHomePath, $newHomePath, $valetConf);
                    $this->files->put($confPath, $valetConf);
                }
            } catch (\DomainException $domainException) {
            }
        }
    }

    /**
     * Validate PHP version.
     */
    public function validateVersion(string $version): bool
    {
        if (!in_array($version, self::SUPPORTED_PHP_VERSIONS)) {
            return false;
        }

        return true;
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     */
    public function stopIfUnused(string $version): void
    {
        $version = $this->normalizePhpVersion($version);

        if (!in_array($version, $this->utilizedPhpVersions())) {
            $this->stop($version);
        }
    }

    /**
     * Determine php service name.
     */
    private function serviceName(string $version = null): string
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }

        return $this->pm->getPhpFpmName($version);
    }

    private function updateNginxConfigFiles(string $version): void
    {
        //Action 1: Update all separate secured versions
        $this->nginx->configuredSites()->map(function ($file) use ($version) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);
            if (!$content) {
                return;
            }
            if (strpos($content, '# ' . ISOLATED_PHP_VERSION) !== false) {
                return;
            }
            preg_match_all('/unix:(.*?.sock)/m', $content, $matchCount);
            if (!count($matchCount)) {
                return;
            }
            $content = preg_replace(
                '/unix:(.*?.sock)/m',
                'unix:' . VALET_HOME_PATH . '/' . $this->socketFileName($version),
                $content
            );
            $this->files->put(VALET_HOME_PATH . '/Nginx/' . $file, $content);
        });

        //Action 2: Update NGINX valet.conf for php socket version.
        $this->nginx->installServer($version);
    }

    private function installExtensions(string $version): void
    {
        $extArray = [];
        $extensionPrefix = $this->pm->getPhpExtensionPrefix($version);
        foreach (self::COMMON_EXTENSIONS as $ext) {
            $extArray[] = "{$extensionPrefix}{$ext}";
        }
        $this->pm->ensureInstalled(implode(' ', $extArray));
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     */
    private function installConfiguration(string $version): void
    {
        $contents = $this->files->get(VALET_ROOT_PATH . '/cli/stubs/fpm.conf');
        $contents = strArrayReplace([
            'VALET_USER'            => user(),
            'VALET_GROUP'           => group(),
            'VALET_FPM_SOCKET_FILE' => $this->fpmSocketFile($version),
        ], $contents);

        $this->files->putAsUser($this->fpmConfigPath($version) . '/' . self::FPM_CONFIG_FILE_NAME, $contents);
    }

    /**
     * Get a list including the global PHP version and all PHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     * @return array<int, string>
     */
    private function utilizedPhpVersions(): array
    {
        /** @var array<int, string> $fpmSockFiles */
        $fpmSockFiles = collect(self::ISOLATION_SUPPORTED_PHP_VERSIONS)->map(function ($version) {
            return $this->socketFileName($this->normalizePhpVersion($version));
        })->unique();

        $versions = $this->nginx->configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);

            // Get the normalized PHP version for this config file, if it's defined
            foreach ($fpmSockFiles as $sock) {
                if (strpos($content, $sock) !== false) {
                    // Extract the PHP version number from a custom .sock path and normalize it, e.g. "7.4"
                    return $this->normalizePhpVersion(str_replace(['valet', '.sock'], '', $sock));
                }
            }
        })->filter()->unique()->values()->toArray();

        // Adding Default version in utilized versions list.
        if (!in_array($currentVersion = $this->getCurrentVersion(), $versions)) {
            $versions[] = $currentVersion;
        }

        /** @var array<int, string> $versions */
        return $versions;
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     * @throws \DomainException
     */
    private function fpmConfigPath(string $version = null): string
    {
        $version = $version ?: $this->getCurrentVersion();
        $versionWithoutDot = preg_replace('~[^\d]~', '', $version);

        /** @var string $confDir */
        return collect([
            '/etc/php/' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/php-fpm.d', // Manjaro
            '/etc/php' . $versionWithoutDot . '/php-fpm.d', // ArchLinux
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
        ])->first(function ($path) {
            return $this->files->isDir($path);
        }, function () {
            throw new \DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }

    /**
     * Validate PHP version for isolation process.
     */
    private function validateIsolationVersion(string $version): void
    {
        if (!in_array($version, self::ISOLATION_SUPPORTED_PHP_VERSIONS)) {
            throw new \DomainException(
                \sprintf(
                    "Invalid version [%s] used. Supported versions are: %s",
                    $version,
                    implode(', ', self::ISOLATION_SUPPORTED_PHP_VERSIONS)
                )
            );
        }
    }

    /**
     * Get installed PHP version.
     */
    private function getDefaultVersion(): string
    {
        return $this->normalizePhpVersion(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }
}
