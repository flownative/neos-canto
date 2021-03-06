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
use Flownative\Canto\Exception\ConnectionException;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Flow\Annotations\Inject;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Psr\Log\LoggerInterface as SystemLoggerInterface;

/**
 *
 */
final class CantoAssetProxyQuery implements AssetProxyQueryInterface
{
    /**
     * @var CantoAssetSource
     */
    private $assetSource;

    /**
     * @var string
     */
    private $searchTerm = '';

    /**
     * @var string
     */
    private $assetTypeFilter = 'All';

    /**
     * @var array
     */
    private $orderings = [];

    /**
     * @var int
     */
    private $offset = 0;

    /**
     * @var int
     */
    private $limit = 30;

    /**
     * @var string
     */
    private $parentFolderIdentifier = '';

    /**
     * @Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    /**
     * @param CantoAssetSource $assetSource
     */
    public function __construct(CantoAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    /**
     * @param int $offset
     */
    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    /**
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @param int $limit
     */
    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    /**
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @param string $searchTerm
     */
    public function setSearchTerm(string $searchTerm): void
    {
        $this->searchTerm = $searchTerm;
    }

    /**
     * @return string
     */
    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    /**
     * @param string $assetTypeFilter
     */
    public function setAssetTypeFilter(string $assetTypeFilter): void
    {
        $this->assetTypeFilter = $assetTypeFilter;
    }

    /**
     * @return string
     */
    public function getAssetTypeFilter(): string
    {
        return $this->assetTypeFilter;
    }

    /**
     * @return array
     */
    public function getOrderings(): array
    {
        return $this->orderings;
    }

    /**
     * @param array $orderings
     */
    public function setOrderings(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    /**
     * @return string
     */
    public function getParentFolderIdentifier(): string
    {
        return $this->parentFolderIdentifier;
    }

    /**
     * @param string $parentFolderIdentifier
     */
    public function setParentFolderIdentifier(string $parentFolderIdentifier): void
    {
        $this->parentFolderIdentifier = $parentFolderIdentifier;
    }

    /**
     * @return AssetProxyQueryResultInterface
     */
    public function execute(): AssetProxyQueryResultInterface
    {
        return new CantoAssetProxyQueryResult($this);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $response = $this->sendSearchRequest(1, []);
        $responseObject = \GuzzleHttp\json_decode($response->getBody());
        return $responseObject->found ?? 0;
    }

    /**
     * @return CantoAssetProxy[]
     */
    public function getArrayResult(): array
    {
        $assetProxies = [];
        $response = $this->sendSearchRequest($this->limit, $this->orderings);
        $responseObject = \GuzzleHttp\json_decode($response->getBody());

        foreach ($responseObject->results as $rawAsset) {
            $assetProxies[] = CantoAssetProxy::fromJsonObject($rawAsset, $this->assetSource);
        }
        return $assetProxies;
    }

    /**
     * @param int $limit
     * @param array $orderings
     * @return Response
     * @throws AuthenticationFailedException
     * @throws ConnectionException
     * @throws IdentityProviderException
     */
    private function sendSearchRequest(int $limit, array $orderings): Response
    {
        $searchTerm = $this->searchTerm;

        switch ($this->assetTypeFilter) {
            case 'Image':
                $formatTypes = ['image'];
                $fileTypes = [];
            break;
            case 'Video':
                $formatTypes = ['video'];
                $fileTypes = [];
            break;
            case 'Audio':
                $formatTypes = ['audio'];
                $fileTypes = [];
            break;
            case 'Document':
                $formatTypes = ['document'];
                $fileTypes = ['pdf'];
            break;
            case 'All':
            default:
                $formatTypes = ['image', 'video', 'audio', 'document'];
                $fileTypes = [];
            break;
        }

        return $this->assetSource->getCantoClient()->search($searchTerm, $formatTypes, $fileTypes, $this->offset, $limit, $orderings);
    }
}
