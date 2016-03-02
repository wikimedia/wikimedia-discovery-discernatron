<?php

namespace WikiMedia\Test\RelevanceScoring\Import;

use WikiMedia\RelevanceScoring\Import\ImportedResult;

class ImportedResultTest extends \PHPUnit_Framework_TestCase
{
    public function testSomething()
    {
        $result = ImportedResult::createFromURL(
            'unitTest',
            'https://en.wikipedia.org/wiki/John_F._Kennedy',
            1
        );

        $this->assertEquals('unitTest', $result->getSource());
        $this->assertEquals('John F. Kennedy', $result->getTitle());
        $this->assertEquals(1, $result->getPosition());
    }
}
