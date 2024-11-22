<?php

namespace Valet\Tests;

use ConsoleComponents\Writer;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\Configuration;
use Valet\DnsMasq;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\Nginx;
use Valet\Ngrok;
use Valet\PhpFpm;
use Valet\Site;
use Valet\SiteIsolate;
use Valet\SiteLink;
use Valet\SiteProxy;
use Valet\SiteSecure;

use function Valet\swap;

class CliTest extends TestCase
{
    /**
     * @test
     */
    public function itWillReadDomainFromConfig(): void
    {
        Writer::fake();
        $this->tester->run(['command' => 'domain']);

        $this->tester->assertCommandIsSuccessful();
        $domain = ConfigurationFacade::get('domain');

        $this->assertSame('test', $domain);

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Your current Valet domain is [test]', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillSetDomainInConfig(): void
    {
        Writer::fake();
        $dnsmasq = Mockery::mock(DnsMasq::class);
        $dnsmasq->shouldReceive('updateDomain')->once()->with('localhost');
        swap(DnsMasq::class, $dnsmasq);

        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('get')->with('domain')->andReturn('test')->once();
        $config->shouldReceive('set')->with('domain', 'localhost')->once();
        swap(Configuration::class, $config);

        $siteSecure = Mockery::mock(SiteSecure::class);
        $siteSecure->shouldReceive('reSecureForNewDomain')->with('test', 'localhost')->once();
        swap(SiteSecure::class, $siteSecure);

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('restart')->withNoArgs()->once();
        swap(PhpFpm::class, $phpFpm);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'domain', 'domain' => 'localhost']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Your Valet domain has been updated to [localhost]', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillReadNginxPortFromConfig(): void
    {
        Writer::fake();

        $this->tester->run(['command' => 'port']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString('Current Nginx port (HTTP): 80', $content);
        $this->assertStringContainsString('Current Nginx port (HTTPS): 443', $content);
    }

    public function nginxPortDataProvider(): array
    {
        return [
            [8443, true, 'https_port', 'Your Nginx HTTPS port has been updated to [8443]'],
            [88, false, 'port', 'Your Nginx HTTP port has been updated to [88]'],
        ];
    }

    /**
     * @test
     * @dataProvider nginxPortDataProvider
     */
    public function itWillUpdateNginxPortSuccessfully(
        int $port,
        bool $isHttps,
        string $updateKey,
        string $expectedOutput
    ): void {
        Writer::fake();

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();

        if ($isHttps === false) {
            $nginx->shouldReceive('updatePort')->with($port)->once();
        }

        swap(Nginx::class, $nginx);

        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('set')->with($updateKey, $port)->once();
        swap(Configuration::class, $config);

        $siteSecure = Mockery::mock(SiteSecure::class);
        $siteSecure->shouldReceive('regenerateSecuredSitesConfig')->withNoArgs()->once();
        swap(SiteSecure::class, $siteSecure);

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('restart')->withNoArgs()->once();
        swap(PhpFpm::class, $phpFpm);

        $this->tester->run(['command' => 'port', 'port' => $port, '--https' => $isHttps]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString($expectedOutput, $output->fetch());
    }

    /**
     * @test
     */
    public function itWillValidateWhichCommand(): void
    {
        Writer::fake();

        $this->tester->run(['command' => 'which']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('This site is served by [Valet\Drivers\BasicValetDriver]', $output->fetch());
    }

    public function parkedDirectoryProvider(): array
    {
        return [
            ['/test/directory', '/test/directory'],
            [null, getcwd()],
        ];
    }

    /**
     * @test
     * @dataProvider parkedDirectoryProvider
     */
    public function itWillParkDirectoryToConfig(?string $directory, string $expectedDirectory): void
    {
        Writer::fake();

        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('addPath')->with($expectedDirectory)->once();
        swap(Configuration::class, $config);

        $this->tester->run(['command' => 'park', 'path' => $directory]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            \sprintf('The [%s] directory has been added to Valet\'s paths.', $expectedDirectory),
            $output->fetch()
        );
    }

    public function directoryProvider(): array
    {
        return [
            [null, 'No paths have been registered.'],
            ['/test/directory', '/test/directory'],
        ];
    }

    /**
     * @test
     * @dataProvider directoryProvider
     */
    public function itWillReadParkedDirectories(?string $path, string $expectedMessage): void
    {
        Writer::fake();

        if ($path !== null) {
            ConfigurationFacade::addPath($path);
        }

        $this->tester->run(['command' => 'paths']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    /**
     * @test
     * @dataProvider parkedDirectoryProvider
     */
    public function itWillForgetParkedDirectory(?string $directory, string $expectedDirectory): void
    {
        Writer::fake();

        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('removePath')->with($expectedDirectory)->once();
        swap(Configuration::class, $config);

        $this->tester->run(['command' => 'forget', 'path' => $directory]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            \sprintf('The [%s] directory has been removed from Valet\'s paths.', $expectedDirectory),
            $output->fetch()
        );
    }

    public function nginxProxyDataProvider(): array
    {
        return [
            [
                'mails',
                'http://localhost:8045',
                true,
                'mails.test',
                'Valet will now proxy [https://mails.test] traffic to [http://localhost:8045]'
            ],
            [
                'withtld.test',
                'http://localhost:8045',
                true,
                'withtld.test',
                'Valet will now proxy [https://withtld.test] traffic to [http://localhost:8045]'
            ],
            [
                'mails',
                'http://localhost:8045',
                false,
                'mails.test',
                'Valet will now proxy [http://mails.test] traffic to [http://localhost:8045]'
            ]
        ];
    }

    /**
     * @test
     * @dataProvider nginxProxyDataProvider
     */
    public function itWillCreateNginxProxy(
        string $domain,
        string $host,
        bool $isSecure,
        string $expectedDomain,
        string $expectedMessage
    ): void {
        Writer::fake();

        $siteProxy = Mockery::mock(SiteProxy::class);
        $siteProxy->shouldReceive('proxyCreate')->with($expectedDomain, $host, $isSecure)->once();
        swap(SiteProxy::class, $siteProxy);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'proxy', 'domain' => $domain, 'host' => $host, '--secure' => $isSecure]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    public function invalidProxyDataProvider(): array
    {
        return [
            [
                [
                    'domain' => null,
                ],
                'Please provide domain'
            ],
            [
                [
                    'host' => null,
                ],
                'Please provide host'
            ],
            [
                [
                    'host' => 'invalid-url',
                ],
                '"invalid-url" is not a valid URL'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider invalidProxyDataProvider
     */
    public function itWillFailToProxyDomainWhenValidParametersNotAvailable(
        array $overrides,
        string $expectedMessage
    ): void {
        Writer::fake();

        $domain = array_key_exists('domain', $overrides) ? $overrides['domain'] : 'mails';
        $host = array_key_exists('host', $overrides) ? $overrides['host'] : 'http://127.0.0.1:8025';

        $site = Mockery::mock(Site::class);
        $site->shouldReceive('proxyDelete')->never();
        swap(Site::class, $site);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->never();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'proxy', 'domain' => $domain, 'host' => $host]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    public function nginxUnproxyDataProvider(): array
    {
        return [
            [
                'mails',
                'mails.test',
                'Valet will no longer proxy [mails.test]'
            ],
            [
                'withtld.test',
                'withtld.test',
                'Valet will no longer proxy [withtld.test]'
            ]
        ];
    }

    /**
     * @test
     * @dataProvider nginxUnproxyDataProvider
     */
    public function itWillRemoveNginxProxy(string $domain, string $expectedDomain, string $expectedMessage): void
    {
        Writer::fake();

        $siteSecure = Mockery::mock(SiteSecure::class);
        $siteSecure->shouldReceive('unsecure')->with($expectedDomain)->once();
        swap(SiteSecure::class, $siteSecure);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'unproxy', 'domain' => $domain]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    public function invalidUnproxyDataProvider(): array
    {
        return [
            [
                [
                    'domain' => null,
                ],
                'Please provide domain'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider invalidUnproxyDataProvider
     */
    public function itWillFailToUnproxyDomainWhenValidParametersNotAvailable(
        array $overrides,
        string $expectedMessage
    ): void {
        Writer::fake();

        $domain = array_key_exists('domain', $overrides) ? $overrides['domain'] : 'mails';

        $site = Mockery::mock(Site::class);
        $site->shouldReceive('proxyDelete')->never();
        swap(Site::class, $site);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->never();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'unproxy', 'domain' => $domain]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    /**
     * @test
     */
    public function itWillListProxies(): void
    {
        Writer::fake();

        $siteProxies = Mockery::mock(SiteProxy::class);
        $siteProxies->shouldReceive('proxies')->withNoArgs()->once()->andReturn(collect([
            [
                'url'     => 'https://mails.localhost',
                'secured' => '✓',
                'path'    => 'http://127.0.0.1:8045',
            ],
            [
                'url'     => 'http://docker-host.localhost',
                'secured' => '✕',
                'path'    => 'http://127.0.0.1:8888',
            ],
        ]));
        swap(SiteProxy::class, $siteProxies);

        $this->tester->run(['command' => 'proxies']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'https://mails.localhost      | ✓   | http://127.0.0.1:8045',
            $content
        );
        $this->assertStringContainsString(
            'http://docker-host.localhost | ✕   | http://127.0.0.1:8888',
            $content
        );
    }

    /**
     * @test
     */
    public function itWillLinkCwdToNginx(): void
    {
        Writer::fake();

        $currentDirectory = getcwd();
        $domainName = basename($currentDirectory);

        $siteLink = Mockery::mock(SiteLink::class);
        $siteLink->shouldReceive('link')
            ->with($currentDirectory, $domainName)
            ->once()
            ->andReturn('direct-path');
        swap(SiteLink::class, $siteLink);

        $this->tester->run(['command' => 'link']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'A [' . $domainName . '] symbolic link has been created in [direct-path]',
            $content
        );
    }

    /**
     * @test
     */
    public function itWillUnlinkCwdToNginx(): void
    {
        Writer::fake();

        $domainName = basename(getcwd());

        $siteLink = Mockery::mock(SiteLink::class);
        $siteLink->shouldReceive('unlink')->with($domainName)->once();
        swap(SiteLink::class, $siteLink);

        $this->tester->run(['command' => 'unlink']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'The [' . $domainName . '] symbolic link has been removed',
            $content
        );
    }

    /**
     * @test
     */
    public function itWillListLinks(): void
    {
        Writer::fake();

        $siteLink = Mockery::mock(SiteLink::class);
        $siteLink->shouldReceive('links')->withNoArgs()->once()->andReturn(collect([
            [
                'url'        => 'http://scripts.test',
                'secured'    => '✕',
                'path'       => '/test/path',
            ],
        ]));
        swap(SiteLink::class, $siteLink);

        $this->tester->run(['command' => 'links']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'http://scripts.test | ✕   | /test/path',
            $content
        );
    }

    public function secureNginxDomainProvider(): array
    {
        return [
            [
                [
                    'domain' => 'test-domain',
                ],
                'The [test-domain.test] site has been secured with a fresh TLS certificate',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider secureNginxDomainProvider
     */
    public function itWillSecureNginxDomain(array $overrides, string $expectedMessage): void
    {
        Writer::fake();

        $domain = array_key_exists('domain', $overrides) ? $overrides['domain'] : 'test-domain';

        $siteSecure = Mockery::mock(SiteSecure::class);
        $siteSecure->shouldReceive('secure')->with($domain . '.test')->once();
        swap(SiteSecure::class, $siteSecure);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'secure', 'domain' => $domain]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    public function unsecureNginxDomainProvider(): array
    {
        return [
            [
                [
                    'domain' => 'test-domain',
                ],
                'The [test-domain.test] site will now serve traffic over HTTP',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider unsecureNginxDomainProvider
     */
    public function itWillUnsecureNginxDomain(array $overrides, string $expectedMessage): void
    {
        Writer::fake();

        $domain = array_key_exists('domain', $overrides) ? $overrides['domain'] : 'test-domain';

        $siteSecure = Mockery::mock(SiteSecure::class);
        $siteSecure->shouldReceive('unsecure')->with($domain . '.test', true)->once();
        swap(SiteSecure::class, $siteSecure);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'unsecure', 'domain' => $domain]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    public function securedNginxDomainProvider(): array
    {
        return [
            [
                [
                    'domain' => 'domain-1',
                ],
                'domain-1.test is secured',
            ],
            [
                [
                    'domain' => null,
                ],
                'valet-linux-plus.test is secured',
            ],
            [
                [
                    'domain' => 'unsecure-domain.test',
                ],
                'unsecure-domain.test is not secured',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider securedNginxDomainProvider
     */
    public function itWillListSecuredNginxDomains(array $overrides, string $expectedMessage): void
    {
        Writer::fake();

        $domain = array_key_exists('domain', $overrides) ? $overrides['domain'] : 'test-domain';

        $siteSecured = Mockery::mock(SiteSecure::class);
        $siteSecured->shouldReceive('secured')->withNoArgs()->once()->andReturn(collect([
            'domain-1.test',
            'domain-2.test',
            'valet-linux-plus.test',
        ]));
        swap(SiteSecure::class, $siteSecured);

        $this->tester->run(['command' => 'secured', 'site' => $domain]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            $expectedMessage,
            $output->fetch()
        );
    }

    /**
     * @test
     */
    public function itWillChangePhpVersion(): void
    {
        Writer::fake();

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('normalizePhpVersion')->with('8.2')->andReturn('8.2');
        $phpFpm->shouldReceive('validateVersion')->with('8.2')->andReturnTrue();
        $phpFpm->shouldReceive('switchVersion')->with('8.2', true, false)->once();
        swap(PhpFpm::class, $phpFpm);

        $this->tester->run(['command' => 'use', 'preferredVersion' => '8.2', '--update-cli' => true]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString(
            'PHP version successfully changed to [8.2]',
            $output->fetch()
        );
    }

    /**
     * @test
     */
    public function itWillHandleInvalidPhpVersion(): void
    {
        Writer::fake();

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('normalizePhpVersion')->with('7.2')->andReturn('7.2');
        $phpFpm->shouldReceive('validateVersion')->with('7.2')->andReturnFalse();
        $phpFpm->shouldReceive('switchVersion')->never();
        swap(PhpFpm::class, $phpFpm);

        $this->tester->run(['command' => 'use', 'preferredVersion' => '7.2', '--update-cli' => true]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'Invalid version [7.2] used. Supported versions are: 8.2, 8.3',
            $content
        );
        $this->assertStringContainsString(
            \sprintf(
                'You can still use any version from [%s] list using `valet isolate` command',
                '7.0, 7.1, 7.2, 7.3, 7.4, 8.0, 8.1, 8.2, 8.3, 8.4',
            ),
            $content
        );
    }

    /**
     * @test
     */
    public function itWillListDatabases(): void
    {
        Writer::fake();

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('getDatabases')->withNoArgs()->andReturn([['database1'], ['database2']]);
        swap(Mysql::class, $mysql);

        $this->tester->run(['command' => 'db:list']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'database1',
            $content
        );
        $this->assertStringContainsString(
            'database2',
            $content
        );
    }

    public function databaseNamesProvider(): array
    {
        return [
            [
                'database1',
                'database1',
            ],
            [
                null,
                basename((string)getcwd())
            ]
        ];
    }

    /**
     * @test
     * @dataProvider databaseNamesProvider
     */
    public function itWillCreateDatabase(?string $databaseName, string $expectedDatabaseName): void
    {
        Writer::fake();

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('createDatabase')->with($expectedDatabaseName)->andReturnTrue();
        swap(Mysql::class, $mysql);

        $this->tester->run(['command' => 'db:create', 'databaseName' => $databaseName]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            \sprintf('Database [%s] created successfully', $expectedDatabaseName),
            $content
        );
    }

    /**
     * @test
     * @dataProvider databaseNamesProvider
     */
    public function itWillDropDatabase(?string $databaseName, string $expectedDatabaseName): void
    {
        Writer::fake();

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('dropDatabase')->with($expectedDatabaseName)->andReturnTrue();
        swap(Mysql::class, $mysql);

        $this->tester->run(['command' => 'db:drop', 'databaseName' => $databaseName, '-y' => true]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            \sprintf('Database [%s] dropped successfully', $expectedDatabaseName),
            $content
        );
    }

    /**
     * @test
     * @dataProvider databaseNamesProvider
     */
    public function itWillResetDatabase(?string $databaseName, string $expectedDatabaseName): void
    {
        Writer::fake();

        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('dropDatabase')->with($expectedDatabaseName)->andReturnTrue();
        $mysql->shouldReceive('createDatabase')->with($expectedDatabaseName)->andReturnTrue();
        swap(Mysql::class, $mysql);

        $this->tester->run(['command' => 'db:reset', 'databaseName' => $databaseName, '-y' => true]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            \sprintf('Database [%s] reset successfully', $expectedDatabaseName),
            $content
        );
    }

    /**
     * @test
     */
    public function itWillImportDatabase(): void
    {
        Writer::fake();

        $databaseName = 'database1';
        $dumpFilePath = '/path/to/sql-file';
        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('importDatabase')->with($dumpFilePath, $databaseName);
        swap(Mysql::class, $mysql);

        $fileSystem = Mockery::mock(Filesystem::class);
        $fileSystem->shouldReceive('exists')->with($dumpFilePath)->andReturnTrue();
        swap(Filesystem::class, $fileSystem);

        $this->tester->run(['command' => 'db:import', 'databaseName' => $databaseName, 'dumpFile' => $dumpFilePath]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            'Importing database...',
            $content
        );
        $this->assertStringContainsString(
            \sprintf('Database [%s] imported successfully', $databaseName),
            $content
        );
    }

    /**
     * @test
     */
    public function itWillExportDatabase(): void
    {
        Writer::fake();

        $databaseName = 'database1';
        $mysql = Mockery::mock(Mysql::class);
        $mysql->shouldReceive('exportDatabase')->with($databaseName, true)->andReturn([
            'database' => $databaseName,
            'filename' => 'database1.sql',
        ]);
        swap(Mysql::class, $mysql);

        $this->tester->run(['command' => 'db:export', 'databaseName' => $databaseName, '--sql' => true]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            \sprintf('Database [%s] exported into file database1.sql', $databaseName),
            $content
        );
    }

    public function isolateDirectoryDataProvider(): array
    {
        return [
            [
                '7.4',
                'info.test',
                'info.test',
                true,
            ],
            [
                '7.4',
                null,
                basename((string)getcwd()),
                true,
            ],
        ];
    }

    /**
     * @test
     * @dataProvider isolateDirectoryDataProvider
     */
    public function itWillIsolatePhpVersion(
        string $phpVersion,
        ?string $siteName,
        string $expectedSiteName,
        bool $isSecure
    ): void {
        Writer::fake();

        $siteIsolate = Mockery::mock(SiteIsolate::class);
        $siteIsolate->shouldReceive('isolateDirectory')
            ->with($expectedSiteName, $phpVersion, $isSecure)
            ->once()
            ->andReturnTrue();
        swap(SiteIsolate::class, $siteIsolate);

        $arguments = [
            'phpVersion' => $phpVersion,
            '--secure'   => $isSecure
        ];
        if ($siteName) {
            $arguments['--site'] = $siteName;
        }

        $this->tester->run([
            'command'    => 'isolate',
            ...$arguments
        ]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            sprintf('The site [%s] is now using %s.', $expectedSiteName, $phpVersion),
            $content
        );
    }

    /**
     * @test
     */
    public function itWillFailWhenRequireArgumentIsNotAvailable(): void
    {
        Writer::fake();

        $siteIsolate = Mockery::mock(SiteIsolate::class);
        $siteIsolate->shouldReceive('isolateDirectory')->never();
        swap(SiteIsolate::class, $siteIsolate);

        $this->tester->run(['command' => 'isolate']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            sprintf('Please select version to isolate'),
            $content
        );
    }

    public function unIsolateDirectoryDataProvider(): array
    {
        return [
            [
                'info.test',
                'info.test',
            ],
            [
                null,
                basename((string)getcwd()),
            ],
        ];
    }

    /**
     * @test
     * @dataProvider unIsolateDirectoryDataProvider
     */
    public function itWillUnIsolateDirectory(?string $domainName, string $expectedDomainName): void
    {
        Writer::fake();

        $siteIsolate = Mockery::mock(SiteIsolate::class);
        $siteIsolate->shouldReceive('unIsolateDirectory')->with($expectedDomainName)->once()->andReturnTrue();
        swap(SiteIsolate::class, $siteIsolate);

        $arguments = [];
        if ($domainName) {
            $arguments['--site'] = $domainName;
        }
        $this->tester->run(['command' => 'unisolate', ...$arguments]);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString(
            sprintf('The site [%s] is now using the default PHP version.', $expectedDomainName),
            $content
        );
    }

    /**
     * @test
     */
    public function itWillListIsolatedDirectories(): void
    {
        Writer::fake();

        $siteIsolate = Mockery::mock(SiteIsolate::class);
        $siteIsolate->shouldReceive('isolatedDirectories')
            ->withNoArgs()
            ->andReturn(
                collect([
                    [
                        'url'     => 'fpm-site.test',
                        'secured'     => '✓',
                        'version' => '7.2',
                    ],
                    [
                        'url'     => 'second.test',
                        'secured'     => '✕',
                        'version' => '8.1'
                    ]
                ])
            )->once();
        swap(SiteIsolate::class, $siteIsolate);

        $this->tester->run(['command' => 'isolated']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString('fpm-site.test | ✓   | 7.2', $content);
        $this->assertStringContainsString('second.test   | ✕   | 8.1', $content);
    }

    /**
     * @test
     */
    public function itWillStoreNgrokAuthToken(): void
    {
        Writer::fake();

        $ngrok = Mockery::mock(Ngrok::class);
        $ngrok->shouldReceive('setAuthToken')
            ->with('auth-token')->once();
        swap(Ngrok::class, $ngrok);

        $this->tester->run(['command' => 'ngrok-auth', 'authtoken' => 'auth-token']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString('Ngrok authentication token set', $content);
    }

    /**
     * @test
     */
    public function itWillThrowErrorWhenTokenNotProvided(): void
    {
        Writer::fake();

        $ngrok = Mockery::mock(Ngrok::class);
        $ngrok->shouldReceive('setAuthToken')->never();
        swap(Ngrok::class, $ngrok);

        $this->tester->run(['command' => 'ngrok-auth']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $content = $output->fetch();
        $this->assertStringContainsString('Please provide ngrok auth token', $content);
    }
}
