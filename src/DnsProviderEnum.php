<?php

enum DnsProviderEnum: string
{
    case DIGITAL_OCEAN = "digitalocean";
    case CLOUDFLARE = "cloudflare";
    case AWS_ROUTE53 = "route53";


    public function createCredentialsFile(string $filepath, string $apiKeySecret, ?string $apiKeyId=null): void
    {
        $content = match($this) {
            self::CLOUDFLARE    => "dns_cloudflare_api_token = {$apiKeySecret}",
            self::DIGITAL_OCEAN => "dns_digitalocean_token = {$apiKeySecret}",
            self::AWS_ROUTE53 => "AWS_ACCESS_KEY_ID={$apiKeyId}" . PHP_EOL . "AWS_SECRET_ACCESS_KEY={$apiKeySecret}",
        };

        file_put_contents($filepath, $content);
    }
}