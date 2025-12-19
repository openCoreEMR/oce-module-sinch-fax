<?php

/**
 * Unit tests for GlobalsAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use PHPUnit\Framework\TestCase;

class GlobalsAccessorTest extends TestCase
{
    private GlobalsAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new GlobalsAccessor();

        // Clear any test globals
        unset($GLOBALS['test_key']);
        unset($GLOBALS['test_string']);
        unset($GLOBALS['test_bool']);
        unset($GLOBALS['test_int']);
    }

    protected function tearDown(): void
    {
        // Clean up test globals
        unset($GLOBALS['test_key']);
        unset($GLOBALS['test_string']);
        unset($GLOBALS['test_bool']);
        unset($GLOBALS['test_int']);
    }

    public function testGetReturnsValueWhenExists(): void
    {
        $GLOBALS['test_key'] = 'test_value';

        $result = $this->accessor->get('test_key');

        $this->assertEquals('test_value', $result);
    }

    public function testGetReturnsDefaultWhenKeyNotExists(): void
    {
        $result = $this->accessor->get('nonexistent_key', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function testGetReturnsNullWhenKeyNotExistsAndNoDefault(): void
    {
        $result = $this->accessor->get('nonexistent_key');

        $this->assertNull($result);
    }

    public function testSetStoresValue(): void
    {
        $this->accessor->set('test_key', 'new_value');

        $this->assertEquals('new_value', $GLOBALS['test_key']);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $GLOBALS['test_key'] = 'old_value';

        $this->accessor->set('test_key', 'new_value');

        $this->assertEquals('new_value', $GLOBALS['test_key']);
    }

    public function testHasReturnsTrueWhenKeyExists(): void
    {
        $GLOBALS['test_key'] = 'value';

        $result = $this->accessor->has('test_key');

        $this->assertTrue($result);
    }

    public function testHasReturnsFalseWhenKeyNotExists(): void
    {
        $result = $this->accessor->has('nonexistent_key');

        $this->assertFalse($result);
    }

    public function testGetStringReturnsStringValue(): void
    {
        $GLOBALS['test_string'] = 'hello';

        $result = $this->accessor->getString('test_string');

        $this->assertEquals('hello', $result);
    }

    public function testGetStringReturnsDefaultWhenNotExists(): void
    {
        $result = $this->accessor->getString('nonexistent', 'default');

        $this->assertEquals('default', $result);
    }

    public function testGetStringConvertsNonStringToString(): void
    {
        $GLOBALS['test_string'] = 123;

        $result = $this->accessor->getString('test_string');

        $this->assertIsString($result);
        $this->assertEquals('123', $result);
    }

    public function testGetBooleanReturnsBooleanValue(): void
    {
        $GLOBALS['test_bool'] = true;

        $result = $this->accessor->getBoolean('test_bool');

        $this->assertTrue($result);
    }

    public function testGetBooleanReturnsDefaultWhenNotExists(): void
    {
        $result = $this->accessor->getBoolean('nonexistent', true);

        $this->assertTrue($result);
    }

    public function testGetBooleanConvertsStringTrue(): void
    {
        $GLOBALS['test_bool'] = '1';

        $result = $this->accessor->getBoolean('test_bool');

        $this->assertTrue($result);
    }

    public function testGetBooleanConvertsStringFalse(): void
    {
        $GLOBALS['test_bool'] = '0';

        $result = $this->accessor->getBoolean('test_bool');

        $this->assertFalse($result);
    }

    public function testGetBooleanConvertsStringBooleans(): void
    {
        $GLOBALS['test_bool'] = 'true';
        $this->assertTrue($this->accessor->getBoolean('test_bool'));

        $GLOBALS['test_bool'] = 'false';
        $this->assertFalse($this->accessor->getBoolean('test_bool'));
    }

    public function testGetIntReturnsIntValue(): void
    {
        $GLOBALS['test_int'] = 42;

        $result = $this->accessor->getInt('test_int');

        $this->assertEquals(42, $result);
    }

    public function testGetIntReturnsDefaultWhenNotExists(): void
    {
        $result = $this->accessor->getInt('nonexistent', 10);

        $this->assertEquals(10, $result);
    }

    public function testGetIntConvertsStringToInt(): void
    {
        $GLOBALS['test_int'] = '123';

        $result = $this->accessor->getInt('test_int');

        $this->assertIsInt($result);
        $this->assertEquals(123, $result);
    }

    public function testGetIntConvertsFloatToInt(): void
    {
        $GLOBALS['test_int'] = 123.7;

        $result = $this->accessor->getInt('test_int');

        $this->assertIsInt($result);
        $this->assertEquals(123, $result);
    }

    public function testAllReturnsAllGlobals(): void
    {
        $result = $this->accessor->all();

        $this->assertIsArray($result);
        $this->assertSame($GLOBALS, $result);
    }
}
