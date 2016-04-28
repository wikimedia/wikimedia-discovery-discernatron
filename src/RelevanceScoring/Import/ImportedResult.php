<?php

namespace WikiMedia\RelevanceScoring\Import;

class ImportedResult
{
    /** @var string */
    private $source;
    /** @var string */
    private $title;
    /** @var int */
    private $position;

    /**
     * ImportedResult constructor.
     * 
     * @param string $source
     * @param string $title
     * @param int $position
     */
    public function __construct($source, $title, $position)
    {
        $this->source = $source;
        $this->title = $title;
        $this->position = $position;
    }

    public static function createFromURL($source, $url, $position)
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

        return new self($source, $title, $position);
    }

    public function getSource()
    {
        return $this->source;
    }

    public function getTitle()
    {
        return $this->title;
    }

    public function getPosition()
    {
        return $this->position;
    }
}
