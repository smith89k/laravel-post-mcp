<?php

namespace Smith89k\PostMcp\Media;

use finfo;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Smith89k\PostMcp\Exceptions\PostMcpException;
use Smith89k\PostMcp\PostMcp;

/**
 * Gets an image from a URL or base64 data and checks its real type and size.
 * URLs that point at private or internal addresses are refused.
 */
class ImageFetcher
{
    protected const MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    protected const MAX_REDIRECTS = 3;

    public function __construct(
        protected PostMcp $postMcp,
        protected HostResolver $resolver,
    ) {}

    public function fromBase64(string $data, ?string $filename = null, ?string $alt = null): ImageFile
    {
        $data = trim($data);

        if (preg_match('/^data:[^;,]+;base64,/i', $data, $matches) === 1) {
            $data = substr($data, strlen($matches[0]));
        }

        $contents = base64_decode(preg_replace('/\s+/', '', $data) ?? '', true);

        if ($contents === false || $contents === '') {
            throw new PostMcpException('The base64 data could not be decoded. Send the file as plain base64 or a data: URI.');
        }

        return $this->check($contents, $filename, $alt);
    }

    public function fromUrl(string $url, ?string $filename = null, ?string $alt = null): ImageFile
    {
        $current = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $response = $this->get($current);

            if (! $response->redirect()) {
                if (! $response->successful()) {
                    throw new PostMcpException("The image URL answered with HTTP {$response->status()}.");
                }

                $filename ??= basename((string) parse_url($current, PHP_URL_PATH));

                return $this->check($response->body(), $filename, $alt);
            }

            $location = (string) $response->header('Location');

            if ($location === '') {
                throw new PostMcpException('The image URL redirected without a location.');
            }

            $current = str_starts_with($location, 'http') ? $location : $this->absolute($current, $location);
        }

        throw new PostMcpException('The image URL redirected too many times.');
    }

    protected function get(string $url): Response
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new PostMcpException('The image URL must start with http:// or https://.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = $this->publicAddress(trim($host, '[]'));

        try {
            $response = Http::timeout((int) $this->postMcp->config('media.timeout', 15))
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                ])
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new PostMcpException('The image could not be downloaded: '.$exception->getMessage());
        }

        $length = (int) $response->header('Content-Length');

        if ($length > $this->maxBytes()) {
            throw new PostMcpException($this->tooLargeMessage());
        }

        return $response;
    }

    /**
     * Returns an address to connect to, refusing hosts that resolve to private,
     * loopback, link-local or reserved ranges.
     */
    protected function publicAddress(string $host): string
    {
        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new PostMcpException("The host {$host} could not be found.");
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new PostMcpException('Images can only be fetched from public addresses.');
            }
        }

        return $ips[0];
    }

    protected function check(string $contents, ?string $filename, ?string $alt): ImageFile
    {
        if (strlen($contents) > $this->maxBytes()) {
            throw new PostMcpException($this->tooLargeMessage());
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $allowed = $this->allowedMimeTypes();
        $extension = array_search($mime, $allowed, true);

        if ($extension === false) {
            throw new PostMcpException("This file is {$mime}. Allowed image types: ".implode(', ', array_keys($allowed)).'.');
        }

        $name = Str::slug(pathinfo((string) $filename, PATHINFO_FILENAME)) ?: 'image';
        $name = Str::limit($name, 60, '').'-'.Str::lower(Str::random(8)).'.'.$extension;

        return new ImageFile($contents, $mime, (string) $extension, $name, $alt);
    }

    /**
     * @return array<string, string>
     */
    protected function allowedMimeTypes(): array
    {
        $allowed = [];

        foreach ((array) $this->postMcp->config('media.mimes', array_keys(self::MIME_TYPES)) as $extension) {
            $extension = strtolower((string) $extension);

            if (isset(self::MIME_TYPES[$extension]) && ! in_array(self::MIME_TYPES[$extension], $allowed, true)) {
                $allowed[$extension === 'jpeg' ? 'jpg' : $extension] = self::MIME_TYPES[$extension];
            }
        }

        return $allowed;
    }

    protected function maxBytes(): int
    {
        return (int) $this->postMcp->config('media.max_kb', 5120) * 1024;
    }

    protected function tooLargeMessage(): string
    {
        return 'The image is larger than '.(int) $this->postMcp->config('media.max_kb', 5120).' KB.';
    }

    protected function absolute(string $base, string $location): string
    {
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.'/'.ltrim($location, '/');
    }
}
