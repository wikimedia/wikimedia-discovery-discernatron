<?php

namespace WikiMedia\RelevanceScoring\Import;

use GuzzleHTTP\Client;
use GuzzleHttp\Promise\PromiseInterface;
use phpQuery;
use Psr\Http\Message\ResponseInterface;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

class HtmlResultGetter implements ResultGetterInterface
{
    private $http;
    private $wikis;
    private $source;
    private $url;
    private $selectors;
    private $extraQueryParams;

    /**
     * @param Client                $http
     * @param array<string, string> $wikis
     * @param string                $source
     * @param string                $url
     * @param array<string, string> $selectors
     * @param array<string, string> $extraQueryParams
     */
    public function __construct(
        Client $http,
        array $wikis,
        $source,
        $url,
        array $selectors,
        array $extraQueryParams = array()
    ) {
        $this->http = $http;
        $this->wikis = $wikis;
        $this->source = $source;
        $this->url = $url;
        $this->selectors = $selectors;
        $this->extraQueryParams = $extraQueryParams;
    }

    /**
     * @param string $wiki
     * @param string $query
     *
     * @return PromiseInterface
     */
    public function fetchAsync($wiki, $query)
    {
        $domain = $this->getWikiDomain($wiki);

        return $this->http->requestAsync('GET', $this->url, [
            'query' => [
                'q' => "site:$domain $query",
            ] + $this->extraQueryParams,
        ]);
    }

    private function getWikiDomain($wiki)
    {
        return parse_url($this->wikis[$wiki], PHP_URL_HOST);
    }
    /**
     * @param ResponseInterface $response
     * @param string            $wiki
     * @param string            $query
     *
     * @return ImportedResult[]
     *
     * @throws \Exception
     */
    public function handleResponse(ResponseInterface $response, $wiki, $query)
    {
        if ($response->getStatusCode() !== 200) {
            var_dump($response);
            throw new RuntimeException('Failed search');
        }

        $doc = phpQuery::newDocumentHTML(
            (string) $response->getBody(),
            'utf8'
        );

        if ($doc[$this->selectors['is_valid']]->count() === 0) {
            throw new RuntimeException('No results section');
        }

        $domain = strtolower($this->getWikiDomain($wiki));
        $results = [];
        foreach ($doc[$this->selectors['results']] as $result) {
            $pq = \pq($result);
            $url = $pq[$this->selectors['url']]->attr('href');
            $urlDomain = strtolower(parse_url($url, PHP_URL_HOST));
            if ($urlDomain === $domain) {
                $results[] = ImportedResult::createFromURL(
                    $this->source,
                    $pq[$this->selectors['url']]->attr('href'),
                    count($results)
                );
            }
        }

        return $results;
    }
}
