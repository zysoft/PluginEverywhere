<?php

//This class is used to wrap static classes to transport them to a plugin instance.
class PluginTransport {
  //Name of the static class we enable transport for
  private $_className = null;

  //Creates transport class instance
  public function __construct($className)
  {
    $this->_className = $className;
  }

  //A getter to allow plugin to access object public properties in transparent way
  public function __get($attribute)
  {
    return call_user_func_array(array($this->_className, 'getStaticSensorData'), array($attribute));
  }

  //Call allows plugin to perform transparent calls to static object
  public function __call($methodName, $parameters)
  {
    return call_user_func_array(array($this->_className, $methodName), $parameters);
  }

  //Checks static object to be instance of given class
  public function isInstanceOf($className)
  {
    return (new $this->_className instanceof $className);
  }

  //wraps getSensorData that is used for dynamic objects and delegates it to getStaticSensorData
  public function getSensorData($sensorName)
  {
    return $this->getStaticSensorData($sensorName);
  }

}

