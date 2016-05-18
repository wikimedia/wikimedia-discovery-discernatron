<?php

namespace WikiMedia\RelevanceScoring\Import;

use GuzzleHTTP\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

/**
 * @TODO Is there any benefit of maintaining this on it's own?
 * Could probably just setup an HtmlResultGetter configuration.
 * That would also ensure we get the same behaviour as a typical
 * user rather than having to make sure appropriate api flags
 * are set to mimic web search
 */
class MediaWikiResultGetter implements ResultGetterInterface
{
    /** @var Client */
    private $http;
    /** @var string[] */
    private $wikis;
    /** @var int */
    private $limit;

    /**
     * @param Client   $http
     * @param string[] $wikis
     * @param int      $limit
     */
    public function __construct(Client $http, array $wikis, $limit)
    {
        $this->http = $http;
        $this->wikis = $wikis;
        $this->limit = $limit;
    }

    /**
     * @param string $wiki
     * @param string $query
     *
     * @return PromiseInterface
     */
    public function fetchAsync($wiki, $query)
    {
        return $this->http->requestAsync('GET', $this->wikis[$wiki], [
            'query' => [
                'action' => 'query',
                'list' => 'search',
                'srsearch' => $query,
                'srlimit' => $this->limit,
                'formatversion' => 2,
                'format' => 'json',
            ],
        ]);
    }

    /**
     * @param ResponseInterface $response
     * @param string            $wiki
     * @param string            $query
     *
     * @return array
     *
     * @throws RuntimeException
     */
    public function handleResponse(ResponseInterface $response, $wiki, $query)
    {
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Failed search');
        }

        $json = $response->getBody();
        $decoded = json_decode((string) $json, true);
        if (!isset($decoded['query']['search'])) {
            throw new RuntimeException('Invalid response: no .query.search');
        }

        $results = [];
        foreach ($decoded['query']['search'] as $result) {
            $results[] = new ImportedResult(
                $wiki,
                $result['title'],
                $this->convertSnippet($result['snippet']),
                count($results)
            );
        }

        return $results;
    }

    private function convertSnippet($snippetHtml)
    {
        $replaced = strtr($snippetHtml, [
            '<span class="searchmatch">' => ImportedResult::START_HIGHLIGHT_MARKER,
            '</span>' => ImportedResult::END_HIGHLIGHT_MARKER,
        ]);

        $c = new \Symfony\Component\DomCrawler\Crawler();
        $c->addContent($replaced, 'text/html; charset=UTF-8');

        return trim($c->text());
    }
}
