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
use Flownative\Canto\Exception\MissingClientSecretException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Exception\InvalidDataException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\Tag;
use Psr\Log\LoggerInterface as SystemLoggerInterface;

/**
 *
 */
final class CantoAssetProxyQuery implements AssetProxyQueryInterface
{
    private string $searchTerm = '';
    private ?Tag $activeTag = null;
    private string $tagQuery = '';
    private ?AssetCollection $activeAssetCollection = null;
    private string $assetTypeFilter = 'All';
    private array $orderings = [];
    private int $offset = 0;
    private int $limit = 30;

    /**
     * @Flow\InjectConfiguration(path="mapping", package="Flownative.Canto")
     */
    protected array $mapping = [];

    /**
     * @Flow\Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    public function __construct(private CantoAssetSource $assetSource)
    {
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setSearchTerm(string $searchTerm): void
    {
        $this->searchTerm = $searchTerm;
    }

    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    public function setActiveTag(Tag $tag): void
    {
        $this->activeTag = $tag;
    }

    public function getActiveTag(): Tag
    {
        return $this->activeTag;
    }

    /**
     * @param AssetCollection|null $assetCollection
     */
    public function setActiveAssetCollection(AssetCollection $assetCollection = null): void
    {
        $this->activeAssetCollection = $assetCollection;
    }

    public function getActiveAssetCollection(): ?AssetCollection
    {
        return $this->activeAssetCollection;
    }

    public function setAssetTypeFilter(string $assetTypeFilter): void
    {
        $this->assetTypeFilter = $assetTypeFilter;
    }

    public function getAssetTypeFilter(): string
    {
        return $this->assetTypeFilter;
    }

    public function getOrderings(): array
    {
        return $this->orderings;
    }

    public function setOrderings(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    public function execute(): AssetProxyQueryResultInterface
    {
        return new CantoAssetProxyQueryResult($this);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws Exception
     * @throws GuzzleException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     * @throws \JsonException
     */
    public function count(): int
    {
        $response = $this->sendSearchRequest(1, []);
        $responseContent = $response->getBody()->getContents();
        $responseObject = json_decode($responseContent, false, 512, JSON_THROW_ON_ERROR);
        return $responseObject->found ?? 0;
    }

    /**
     * @return CantoAssetProxy[]
     * @throws OAuthClientException
     * @throws GuzzleException
     * @throws CacheException
     * @throws InvalidDataException
     * @throws AuthenticationFailedException
     * @throws \JsonException
     * @throws \Exception
     */
    public function getArrayResult(): array
    {
        $assetProxies = [];
        $response = $this->sendSearchRequest($this->limit, $this->orderings);
        $responseContent = $response->getBody()->getContents();
        $responseObject = json_decode($responseContent, false, 512, JSON_THROW_ON_ERROR);

        if (isset($responseObject->results) && is_array($responseObject->results)) {
            foreach ($responseObject->results as $rawAsset) {
                $assetIdentifier = $rawAsset->scheme . '-' . $rawAsset->id;

                $this->assetSource->getAssetProxyCache()->set($assetIdentifier, json_encode($rawAsset, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT));

                $assetProxies[] = CantoAssetProxy::fromJsonObject($rawAsset, $this->assetSource);
            }
        }
        return $assetProxies;
    }

    /**
     * @throws AuthenticationFailedException
     * @throws Exception
     * @throws GuzzleException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     * @throws \JsonException
     */
    private function sendSearchRequest(int $limit, array $orderings): Response
    {
        if (!empty($this->activeTag)) {
            $this->prepareTagQuery();
        }

        $searchTerm = $this->searchTerm;

        $formatTypes = match ($this->assetTypeFilter) {
            'Image' => ['image'],
            'Video' => ['video'],
            'Audio' => ['audio'],
            'Document' => ['document'],
            default => ['image', 'video', 'audio', 'document', 'presentation', 'other'],
        };
        return $this->assetSource->getCantoClient()->search($searchTerm, $formatTypes, $this->tagQuery, $this->offset, $limit, $orderings);
    }

    /**
     * @throws AuthenticationFailedException
     * @throws Exception
     * @throws GuzzleException
     * @throws IdentityProviderException
     * @throws MissingActionNameException
     * @throws MissingClientSecretException
     * @throws OAuthClientException
     * @throws \JsonException
     */
    public function prepareTagQuery(): void
    {
        $assetCollectionTitlesToSearch = [];
        if (!empty($this->activeAssetCollection)) {
            $assetCollectionTitlesToSearch[] = $this->activeAssetCollection->getTitle();
        } else {
            foreach ($this->activeTag->getAssetCollections() as $collection) {
                $assetCollectionTitlesToSearch[] = $collection->getTitle();
            }
        }

        $this->tagQuery = '';

        if (!empty($this->mapping['customFields'])) {
            $cantoCustomFields = $this->assetSource->getCantoClient()->getCustomFields();

            foreach ($cantoCustomFields as $cantoCustomField) {
                // field should not be mapped if it does not exist in the settings or if asAssetCollection is set to false
                if (!array_key_exists($cantoCustomField->id, $this->mapping['customFields']) || !$this->mapping['customFields'][$cantoCustomField->id]['asAssetCollection']) {
                    continue;
                }
                if (in_array($cantoCustomField->name, $assetCollectionTitlesToSearch, true)) {
                    $this->tagQuery .= '&' . $cantoCustomField->id . '.keyword="' . $this->activeTag->getLabel() . '"';
                }
            }
        }

        if (!empty($this->mapping['tags'])) {
            $this->tagQuery .= '&tags="' . $this->activeTag->getLabel() . '"';
        }
    }

    /**
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws OAuthClientException
     * @throws MissingClientSecretException
     * @throws \JsonException
     * @throws IdentityProviderException
     * @throws Exception
     * @throws MissingActionNameException
     */
    public function prepareUntaggedQuery(): void
    {
        $this->tagQuery = '';
        if (!empty($this->mapping['customFields'])) {
            if ($this->activeAssetCollection !== null) {
                $cantoCustomFields = $this->assetSource->getCantoClient()->getCustomFields();
                foreach ($cantoCustomFields as $cantoCustomField) {
                    if (array_key_exists($cantoCustomField->id, $this->mapping['customFields']) && $this->mapping['customFields'][$cantoCustomField->id]['asAssetCollection'] && $cantoCustomField->name === $this->activeAssetCollection->getTitle()) {
                        $this->tagQuery .= '&' . $cantoCustomField->id . '.keyword="__null__"';
                    }
                }
            } else {
                foreach ($this->mapping['customFields'] as $customFieldId => $customFieldToBeMapped) {
                    if (!$customFieldToBeMapped['asAssetCollection']) {
                        continue;
                    }
                    $this->tagQuery .= '&' . $customFieldId . '.keyword="__null__"';
                }
            }
        }
    }
}
