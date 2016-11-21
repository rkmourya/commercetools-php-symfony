<?php
/**
 * Created by PhpStorm.
 * User: ibrahimselim
 * Date: 21/11/16
 * Time: 11:41
 */

namespace Commercetools\Symfony\CtpBundle\Model\Import;

use Commercetools\Core\Client;

class ProductsImport
{
    /**
     * @var Client
     */
    private $client;
    private $requestBuilder;
    private $identifiedByColumn;
    private $requests;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->requestBuilder = new ProductsRequestBuilder($this->client);
    }

    public function import($data)
    {
        $headings = null;
        $import = null;

        foreach ($data as $row) {
            $this->client->addBatchRequest(
                $this->requestBuilder->createRequest($row, $this->identifiedByColumn)
            );
            $this->requests++;
            $this->execute();
            break;
        }

        $this->execute(true);
    }

    private function execute($force = false)
    {
        $responses = null;
        if ($force || $this->requests > 25) {
            $responses = $this->client->executeBatch();
            $this->requests = 0;
        }

        return $responses;
    }
    public function setOptions($identifiedByColumn)
    {
        $this->identifiedByColumn = $identifiedByColumn;
    }
}