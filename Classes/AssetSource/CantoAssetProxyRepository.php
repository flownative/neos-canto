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

use Flownative\Canto\Exception\AssetNotFoundException;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Exception\InvalidDataException;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsTaggingInterface;
use Neos\Media\Domain\Model\Tag;

/**
 * CantoAssetProxyRepository
 */
class CantoAssetProxyRepository implements AssetProxyRepositoryInterface, SupportsSortingInterface, SupportsTaggingInterface, SupportsCollectionsInterface
{
    /**
     * @var CantoAssetSource
     */
    private $assetSource;

    /**
     * @var AssetCollection
     */
    private $activeAssetCollection;

    /**
     * @param CantoAssetSource $assetSource
     */
    public function __construct(CantoAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    /**
     * @var string
     */
    private $assetTypeFilter = 'All';

    /**
     * @var array
     */
    private $orderings = [];

    /**
     * @param string $identifier
     * @return AssetProxyInterface
     * @throws AssetNotFoundException
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws CacheException
     * @throws InvalidDataException
     * @throws AuthenticationFailedException
     * @throws \Exception
     */
    public function getAssetProxy(string $identifier): AssetProxyInterface
    {
        $cacheEntry = $this->assetSource->getAssetProxyCache()->get($identifier);
        if ($cacheEntry) {
            $responseObject = \GuzzleHttp\json_decode($cacheEntry);
        } else {
            try {
                $response = $this->assetSource->getCantoClient()->getFile($identifier);
                $responseObject = \GuzzleHttp\json_decode($response->getBody());
            } catch (\Exception $e) {
                throw new AssetNotFoundException('Asset not found', 1526636260);
            }
        }

        if (!$responseObject instanceof \stdClass) {
            throw new AssetNotFoundException('Asset not found', 1526636260);
        }

        $this->assetSource->getAssetProxyCache()->set($identifier, \GuzzleHttp\json_encode($responseObject, JSON_FORCE_OBJECT));

        return CantoAssetProxy::fromJsonObject($responseObject, $this->assetSource);
    }

    /**
     * @param AssetTypeFilter|null $assetType
     */
    public function filterByType(AssetTypeFilter $assetType = null): void
    {
        $this->assetTypeFilter = (string)$assetType ?: 'All';
    }

    /**
     * @return AssetProxyQueryResultInterface
     */
    public function findAll(): AssetProxyQueryResultInterface
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new CantoAssetProxyQueryResult($query);
    }

    /**
     * @param string $searchTerm
     * @return AssetProxyQueryResultInterface
     */
    public function findBySearchTerm(string $searchTerm): AssetProxyQueryResultInterface
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        $query->setSearchTerm($searchTerm);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new CantoAssetProxyQueryResult($query);
    }

    /**
     * @param Tag $tag
     * @return AssetProxyQueryResultInterface
     */
    public function findByTag(Tag $tag): AssetProxyQueryResultInterface
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        $query->setActiveTag($tag);
        $query->setActiveAssetCollection($this->activeAssetCollection);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new CantoAssetProxyQueryResult($query);
    }

    /**
     * @return AssetProxyQueryResultInterface
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws AuthenticationFailedException
     */
    public function findUntagged(): AssetProxyQueryResultInterface
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        $query->setActiveAssetCollection($this->activeAssetCollection);
        $query->prepareUntaggedQuery();
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new CantoAssetProxyQueryResult($query);
    }

    /**
     * @return int
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws AuthenticationFailedException
     */
    public function countAll(): int
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        return $query->count();
    }

    /**
     * Sets the property names to order results by. Expected like this:
     * array(
     *  'filename' => SupportsSorting::ORDER_ASCENDING,
     *  'lastModified' => SupportsSorting::ORDER_DESCENDING
     * )
     *
     * @param array $orderings The property names to order by by default
     * @return void
     * @api
     */
    public function orderBy(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    /**
     * @return int
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws AuthenticationFailedException
     */
    public function countUntagged(): int
    {
        $query = new CantoAssetProxyQuery($this->assetSource);
        $query->setActiveAssetCollection($this->activeAssetCollection);
        $query->prepareUntaggedQuery();
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return $query->count();
    }

    /**
     * @param AssetCollection|null $assetCollection
     * @return void
     */
    public function filterByCollection(AssetCollection $assetCollection = null): void
    {
        $this->activeAssetCollection = $assetCollection;
    }
}
