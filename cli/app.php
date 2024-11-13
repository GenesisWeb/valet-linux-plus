<?php

use ConsoleComponents\Writer;
use Illuminate\Container\Container;
use Silly\Application;
use Valet\Drivers\ValetDriver;
use Valet\Facades\Configuration;
use Valet\Facades\DevTools;
use Valet\Facades\DnsMasq;
use Valet\Facades\Filesystem;
use Valet\Facades\Mailpit;
use Valet\Facades\Mysql;
use Valet\Facades\Nginx;
use Valet\Facades\Ngrok;
use Valet\Facades\PhpFpm;
use Valet\Facades\Requirements;
use Valet\Facades\Site;
use Valet\Facades\SiteIsolate;
use Valet\Facades\SiteLink;
use Valet\Facades\SiteProxy;
use Valet\Facades\SiteSecure;
use Valet\Facades\Valet;
use Valet\Facades\ValetRedis;

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once __DIR__ . '/../../../autoload.php';
} else {
    require_once getenv('HOME') . '/.composer/vendor/autoload.php';
}

/**
 * Create the application.
 */
Container::setInstance(new Container());
$version = '2.0.1';

$app = new Application('ValetLinux+', $version);

/**
 * Detect environment.
 */
Valet::environmentSetup();
Valet::migrateConfig();

/**
 * Install valet required services
 */
$app->command('install [--ignore-selinux] [--mariadb]', function ($ignoreSELinux, $mariadb) {
    Writer::info('Installing valet services');

    passthru(dirname(__FILE__) . '/scripts/update.sh'); // Clean up cruft
    Requirements::setIgnoreSELinux($ignoreSELinux)->check();
    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::get('domain'));
    Mailpit::install();
    ValetRedis::install();
    Nginx::restart();
    Mysql::install($mariadb);
    Ngrok::install();
    Valet::symlinkToUsersBin();

    Writer::info('Valet installed successfully!');

    $canLinkValetPhp = Writer::confirm('Do you want to link valet\'s php binary?', true);
    if ($canLinkValetPhp) {
        Valet::symlinkPhpToUsersBin();
    }

    if ($canLinkValetPhp) {
        Writer::info('Valet executable php helper is linked to /usr/local/bin/php.');
    }
})->descriptions('Install the Valet services', [
    '--ignore-selinux' => 'Skip SELinux checks',
]);

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Prune missing directories and symbolic links on every command.
     */
    Configuration::prune();
    Site::pruneLinks();

    /**
     * Start the daemon services.
     */
    $app->command('start [services]*', function ($services) {
        if (empty($services)) {
            DnsMasq::restart();
            PhpFpm::restart();
            Nginx::restart();
            Mailpit::restart();
            Mysql::restart();
            ValetRedis::restart();
            Writer::info('Valet services have been started.');

            return;
        }
        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::restart();
                    break;

                case 'php':
                    PhpFpm::restart();
                    break;

                case 'mailpit':
                    Mailpit::restart();
                    break;

                case 'dnsmasq':
                    DnsMasq::restart();
                    break;

                case 'mysql':
                    Mysql::restart();
                    break;

                case 'redis':
                    ValetRedis::restart();
                    break;
                default:
                    break;
            }
        }

        Writer::info('Specified Valet services have been started.');
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [services]*', function ($services) {
        if (empty($services)) {
            DnsMasq::restart();
            PhpFpm::restart();
            Nginx::restart();
            Mailpit::restart();
            Mysql::restart();
            ValetRedis::restart();
            Writer::info('Valet services have been restarted.');

            return;
        }

        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::restart();
                    break;

                case 'php':
                    PhpFpm::restart();
                    break;

                case 'mailpit':
                    Mailpit::restart();
                    break;

                case 'dnsmasq':
                    DnsMasq::restart();
                    break;

                case 'mysql':
                    Mysql::restart();
                    break;

                case 'redis':
                    ValetRedis::restart();
                    break;
                default:
                    break;
            }
        }

        Writer::info('Specified Valet services have been restarted.');
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [services]*', function ($services) {
        if (empty($services)) {
            PhpFpm::stop();
            Nginx::stop();
            Mailpit::stop();
            Mysql::stop();
            ValetRedis::stop();
            Writer::info('Valet services have been stopped.');

            return;
        }

        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::stop();
                    break;

                case 'php':
                    PhpFpm::stop();
                    break;

                case 'mailpit':
                    Mailpit::stop();
                    break;

                case 'mysql':
                    Mysql::stop();
                    break;

                case 'redis':
                    ValetRedis::stop();
                    break;
                default:
                    break;
            }
        }

        Writer::info('Specified Valet services have been stopped.');
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely.
     */
    $app->command('uninstall', function () {
        Nginx::uninstall();
        PhpFpm::uninstall();
        DnsMasq::uninstall();
        Mailpit::uninstall();
        Configuration::uninstall();
        Valet::uninstall();

        Writer::info('Valet has been uninstalled.');
    })->descriptions('Uninstall the Valet services');

    /**
     * Remove the current working directory to paths configuration.
     */
    $app->command('status', function () {
        PhpFpm::status();
        Nginx::status();
    })->descriptions('View Valet service status');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('is-latest', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            Writer::info('YES');
        } else {
            Writer::info('NO');
        }
    })->descriptions('Determine if this is the latest version of Valet');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('update', function () use ($version) {
        $script = dirname(__FILE__) . '/scripts/update.sh';

        if (Valet::onLatestVersion($version)) {
            Writer::info('You have the latest version of Valet Linux+');
            passthru($script);
        } else {
            Writer::warn('There is a new release of Valet Linux+');
            Writer::warn('Updating now...');
            $latestVersion = Valet::getLatestVersion();
            if ($latestVersion) {
                passthru($script . " update $latestVersion");
            } else {
                passthru($script . ' update');
            }
        }
    })->descriptions('Update Valet Linux+ and clean up cruft');

    /**
     * Get or set the domain currently being used by Valet.
     */
    $app->command('domain [domain]', function ($domain = null) {
        if ($domain === null) {
            Writer::info(sprintf('Your current Valet domain is [%s].', Configuration::get('domain')));

            return;
        }

        DnsMasq::updateDomain($domain = trim($domain, '.'));
        $oldDomain = Configuration::get('domain');

        Configuration::set('domain', $domain);
        SiteSecure::reSecureForNewDomain($oldDomain, $domain);
        PhpFpm::restart();
        Nginx::restart();

        Writer::info('Your Valet domain has been updated to [' . $domain . '].');
    })->descriptions('Get or set the domain used for Valet sites');

    /**
     * Get or set the port number currently being used by Valet.
     */
    $app->command('port [port] [--https]', function ($port, $https) {
        if ($port === null) {
            Writer::info('Current Nginx port (HTTP): ' . Configuration::get('port', 80));
            Writer::info('Current Nginx port (HTTPS): ' . Configuration::get('https_port', 443));

            return;
        }

        $port = trim($port);

        if ($https) {
            Configuration::set('https_port', $port);
        } else {
            Nginx::updatePort($port);
            Configuration::set('port', $port);
        }

        SiteSecure::regenerateSecuredSitesConfig();

        Nginx::restart();
        PhpFpm::restart();

        $protocol = $https ? 'HTTPS' : 'HTTP';
        Writer::info("Your Nginx $protocol port has been updated to [$port].");
    })->descriptions('Get or set the port number used for Valet sites');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            Writer::info('This site is served by [' . get_class($driver) . '].');
        } else {
            Writer::warn('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Add the current working directory to paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        $path = $path ?: getcwd();
        Configuration::addPath($path);

        Writer::info("The [$path] directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Display all the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::get('paths');

        if (count($paths) > 0) {
            $paths = array_map(function ($path) {
                return [$path];
            }, $paths);

            Writer::table(['Path'], $paths);
        } else {
            Writer::warn('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Remove the current working directory from paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        $path = $path ?: getcwd();
        Configuration::removePath($path);

        Writer::info("The [$path] directory has been removed from Valet's paths.");
    })->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Create Nginx proxy config for the specified domain.
     */
    $app->command('proxy [domain] [host] [--secure]', function ($domain, $host, $secure) {
        if ($domain === null) {
            Writer::error('Please provide domain');
            return;
        }

        if ($host === null) {
            Writer::error('Please provide host');
            return;
        }

        if (!preg_match('~^https?://.*$~', $host)) {
            Writer::error(sprintf('"%s" is not a valid URL', $host));
            return;
        }

        $tld = Configuration::get('domain');

        if (!str_ends_with($domain, '.' . $tld)) {
            $domain .= '.' . $tld;
        }

        SiteProxy::proxyCreate($domain, $host, $secure);
        Nginx::restart();

        $protocol = $secure ? 'https' : 'http';

        Writer::info('Valet will now proxy [' . $protocol . '://' . $domain . '] traffic to [' . $host . '].');
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, node etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete Nginx proxy config.
     */
    $app->command('unproxy [domain]', function ($domain) {
        if ($domain === null) {
            Writer::error('Please provide domain');
            return;
        }

        $tld = Configuration::get('domain');
        if (!str_ends_with($domain, '.' . $tld)) {
            $domain .= '.' . $tld;
        }

        SiteSecure::unsecure($domain);
        Nginx::restart();

        Writer::info('Valet will no longer proxy [' . $domain . '].');
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all the sites that are proxies.
     */
    $app->command('proxies', function () {
        $proxies = SiteProxy::proxies();

        Writer::table(['URL', 'SSL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name]', function ($name) {
        $name = $name ?: basename(getcwd());
        $linkPath = SiteLink::link(getcwd(), $name);

        Writer::info('A [' . $name . '] symbolic link has been created in [' . $linkPath . '].');
    })->descriptions('Link the current working directory to Valet');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name) {
        $name = $name ?: basename(getcwd());
        SiteLink::unlink($name);

        Writer::info('The [' . $name . '] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Display all the registered symbolic links.
     */
    $app->command('links', function () {
        $links = SiteLink::links();

        Writer::table(['URL', 'SSL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: basename(getcwd()));
        $url = Configuration::parseDomain($url);

        SiteSecure::secure($url);
        Nginx::restart();

        Writer::info('The [' . $url . '] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain]', function ($domain = null) {
        $url = ($domain ?: basename(getcwd()));
        $url = Configuration::parseDomain($url);

        SiteSecure::unsecure($url, true);
        Nginx::restart();

        Writer::info('The [' . $url . '] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Determine if the site is secured or not.
     */
    $app->command('secured [site]', function ($site) {
        $site = $site ?: basename(getcwd());
        $site = Configuration::parseDomain($site);

        if (SiteSecure::secured()->contains($site)) {
            Writer::info("$site is secured.");
            return;
        }

        Writer::info("$site is not secured.");
    })->descriptions('Determine if the site is secured or not');

    /**
     * Change the PHP version to the desired one.
     */
    $app->command('use [preferredVersion] [--update-cli] [--ignore-ext]', function (
        $preferredVersion = null,
        $updateCli = null,
        $ignoreExt = null
    ) {
        $preferredVersion = PhpFpm::normalizePhpVersion($preferredVersion);
        $isValid = PhpFpm::validateVersion($preferredVersion);
        if (!$isValid) {
            Writer::error(
                sprintf(
                    "Invalid version [%s] used. Supported versions are: %s",
                    $preferredVersion,
                    implode(', ', \Valet\PhpFpm::SUPPORTED_PHP_VERSIONS)
                )
            );
            Writer::info(
                sprintf(
                    'You can still use any version from [%s] list using `valet isolate` command',
                    implode(', ', \Valet\PhpFpm::ISOLATION_SUPPORTED_PHP_VERSIONS)
                )
            );
            return;
        }

        PhpFpm::switchVersion($preferredVersion, $updateCli, $ignoreExt);
        Writer::info(sprintf('PHP version successfully changed to [%s]', $preferredVersion));
    })->descriptions(
        sprintf(
            'Set the PHP version to use, enter "default" or leave empty to use version: %s',
            PHP_VERSION
        ),
        [
            '--update-cli'    => 'Updates CLI version as well',
            '--ignore-ext'    => 'Installs extension with selected php version',
        ]
    );

    /**
     * List MySQL Database.
     */
    $app->command('db:list', function () {
        $databases = Mysql::getDatabases();

        Writer::table(['Database'], $databases);
    })->descriptions('List all available database in MySQL/MariaDB');

    /**
     * Create new database in MySQL.
     */
    $app->command('db:create [databaseName]', function ($databaseName) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        $isCreated = Mysql::createDatabase($databaseName);
        if ($isCreated) {
            Writer::info(sprintf('Database [%s] created successfully', $databaseName));
        }
    })->descriptions('Create new database in MySQL/MariaDB');

    /**
     * Drop database in MySQL.
     */
    $app->command('db:drop [databaseName] [-y|--yes]', function ($databaseName, $yes) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Are you sure you want to delete [%s] database?', $databaseName));
            if (!$confirm) {
                Writer::warn('Aborted');

                return;
            }
        }
        $isDropped = Mysql::dropDatabase($databaseName);
        if ($isDropped) {
            Writer::info(sprintf('Database [%s] dropped successfully', $databaseName));
        }
    })->descriptions('Drop given database from MySQL/MariaDB');

    /**
     * Reset database in MySQL.
     */
    $app->command('db:reset [databaseName] [-y|--yes]', function ($databaseName, $yes) {
        $databaseName = $databaseName ?: basename((string)getcwd());

        if (!$yes) {
            $confirm = Writer::confirm(sprintf('Are you sure you want to reset [%s] database?', $databaseName));
            if (!$confirm) {
                Writer::warn('Aborted');

                return;
            }
        }
        $dropDB = Mysql::dropDatabase($databaseName);
        if (!$dropDB) {
            Writer::warn('Error resetting database');

            return;
        }

        $isCreated = Mysql::createDatabase($databaseName);

        if (!$isCreated) {
            Writer::warn('Error resetting database');

            return;
        }

        Writer::info(sprintf('Database [%s] reset successfully', $databaseName));
    })->descriptions('Clear all tables for given database in MySQL/MariaDB');

    /**
     * Import database in MySQL.
     *
     * @throws Exception
     */
    $app->command('db:import [databaseName] [dumpFile]', function ($databaseName, $dumpFile) {
        if (!$databaseName) {
            Writer::error('Please provide database name');
            return;
        }
        if (!$dumpFile) {
            Writer::error('Please provide a dump file path');
            return;
        }

        if (!Filesystem::exists($dumpFile)) {
            Writer::error(sprintf('Unable to locate [%s]', $dumpFile));
            return;
        }
        Writer::info('Importing database...');

        Mysql::importDatabase($dumpFile, $databaseName);

        Writer::info(sprintf('Database [%s] imported successfully', $databaseName));
    })->descriptions('Import dump file for selected database in MySQL/MariaDB');

    /**
     * Export database in MySQL.
     */
    $app->command('db:export [databaseName] [--sql]', function ($databaseName, $sql) {
        Writer::info('Exporting database...');
        $databaseName = $databaseName ?: basename((string)getcwd());

        $data = Mysql::exportDatabase($databaseName, $sql);

        Writer::info(sprintf("Database [%s] exported into file %s", $data['database'], $data['filename']));
    })->descriptions('Export selected MySQL/MariaDB database');

    /**
     * Configure valet database user for MySQL/MariaDB.
     */
    $app->command('db:configure [--force]', function ($force) {
        Mysql::configure($force);
    })->descriptions('Configure valet database user for MySQL/MariaDB');

    /**
     * Visual Studio Code IDE Helper Command.
     */
    $app->command('code [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::VS_CODE);
    })->descriptions('Open project in Visual Studio Code');

    /**
     * PHPStorm IDE Helper Command.
     */
    $app->command('ps [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::PHP_STORM);
    })->descriptions('Open project in PHPStorm');

    /**
     * Atom IDE Helper Command.
     */
    $app->command('atom [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::ATOM);
    })->descriptions('Open project in Atom');

    /**
     * Sublime IDE Helper Command.
     */
    $app->command('subl [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::SUBLIME);
    })->descriptions('Open project in Sublime');

    /**
     * Allow the user to change the version of PHP Valet uses to serve the current site.
     */
    $app->command('isolate [phpVersion] [--site=] [--secure]', function ($phpVersion, $site, $secure) {
        if (!$site) {
            $site = basename((string)getcwd());
        }

        if ($phpVersion === null && $phpVersion = Site::phpRcVersion($site)) {
            Writer::info("Found '$site/.valetphprc' specifying version: $phpVersion");
        }

        if ($phpVersion === null) {
            Writer::warn('Please select version to isolate');
            return;
        }

        $isSuccess = SiteIsolate::isolateDirectory($site, $phpVersion, $secure);

        if ($isSuccess) {
            Writer::info(sprintf('The site [%s] is now using %s.', $site, $phpVersion));
        }
    })->descriptions('Change the version of PHP used by Valet to serve the current working directory', [
        'phpVersion' => 'The PHP version you want to use; e.g php@8.1',
        '--site'       => 'Specify the site to isolate (e.g. if the site isn\'t linked as its directory name)',
        '--secure'   => 'Create a isolated site with a trusted TLS certificate',
    ]);

    /**
     * Allow the user to un-do specifying the version of PHP Valet uses to serve the current site.
     */
    $app->command('unisolate [--site=]', function ($site = null) {
        if (!$site) {
            $site = basename((string)getcwd());
        }

        SiteIsolate::unIsolateDirectory($site);

        Writer::info(sprintf('The site [%s] is now using the default PHP version.', $site));
    })->descriptions('Stop customizing the version of PHP used by Valet to serve the current working directory', [
        '--site' => 'Specify the site to un-isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * List isolated sites.
     */
    $app->command('isolated', function () {
        $sites = SiteIsolate::isolatedDirectories();

        Writer::table(['URL', 'SSL', 'PHP Version'], $sites->all());
    })->descriptions('List all sites using isolated versions of PHP.');

    /**
     * Get the PHP executable path for a site.
     */
    $app->command('which-php [site]', function ($site) {
        $site = basename($site ?: (string)getcwd());
        $domain = Configuration::parseDomain($site);
        $phpVersion = SiteIsolate::isolatedPhpVersion($domain);

        if (!$phpVersion) {
            $phpVersion = Site::phpRcVersion($site ?: basename(getcwd()));
        }

        echo PhpFpm::getPhpExecutablePath($phpVersion);
    })->descriptions('Get the PHP executable path for a given site', [
        'site' => 'The site to get the PHP executable path for',
    ]);

    /**
     * Proxy commands through to an isolated site's version of PHP.
     */
    $app->command('php [--site=] [command]', function () {
        Writer::warn(
            'It looks like you are running `cli/valet.php` directly;
            please use the `valet` script in the project root instead.'
        );
    })->descriptions("Proxy PHP commands with isolated site's PHP executable", [
        'command' => "Command to run with isolated site's PHP executable",
        '--site'  => 'Specify the site to use to get the PHP version',
    ]);

    /**
     * Proxy commands through to an isolated site's version of Composer.
     */
    $app->command('composer [--site=] [command]', function () {
        Writer::warn('It looks like you are running `cli/valet.php` directly;
        please use the `valet` script in the project root instead.');
    })->descriptions("Proxy Composer commands with isolated site's PHP executable", [
        'command' => "Composer command to run with isolated site's PHP executable",
        '--site'  => 'Specify the site to use to get the PHP version',
    ]);

    /**
     * Open the current directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = sprintf(
            'http://%s.%s/',
            $domain ?: basename(getcwd()),
            Configuration::get('domain')
        );

        passthru('xdg-open ' . escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        Writer::warn(
            'It looks like you are running `cli/valet.php` directly,
            please use the `valet` script in the project root instead.'
        );
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url', function () {
        echo Ngrok::currentTunnelUrl();
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Set authentication token in Ngrok.
     */
    $app->command('ngrok-auth [authtoken]', function ($authtoken) {
        if (!$authtoken) {
            Writer::error('Please provide ngrok auth token');
            return;
        }

        Ngrok::setAuthToken($authtoken);

        Writer::info('Ngrok authentication token set.');
    })->descriptions('Set authentication token for ngrok');
}

/**
 * Load all Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include_once $extension;
}
