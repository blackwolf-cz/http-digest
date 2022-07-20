<?php
namespace Jasny\HttpDigest\Tests;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;

/**
 * Helper methods
 */
trait TestHelper
{
    /**
     * Call a private or protected method
     *
     * @param object $object
     * @param string $method
     * @param array  $args
     * @return mixed
     */
    protected function callPrivateMethod($object, string $method, array $args = [])
    {
        $refl = new \ReflectionMethod(get_class($object), $method);
        $refl->setAccessible(true);
        
        return $refl->invokeArgs($object, $args);
    }
    
    /**
     * Set a private or protected property
     * 
     * @param object $object
     * @param string $property
     * @param mixed  $value
     */
    protected function setPrivateProperty($object, string $property, $value)
    {
        $refl = new \ReflectionProperty(get_class($object), $property);
        $refl->setAccessible(true);
        
        $refl->setValue($object, $value);
    }

    /**
     * Create mock for next callback.
     * 
     * <code>
     *   $callback = $this->createCallbackMock($this->once(), ['abc'], 10);
     * </code>
     * 
     * OR
     * 
     * <code>
     *   $callback = $this->createCallbackMock(
     *     $this->once(),
     *     function(PHPUnit_Framework_MockObject_InvocationMocker $invoke) {
     *       $invoke->with('abc')->willReturn(10);
     *     }
     *   );
     * </code>
     * 
     * @param InvocationOrder          $matcher
     * @param \Closure|array|null $assert
     * @param mixed               $return
     * @return MockObject
     */
    protected function createCallbackMock(InvocationOrder $matcher, $assert = null, $return = null): MockObject
    {
        if (isset($assert) && !is_array($assert) && !$assert instanceof \Closure) {
            $type = (is_object($assert) ? get_class($assert) . ' ' : '') . gettype($assert);
            throw new \InvalidArgumentException("Expected an array or Closure, got a $type");
        }
        
        $callback = $this->getMockBuilder(\stdClass::class)->setMethods(['__invoke'])->getMock();
        $invoke = $callback->expects($matcher)->method('__invoke');
        
        if ($assert instanceof \Closure) {
            $assert($invoke);
        } elseif (is_array($assert)) {
            $invoke->with(...$assert)->willReturn($return);
        }
        
        return $callback;
    }
}

