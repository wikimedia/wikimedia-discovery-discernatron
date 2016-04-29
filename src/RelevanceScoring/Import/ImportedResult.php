<?php

namespace WikiMedia\RelevanceScoring\Import;

class ImportedResult
{
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
     * @param int $position
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
        if ($prefix !== substr($path, 0, strlen($prefix))) {
            throw new \Exception("Invalid url: $url");
        }
        $titlePart = substr($path, strlen($prefix));
        $title = urldecode(strtr($titlePart, '_', ' '));

        return new self($source, $title, $snippet, $position);
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
}
