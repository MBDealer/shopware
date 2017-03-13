<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\StoreFrontBundle\Gateway;

use Doctrine\DBAL\Connection;
use Shopware\Bundle\StoreFrontBundle\Struct;

/**
 * @category  Shopware
 *
 * @copyright Copyright (c) shopware AG (http://www.shopware.de)
 */
class VariantMediaGateway
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var FieldHelper
     */
    private $fieldHelper;

    /**
     * @var Hydrator\MediaHydrator
     */
    private $hydrator;

    /**
     * @param Connection             $connection
     * @param FieldHelper            $fieldHelper
     * @param Hydrator\MediaHydrator $hydrator
     */
    public function __construct(
        Connection $connection,
        FieldHelper $fieldHelper,
        Hydrator\MediaHydrator $hydrator
    ) {
        $this->connection = $connection;
        $this->fieldHelper = $fieldHelper;
        $this->hydrator = $hydrator;
    }

    /**
     * The passed $products array contains in some case two variations of the same product.
     * For example:
     *  - Product.1  (white / XL)
     *  - Product.2  (black / L)
     *
     * The
     * <php>
     * array(
     *     'Product.1' => array(
     *          Shopware\Bundle\StoreFrontBundle\Struct\Media(id=3)  (configuration: color=white / size=XL)
     *          Shopware\Bundle\StoreFrontBundle\Struct\Media(id=4)  (configuration: color=white)
     *      ),
     *     'Product.2' => array(
     *          Shopware\Bundle\StoreFrontBundle\Struct\Media(id=1)  (configuration: color=black)
     *          Shopware\Bundle\StoreFrontBundle\Struct\Media(id=2)  (configuration: size=L)
     *      )
     * )
     * </php>
     *
     * @param Struct\BaseProduct[]      $products
     * @param Struct\TranslationContext $context
     *
     * @return array Indexed by product number. Each element contains a \Shopware\Bundle\StoreFrontBundle\Struct\Media array.
     */
    public function getList($products, Struct\TranslationContext $context)
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product->getVariantId();
        }
        $ids = array_unique($ids);

        $query = $this->getQuery($context);

        $query->andWhere('childImage.article_detail_id IN (:products)')
            ->orderBy('image.main')
            ->addOrderBy('image.position')
            ->setParameter(':products', $ids, Connection::PARAM_INT_ARRAY);

        /** @var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($data as $row) {
            $productId = $row['number'];
            $imageId = $row['__image_id'];

            $result[$productId][$imageId] = $this->hydrator->hydrateProductImage($row);
        }

        return $result;
    }

    /**
     * To get detailed information about the selection conditions, structure and content of the returned object,
     * please refer to the linked classes.
     *
     * @see \Shopware\Bundle\StoreFrontBundle\Gateway\VariantMediaGatewayInterface::getCover()
     *
     * The passed $products array contains in some case two variations of the same product.
     * For example:
     *  - Product.1  (white / XL)
     *  - Product.2  (black / L)
     *
     * The
     * <php>
     * array(
     *     'Product.1' => Shopware\Bundle\StoreFrontBundle\Struct\Media(id=4)  (configuration: color=white)
     *     'Product.2' => Shopware\Bundle\StoreFrontBundle\Struct\Media(id=1)  (configuration: color=black)
     * )
     * </php>
     *
     * @param Struct\BaseProduct[]      $products
     * @param Struct\TranslationContext $context
     *
     * @return Struct\Media[] Indexed by the product order number
     */
    public function getCovers($products, Struct\TranslationContext $context)
    {
        $ids = [];
        foreach ($products as $product) {
            $ids[] = $product->getVariantId();
        }
        $ids = array_unique($ids);

        $query = $this->getQuery($context);

        $query->andWhere('childImage.article_detail_id IN (:products)')
            ->orderBy('image.main')
            ->addOrderBy('image.position')
            ->setParameter(':products', $ids, Connection::PARAM_INT_ARRAY);

        /** @var $statement \Doctrine\DBAL\Driver\ResultStatement */
        $statement = $query->execute();

        $data = $statement->fetchAll(\PDO::FETCH_GROUP);

        $result = [];
        foreach ($data as $number => $row) {
            $cover = array_shift($row);

            $result[$number] = $this->hydrator->hydrateProductImage($cover);
        }

        return $result;
    }

    /**
     * @param \Shopware\Bundle\StoreFrontBundle\Struct\TranslationContext $context
     *
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function getQuery(Struct\TranslationContext $context)
    {
        $query = $this->connection->createQueryBuilder();

        $query->addSelect('variant.ordernumber as number')
            ->addSelect($this->fieldHelper->getMediaFields())
            ->addSelect($this->fieldHelper->getImageFields());

        $query->from('s_articles_img', 'image')
            ->innerJoin('image', 's_media', 'media', 'image.media_id = media.id')
            ->innerJoin('media', 's_media_album_settings', 'mediaSettings', 'mediaSettings.albumID = media.albumID')
            ->innerJoin('image', 's_articles_img', 'childImage', 'childImage.parent_id = image.id')
            ->innerJoin('image', 's_articles_details', 'variant', 'variant.id = childImage.article_detail_id')
            ->leftJoin('image', 's_media_attributes', 'mediaAttribute', 'mediaAttribute.mediaID = image.media_id')
            ->leftJoin('image', 's_articles_img_attributes', 'imageAttribute', 'imageAttribute.imageID = image.id');

        $this->fieldHelper->addImageTranslation($query, $context);
        $this->fieldHelper->addMediaTranslation($query, $context);

        return $query;
    }
}
