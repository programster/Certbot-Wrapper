<?php

use Programster\CoreLibs\Filesystem;

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/DnsProviderEnum.php');




function main(string $configFilepath)
{
    if (file_exists($configFilepath) === false)
    {
        die("Missing required JSON config file at: {$configFilepath}" . PHP_EOL);
    }

    $rawConfigContent = file_get_contents($configFilepath);

    if (empty($rawConfigContent))
    {
        die("Config file is empty."  . PHP_EOL);
    }

    $content = yaml_parse($rawConfigContent);

    if ($content === false)
    {
        die("Config file is not valid YAML."  . PHP_EOL);
    }

    if (array_key_exists('sites', $content) === false)
    {
        die("Missing required sites array in YAML config."  . PHP_EOL);
    }

    if (array_key_exists('dnsProvider', $content) === false)
    {
        die("Missing required dnsProvider in YAML config."  . PHP_EOL);
    }

    $dnsProviderDetails = $content['dnsProvider'];

    if (array_key_exists('name', $dnsProviderDetails) === false)
    {
        die("Missing required name in dnsProvider details of YAML config."  . PHP_EOL);
    }

    if (array_key_exists('apiKey', $dnsProviderDetails) === false)
    {
        die("Missing required apiKey in dnsProvider details of YAML config."  . PHP_EOL);
    }

    if (array_key_exists('email', $content) === false)
    {
        die("Missing required 'email' YAML config. This needs to be the email that should get notified of certificates expiring. E.g. it.admin@irap.org"  . PHP_EOL);
    }

    $propagationTime = (array_key_exists('propagationTime', $content)) ? intval($content['propagationTime']) : 30;

    try
    {
        $provider = DnsProviderEnum::from(strtolower($content['dnsProvider']['name']));
    }
    catch (Exception)
    {
        $names = array_column(DnsProviderEnum::cases(), 'name');
        die("Invalid provider name provided. Must be one of: " . implode(", ", $names) . PHP_EOL);
    }


    $apiKey = $content['dnsProvider']['apiKey'];
    $apiKeyId = (array_key_exists('apiKeyId', $content['dnsProvider'])) ? $content['dnsProvider']['apiKeyId'] : null;

    $email = $content['email'];
    $sites = $content['sites'];


    // create the certbot creds ini file for re-use.
    $credentialsFileLocation = '/credentials/certbot-creds.ini'; # must be within /credentials folder as is volume

    $provider->createCredentialsFile($credentialsFileLocation, $apiKey, $apiKeyId);
    chmod($credentialsFileLocation, 600);

    # Create a local ssl dir if it doesn't exist already.
    @mkdir(__DIR__ . '/ssl');

    foreach ($sites as $siteConfig)
    {
        $skipSite = false;

        if (array_key_exists('destFolder', $siteConfig) === false)
        {
            print "Missing required 'destFolder' array in site config for " . print_r($siteConfig, true) . " so skipping..." . PHP_EOL;
            $skipSite = true;
        }

        if (array_key_exists('domains', $siteConfig) === false)
        {
            print "Missing required 'domains' array in site config for " . print_r($siteConfig, true) . " so skipping..." . PHP_EOL;
            $skipSite = true;
        }

        if ($skipSite === false)
        {
            $destFolder = $siteConfig['destFolder'];
            $domains = $siteConfig['domains'];

            if (is_array($domains) === false || count($domains) === 0)
            {
                print "Domains needs to be an array of domains the SSL certificate is for. Erroneous site config:" . print_r($siteConfig, true) . PHP_EOL;
                print "skipping..." . PHP_EOL;
            }
            else
            {
                $firstDomain = $domains[0];
                $domainsString = implode(",", $domains);

                switch ($provider) {
                    case DnsProviderEnum::DIGITAL_OCEAN:
                    case DnsProviderEnum::CLOUDFLARE:
                    {
                        $command =
                            '/usr/bin/docker run '
                            . '--rm '
                            . ' --name certbot'
                            . ' -v "credentials:/credentials" ' # must use named volumes as using dind
                            . ' -v "letsencrypt:/etc/letsencrypt" ' # must use named volumes as using dind
                            . " certbot/dns-{$provider->value}"
                            . ' certonly'
                            . " --dns-{$provider->value}"
                            . ' --email=' . $email
                            . ' --agree-tos'
                            . ' --no-eff-email '
                            . ' --keep-until-expiring '
                            . " --dns-{$provider->value}-credentials " . $credentialsFileLocation
                            . " --dns-{$provider->value}-propagation-seconds {$propagationTime}"
                            . ' -d "' . $domainsString . '"';
                    }
                    break;

                    case DnsProviderEnum::AWS_ROUTE53:
                    {
                        $command =
                            '/usr/bin/docker run '
                            . '--rm '
                            . ' --name certbot'
                            . " --env AWS_ACCESS_KEY_ID={$apiKeyId}"
                            . " --env AWS_SECRET_ACCESS_KEY=\"{$apiKey}\""
                            . ' -v "credentials:/credentials" ' # must use named volumes as using dind
                            . ' -v "letsencrypt:/etc/letsencrypt" ' # must use named volumes as using dind
                            . " certbot/dns-{$provider->value}"
                            . ' certonly'
                            . " --dns-{$provider->value}"
                            . ' --email=' . $email
                            . ' --agree-tos'
                            . ' --no-eff-email '
                            . ' --keep-until-expiring '
                            . ' -d "' . $domainsString . '"';
                    }
                    break;

                    default:
                    {
                        throw new Exception("Unknown DNS provider: " . $provider->value);
                    }
                }

                passthru($command);

                # copy the files, resolving links.
                $domainFolder = (stripos($firstDomain, '*') === 0) ? substr($firstDomain, 2) : $firstDomain;
                shell_exec("cp --recursive --dereference /letsencrypt/live/{$domainFolder} " . __DIR__ . "/ssl/.");


                if (str_starts_with($destFolder, "/") === true)
                {
                    $destFolder = substr($destFolder, 0, strlen($destFolder) - 1);
                }

                $fullDestPath = "/tls-certs/{$destFolder}";
                Filesystem::mkdir($fullDestPath); // create the directory if it doesnt already exist.
                $from = __DIR__ . "/ssl/{$domainFolder}";
                $to = $fullDestPath;

                if (str_ends_with($to, "/"))
                {
                    $to = substr($to, 0, strlen($to) - 1);
                }

                print "Copying {$from} {$to}" . PHP_EOL;
                shell_exec("cp -r {$from}/* {$to}/.");
                shell_exec("chown --recursive admin:admin {$to}");
                shell_exec("chmod --recursive +r {$to}");
            }
        }
    }

    # Cleanup
    unlink($credentialsFileLocation);

    print "Done." . PHP_EOL;
}


if (count($argv) !== 2)
{
    die("Missing expected path to the YAML config file." . PHP_EOL);
}

$configFilepath = $argv[1];
main($configFilepath);
