<?php
/**
 */

namespace Commercetools\Symfony\CatalogBundle\Model\Repository;

use Commercetools\Core\Builder\Request\RequestBuilder;
use Commercetools\Core\Client;
use Commercetools\Core\Error\InvalidArgumentException;
use Commercetools\Core\Model\Common\LocalizedString;
use Commercetools\Core\Model\Product\Product;
use Commercetools\Core\Model\Product\ProductDraft;
use Commercetools\Core\Model\Product\ProductProjection;
use Commercetools\Core\Model\Product\ProductProjectionCollection;
use Commercetools\Core\Model\Product\SuggestionCollection;
use Commercetools\Core\Model\ProductType\ProductTypeDraft;
use Commercetools\Core\Model\ProductType\ProductTypeReference;
use Commercetools\Core\Request\Products\ProductProjectionSearchRequest;
use Commercetools\Symfony\CtpBundle\Model\QueryParams;
use Commercetools\Symfony\CtpBundle\Model\Repository;
use Commercetools\Symfony\CatalogBundle\Model\Search;
use Commercetools\Symfony\CtpBundle\Service\MapperFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\UriInterface;

class CatalogRepository extends Repository
{
    const NAME = 'products';

    /**
     * @var Search
     */
    private $searchModel;

    /**
     * CatalogRepository constructor.
     * @param string|bool $enableCache
     * @param CacheItemPoolInterface $cache
     * @param Client $client
     * @param MapperFactory $mapperFactory
     * @param Search $searchModel
     */
    public function __construct(
        $enableCache,
        CacheItemPoolInterface $cache,
        Client $client,
        MapperFactory $mapperFactory,
        Search $searchModel
    ) {
        $this->searchModel = $searchModel;
        parent::__construct($enableCache, $cache, $client, $mapperFactory);
    }


    /**
     * @param string $locale
     * @param string $slug
     * @param string $currency
     * @param string $country
     * @return ProductProjection|null
     */
    public function getProductBySlug($locale, $slug, $currency, $country)
    {
        $cacheKey = static::NAME . '-' . $slug . '-' . $locale;

        $productRequest = RequestBuilder::of()->productProjections()
            ->getBySlug($slug, $this->client->getConfig()->getContext()->getLanguages())
            ->country($country)
            ->currency($currency);

        $productProjection = $this->retrieve($cacheKey, $productRequest, $locale);

        return $productProjection;
    }

    /**
     * @param string $locale
     * @param string $id
     * @return ProductProjection
     */
    public function getProductById($locale, $id)
    {
        $cacheKey = static::NAME . '-' . $id . '-' . $locale;

        $productRequest = RequestBuilder::of()->productProjections()->getById($id);

        $product = $this->retrieve($cacheKey, $productRequest, $locale);

        return $product;
    }

    /**
     * @param string $locale
     * @param string $term
     * @param int $limit
     * @param string $currency
     * @param string $country
     * @return ProductProjectionCollection
     */
    public function suggestProducts($locale, $term, $limit, $currency, $country)
    {
        $suggestRequest = RequestBuilder::of()->productProjections()->suggest(LocalizedString::ofLangAndText($locale, $term));
        $response = $this->executeRequest($suggestRequest);
        $data = $response->toArray();
        $language = \Locale::getPrimaryLanguage($locale);

        if (isset($data['searchKeywords.'. $language])) {
            $suggestions = SuggestionCollection::fromArray($data['searchKeywords.'. $language]);
            $suggestion = $suggestions->current();

            if (!is_null($suggestion)) {
                $term = $suggestion->getText();
            }
        }

        $searchRequest = RequestBuilder::of()->productProjections()->search()
            ->limit($limit)
            ->currency($currency)
            ->country($country)
            ->fuzzy(true)
            ->addParam('text.' . $language, $term);

        return $this->executeRequest($searchRequest, $locale);
    }

    /**
     * @param int|null $itemsPerPage
     * @param int|null $currentPage
     * @param string|null $sort
     * @return ProductProjectionSearchRequest
     */
    public function baseSearchProductsRequest($itemsPerPage = 20, $currentPage = 1, $sort = 'id asc')
    {
        return RequestBuilder::of()->productProjections()->search()
            ->sort($sort)
            ->limit($itemsPerPage)
            ->offset(min($itemsPerPage * ($currentPage - 1), 100000));
    }

    /**
     * @param ProductProjectionSearchRequest|null $searchRequest
     * @param string|null $country
     * @param string|null $currency
     * @return ProductProjectionSearchRequest
     */
    public function searchRequestAddCountryAndCurrency(ProductProjectionSearchRequest $searchRequest = null, $country = null, $currency = null)
    {
        if (is_null($searchRequest)) {
            $searchRequest = $this->baseSearchProductsRequest();
        }

        if (!is_null($country)) {
            $searchRequest->country($country);
        }

        if (!is_null($currency)) {
            $searchRequest->currency($currency);
        }

        return $searchRequest;
    }

    /**
     * @param ProductProjectionSearchRequest|null $searchRequest
     * @param string $locale
     * @param UriInterface|null $uri
     * @param string|null $search
     * @return ProductProjectionSearchRequest
     */
    public function searchRequestAddSearchParameters(ProductProjectionSearchRequest $searchRequest = null, $locale, UriInterface $uri = null, $search = null)
    {
        if (is_null($searchRequest)) {
            $searchRequest = $this->baseSearchProductsRequest();
        }

        if (!is_null($search)) {
            $language = \Locale::getPrimaryLanguage($locale);
            $searchRequest->addParam('text.' . $language, $search);
            $searchRequest->fuzzy(true);
        }

        if (!is_null($uri)) {
            $selectedValues = $this->searchModel->getSelectedValues($uri);
            $searchRequest = $this->searchModel->addFacets($searchRequest, $selectedValues);
        }

        return $searchRequest;
    }

    /**
     * @param ProductProjectionSearchRequest|null $searchRequest
     * @param array|null $filters
     * @return ProductProjectionSearchRequest
     */
    public function searchRequestAddSearchFilters(ProductProjectionSearchRequest $searchRequest = null, array $filters = null)
    {
        if (is_null($searchRequest)) {
            $searchRequest = $this->baseSearchProductsRequest();
        }

        if (!is_null($filters)) {
            foreach ($filters as $type => $typeFilters) {
                foreach ($typeFilters as $filter) {
                    switch ($type) {
                        case 'filter':
                            $searchRequest->addFilter($filter);
                            break;
                        case 'filter.query':
                            $searchRequest->addFilterQuery($filter);
                            break;
                        case 'filter.facets':
                            $searchRequest->addFilterFacets($filter);
                            break;
                        default:
                            throw new InvalidArgumentException('unknown filter type provided');
                    }
                }
            }
        }

        return $searchRequest;
    }

    /**
     * @param ProductProjectionSearchRequest $searchRequest
     * @param $locale
     * @return array
     */
    public function executeSearchRequest(ProductProjectionSearchRequest $searchRequest, $locale)
    {
        $response = $searchRequest->executeWithClient($this->getClient());
        $products = $searchRequest->mapFromResponse(
            $response,
            $this->getMapper($locale)
        );

        return [$products, $response->getFacets(), $response->getOffset(), $response->getTotal()];
    }

    /**
     * @param $locale
     * @param QueryParams $params
     * @return mixed
     */
    public function getProductTypes($locale, QueryParams $params = null)
    {
        $productTypesRequest = RequestBuilder::of()->productTypes()->query();

        return $this->executeRequest($productTypesRequest, $locale, $params);
    }

    /**
     * @param $locale
     * @param QueryParams $params
     * @return mixed
     */
    public function getCategories($locale, QueryParams $params = null)
    {
        $categoriesRequest = RequestBuilder::of()->categories()->query();

        return $this->executeRequest($categoriesRequest, $locale, $params);
    }

    /**
     * @param Product $product
     * @param array $actions
     * @param QueryParams|null $params
     * @return mixed
     */
    public function update(Product $product, array $actions, QueryParams $params = null)
    {
        $request = RequestBuilder::of()->products()->update($product)->setActions($actions);

        if (!is_null($params)) {
            foreach ($params->getParams() as $param) {
                $request->addParamObject($param);
            }
        }

        return $this->executeRequest($request);
    }

    /**
     * @param $locale
     * @param ProductTypeReference $productType
     * @param $name
     * @param $slug
     * @return mixed
     */
    public function createProduct($locale, ProductTypeReference $productType, $name, $slug)
    {
        $productDraft = ProductDraft::ofTypeNameAndSlug(
            $productType,
            LocalizedString::ofLangAndText($locale, $name),
            LocalizedString::ofLangAndText($locale, $slug)
        );

        $request = RequestBuilder::of()->products()->create($productDraft);

        return $this->executeRequest($request, $locale);
    }

    /**
     * @param $locale
     * @param $name
     * @param $description
     * @return mixed
     */
    public function createProductType($locale, $name, $description)
    {
        $productTypeDraft = ProductTypeDraft::ofNameAndDescription($name, $description);

        $request = RequestBuilder::of()->productTypes()->create($productTypeDraft);

        return $this->executeRequest($request, $locale);
    }
}
