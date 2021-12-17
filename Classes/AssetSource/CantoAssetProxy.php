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
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
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
    /**
     * @var CantoAssetSource
     */
    private $assetSource;

    /**
     * @var string
     */
    private $identifier;

    /**
     * @var string
     */
    private $label;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var \DateTime
     */
    private $lastModified;

    /**
     * @var int
     */
    private $fileSize;

    /**
     * @var string
     */
    private $mediaType;

    /**
     * @var array
     */
    private $iptcProperties = [];

    /**
     * @var string
     */
    private $previewUri;

    /**
     * @var string
     */
    private $originalUri;

    /**
     * @var int
     */
    private $widthInPixels;

    /**
     * @var int
     */
    private $heightInPixels;

    /**
     * @var array
     */
    private $tags = [];

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
     * @param stdClass $jsonObject
     * @param CantoAssetSource $assetSource
     * @return static
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
        $assetProxy->tags = $jsonObject->tag ?? [];

        $assetProxy->iptcProperties['CopyrightNotice'] = $jsonObject->copyright ?? ($jsonObject->default->Copyright ?? '');

        $assetProxy->widthInPixels = $jsonObject->width ? (int)$jsonObject->width : null;
        $assetProxy->heightInPixels = $jsonObject->height ? (int)$jsonObject->height : null;

        $assetProxy->originalUri = $jsonObject->url->directUrlOriginal;
        $assetProxy->previewUri = $jsonObject->url->directUrlPreview;

        return $assetProxy;
    }

    /**
     * @return AssetSourceInterface
     */
    public function getAssetSource(): AssetSourceInterface
    {
        return $this->assetSource;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @return \DateTime
     */
    public function getLastModified(): \DateTime
    {
        return $this->lastModified;
    }

    /**
     * @return int
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * @return string
     */
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @param string $propertyName
     * @return bool
     */
    public function hasIptcProperty(string $propertyName): bool
    {
        return isset($this->iptcProperties[$propertyName]);
    }

    /**
     * @param string $propertyName
     * @return string
     */
    public function getIptcProperty(string $propertyName): string
    {
        return $this->iptcProperties[$propertyName] ?? '';
    }

    /**
     * @return array
     */
    public function getIptcProperties(): array
    {
        return $this->iptcProperties;
    }

    /**
     * @return int|null
     */
    public function getWidthInPixels(): ?int
    {
        return $this->widthInPixels;
    }

    /**
     * @return int|null
     */
    public function getHeightInPixels(): ?int
    {
        return $this->heightInPixels;
    }

    /**
     * @return UriInterface
     * @throws ThumbnailServiceException
     */
    public function getThumbnailUri(): ?UriInterface
    {
        $thumbnailConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Thumbnail');
        return new Uri(sprintf(
            '%s/%d',
            preg_replace('|/[0-9]+$|', '', $this->previewUri),
            max($thumbnailConfiguration->getMaximumWidth(), $thumbnailConfiguration->getMaximumHeight())
        ));
    }

    /**
     * @return UriInterface
     * @throws ThumbnailServiceException
     */
    public function getPreviewUri(): ?UriInterface
    {
        $previewConfiguration = $this->thumbnailService->getThumbnailConfigurationForPreset('Neos.Media.Browser:Preview');
        return new Uri(sprintf(
            '%s/%d',
            preg_replace('|/[0-9]+$|', '', $this->previewUri),
            max($previewConfiguration->getMaximumWidth(), $previewConfiguration->getMaximumHeight())
        ));
    }

    /**
     * @return resource
     * @throws AuthenticationFailedException
     * @throws OAuthClientException
     * @throws GuzzleException
     */
    public function getImportStream()
    {
        return fopen($this->originalUri, 'rb');
    }

    /**
     * @return string
     */
    public function getLocalAssetIdentifier(): ?string
    {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return ($importedAsset instanceof ImportedAsset ? $importedAsset->getLocalAssetIdentifier() : null);
    }

    /**
     * @return array
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @return bool
     */
    public function isImported(): bool
    {
        return true;
    }
}
