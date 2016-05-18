<?php

namespace WikiMedia\RelevanceScoring\Import;

use GuzzleHTTP\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;
use WikiMedia\RelevanceScoring\Exception\RuntimeException;

class HtmlResultGetter implements ResultGetterInterface
{
    private $http;
    private $wikis;
    private $source;
    private $url;
    private $selectors;
    private $highlightStart;
    private $highlightEnd;
    private $extraQueryParams;

    /**
     * @param Client                $http
     * @param array<string, string> $wikis
     * @param string                $source
     * @param string                $url
     * @param array<string, string> $selectors
     * @param string                $highlightStart   Marker that signifies the beginning of
     *                                                highlighted snippet text.
     * @param string                $highlightEnd     Marker that signifies the end of
     *                                                highlighted snippet text.
     * @param array<string, string> $extraQueryParams
     */
    public function __construct(
        Client $http,
        array $wikis,
        $source,
        $url,
        array $selectors,
        $highlightStart,
        $highlightEnd,
        array $extraQueryParams = array()
    ) {
        $this->http = $http;
        $this->wikis = $wikis;
        $this->source = $source;
        $this->url = $url;
        $this->selectors = $selectors;
        $this->highlightStart = $highlightStart;
        $this->highlightEnd = $highlightEnd;
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

        $html = (string) $response->getBody();
        $contentType = $response->hasHeader('Content-Type')
            ? end($response->getHeader('Content-Type'))
            : null;

        $crawler = new Crawler();
        $crawler->addContent($html, $contentType);

        if ($crawler->filter($this->selectors['is_valid'])->count() === 0) {
            throw new RuntimeException('No results section');
        }

        $results = [];
        $crawler->filter($this->selectors['results'])->each(function ($result) use (&$results, $wiki, $contentType) {
            $url = $result->filter($this->selectors['url'])->attr('href');
            if ($this->isValidWikiArticle($wiki, $url)) {
                $results[] = ImportedResult::createFromURL(
                    $this->source,
                    $url,
                    $this->extractSnippet($result, $contentType),
                    count($results)
                );
            }
        });

        return $results;
    }

    /**
     * @param string $wiki
     *
     * @return string
     */
    private function getWikiDomain($wiki)
    {
        return parse_url($this->wikis[$wiki], PHP_URL_HOST);
    }

    /**
     * @param string $wiki The wiki the url should belong to
     * @param string $url  The url to check
     *
     * @return bool True is the provided URL looks like a url
     *              for an article on the provided wiki
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

    /**
     * @param DOMCrawler $result      Crawler instance containing a single
     *                                search result.
     * @param string     $contentType The content type of the result
     *
     * @return string Sanitized HTML string containing only tags for bold
     *                open and close
     */
    private function extractSnippet(Crawler $result, $contentType)
    {
        // Pull html from the result
        $html = $result->filter($this->selectors['snippet'])->html();
        // Replace start and end of bolded portions with custom markers
        $replaced = strtr($html, [
            $this->highlightStart => ImportedResult::START_HIGHLIGHT_MARKER,
            $this->highlightEnd => ImportedResult::END_HIGHLIGHT_MARKER,
        ]);

        // convert html -> plaintext. Perhaps slower than necessary, but is
        // safer than stringing together some function ourself.
        $c = new Crawler();
        // UTF-8 might be a bold assumption...
        $c->addContent($replaced, $contentType);

        return trim($c->text());
    }
}
