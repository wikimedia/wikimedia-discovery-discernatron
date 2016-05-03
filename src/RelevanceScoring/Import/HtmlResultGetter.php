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
            throw new RuntimeException('Failed search');
        }

        $doc = phpQuery::newDocumentHTML(
            (string) $response->getBody(),
            'utf8'
        );

        if ($doc[$this->selectors['is_valid']]->count() === 0) {
            throw new RuntimeException('No results section');
        }

        $results = [];
        foreach ($doc[$this->selectors['results']] as $result) {
            $pq = \pq($result);
            $url = $pq[$this->selectors['url']]->attr('href');
            if ($this->isValidWikiArticle($wiki, $url)) {
                $results[] = ImportedResult::createFromURL(
                    $this->source,
                    $url,
                    $pq[$this->selectors['snippet']]->text(),
                    count($results)
                );
            }
        }

        return $results;
    }

    /**
     * @param string $wiki
     * @return string
     */
    private function getWikiDomain($wiki)
    {
        return parse_url($this->wikis[$wiki], PHP_URL_HOST);
    }

    /**
     * @param string $url
     * @return bool
     */
    private function isValidWikiArticle($wiki, $url)
    {

        $parts = parse_url($url);

        $domain = strtolower($this->getWikiDomain($wiki));
        $urlDomain = strtolower($parts['host']);
        if ($urlDomain !== $domain) {
            return false;
        }

        if (strlen($parts['path']) > 6 && substr($parts['path'], 0, 6) === '/wiki/') {
            return true;
        }

        if (empty($parts['query'])) {
            return false;
        }

        parse_str($parts['query'], $query);
        return !empty($query['title']);
    }
}
