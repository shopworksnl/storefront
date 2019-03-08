<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Seo;

use Cocur\Slugify\Bridge\Twig\SlugifyExtension;
use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Doctrine\FetchModeHelper;
use Shopware\Core\Framework\Doctrine\MultiInsertQueryQueue;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Storefront\Framework\Seo\Exception\InvalidTemplateException;
use Shopware\Storefront\Framework\Seo\SeoUrl\SeoUrlDefinition;
use Shopware\Storefront\Framework\Seo\SeoUrl\SeoUrlEntity;
use Shopware\Storefront\Framework\Seo\SeoUrlGenerator\SeoUrlGeneratorInterface;
use Shopware\Storefront\Framework\Seo\SeoUrlTemplate\SeoUrlTemplateEntity;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;

class SeoService implements SeoServiceInterface
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EntityRepositoryInterface
     */
    private $seoUrlTemplateRepository;

    /**
     * @var SeoUrlGeneratorInterface[]
     */
    private $seoUrlGenerators;

    /**
     * @var Environment
     */
    private $twig;

    public function __construct(Connection $connection, EntityRepositoryInterface $seoUrlTemplateRepository, Slugify $slugify, iterable $seoUrlGenerators)
    {
        $this->connection = $connection;
        $this->seoUrlTemplateRepository = $seoUrlTemplateRepository;

        $this->twig = new Environment(new ArrayLoader());
        $this->twig->setCache(false);
        $this->twig->enableStrictVariables();
        $this->twig->addExtension(new SlugifyExtension($slugify));

        $this->seoUrlGenerators = $seoUrlGenerators;
    }

    public function generateSeoUrls(string $salesChannelId, string $routeName, array $ids, ?string $templateOverride = null): iterable
    {
        $generator = $this->getGenerator($routeName);
        $template = $templateOverride ?? $this->getTemplateString($salesChannelId, $routeName, $generator->getDefaultTemplate());
        $this->validateTemplateString($template);

        $template = "{% autoescape 'url' %}$template{% endautoescape %}";

        return $generator->generateSeoUrls($salesChannelId, $ids, $template);
    }

    public static function resolveSeoPath(Connection $connection, string $salesChannelId, string $pathInfo): ?array
    {
        $seoPathInfo = ltrim($pathInfo, '/');
        if ($seoPathInfo === '') {
            return [
                'pathInfo' => '/' . $seoPathInfo,
                'isCanonical' => false,
            ];
        }

        $seoPath = $connection->createQueryBuilder()
            ->select('id', 'path_info pathInfo', 'is_canonical isCanonical')
            ->from('seo_url')
            ->where('sales_channel_id = :salesChannelId')
            ->andWhere('seo_path_info = :seoPath')
            ->andWhere('is_valid = 1')
            ->setMaxResults(1)
            ->setParameter('salesChannelId', Uuid::fromHexToBytes($salesChannelId))
            ->setParameter('seoPath', $seoPathInfo)
            ->execute()
            ->fetch();

        $seoPath = $seoPath !== false
            ? $seoPath
            : ['pathInfo' => $seoPathInfo, 'isCanonical' => false];

        if (!$seoPath['isCanonical']) {
            $query = $connection->createQueryBuilder()
                ->select('path_info pathInfo', 'seo_path_info seoPathInfo')
                ->from('seo_url')
                ->where('sales_channel_id = :salesChannelId')
                ->andWhere('id != :id')
                ->andWhere('path_info = :pathInfo')
                ->andWhere('is_valid = 1')
                ->andWhere('is_canonical = 1')
                ->setMaxResults(1)
                ->setParameter('salesChannelId', Uuid::fromHexToBytes($salesChannelId))
                ->setParameter('id', $seoPath['id'] ?? '')
                ->setParameter('pathInfo', '/' . ltrim($seoPath['pathInfo'], '/'));

            $canonical = $query->execute()->fetch();
            if ($canonical) {
                $seoPath['canonicalPathInfo'] = '/' . ltrim($canonical['seoPathInfo'], '/');
            }
        }

        $seoPath['pathInfo'] = '/' . ltrim($seoPath['pathInfo'], '/');

        return $seoPath;
    }

    public function updateSeoUrls(string $salesChannelId, string $routeName, array $foreignKeys, iterable $seoUrls): void
    {
        $canonicals = $this->findCanonicalPaths($salesChannelId, $routeName, $foreignKeys);
        $dateTime = (new \DateTimeImmutable())->format(Defaults::DATE_FORMAT);
        $insertQuery = new MultiInsertQueryQueue($this->connection, 250, false, false);

        $updatedFks = [];
        $obsoleted = [];

        /** @var SeoUrlEntity $seoUrl */
        foreach ($seoUrls as $seoUrl) {
            if ($seoUrl instanceof \JsonSerializable) {
                $seoUrl = $seoUrl->jsonSerialize();
            }

            $fk = $seoUrl['foreignKey'];
            $updatedFks[] = $fk;

            $existing = null;
            if (array_key_exists($fk, $canonicals)) {
                $existing = $canonicals[$fk];
            }

            if ($existing) {
                // entity has override or does not change
                if ($existing['isModified'] || $seoUrl['seoPathInfo'] === $existing['seoPathInfo']) {
                    continue;
                }
                $obsoleted[] = $existing['id'];
            }

            $insert = [];
            $insert['id'] = Uuid::uuid4()->getBytes();
            $insert['sales_channel_id'] = Uuid::fromHexToBytes($salesChannelId);
            $insert['foreign_key'] = Uuid::fromHexToBytes($seoUrl['foreignKey']);

            $insert['path_info'] = $seoUrl['pathInfo'];
            $insert['seo_path_info'] = $seoUrl['seoPathInfo'];

            $insert['route_name'] = $routeName;
            $insert['is_canonical'] = ($seoUrl['isCanonical'] ?? true) ? 1 : 0;
            $insert['is_modified'] = ($seoUrl['isModified'] ?? false) ? 1 : 0;

            $insert['is_valid'] = true;
            $insert['created_at'] = $dateTime;
            $insertQuery->addInsert(SeoUrlDefinition::getEntityName(), $insert);
        }
        $insertQuery->execute();

        if (!empty($obsoleted)) {
            $obsoleted = array_map(function ($id) { return Uuid::fromHexToBytes($id); }, $obsoleted);
            $this->connection->createQueryBuilder()
                ->update('seo_url')
                ->set('is_canonical', '0')
                ->set('updated_at', ':dateTime')
                ->where('id IN (:ids)')
                ->setParameter('dateTime', $dateTime)
                ->setParameter('ids', $obsoleted, Connection::PARAM_STR_ARRAY)
                ->execute();
        }

        $deletedIds = array_diff($foreignKeys, $updatedFks);
        if (!empty($deletedIds)) {
            $deletedIds = array_map(function ($id) { return Uuid::fromHexToBytes($id); }, $deletedIds);
            $this->connection->createQueryBuilder()
                ->update('seo_url')
                ->set('is_deleted', '1')
                ->set('updated_at', ':dateTime')
                ->where('foreign_key IN (:fks)')
                ->setParameter('dateTime', $dateTime)
                ->setParameter('fks', $deletedIds, Connection::PARAM_STR_ARRAY)
                ->execute();
        }

        $this->invalidateDuplicates($salesChannelId);
    }

    private function getGenerator(string $routeName): SeoUrlGeneratorInterface
    {
        foreach ($this->seoUrlGenerators as $generator) {
            if ($generator->getRouteName() === $routeName) {
                return $generator;
            }
        }

        throw new \RuntimeException('SeoUrlGenerator with ' . $routeName . ' not found.');
    }

    private function getTemplateString(string $salesChannelId, string $routeName, string $defaultTemplate): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));
        $criteria->addFilter(new EqualsFilter('routeName', $routeName));

        /** @var SeoUrlTemplateEntity|null $seoUrlTemplate */
        $seoUrlTemplate = $this->seoUrlTemplateRepository->search($criteria, Context::createDefaultContext())->first();
        if (!$seoUrlTemplate) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('salesChannelId', null));
            $criteria->addFilter(new EqualsFilter('routeName', $routeName));

            // default
            $seoUrlTemplate = $this->seoUrlTemplateRepository->search($criteria, Context::createDefaultContext())->first();
        }

        return $seoUrlTemplate ? $seoUrlTemplate->getTemplate() : $defaultTemplate;
    }

    private function validateTemplateString(string $template): void
    {
        try {
            $this->twig->setLoader(new ArrayLoader(['template' => $template]));
            $this->twig->loadTemplate('template');
        } catch (SyntaxError $syntaxError) {
            throw new InvalidTemplateException('Syntax error: ' . $syntaxError->getMessage(), $syntaxError);
        }
    }

    private function findCanonicalPaths(string $salesChannelId, $routeName, array $foreignKeys): array
    {
        $fks = array_map(function ($id) { return Uuid::fromHexToBytes($id); }, $foreignKeys);

        $query = $this->connection->createQueryBuilder();

        $query->select([
            'LOWER(HEX(seo_url.foreign_key)) as indexKey',
            'LOWER(HEX(seo_url.id)) as id',
            'seo_url.is_modified as isModified',
            'seo_url.seo_path_info seoPathInfo',
        ]);
        $query->from('seo_url', 'seo_url');

        $query->andWhere('seo_url.route_name = :routeName');
        $query->andWhere('seo_url.sales_channel_id = :salesChannel');
        $query->andWhere('seo_url.is_canonical = 1');
        $query->andWhere('seo_url.foreign_key IN (:fks)');

        $query->setParameter('fks', $fks, Connection::PARAM_STR_ARRAY);
        $query->setParameter('routeName', $routeName);
        $query->setParameter('salesChannel', Uuid::fromHexToBytes($salesChannelId));

        $rows = $query->execute()->fetchAll();

        return FetchModeHelper::groupUnique($rows);
    }

    private function invalidateDuplicates(string $salesChannelId): void
    {
        /*
         * If we find duplicates for a seo_path_info we need to mark all but one seo_url as invalid.
         * The first created seo_url wins. The ordering is established by the auto_increment column.
         */
        $this->connection->executeQuery('
            UPDATE seo_url
            SET is_valid = 0
            WHERE id IN (
                SELECT tmp.id FROM (
                    SELECT DISTINCT invalid.id
                    FROM seo_url valid
                    INNER JOIN seo_url invalid
                      ON valid.sales_channel_id = invalid.sales_channel_id
                      AND valid.seo_path_info = invalid.seo_path_info
                      AND valid.auto_increment < invalid.auto_increment
                    WHERE valid.sales_channel_id = :sales_channel_id
                    AND NOT valid.is_deleted
                    AND NOT invalid.is_deleted
                ) tmp
            )',
            ['sales_channel_id' => Uuid::fromHexToBytes($salesChannelId)]
        );
    }
}
