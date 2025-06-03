<?php
declare(strict_types=1);

namespace Flownative\Canto\AssetSource;

/*
 * This file is part of the Flownative.Canto package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Exception\InvalidDataException;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

/**
 *
 */
class CantoAssetProxyQueryResult implements AssetProxyQueryResultInterface
{
    private ?array $assetProxies = null;
    private ?int $numberOfAssetProxies = null;
    private \ArrayIterator $assetProxiesIterator;

    public function __construct(private CantoAssetProxyQuery $query)
    {
    }

    /**
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws CacheException
     * @throws InvalidDataException
     * @throws AuthenticationFailedException
     */
    private function initialize(): void
    {
        if ($this->assetProxies === null) {
            $this->assetProxies = $this->query->getArrayResult();
            $this->assetProxiesIterator = new \ArrayIterator($this->assetProxies);
        }
    }

    public function getQuery(): AssetProxyQueryInterface
    {
        return clone $this->query;
    }

    /**
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     */
    public function getFirst(): ?AssetProxyInterface
    {
        $this->initialize();
        return reset($this->assetProxies);
    }

    /**
     * @return AssetProxyInterface[]
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     */
    public function toArray(): array
    {
        $this->initialize();
        return $this->assetProxies;
    }

    /**
     * @throws AuthenticationFailedException
     * @throws OAuthClientException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function current(): mixed
    {
        $this->initialize();
        return $this->assetProxiesIterator->current();
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function next(): void
    {
        $this->initialize();
        $this->assetProxiesIterator->next();
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function key(): mixed
    {
        $this->initialize();
        return $this->assetProxiesIterator->key();
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function valid(): bool
    {
        $this->initialize();
        return $this->assetProxiesIterator->valid();
    }

    /**
     * @throws OAuthClientException
     * @throws AuthenticationFailedException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function rewind(): void
    {
        $this->initialize();
        $this->assetProxiesIterator->rewind();
    }

    /**
     * @throws AuthenticationFailedException
     * @throws OAuthClientException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function offsetExists($offset): bool
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetExists($offset);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws OAuthClientException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function offsetGet($offset): mixed
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetGet($offset);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws OAuthClientException
     * @throws CacheException
     * @throws GuzzleException
     * @throws InvalidDataException
     */
    public function offsetSet($offset, $value): void
    {
        $this->initialize();
        $this->assetProxiesIterator->offsetSet($offset, $value);
    }

    public function offsetUnset($offset): void
    {
    }

    /**
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws AuthenticationFailedException
     */
    public function count(): int
    {
        if ($this->numberOfAssetProxies === null) {
            if (is_array($this->assetProxies)) {
                $this->numberOfAssetProxies = count($this->assetProxies);
            } else {
                $this->numberOfAssetProxies = $this->query->count();
            }
        }

        return $this->numberOfAssetProxies;
    }
}
