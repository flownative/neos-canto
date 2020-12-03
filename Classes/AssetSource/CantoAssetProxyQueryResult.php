<?php

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
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

/**
 *
 */
class CantoAssetProxyQueryResult implements AssetProxyQueryResultInterface
{
    /**
     * @var CantoAssetProxyQuery
     */
    private $query;

    /**
     * @var array
     */
    private $assetProxies;

    /**
     * @var int
     */
    private $numberOfAssetProxies;

    /**
     * @var \ArrayIterator
     */
    private $assetProxiesIterator;

    /**
     * @param CantoAssetProxyQuery $query
     */
    public function __construct(CantoAssetProxyQuery $query)
    {
        $this->query = $query;
    }

    /**
     * @return void
     */
    private function initialize(): void
    {
        if ($this->assetProxies === null) {
            $this->assetProxies = $this->query->getArrayResult();
            $this->assetProxiesIterator = new \ArrayIterator($this->assetProxies);
        }
    }

    /**
     * @return AssetProxyQueryInterface
     */
    public function getQuery(): AssetProxyQueryInterface
    {
        return clone $this->query;
    }

    /**
     * @return AssetProxyInterface|null
     */
    public function getFirst(): ?AssetProxyInterface
    {
        $this->initialize();
        return reset($this->assetProxies);
    }

    /**
     * @return AssetProxyInterface[]
     */
    public function toArray(): array
    {
        $this->initialize();
        return $this->assetProxies;
    }

    public function current()
    {
        $this->initialize();
        return $this->assetProxiesIterator->current();
    }

    public function next()
    {
        $this->initialize();
        $this->assetProxiesIterator->next();
    }

    public function key()
    {
        $this->initialize();
        return $this->assetProxiesIterator->key();

    }

    public function valid()
    {
        $this->initialize();
        return $this->assetProxiesIterator->valid();
    }

    public function rewind()
    {
        $this->initialize();
        $this->assetProxiesIterator->rewind();
    }

    public function offsetExists($offset)
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetExists($offset);
    }

    public function offsetGet($offset)
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetGet($offset);
    }

    public function offsetSet($offset, $value)
    {
        $this->initialize();
        $this->assetProxiesIterator->offsetSet($offset, $value);
    }

    public function offsetUnset($offset)
    {
    }

    /**
     * @return int
     */
    public function count(): int
    {
        if ($this->numberOfAssetProxies === null) {
            if (is_array($this->assetProxies)) {
                return count($this->assetProxies);
            } else {
                return $this->query->count();
            }
        }
    }
}
