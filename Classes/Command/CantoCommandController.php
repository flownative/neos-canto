<?php
declare(strict_types=1);

namespace Flownative\Canto\Command;

use Flownative\Canto\AssetSource\CantoAssetProxy;
use Flownative\Canto\AssetSource\CantoAssetProxyRepository;
use Flownative\Canto\AssetSource\CantoAssetSource;
use Flownative\Canto\Exception\AuthenticationFailedException;
use Flownative\Canto\Exception\MissingClientSecretException;
use Flownative\OAuth2\Client\OAuthClientException;
use GuzzleHttp\Exception\GuzzleException;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Http\Exception;
use Neos\Flow\Mvc\Routing\Exception\MissingActionNameException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\Tag;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetSourceService;

class CantoCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetSourceService
     */
    protected $assetSourceService;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\InjectConfiguration(path="mapping", package="Flownative.Canto")
     * @var array
     */
    protected array $mapping = [];

    /**
     * Tag used assets
     *
     * @param string $assetSource Name of the canto asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws StopCommandException
     */
    public function tagUsedAssetsCommand(string $assetSource = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        $iterator = $this->assetRepository->findAllIterator();

        !$quiet && $this->outputLine('<b>Tagging used assets of asset source "%s" via Canto API:</b>', [$assetSourceIdentifier]);

        /** @var CantoAssetSource $cantoAssetSource */
        $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
        $cantoClient = $cantoAssetSource->getCantoClient();

        if (!$cantoAssetSource->isAutoTaggingEnabled()) {
            $this->outputLine('<error>Auto-tagging is disabled</error>');
            $this->quit(1);
        }

        $cantoAssetSource->getAssetProxyCache()->flush();

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            if ($asset->getAssetSourceIdentifier() !== $assetSourceIdentifier) {
                continue;
            }

            $assetProxy = $asset->getAssetProxy();

            if (!$assetProxy instanceof CantoAssetProxy) {
                $this->outputLine('   error   Asset "%s" (%s) could not be accessed via Canto-API', [$asset->getLabel(), $asset->getIdentifier()]);
                continue;
            }

            $currentTags = $assetProxy->getTags();
            sort($currentTags);
            if ($asset->getUsageCount() > 0) {
                $newTags = array_unique(array_merge($currentTags, [$cantoAssetSource->getAutoTaggingInUseTag()]));
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $cantoClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   tagged   %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (tagged)  %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                }
            } else {
                $newTags = array_flip($currentTags);
                unset($newTags[$cantoAssetSource->getAutoTaggingInUseTag()]);
                $newTags = array_flip($newTags);
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $cantoClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   removed %s', [$asset->getLabel(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (removed) %s', [$asset->getLabel(), $asset->getUsageCount()]);
                }
            }
        }
    }

    /**
     * Import Canto Custom Fields as Tags and Collections
     *
     * @param string $assetSourceIdentifier Name of the canto asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws AuthenticationFailedException
     * @throws GuzzleException
     * @throws IllegalObjectTypeException
     * @throws OAuthClientException
     * @throws StopCommandException
     * @throws MissingClientSecretException
     * @throws \JsonException
     * @throws IdentityProviderException
     * @throws Exception
     * @throws MissingActionNameException
     */
    public function importCustomFieldsAsCollectionsAndTagsCommand(string $assetSourceIdentifier = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $quiet = true): void
    {
        !$quiet && $this->outputLine('<b>Importing custom fields as tags and asset collections via Canto API</b>');

        $customFieldsMapping = $this->mapping['customFields'];
        if (empty($customFieldsMapping)) {
            $this->outputLine('<error>No custom fields configured for mapping</error>');
            $this->quit(1);
        }

        try {
            /** @var CantoAssetSource $cantoAssetSource */
            $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
            $cantoClient = $cantoAssetSource->getCantoClient();
            $cantoClient->allowClientCredentialsAuthentication(true);
        } catch (\Exception) {
            $this->outputLine('<error>Canto client could not be created</error>');
            $this->quit(1);
        }

        $cantoCustomFields = $cantoClient->getCustomFields();
        foreach ($cantoCustomFields as $cantoCustomField) {
            if (array_key_exists($cantoCustomField->id, $customFieldsMapping) && $customFieldsMapping[$cantoCustomField->id]['asAssetCollection']) {
                $assetCollection = $this->assetCollectionRepository->findOneByTitle($cantoCustomField->name);

                if (!($assetCollection instanceof AssetCollection)) {
                    $assetCollection = new AssetCollection($cantoCustomField->name);
                    $this->assetCollectionRepository->add($assetCollection);
                    !$quiet && $this->outputLine('+ %s', [$cantoCustomField->name]);
                } else {
                    !$quiet && $this->outputLine('= %s', [$cantoCustomField->name]);
                }

                if ($customFieldsMapping[$cantoCustomField->id]['valuesAsTags'] !== true) {
                    continue;
                }

                foreach ($cantoCustomField->values as $cantoCustomFieldValue) {
                    if (!empty($customFieldsMapping[$cantoCustomField->id]['include']) && !in_array($cantoCustomFieldValue, $customFieldsMapping[$cantoCustomField->id]['include'], true)) {
                        continue;
                    }
                    if (!empty($customFieldsMapping[$cantoCustomField->id]['exclude']) && in_array($cantoCustomFieldValue, $customFieldsMapping[$cantoCustomField->id]['exclude'], true)) {
                        continue;
                    }

                    $tag = $this->tagRepository->findOneByLabel($cantoCustomFieldValue);

                    if ($tag === null) {
                        $tag = new Tag($cantoCustomFieldValue);
                        $this->tagRepository->add($tag);
                        $assetCollection->addTag($tag);
                        $this->assetCollectionRepository->update($assetCollection);
                        !$quiet && $this->outputLine('  + %s', [$cantoCustomFieldValue]);
                    } elseif (!$assetCollection->getTags()->contains($tag)) {
                        $assetCollection->addTag($tag);
                        $this->assetCollectionRepository->update($assetCollection);
                        !$quiet && $this->outputLine('  ~ %s', [$cantoCustomFieldValue]);
                    }
                }

                !$quiet && $this->outputLine();
            }
        }

        !$quiet && $this->outputLine('<success>Import done.</success>');
    }

    /**
     * Import Canto Tags as Tags
     *
     * @param string $assetSourceIdentifier Name of the canto asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws GuzzleException
     * @throws IllegalObjectTypeException
     * @throws OAuthClientException
     * @throws StopCommandException
     * @throws AuthenticationFailedException
     */
    public function importTagsCommand(string $assetSourceIdentifier = CantoAssetSource::ASSET_SOURCE_IDENTIFIER, bool $quiet = true): void
    {
        !$quiet && $this->outputLine('<b>Importing tags via Canto API</b>');

        $tagMapping = $this->mapping['tags'];
        if (empty($tagMapping)) {
            $this->outputLine('<error>No tags mapping configured</error>');
            $this->quit(1);
        }

        try {
            /** @var CantoAssetSource $cantoAssetSource */
            $cantoAssetSource = $this->assetSourceService->getAssetSources()[$assetSourceIdentifier];
            $cantoClient = $cantoAssetSource->getCantoClient();
            $cantoClient->allowClientCredentialsAuthentication(true);
        } catch (\Exception) {
            $this->outputLine('<error>Canto client could not be created</error>');
            $this->quit(1);
        }

        $tags = $cantoClient->getFacetValues($tagMapping['field']);
        $relevantTags = array_chunk($tags, $tagMapping['limit']);
        foreach ($relevantTags[0] as $tagLabel) {

            if (!empty($tagMapping['include']) && !in_array($tagLabel, $tagMapping['include'], true)) {
                continue;
            }
            if (!empty($tagMapping['exclude']) && in_array($tagLabel, $tagMapping['exclude'], true)) {
                continue;
            }

            $tag = $this->tagRepository->findOneByLabel($tagLabel);

            if ($tag === null) {
                $tag = new Tag($tagLabel);
                $this->tagRepository->add($tag);
                !$quiet && $this->outputLine('  + %s', [$tagLabel]);
            }

            !$quiet && $this->outputLine();
        }

        !$quiet && $this->outputLine('<success>Import done.</success>');
    }
}
