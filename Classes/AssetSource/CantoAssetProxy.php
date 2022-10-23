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

use Exception;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\Canto\Exception\MissingClientSecretException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Media\Domain\Service\ThumbnailService;
use Neos\Media\Exception\ThumbnailServiceException;
use Neos\Utility\MediaTypes;
use Psr\Http\Message\UriInterface;
use stdClass;

/**
 *
 */
final class CantoAssetProxy implements AssetProxyInterface, HasRemoteOriginalInterface, SupportsIptcMetadataInterface
{
    private CantoAssetSource $assetSource;
    private string $identifier;
    private string $label;
    private string $filename;
    private \DateTime $lastModified;
    private int $fileSize;
    private string $mediaType;
    private array $iptcProperties = [];
    private string $previewUri;
    private int $widthInPixels;
    private int $heightInPixels;
    private array $tags = [];

    /**
     * @Flow\Inject
     * @var ImportedAssetRepository
     */
    protected $importedAssetRepository;

    /**
     * @FLow\Inject
     * @var ThumbnailService
     */
    protected $thumbnailService;

    /**
     * @throws Exception
     */
    public static function fromJsonObject(stdClass $jsonObject, CantoAssetSource $assetSource): CantoAssetProxy
    {
        // static used here despite this being a final class because Flow still builds a proxy and self causes
        // an error because the returned Flownative\Canto\AssetSource\CantoAssetProxy_Original is not the
        // declared Flownative\Canto\AssetSource\CantoAssetProxy
        /** @noinspection PhpUnnecessaryStaticReferenceInspection */
        $assetProxy = new static();
        $assetProxy->assetSource = $assetSource;
        $assetProxy->identifier = $jsonObject->scheme . '-' . $jsonObject->id;
        $assetProxy->label = $jsonObject->name;
        $assetProxy->filename = $jsonObject->name;
        $assetProxy->lastModified = \DateTime::createFromFormat('YmdHisv', $jsonObject->default->{'Date modified'});
        $assetProxy->fileSize = (int)$jsonObject->size;
        $assetProxy->mediaType = MediaTypes::getMediaTypeFromFilename($jsonObject->name);

        $assetProxy->iptcProperties['CopyrightNotice'] = $jsonObject->copyright ?? ($jsonObject->default->Copyright ?? '');

        $assetProxy->widthInPixels = $jsonObject->width ? (int)$jsonObject->width : null;
        $assetProxy->heightInPixels = $jsonObject->height ? (int)$jsonObject->height : null;

        $assetProxy->previewUri = $jsonObject->url->directUrlPreview;

        return $assetProxy;
    }

    public function getAssetSource(): AssetSourceInterface
    {
        return $this->assetSource;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getLastModified(): \DateTime
    {
        return $this->lastModified;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function hasIptcProperty(string $propertyName): bool
    {
        return isset($this->iptcProperties[$propertyName]);
    }

    public function getIptcProperty(string $propertyName): string
    {
        return $this->iptcProperties[$propertyName] ?? '';
    }

    public function getIptcProperties(): array
    {
        return $this->iptcProperties;
    }

    public function getWidthInPixels(): ?int
    {
        return $this->widthInPixels;
    }

    public function getHeightInPixels(): ?int
    {
        return $this->heightInPixels;
    }

    /**
     * @throws ThumbnailServiceException
     */
    public function getThumbnailUri(): ?UriInterface
    {
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Thumbnail');
        return new Uri(sprintf(
            '%s/%d',
            preg_replace('|/\d+$|', '', $this->previewUri),
            max($thumbnailConfiguration->getMaximumWidth(), $thumbnailConfiguration->getMaximumHeight())
        ));
    }

    /**
     * @throws ThumbnailServiceException
     */
    public function getPreviewUri(): ?UriInterface
    {
        $previewConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview');
        return new Uri(sprintf(
            '%s/%d',
            preg_replace('|/\d+$|', '', $this->previewUri),
            max($previewConfiguration->getMaximumWidth(), $previewConfiguration->getMaximumHeight())
        ));
    }

    /**
     * @return resource
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws OAuthClientException
     * @throws MissingClientSecretException
     * @throws IdentityProviderException
     * @throws \Neos\Flow\Http\Exception
     * @throws MissingActionNameException
     */
    public function getImportStream()
    {
        return fopen((string)$this->assetSource->getCantoClient()->directUri($this->identifier), 'rb');
    }

    public function getLocalAssetIdentifier(): ?string
    {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return ($importedAsset instanceof ImportedAsset ? $importedAsset->getLocalAssetIdentifier() : null);
    }

    public function getTags(): array
    {
        return [];
        // return $this->tags;
    }

    public function isImported(): bool
    {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return ($importedAsset instanceof ImportedAsset);
    }
}
