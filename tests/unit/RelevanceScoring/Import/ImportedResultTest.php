<?php

namespace WikiMedia\Test\RelevanceScoring\Import;

use WikiMedia\RelevanceScoring\Import\ImportedResult;

class ImportedResultTest extends \PHPUnit_Framework_TestCase
{
    public function createFromURLProvider()
    {
        return array(
            'standard url' => array(
                'John F. Kennedy',
                'https://en.wikipedia.org/wiki/John_F._Kennedy',
            ),
            'url with encoded parts' => array(
                'Fuller\'s Brewery',
                'https://en.wikipedia.org/wiki/Fuller%27s_Brewery',
            ),
            'oddly formed url with query string' => array(
                'Talk:SL-1',
                'https://en.wikipedia.org/wiki?title=Talk:SL-1',
            ),
            'query string with encoded parts' => array(
                'Foo & Bar',
                'https://en.wikipedia.org/w/index.php?title=Foo_%26_Bar',
            ),
        );
    }

    /**
     * @dataProvider createFromURLProvider
     */
    public function testCreateFromURL($title, $url)
    {
        $result = ImportedResult::createFromURL('unitTest', $url, '', 1);

        $this->assertEquals('unitTest', $result->getSource());
        $this->assertEquals($title, $result->getTitle());
        $this->assertEquals(1, $result->getPosition());
    }
}
