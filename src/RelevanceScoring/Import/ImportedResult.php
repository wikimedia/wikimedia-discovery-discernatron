<?php

namespace WikiMedia\RelevanceScoring\Import;

class ImportedResult
{
    // @todo replace with unprintable unicode characters
    const START_HIGHLIGHT_MARKER = "\xee\x80\x80"; // \uE000
    const END_HIGHLIGHT_MARKER = "\xee\x80\x81"; // \uE001

    /** @var string */
    private $source;
    /** @var string */
    private $title;
    /** @var string */
    private $snippet;
    /** @var int */
    private $position;

    /**
     * ImportedResult constructor.
     *
     * @param string $source
     * @param string $title
     * @param string $snippet
     * @param int    $position
     */
    public function __construct($source, $title, $snippet, $position)
    {
        $this->source = $source;
        $this->title = $title;
        $this->snippet = $snippet;
        $this->position = $position;
    }

    public static function createFromURL($source, $url, $snippet, $position)
    {
        $path = parse_url($url, PHP_URL_PATH);
         // make the bold assumption wikimedia wikis all
        // prefix with /wiki/
        $prefix = '/wiki/';
        if ($prefix === substr($path, 0, strlen($prefix))) {
            $titlePart = substr($path, strlen($prefix));
            $title = urldecode(strtr($titlePart, '_', ' '));
            if (!empty($title)) {
                return new self($source, $title, $snippet, $position);
            }
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $decoded);
            if (!empty($decoded['title'])) {
                $title = strtr($decoded['title'], '_', ' ');

                return new self($source, $title, $snippet, $position);
            }
        }

        throw new \Exception("Invalid url: $url");
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getSnippet()
    {
        return $this->snippet;
    }

    public function getPosition()
    {
        return $this->position;
    }

    public function getSnippetScore()
    {
        if (strlen($this->snippet) === 0) {
            return 0;
        }
        switch ($this->source) {
        case 'google':
            return 100;
        case 'bing':
            return 80;
        case 'ddg':
            return 50;
        default:
            return 10;
        }
    }
}
